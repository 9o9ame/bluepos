<?php

namespace App\Actions\Platform;

use App\Enums\CashierShiftStatus;
use App\Enums\TenantStatus;
use App\Models\AuthSessionRecord;
use App\Models\CashierShift;
use App\Models\OfflineAuthorizationLease;
use App\Models\Tenant;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;
use Illuminate\Support\Facades\DB;

class CancelTenantAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
    ) {}

    public function execute(Tenant $tenant, ?string $reason = null): Tenant
    {
        return DB::transaction(function () use ($tenant, $reason): Tenant {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $tenant->status = TenantStatus::Cancelled;
            $tenant->bumpSecurityVersion();

            AuthSessionRecord::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            OfflineAuthorizationLease::query()
                ->where('tenant_id', $tenant->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            CashierShift::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', CashierShiftStatus::Open)
                ->update([
                    'status' => CashierShiftStatus::Voided,
                    'closed_at' => now(),
                ]);

            $this->audit->record('TENANT_CANCELLED', [
                'resource_type' => 'tenant',
                'resource_ulid' => $tenant->ulid,
                'reason' => $reason,
            ], null, $this->context->hasUser() ? $this->context->user() : null);

            return $tenant->fresh(['subscription.plan']) ?? $tenant;
        });
    }
}
