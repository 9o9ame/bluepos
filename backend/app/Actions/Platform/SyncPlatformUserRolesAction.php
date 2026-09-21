<?php

namespace App\Actions\Platform;

use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use App\Platform\FinalPlatformAdminGuard;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;
use App\Platform\PlatformPermissionCatalogue;
use App\Platform\PlatformPrivilegeGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncPlatformUserRolesAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformPrivilegeGuard $privileges,
        private readonly FinalPlatformAdminGuard $integrity,
        private readonly PlatformContext $context,
    ) {}

    /**
     * @param  list<string>  $roleUlids
     */
    public function execute(Request $request, PlatformUser $user, array $roleUlids, ?string $reason = null): PlatformUser
    {
        $unique = array_values(array_unique($roleUlids));

        return DB::transaction(function () use ($request, $user, $unique, $reason): PlatformUser {
            $user = PlatformUser::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $roles = PlatformRole::query()->with('permissions')->whereIn('ulid', $unique)->lockForUpdate()->get();
            if ($roles->count() !== count($unique)) {
                throw ValidationException::withMessages([
                    'role_ulids' => 'One or more roles were not found.',
                ]);
            }
            foreach ($roles as $role) {
                if (! $role->is_active) {
                    throw ValidationException::withMessages([
                        'role_ulids' => 'Inactive roles cannot be assigned.',
                    ]);
                }
            }

            $this->privileges->assertCanAssignRoles($this->context->user(), $roles, $request, $reason);

            $hadSuperAdmin = $user->isSuperAdmin();
            $willHaveSuperAdmin = $roles->contains(fn (PlatformRole $role): bool => $role->code === PlatformPermissionCatalogue::SUPER_ADMIN);
            if ($hadSuperAdmin && ! $willHaveSuperAdmin) {
                $this->integrity->assertCanRemoveSuperAdminRole($user);
            }

            $previous = $user->roles()->pluck('platform_roles.code', 'platform_roles.id');
            $desiredIds = $roles->pluck('id')->all();
            $currentIds = $previous->keys()->all();

            $detach = array_diff($currentIds, $desiredIds);
            if ($detach !== []) {
                $user->roles()->detach($detach);
                $this->audit->record('PLATFORM_USER_ROLE_REMOVED', [
                    'resource_type' => 'platform_user',
                    'resource_ulid' => $user->ulid,
                    'role_codes' => $previous->only($detach)->values()->all(),
                ]);
            }

            foreach (array_diff($desiredIds, $currentIds) as $roleId) {
                $user->roles()->attach($roleId, ['ulid' => (string) Str::ulid()]);
            }
            $added = $roles->whereIn('id', array_diff($desiredIds, $currentIds));
            if ($added->isNotEmpty()) {
                $this->audit->record('PLATFORM_USER_ROLE_ASSIGNED', [
                    'resource_type' => 'platform_user',
                    'resource_ulid' => $user->ulid,
                    'role_codes' => $added->pluck('code')->values()->all(),
                ]);
            }

            if (! $hadSuperAdmin && $willHaveSuperAdmin) {
                $this->audit->record('PLATFORM_SUPER_ADMIN_GRANTED', [
                    'resource_type' => 'platform_user',
                    'resource_ulid' => $user->ulid,
                    'reason' => $reason,
                ]);
            }

            return $user->fresh(['roles']) ?? $user;
        });
    }
}
