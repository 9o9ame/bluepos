<?php

namespace App\Actions\Memberships;

use App\Authz\FinalOwnerGuard;
use App\Authz\PermissionCatalogue;
use App\Authz\PermissionService;
use App\Models\Membership;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncMembershipRolesAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionService $permissions,
        private readonly FinalOwnerGuard $finalOwnerGuard,
    ) {}

    /**
     * @param  list<string>  $roleUlids
     */
    public function execute(Membership $membership, array $roleUlids): Membership
    {
        return DB::transaction(function () use ($membership, $roleUlids): Membership {
            $tenantId = $this->tenantContext->tenantId();
            $membership = Membership::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            DB::table('membership_roles')
                ->where('membership_id', $membership->id)
                ->lockForUpdate()
                ->get();

            $roles = Role::query()
                ->forTenant($tenantId)
                ->where('is_active', true)
                ->whereIn('ulid', $roleUlids)
                ->get();

            if ($roles->count() !== count(array_unique($roleUlids))) {
                throw ValidationException::withMessages([
                    'roles' => 'One or more roles were not found in this tenant.',
                ]);
            }

            foreach ($roles as $role) {
                if (! $this->permissions->canAssignPrivilegedRole($role)) {
                    throw ValidationException::withMessages([
                        'roles' => 'You are not allowed to assign the '.$role->name.' role.',
                    ]);
                }
            }

            $willBeOwner = $roles->contains(fn (Role $role): bool => $role->code === PermissionCatalogue::OWNER);

            if ($this->finalOwnerGuard->isActiveOwner($membership, $tenantId) && ! $willBeOwner) {
                $this->finalOwnerGuard->assertCanRemoveOwner($tenantId, $membership);
            }

            $current = $membership->roles()->pluck('roles.id')->all();
            $desired = $roles->pluck('id')->all();

            $detach = array_diff($current, $desired);
            if ($detach !== []) {
                $membership->roles()->detach($detach);
            }

            foreach (array_diff($desired, $current) as $roleId) {
                $membership->roles()->attach($roleId, [
                    'ulid' => (string) Str::ulid(),
                ]);
            }

            $membership->is_owner = $willBeOwner;
            $membership->save();

            return $membership->fresh(['roles.permissions', 'user', 'branches']);
        });
    }
}
