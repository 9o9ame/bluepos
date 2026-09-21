<?php

namespace App\Actions\Memberships;

use App\Models\Membership;
use App\Security\AuditLogger;
use App\Security\SessionRevocationService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ForceLogoutMembershipAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SessionRevocationService $revocation,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Membership $membership): Membership
    {
        return DB::transaction(function () use ($membership): Membership {
            $membership = Membership::query()
                ->where('tenant_id', $this->tenantContext->tenantId())
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->revocation->revokeMembership($membership, false);

            $this->audit->record('SESSION_REVOKED', [
                'resource_type' => 'membership',
                'resource_ulid' => $membership->ulid,
                'source' => 'force_logout',
            ]);

            return $membership->fresh(['user', 'roles', 'branches']);
        });
    }
}
