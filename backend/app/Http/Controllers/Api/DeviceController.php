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

        return DeviceResource::collection(
            Device::query()
                ->forTenant($tenantContext->tenantId())
                ->with('branch', 'warehouse', 'approver')
                ->orderByDesc('registered_at')
                ->get()
        );
    }

    public function accessRequests(TenantContext $tenantContext): JsonResponse
    {
        $this->authorize('viewAny', Device::class);

        $rows = DB::table('device_access_requests as access')
            ->join('memberships as member', function ($join) {
                $join->on('member.id', '=', 'access.membership_id')
                    ->on('member.tenant_id', '=', 'access.tenant_id');
            })
            ->join('devices as device', function ($join) {
                $join->on('device.id', '=', 'access.device_id')
                    ->on('device.tenant_id', '=', 'access.tenant_id');
            })
            ->where('access.tenant_id', $tenantContext->tenantId())
            ->where('access.status', 'pending')
            ->orderByDesc('access.created_at')
            ->limit(100)
            ->get([
                'access.ulid', 'member.ulid as membership_ulid',
                'member.username', 'device.ulid as device_ulid',
                'device.name as device_name', 'access.created_at',
            ]);

        return response()->json(['requests' => $rows]);
    }

    public function reviewAccessRequest(Request $request, string $requestUlid, TenantContext $tenantContext): JsonResponse
    {
        if (! $tenantContext->membership()->hasOwnerAuthority()) {
            throw new ApiException('FORBIDDEN', 'Only the tenant owner may review employee access.', 403);
        }

        $data = $request->validate(['decision' => ['required', 'in:approved,rejected']]);

        $row = DB::transaction(function () use ($tenantContext, $requestUlid, $data) {
            $tenant = \App\Models\Tenant::query()->whereKey($tenantContext->tenantId())
                ->lockForUpdate()->firstOrFail();
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

            $member = \App\Models\Membership::query()
                ->where('tenant_id', $tenant->id)->whereKey($access->membership_id)->firstOrFail();
            if ($member->hasOwnerAuthority() || $member->status !== \App\Enums\MembershipStatus::Active) {
                throw new ApiException('ACCESS_REQUEST_INVALID', 'Employee is no longer eligible.', 409);
            }

            $device = Device::query()->where('tenant_id', $tenant->id)
                ->whereKey($access->device_id)->lockForUpdate()->firstOrFail();
            if (! in_array($device->status, [DeviceStatus::Pending, DeviceStatus::Active], true)) {
                throw new ApiException('DEVICE_NOT_APPROVED', 'Device is no longer eligible.', 409);
            }
            if ($data['decision'] === 'approved' && $device->status === DeviceStatus::Pending) {
                app(TenantEntitlementService::class)->assertOperational($tenant);
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

            DB::table('device_access_requests')->where('id', $access->id)->update([
                'status' => $data['decision'],
                'reviewed_by' => $tenantContext->userId(),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
            return $access;
        });

        app(AuditLogger::class)->record(
            $data['decision'] === 'approved' ? 'DEVICE_ACCESS_APPROVED' : 'DEVICE_ACCESS_REJECTED',
            ['resource_type' => 'device_access_request', 'resource_ulid' => $requestUlid],
            $request
        );

        return response()->json(['ulid' => $requestUlid, 'status' => $data['decision']]);
    }

    public function approve(Request $request, string $deviceUlid, TenantContext $tenantContext): DeviceResource
    {
        $device = $this->findTenantDevice($tenantContext, $deviceUlid);
        $this->authorize('approve', $device);

        if ($tenantContext->membership()->hasRoleCode(\App\Authz\PermissionCatalogue::CASHIER)
            && ! $tenantContext->membership()->hasOwnerAuthority()) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

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

        $data = $request->validate([
            'branch_ulid' => ['nullable', 'string', 'size:26'],
            'warehouse_ulid' => ['nullable', 'string', 'size:26'],
            'name' => ['sometimes', 'string', 'max:120'],
        ]);

        if ($device->status !== DeviceStatus::Active) {
            app(TenantEntitlementService::class)->assertCanRegisterDevice($tenantContext->tenant());
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
        $device->save();

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
        if (! empty($data['branch_ulid'])) {
            $device->branch_id = Branch::query()->forTenant($tenantContext->tenantId())->where('ulid', $data['branch_ulid'])->firstOrFail()->id;
        } else {
            $device->branch_id = null;
        }
        if (! empty($data['warehouse_ulid'])) {
            $device->warehouse_id = Warehouse::query()->forTenant($tenantContext->tenantId())->where('ulid', $data['warehouse_ulid'])->firstOrFail()->id;
        } else {
            $device->warehouse_id = null;
        }
        $device->save();

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
