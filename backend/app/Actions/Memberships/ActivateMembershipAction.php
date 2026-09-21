<?php

namespace App\Actions\Memberships;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ActivateMembershipAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
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

            $membership->status = MembershipStatus::Active;
            $membership->save();

            $this->audit->record('USER_ACTIVATED', [
                'resource_type' => 'membership',
                'resource_ulid' => $membership->ulid,
            ]);

            return $membership->fresh(['user', 'roles', 'branches']);
        });
    }
}
