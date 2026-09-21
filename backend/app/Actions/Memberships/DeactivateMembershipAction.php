<?php

namespace App\Actions\Memberships;

use App\Authz\FinalOwnerGuard;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Security\AuditLogger;
use App\Security\SessionRevocationService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class DeactivateMembershipAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FinalOwnerGuard $finalOwnerGuard,
        private readonly SessionRevocationService $revocation,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Membership $membership): Membership
    {
        return DB::transaction(function () use ($membership): Membership {
            $tenantId = $this->tenantContext->tenantId();
            $membership = Membership::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->finalOwnerGuard->assertCanDeactivate($tenantId, $membership);

            $membership->status = MembershipStatus::Suspended;
            $membership->save();
            $this->revocation->revokeMembership($membership);

            $this->audit->record('USER_DEACTIVATED', [
                'resource_type' => 'membership',
                'resource_ulid' => $membership->ulid,
            ]);

            return $membership->fresh(['user', 'roles', 'branches']);
        });
    }
}
