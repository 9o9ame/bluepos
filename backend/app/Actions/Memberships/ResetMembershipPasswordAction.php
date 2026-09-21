<?php

namespace App\Actions\Memberships;

use App\Models\Membership;
use App\Security\AuditLogger;
use App\Security\SessionRevocationService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class ResetMembershipPasswordAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SessionRevocationService $revocation,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Membership $membership, string $password): Membership
    {
        return DB::transaction(function () use ($membership, $password): Membership {
            $membership = Membership::query()
                ->with('user')
                ->where('tenant_id', $this->tenantContext->tenantId())
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            $user = $membership->user;
            $user->password = $password;
            $user->must_change_password = true;
            $user->password_changed_at = now();
            $user->save();

            $this->revocation->revokeMembership($membership);

            $this->audit->record('PASSWORD_RESET_COMPLETED', [
                'resource_type' => 'membership',
                'resource_ulid' => $membership->ulid,
                'source' => 'admin',
            ]);

            return $membership->fresh(['user', 'roles', 'branches']);
        });
    }
}
