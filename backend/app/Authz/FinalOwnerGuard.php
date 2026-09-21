<?php

namespace App\Authz;

use App\Enums\MembershipStatus;
use App\Exceptions\ApiException;
use App\Models\Membership;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class FinalOwnerGuard
{
    public function assertCanRemoveOwner(int $tenantId, Membership $target): void
    {
        DB::transaction(function () use ($tenantId, $target): void {
            $owners = $this->lockActiveOwners($tenantId);

            $remaining = $owners->contains(fn (Membership $owner): bool => $owner->id === $target->id)
                ? $owners->count() - 1
                : $owners->count();

            if ($remaining < 1) {
                throw new ApiException(
                    'FINAL_OWNER_REQUIRED',
                    'The tenant must retain at least one active owner.',
                    403,
                );
            }
        });
    }

    public function assertCanDeactivate(int $tenantId, Membership $target): void
    {
        if (! $this->isActiveOwner($target, $tenantId)) {
            return;
        }

        $this->assertCanRemoveOwner($tenantId, $target);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Membership>
     */
    public function lockActiveOwners(int $tenantId)
    {
        $memberships = Membership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', MembershipStatus::Active)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $ownerRole = Role::query()
            ->where('tenant_id', $tenantId)
            ->where('code', PermissionCatalogue::OWNER)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($ownerRole === null) {
            return collect();
        }

        $ownerMembershipIds = DB::table('membership_roles')
            ->where('role_id', $ownerRole->id)
            ->orderBy('membership_id')
            ->lockForUpdate()
            ->pluck('membership_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $memberships
            ->filter(fn (Membership $membership): bool => in_array((int) $membership->id, $ownerMembershipIds, true))
            ->values();
    }

    public function isActiveOwner(Membership $membership, ?int $tenantId = null): bool
    {
        $tenantId ??= (int) $membership->tenant_id;

        return $membership->hasOwnerAuthority($tenantId);
    }
}
