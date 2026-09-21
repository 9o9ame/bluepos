<?php

namespace App\Http\Controllers\Platform;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\PlatformTenantResource;
use App\Models\Plan;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

class PlatformDashboardController extends Controller
{
    public function show(): JsonResponse
    {
        $recentTenants = Tenant::query()
            ->with(['subscription.plan', 'ownerMembership'])
            ->withCount(['memberships', 'branches'])
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $alerts = PlatformAuditLog::query()
            ->with('actor')
            ->whereIn('event', [
                'PLATFORM_LOGIN_FAILURE',
                'PLATFORM_MFA_FAILURE',
                'TENANT_SUSPENDED',
                'TENANT_SECURITY_REVOKED',
                'FINAL_PLATFORM_ADMIN_REQUIRED',
            ])
            ->orderByDesc('occurred_at')
            ->limit(12)
            ->get(['ulid', 'event', 'resource_type', 'resource_ulid', 'occurred_at', 'actor_platform_user_id']);

        return response()->json([
            'tenants' => [
                'total' => Tenant::query()->count(),
                'trial' => Tenant::query()->where('status', TenantStatus::Trial)->count(),
                'active' => Tenant::query()->where('status', TenantStatus::Active)->count(),
                'suspended' => Tenant::query()->where('status', TenantStatus::Suspended)->count(),
            ],
            'plans' => Plan::query()->where('status', 'active')->orderBy('name')->get()->map(fn (Plan $plan) => [
                'ulid' => $plan->ulid,
                'code' => $plan->code,
                'name' => $plan->name,
                'status' => $plan->status->value,
            ])->values(),
            'recent_tenants' => PlatformTenantResource::collection($recentTenants),
            'security_alerts' => $alerts->map(fn (PlatformAuditLog $row) => [
                'ulid' => $row->ulid,
                'event' => $row->event,
                'resource_ulid' => $row->resource_ulid,
                'occurred_at' => $row->occurred_at?->toISOString(),
                'actor_email' => $row->actor?->email,
            ])->values(),
        ]);
    }
}
