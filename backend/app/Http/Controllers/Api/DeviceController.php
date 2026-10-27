<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceResource;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Warehouse;
use App\Security\AuditLogger;
use App\Security\DeviceCredentialService;
use App\Security\SessionRevocationService;
use App\Security\TenantEntitlementService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
    public function __construct(private readonly DeviceCredentialService $credentials) {}

    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        $existing = $this->credentials->locate($request);
        if ($existing) {
            return (new DeviceResource($existing))->response()->cookie($this->credentials->cookie($this->credentials->read($request) ?? ''));
        }

        $device = Device::query()->create([
            'name' => $data['name'] ?? 'POS terminal',
            'device_type' => 'pos_terminal',
            'status' => DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        $credential = $this->credentials->issue($device);

        app(AuditLogger::class)->record('DEVICE_ENROLLMENT_REQUESTED', [
            'resource_type' => 'device',
            'resource_ulid' => $device->ulid,
        ], $request);

        return (new DeviceResource($device))
            ->response()
            ->cookie($this->credentials->cookie($credential));
    }

    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Device::class);

        $devices = Device::query()
            ->forTenant($tenantContext->tenantId())
            ->with('branch', 'warehouse', 'approver')
            ->orderByDesc('registered_at')
            ->get();

        $managedIds = DB::table('device_access_requests')
            ->where('tenant_id', $tenantContext->tenantId())
            ->whereIn('device_id', $devices->pluck('id'))
            ->distinct()
            ->pluck('device_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true]);

        foreach ($devices as $device) {
            $device->setAttribute('employee_access_policy',
                $managedIds->has((int) $device->id) || $device->device_type === 'browser'
                    ? 'managed'
                    : 'legacy_grandfathered');
        }

        return DeviceResource::collection($devices);
    }

    public function accessNotifications(Request $request, TenantContext $tenantContext): JsonResponse
    {
        if (! $tenantContext->membership()->hasOwnerAuthority()) {
            throw new ApiException('FORBIDDEN', 'Only the tenant owner may view access notifications.', 403);
        }
        $this->authorize('viewAny', Device::class);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
        $tenantId = $tenantContext->tenantId();
        $membershipId = $tenantContext->membershipId();

        $query = DB::table('device_access_notifications as notice')
            ->join('device_access_requests as access', function ($join) {
                $join->on('access.id', '=', 'notice.access_request_id')
                    ->on('access.tenant_id', '=', 'notice.tenant_id');
            })
            ->join('memberships as employee', function ($join) {
                $join->on('employee.id', '=', 'access.membership_id')
                    ->on('employee.tenant_id', '=', 'notice.tenant_id');
            })
            ->join('devices as device', function ($join) {
                $join->on('device.id', '=', 'access.device_id')
                    ->on('device.tenant_id', '=', 'notice.tenant_id');
            })
            ->where('notice.tenant_id', $tenantId)
            ->where('notice.recipient_membership_id', $membershipId);

        $unread = (clone $query)->whereNull('notice.read_at')->count();
        $page = $query->orderByDesc('notice.created_at')->orderByDesc('notice.id')
            ->paginate(30, [
                'notice.ulid', 'notice.kind', 'notice.created_at', 'notice.read_at',
                'access.ulid as request_ulid', 'access.status',
                'access.request_ip', 'access.request_user_agent',
                'employee.ulid as employee_ulid', 'employee.username',
                'device.ulid as device_ulid', 'device.name as device_name',
            ], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'notifications' => $page->items(),
            'unread_count' => $unread,
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function readAccessNotification(string $notificationUlid, TenantContext $tenantContext): JsonResponse
    {
        if (! $tenantContext->membership()->hasOwnerAuthority()) {
            throw new ApiException('FORBIDDEN', 'Only the tenant owner may read access notifications.', 403);
        }
        $this->authorize('viewAny', Device::class);
        $updated = DB::table('device_access_notifications')
            ->where('tenant_id', $tenantContext->tenantId())
            ->where('recipient_membership_id', $tenantContext->membershipId())
            ->where('ulid', $notificationUlid)
            ->update(['read_at' => DB::raw('COALESCE(read_at, CURRENT_TIMESTAMP)'), 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }
        return response()->json(['ulid' => $notificationUlid, 'read' => true]);
    }

    public function accessRequests(Request $request, TenantContext $tenantContext): JsonResponse
    {
        if (! $tenantContext->membership()->hasOwnerAuthority()) {
            throw new ApiException('FORBIDDEN', 'Only the tenant owner may view employee access requests.', 403);
        }
        $this->authorize('viewAny', Device::class);
        $data = $request->validate([
            'status' => ['sometimes', 'in:pending,approved,rejected,all'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'membership_ulid' => ['sometimes', 'string', 'size:26'],
            'device_ulid' => ['sometimes', 'string', 'size:26'],
        ]);

        $status = $data['status'] ?? 'pending';
        $query = DB::table('device_access_requests as access')
            ->join('memberships as member', function ($join) {
                $join->on('member.id', '=', 'access.membership_id')
                    ->on('member.tenant_id', '=', 'access.tenant_id');
            })
            ->join('devices as device', function ($join) {
                $join->on('device.id', '=', 'access.device_id')
                    ->on('device.tenant_id', '=', 'access.tenant_id');
            })
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'access.reviewed_by')
            ->where('access.tenant_id', $tenantContext->tenantId());

        if (isset($data['membership_ulid'])) {
            $query->where('member.ulid', $data['membership_ulid']);
        }
        if (isset($data['device_ulid'])) {
            $query->where('device.ulid', $data['device_ulid']);
        }

        if ($status !== 'all') {
            $query->where('access.status', $status);
        }

        $page = $query->orderByDesc('access.created_at')
            ->orderByDesc('access.id')
            ->paginate((int) ($data['per_page'] ?? 50), [
                'access.ulid',
                'access.status',
                'access.created_at',
                'access.request_ip',
                'access.request_user_agent',
                'access.reviewed_at',
                'reviewer.ulid as reviewer_user_ulid',
                'reviewer.name as reviewer_name',
                'member.ulid as membership_ulid',
                'member.username',
                'device.ulid as device_ulid',
                'device.name as device_name',
            ], 'page', (int) ($data['page'] ?? 1));

        $members = \App\Models\Membership::query()
            ->where('tenant_id', $tenantContext->tenantId())
            ->whereIn('ulid', collect($page->items())->pluck('membership_ulid'))
            ->with(['roles' => fn ($query) => $query->where('roles.tenant_id', $tenantContext->tenantId())->where('roles.is_active', true)])
            ->get()->keyBy('ulid');

        $page->getCollection()->transform(function ($item) use ($members) {
            $item->role_names = $members->get($item->membership_ulid)?->roles->pluck('name')->values()->all() ?? [];
            return $item;
        });

        $pendingCount = DB::table('device_access_requests')
            ->where('tenant_id', $tenantContext->tenantId())
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'requests' => $page->items(),
            'pending_count' => $pendingCount,
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function reviewAccessRequest(Request $request, string $requestUlid, TenantContext $tenantContext): JsonResponse
    {
        if (! $tenantContext->membership()->hasOwnerAuthority()) {
            throw new ApiException('FORBIDDEN', 'Only the tenant owner may review employee access.', 403);
        }

        $data = $request->validate(['decision' => ['required', 'in:approved,rejected']]);

        $decisionEmail = DB::transaction(function () use ($tenantContext, $requestUlid, $data, $request): array {
            $tenant = \App\Models\Tenant::query()->whereKey($tenantContext->tenantId())
                ->lockForUpdate()->firstOrFail();
            // Recheck reviewer authority inside the serialized decision transaction.
            // A stale session must not approve access after owner rights are removed.
            $reviewer = \App\Models\Membership::query()
                ->with('user')
                ->where('tenant_id', $tenant->id)
                ->whereKey($tenantContext->membership()->id)
                ->lockForUpdate()
                ->first();
            if (! $reviewer
                || $reviewer->status !== \App\Enums\MembershipStatus::Active
                || $reviewer->user?->status !== \App\Enums\UserStatus::Active
                || ! $reviewer->hasOwnerAuthority()) {
                throw new ApiException('FORBIDDEN', 'Only an active tenant owner may review employee access.', 403);
            }

            $access = DB::table('device_access_requests')
                ->where('tenant_id', $tenant->id)
                ->where('ulid', $requestUlid)
                ->lockForUpdate()->first();
            if (! $access) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }
            if ($access->status !== 'pending') {
                throw new ApiException('ACCESS_REQUEST_ALREADY_REVIEWED', 'This request has already been reviewed.', 409);
            }

            $member = \App\Models\Membership::query()->with('user')
                ->where('tenant_id', $tenant->id)->whereKey($access->membership_id)
                ->lockForUpdate()->firstOrFail();
            if ($member->hasOwnerAuthority()
                || $member->status !== \App\Enums\MembershipStatus::Active
                || $member->user?->status !== \App\Enums\UserStatus::Active) {
                throw new ApiException('ACCESS_REQUEST_INVALID', 'Employee is no longer eligible.', 409);
            }

            $device = Device::query()->where('tenant_id', $tenant->id)
                ->whereKey($access->device_id)->lockForUpdate()->firstOrFail();
            if (! in_array($device->status, [DeviceStatus::Pending, DeviceStatus::Active], true)) {
                throw new ApiException('DEVICE_NOT_APPROVED', 'Device is no longer eligible.', 409);
            }
            if ($data['decision'] === 'approved') {
                // Subscription and assigned branch must be valid on every new network grant.
                app(TenantEntitlementService::class)->assertOperational($tenant);
                if ($device->branch_id !== null
                    && ! Branch::query()->forTenant((int) $tenant->id)
                        ->whereKey($device->branch_id)
                        ->where('status', \App\Enums\BranchStatus::Active->value)
                        ->exists()) {
                    throw new ApiException('BRANCH_ACCESS_DENIED', 'The assigned device branch is not active.', 403);
                }
            }
            if ($data['decision'] === 'approved' && $device->status === DeviceStatus::Pending) {
                app(TenantEntitlementService::class)->assertCanRegisterDevice($tenant);
                $device->status = DeviceStatus::Active;
                $device->approved_at = now();
                $device->approved_by = $tenantContext->userId();
                $device->save();
            }

            if ($data['decision'] === 'rejected') {
                // Offline leases are not network-scoped; revoke every lease for
                // this employee/device pair rather than leave an unsafe lease active.
                \App\Models\OfflineAuthorizationLease::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('membership_id', $member->id)
                    ->where('device_id', $device->id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);
            }

            $updated = DB::table('device_access_requests')
                ->where('id', $access->id)
                ->where('tenant_id', $tenant->id)
                ->where('status', 'pending')
                ->update([
                'status' => $data['decision'],
                'reviewed_by' => $tenantContext->userId(),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                throw new ApiException('ACCESS_REQUEST_ALREADY_REVIEWED', 'This request has already been reviewed.', 409);
            }

            // Keep the decision and its audit evidence in the same transaction.
            app(AuditLogger::class)->record(
                $data['decision'] === 'approved' ? 'DEVICE_ACCESS_APPROVED' : 'DEVICE_ACCESS_REJECTED',
                ['resource_type' => 'device_access_request', 'resource_ulid' => $requestUlid],
                $request
            );

            return ['member' => $member, 'device_name' => $device->name];
        });

        // Notify only after the decision has committed; email delivery never changes authorization.
        app(\App\Security\EmployeeDeviceApprovalNotifier::class)->notifyEmployeeDecision(
            $decisionEmail['member'], $decisionEmail['device_name'], $data['decision']
        );

        return response()->json(['ulid' => $requestUlid, 'status' => $data['decision']]);
    }

    public function revokeAccessRequest(Request $request, string $requestUlid, TenantContext $tenantContext): JsonResponse
    {
        if (! $tenantContext->membership()->hasOwnerAuthority()) {
            throw new ApiException('FORBIDDEN', 'Only the tenant owner may revoke employee network access.', 403);
        }
        $this->authorize('viewAny', Device::class);

        $decision = DB::transaction(function () use ($request, $requestUlid, $tenantContext): array {
            $tenant = \App\Models\Tenant::query()->whereKey($tenantContext->tenantId())
                ->lockForUpdate()->firstOrFail();
            $reviewer = \App\Models\Membership::query()->with('user')
                ->where('tenant_id', $tenant->id)
                ->whereKey($tenantContext->membership()->id)->lockForUpdate()->first();
            if (! $reviewer
                || $reviewer->status !== \App\Enums\MembershipStatus::Active
                || $reviewer->user?->status !== \App\Enums\UserStatus::Active
                || ! $reviewer->hasOwnerAuthority()) {
                throw new ApiException('FORBIDDEN', 'Only an active tenant owner may revoke employee network access.', 403);
            }

            $access = DB::table('device_access_requests')
                ->where('tenant_id', $tenant->id)->where('ulid', $requestUlid)
                ->lockForUpdate()->first();
            if (! $access) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }
            if ($access->status !== 'approved') {
                throw new ApiException('ACCESS_REQUEST_NOT_APPROVED', 'Only approved network access may be revoked.', 409);
            }
            $member = \App\Models\Membership::query()->where('tenant_id', $tenant->id)
                ->whereKey($access->membership_id)->first();
            $device = Device::query()->where('tenant_id', $tenant->id)
                ->whereKey($access->device_id)->first();
            if (! $member || ! $device || $member->hasOwnerAuthority()) {
                throw new ApiException('ACCESS_REQUEST_INVALID', 'The approved employee access is no longer eligible.', 409);
            }

            $updated = DB::table('device_access_requests')
                ->where('tenant_id', $tenant->id)->where('id', $access->id)
                ->where('status', 'approved')->update([
                    'status' => 'rejected',
                    'reviewed_by' => $tenantContext->userId(),
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new ApiException('ACCESS_REQUEST_NOT_APPROVED', 'Only approved network access may be revoked.', 409);
            }
            // Leases are not network-bound: revoke all leases for this employee/device.
            \App\Models\OfflineAuthorizationLease::query()
                ->where('tenant_id', $tenant->id)->where('membership_id', $member->id)
                ->where('device_id', $device->id)->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            app(AuditLogger::class)->record('DEVICE_ACCESS_REVOKED', [
                'resource_type' => 'device_access_request', 'resource_ulid' => $requestUlid,
            ], $request);

            return ['member' => $member, 'device_name' => $device->name];
        });

        app(\App\Security\EmployeeDeviceApprovalNotifier::class)->notifyEmployeeDecision(
            $decision['member'], $decision['device_name'], 'rejected'
        );
        return response()->json(['ulid' => $requestUlid, 'status' => 'rejected']);
    }

    public function approve(Request $request, string $deviceUlid, TenantContext $tenantContext): DeviceResource
    {
        $device = $this->findTenantDevice($tenantContext, $deviceUlid);
        $this->authorize('approve', $device);

        if ($tenantContext->membership()->hasRoleCode(\App\Authz\PermissionCatalogue::CASHIER)
            && ! $tenantContext->membership()->hasOwnerAuthority()) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        $data = $request->validate([
            'branch_ulid' => ['nullable', 'string', 'size:26'],
            'warehouse_ulid' => ['nullable', 'string', 'size:26'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        // Serialize generic approval with employee request creation/review on the same tenant lock.
        DB::transaction(function () use ($tenantContext, $deviceUlid, $data, &$device): void {
            $lockedTenant = \App\Models\Tenant::query()
                ->whereKey($tenantContext->tenantId())->lockForUpdate()->firstOrFail();
            $device = $this->findTenantDevice($tenantContext, $deviceUlid);
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            if (DB::table('device_access_requests')
                ->where('tenant_id', $tenantContext->tenantId())
                ->where('device_id', $device->id)
                ->exists()) {
                throw new ApiException(
                    'EMPLOYEE_ACCESS_REVIEW_REQUIRED',
                    'Review the employee-specific device access request instead.',
                    409
                );
            }

            // Browser access follows owner email OTP or employee device/network review.
            // The generic terminal approval endpoint must not bypass either workflow.
            if ($device->device_type === 'browser') {
                throw new ApiException(
                    'DEVICE_ACCESS_VERIFICATION_REQUIRED',
                    'Browser access requires owner email verification or tenant employee access review.',
                    409
                );
            }

            if ($device->status !== DeviceStatus::Active) {
                $entitlements = app(TenantEntitlementService::class);
                $entitlements->assertOperational($lockedTenant);
                $entitlements->assertCanRegisterDevice($lockedTenant);
            }

            $device->status = DeviceStatus::Active;
            $device->approved_at = now();
            $device->approved_by = $tenantContext->userId();
            $device->tenant_id = $tenantContext->tenantId();
            if (isset($data['name'])) {
                $device->name = $data['name'];
            }
            if (! empty($data['branch_ulid'])) {
                $branch = Branch::query()->forTenant($tenantContext->tenantId())->where('ulid', $data['branch_ulid'])->firstOrFail();
                $device->branch_id = $branch->id;
            }
            if (! empty($data['warehouse_ulid'])) {
                $warehouse = Warehouse::query()->forTenant($tenantContext->tenantId())->where('ulid', $data['warehouse_ulid'])->firstOrFail();
                $device->warehouse_id = $warehouse->id;
            }
            if ($device->warehouse_id !== null) {
                $warehouse = Warehouse::query()
                    ->forTenant($tenantContext->tenantId())
                    ->whereKey($device->warehouse_id)
                    ->firstOrFail();
                if ($device->branch_id !== null && (int) $warehouse->branch_id !== (int) $device->branch_id) {
                    throw new ApiException('WAREHOUSE_BRANCH_MISMATCH', 'Warehouse must belong to the assigned device branch.', 422);
                }
            }
            $device->save();

        });

        app(AuditLogger::class)->record('DEVICE_APPROVED', [
            'resource_type' => 'device',
            'resource_ulid' => $device->ulid,
        ], $request);

        return new DeviceResource($device->fresh(['branch', 'warehouse', 'approver']));
    }

    public function revoke(Request $request, string $deviceUlid, TenantContext $tenantContext, SessionRevocationService $revocation): DeviceResource
    {
        $device = $this->findTenantDevice($tenantContext, $deviceUlid);
        $this->authorize('revoke', $device);
        $revocation->revokeDevice($device);

        app(AuditLogger::class)->record('DEVICE_REVOKED', [
            'resource_type' => 'device',
            'resource_ulid' => $device->ulid,
        ], $request);

        return new DeviceResource($device->fresh(['branch', 'warehouse', 'approver']));
    }

    public function assign(Request $request, string $deviceUlid, TenantContext $tenantContext): DeviceResource
    {
        $device = $this->findTenantDevice($tenantContext, $deviceUlid);
        $this->authorize('assignBranch', $device);
        $data = $request->validate([
            'branch_ulid' => ['nullable', 'string', 'size:26'],
            'warehouse_ulid' => ['nullable', 'string', 'size:26'],
        ]);
        DB::transaction(function () use ($tenantContext, $deviceUlid, $data, &$device): void {
            \App\Models\Tenant::query()->whereKey($tenantContext->tenantId())->lockForUpdate()->firstOrFail();
            $device = Device::query()->where('tenant_id', $tenantContext->tenantId())
                ->where('ulid', $deviceUlid)->lockForUpdate()->firstOrFail();

            $branch = ! empty($data['branch_ulid'])
                ? Branch::query()->forTenant($tenantContext->tenantId())->where('ulid', $data['branch_ulid'])->firstOrFail()
                : null;
            $warehouse = ! empty($data['warehouse_ulid'])
                ? Warehouse::query()->forTenant($tenantContext->tenantId())->where('ulid', $data['warehouse_ulid'])->firstOrFail()
                : null;

            if ($branch && $warehouse && (int) $warehouse->branch_id !== (int) $branch->id) {
                throw new ApiException('WAREHOUSE_BRANCH_MISMATCH', 'Warehouse must belong to the assigned device branch.', 422);
            }

            $device->branch_id = $branch?->id;
            $device->warehouse_id = $warehouse?->id;
            $device->save();
        });

        return new DeviceResource($device->fresh(['branch', 'warehouse', 'approver']));
    }

    private function findTenantDevice(TenantContext $tenantContext, string $deviceUlid): Device
    {
        $device = Device::query()
            ->where('ulid', $deviceUlid)
            ->where('tenant_id', $tenantContext->tenantId())
            ->first();

        if (! $device) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $device;
    }
}
