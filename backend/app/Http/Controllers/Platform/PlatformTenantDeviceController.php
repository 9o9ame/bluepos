<?php

namespace App\Http\Controllers\Platform;

use App\Enums\DeviceStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\AuthSessionRecord;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Platform\PlatformAuditLogger;
use App\Security\SessionRevocationService;
use App\Security\TenantEntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformTenantDeviceController extends Controller
{
    private function tenant(string $tenantUlid): Tenant
    {
        return Tenant::query()->where('ulid', $tenantUlid)->firstOrFail();
    }

    public function overview(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'tenant_code' => ['required_with:username', 'string', 'max:60'],
            'username' => ['sometimes', 'string', 'max:120'],
            'event' => ['sometimes', 'in:LOGIN_SUCCESS,LOGIN_FAILURE,LOGIN_BLOCKED,DEVICE_ACCESS_REQUESTED,DEVICE_ACCESS_APPROVED,DEVICE_ACCESS_REJECTED,DEVICE_REVOKED'],
            'ip_address' => ['sometimes', 'ip'],
            'since' => ['sometimes', 'date_format:Y-m-d'],
            'until' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:since'],
        ]);
        $tenant = isset($filters['tenant_code'])
            ? Tenant::query()->where('code', $filters['tenant_code'])->first()
            : null;
        $tenantIds = isset($filters['tenant_code']) ? [$tenant?->id ?? -1] : null;
        $member = isset($filters['username']) && $tenant
            ? \App\Models\Membership::query()->where('tenant_id', $tenant->id)
                ->where('username', $filters['username'])->first()
            : null;
        $memberIds = isset($filters['username']) ? [$member?->id ?? -1] : null;

        $sessions = AuthSessionRecord::query()
            ->when($tenantIds !== null, fn ($query) => $query->whereIn('tenant_id', $tenantIds))
            ->when($memberIds !== null, fn ($query) => $query->whereIn('membership_id', $memberIds))
            ->when(isset($filters['ip_address']), fn ($query) => $query->where('ip_address', $filters['ip_address']))
            ->when(isset($filters['since']), fn ($query) => $query->whereDate('last_seen_at', '>=', $filters['since']))
            ->when(isset($filters['until']), fn ($query) => $query->whereDate('last_seen_at', '<=', $filters['until']))
            ->with(['device:id,ulid,name', 'membership:id,ulid,username,tenant_id', 'membership.tenant:id,ulid,name,code', 'membership.roles:id,ulid,tenant_id,name,code,is_active'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $events = AuditLog::query()
            ->when($tenantIds !== null, fn ($query) => $query->whereIn('tenant_id', $tenantIds))
            ->when($memberIds !== null, fn ($query) => $query->whereIn('actor_membership_id', $memberIds))
            ->when(isset($filters['event']), fn ($query) => $query->where('event', $filters['event']))
            ->when(isset($filters['ip_address']), fn ($query) => $query->where('ip_address', $filters['ip_address']))
            ->when(isset($filters['since']), fn ($query) => $query->whereDate('occurred_at', '>=', $filters['since']))
            ->when(isset($filters['until']), fn ($query) => $query->whereDate('occurred_at', '<=', $filters['until']))
            ->whereIn('event', ['LOGIN_SUCCESS', 'LOGIN_FAILURE', 'LOGIN_BLOCKED', 'DEVICE_ACCESS_REQUESTED', 'DEVICE_ACCESS_APPROVED', 'DEVICE_ACCESS_REJECTED', 'DEVICE_REVOKED'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(200)->get();
        $tenants = Tenant::query()->whereIn('id', $events->pluck('tenant_id')->filter()->unique())->get()->keyBy('id');
        $memberships = \App\Models\Membership::query()
            ->whereIn('id', $events->pluck('actor_membership_id')->filter()->unique())
            ->get()->keyBy('id');
        $devices = Device::query()->whereIn('id', $events->pluck('device_id')->filter()->unique())->get()->keyBy('id');

        $eventRows = $events->map(function (AuditLog $log) use ($tenants, $memberships, $devices) {
            $tenant = $tenants->get($log->tenant_id);
            $member = $memberships->get($log->actor_membership_id);
            $device = $devices->get($log->device_id);
            return [
                'ulid' => $log->ulid,
                'event' => $log->event,
                'reason' => $log->event === 'LOGIN_BLOCKED' && in_array($log->metadata['reason'] ?? null, ['DEVICE_NOT_APPROVED', 'DEVICE_REVOKED', 'ACCOUNT_DISABLED', 'TENANT_DISABLED', 'SUBSCRIPTION_REQUIRED', 'SUBSCRIPTION_INACTIVE', 'BRANCH_ACCESS_DENIED', 'NO_WAREHOUSE'], true) ? $log->metadata['reason'] : null,
                'tenant' => $tenant ? ['ulid' => $tenant->ulid, 'code' => $tenant->code, 'name' => $tenant->name] : null,
                'membership' => $member && $tenant && (int) $member->tenant_id === (int) $tenant->id
                    ? ['ulid' => $member->ulid, 'username' => $member->username] : null,
                'device' => $device && $tenant && (int) $device->tenant_id === (int) $tenant->id
                    ? ['ulid' => $device->ulid, 'name' => $device->name] : null,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'occurred_at' => $log->occurred_at?->toISOString(),
            ];
        })->values();

        return response()->json(['events' => $eventRows, 'sessions' => $sessions->map(fn (AuthSessionRecord $session) => [
            'ulid' => $session->ulid,
            'tenant' => $session->membership?->tenant ? [
                'ulid' => $session->membership->tenant->ulid,
                'code' => $session->membership->tenant->code,
                'name' => $session->membership->tenant->name,
            ] : null,
            'membership' => $session->membership ? [
                'ulid' => $session->membership->ulid,
                'username' => $session->membership->username,
                'roles' => $session->membership->roles->filter(fn ($role) => $role->is_active && (int) $role->tenant_id === (int) $session->membership->tenant_id)->map(fn ($role) => ['code' => $role->code, 'name' => $role->name])->values()->all(),
            ] : null,
            'device' => $session->device ? [
                'ulid' => $session->device->ulid,
                'name' => $session->device->name,
            ] : null,
            'ip_address' => $session->ip_address,
            'last_seen_at' => $session->last_seen_at?->toISOString(),
            'revoked_at' => $session->revoked_at?->toISOString(),
        ])->values()]);
    }

    public function index(string $tenantUlid): JsonResponse
    {
        $tenant = $this->tenant($tenantUlid);
        $rows = Device::query()->where('tenant_id', $tenant->id)
            ->orderByDesc('registered_at')->limit(250)->get();
        $approvalCounts = DB::table('device_access_requests')
            ->select('device_id', 'status', DB::raw('COUNT(*) as total'))
            ->where('tenant_id', $tenant->id)
            ->whereIn('device_id', $rows->pluck('id'))
            ->groupBy('device_id', 'status')
            ->get()->groupBy('device_id');
        $requests = DB::table('device_access_requests as access')
            ->join('memberships as member', function ($join) {
                $join->on('member.id', '=', 'access.membership_id')
                    ->on('member.tenant_id', '=', 'access.tenant_id');
            })
            ->join('devices as device', function ($join) {
                $join->on('device.id', '=', 'access.device_id')
                    ->on('device.tenant_id', '=', 'access.tenant_id');
            })
            ->where('access.tenant_id', $tenant->id)
            ->orderByDesc('access.created_at')->orderByDesc('access.id')
            ->limit(100)
            ->get([
                'access.ulid', 'access.status', 'access.created_at', 'access.reviewed_at',
                'access.request_ip', 'access.request_user_agent',
                'member.ulid as membership_ulid', 'member.username',
                'device.ulid as device_ulid', 'device.name as device_name',
            ]);

        return response()->json(['access_requests' => $requests, 'devices' => $rows->map(fn (Device $device) => [
            'ulid' => $device->ulid, 'name' => $device->name,
            'type' => $device->device_type, 'status' => $device->status->value,
            'registered_at' => $device->registered_at?->toISOString(),
            'last_seen_at' => $device->last_seen_at?->toISOString(),
            'employee_approvals' => [
                'pending' => (int) ($approvalCounts->get($device->id)?->firstWhere('status', 'pending')?->total ?? 0),
                'approved' => (int) ($approvalCounts->get($device->id)?->firstWhere('status', 'approved')?->total ?? 0),
                'rejected' => (int) ($approvalCounts->get($device->id)?->firstWhere('status', 'rejected')?->total ?? 0),
            ],
        ])->values()]);
    }

    public function activity(string $tenantUlid): JsonResponse
    {
        $tenant = $this->tenant($tenantUlid);
        $sessions = AuthSessionRecord::query()
            ->where('tenant_id', $tenant->id)
            ->with(['device:id,ulid,name', 'membership:id,ulid,username,tenant_id', 'membership.roles:id,ulid,tenant_id,name,code,is_active'])
            ->orderByDesc('id')->limit(100)->get();
        $events = AuditLog::query()->where('tenant_id', $tenant->id)
            ->whereIn('event', ['LOGIN_FAILURE', 'LOGIN_SUCCESS', 'DEVICE_ENROLLMENT_REQUESTED', 'DEVICE_ACCESS_REQUESTED', 'DEVICE_ACCESS_APPROVED', 'DEVICE_ACCESS_REJECTED', 'DEVICE_REVOKED'])
            ->orderByDesc('occurred_at')->limit(100)->get();
        $eventMemberships = \App\Models\Membership::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $events->pluck('actor_membership_id')->filter()->unique())
            ->get()->keyBy('id');
        $eventDevices = Device::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $events->pluck('device_id')->filter()->unique())
            ->get()->keyBy('id');

        return response()->json([
            'sessions' => $sessions->map(fn (AuthSessionRecord $session) => [
                'ulid' => $session->ulid,
                'membership' => $session->membership ? ['ulid' => $session->membership->ulid, 'username' => $session->membership->username, 'roles' => $session->membership->roles->filter(fn ($role) => $role->is_active && (int) $role->tenant_id === (int) $tenant->id)->map(fn ($role) => ['code' => $role->code, 'name' => $role->name])->values()->all()] : null,
                'device' => $session->device ? ['ulid' => $session->device->ulid, 'name' => $session->device->name] : null,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_seen_at' => $session->last_seen_at?->toISOString(),
                'revoked_at' => $session->revoked_at?->toISOString(),
            ])->values(),
            'events' => $events->map(fn (AuditLog $log) => [
                'ulid' => $log->ulid,
                'membership' => $eventMemberships->get($log->actor_membership_id)
                    ? ['ulid' => $eventMemberships->get($log->actor_membership_id)->ulid, 'username' => $eventMemberships->get($log->actor_membership_id)->username] : null,
                'device' => $eventDevices->get($log->device_id)
                    ? ['ulid' => $eventDevices->get($log->device_id)->ulid, 'name' => $eventDevices->get($log->device_id)->name] : null,
                'event' => $log->event,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'occurred_at' => $log->occurred_at?->toISOString(),
            ])->values(),
        ]);
    }

}
