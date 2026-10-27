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

    public function overview(): JsonResponse
    {
        $sessions = AuthSessionRecord::query()
            ->with(['device:id,ulid,name', 'membership:id,ulid,username,tenant_id', 'membership.tenant:id,ulid,name,code'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['sessions' => $sessions->map(fn (AuthSessionRecord $session) => [
            'ulid' => $session->ulid,
            'tenant' => $session->membership?->tenant ? [
                'ulid' => $session->membership->tenant->ulid,
                'code' => $session->membership->tenant->code,
                'name' => $session->membership->tenant->name,
            ] : null,
            'membership' => $session->membership ? [
                'ulid' => $session->membership->ulid,
                'username' => $session->membership->username,
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
        return response()->json(['devices' => $rows->map(fn (Device $device) => [
            'ulid' => $device->ulid, 'name' => $device->name,
            'type' => $device->device_type, 'status' => $device->status->value,
            'registered_at' => $device->registered_at?->toISOString(),
            'last_seen_at' => $device->last_seen_at?->toISOString(),
        ])->values()]);
    }

    public function activity(string $tenantUlid): JsonResponse
    {
        $tenant = $this->tenant($tenantUlid);
        $sessions = AuthSessionRecord::query()
            ->where('tenant_id', $tenant->id)
            ->with(['device:id,ulid,name', 'membership:id,ulid,username'])
            ->orderByDesc('id')->limit(100)->get();
        $events = AuditLog::query()->where('tenant_id', $tenant->id)
            ->whereIn('event', ['LOGIN_FAILURE', 'LOGIN_SUCCESS', 'DEVICE_ENROLLMENT_REQUESTED'])
            ->orderByDesc('occurred_at')->limit(100)->get();
        return response()->json([
            'sessions' => $sessions->map(fn (AuthSessionRecord $session) => [
                'ulid' => $session->ulid,
                'membership' => $session->membership ? ['ulid' => $session->membership->ulid, 'username' => $session->membership->username] : null,
                'device' => $session->device ? ['ulid' => $session->device->ulid, 'name' => $session->device->name] : null,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_seen_at' => $session->last_seen_at?->toISOString(),
                'revoked_at' => $session->revoked_at?->toISOString(),
            ])->values(),
            'events' => $events->map(fn (AuditLog $log) => [
                'ulid' => $log->ulid,
                'event' => $log->event,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'occurred_at' => $log->occurred_at?->toISOString(),
            ])->values(),
        ]);
    }

}
