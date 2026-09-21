<?php

namespace App\Actions\Platform;

use App\Exceptions\ApiException;
use App\Models\Membership;
use App\Models\Tenant;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;
use App\Security\SessionRevocationService;
use Illuminate\Support\Facades\DB;

class ForceLogoutTenantAdminAction
{
    public function __construct(
        private readonly SessionRevocationService $revocation,
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
    ) {}

    public function execute(Tenant $tenant, string $membershipUlid): Membership
    {
        return DB::transaction(function () use ($tenant, $membershipUlid): Membership {
            $membership = Membership::query()
                ->where('tenant_id', $tenant->id)
                ->where('ulid', $membershipUlid)
                ->lockForUpdate()
                ->first();
            if (! $membership) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }

            $this->revocation->revokeMembership($membership->fresh() ?? $membership, false);
            $this->audit->record('TENANT_SECURITY_REVOKED', [
                'resource_type' => 'membership',
                'resource_ulid' => $membership->ulid,
                'tenant_ulid' => $tenant->ulid,
                'reason' => 'force_logout',
            ], null, $this->context->hasUser() ? $this->context->user() : null);

            return $membership->fresh(['user', 'roles']) ?? $membership;
        });
    }
}
