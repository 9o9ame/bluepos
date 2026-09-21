<?php

namespace App\Platform;

use App\Exceptions\ApiException;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PlatformPrivilegeGuard
{
    public function __construct(private readonly RecentPlatformMfa $recentMfa) {}

    /**
     * @param  list<string>  $keys
     */
    public function assertCanGrantPermissions(PlatformUser $actor, array $keys): void
    {
        $unique = array_values(array_unique($keys));
        foreach ($unique as $key) {
            if (! PlatformPermissionCatalogue::isValid($key)) {
                throw ValidationException::withMessages([
                    'permissions' => 'Invalid or unknown permission: '.$key,
                ]);
            }

            if (! $actor->isSuperAdmin() && ! $actor->canPlatform($key)) {
                throw new ApiException('FORBIDDEN', 'You cannot grant a permission you are not allowed to manage.', 403);
            }
        }
    }

    /**
     * @param  Collection<int, PlatformRole>  $roles
     */
    public function assertCanAssignRoles(PlatformUser $actor, Collection $roles, Request $request, ?string $reason = null): void
    {
        foreach ($roles as $role) {
            if ($role->code === PlatformPermissionCatalogue::SUPER_ADMIN) {
                $this->assertCanElevateSuperAdmin($actor, $request, $reason);

                continue;
            }

            $role->loadMissing('permissions');
            $this->assertCanGrantPermissions($actor, $role->permissions->pluck('key')->all());
        }
    }

    public function assertCanElevateSuperAdmin(PlatformUser $actor, Request $request, ?string $reason): void
    {
        if (
            ! $actor->isSuperAdmin()
            || ! $actor->canPlatform('platform.users.assign_roles')
            || ! $actor->canPlatform('platform.roles.manage_permissions')
        ) {
            throw new ApiException('FORBIDDEN', 'You are not allowed to assign the Super Admin role.', 403);
        }

        $this->recentMfa->assert($request);

        if (! is_string($reason) || strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages([
                'reason' => 'A reason is required to assign Super Admin.',
            ]);
        }
    }

    public function assertSystemRoleProtected(PlatformRole $role, string $action = 'change'): void
    {
        if ($role->is_system || $role->code === PlatformPermissionCatalogue::SUPER_ADMIN) {
            throw new ApiException('SYSTEM_ROLE_PROTECTED', 'The Super Admin system role cannot be '.$action.'.', 403);
        }
    }
}
