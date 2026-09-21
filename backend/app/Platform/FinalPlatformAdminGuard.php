<?php

namespace App\Platform;

use App\Enums\PlatformUserStatus;
use App\Exceptions\ApiException;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FinalPlatformAdminGuard
{
    /**
     * @return Collection<int, PlatformUser>
     */
    public function lockActiveSuperAdmins(): Collection
    {
        $role = PlatformRole::query()
            ->where('code', PlatformPermissionCatalogue::SUPER_ADMIN)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($role === null) {
            return collect();
        }

        $userIds = DB::table('platform_user_roles')
            ->where('platform_role_id', $role->id)
            ->orderBy('platform_user_id')
            ->lockForUpdate()
            ->pluck('platform_user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return PlatformUser::query()
            ->where('status', PlatformUserStatus::Active)
            ->whereIn('id', $userIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function assertCanDeactivate(PlatformUser $target): void
    {
        if (! $this->isActiveSuperAdmin($target)) {
            return;
        }

        $this->assertNotFinal($target);
    }

    public function assertCanRemoveSuperAdminRole(PlatformUser $target): void
    {
        if (! $this->isActiveSuperAdmin($target)) {
            return;
        }

        $this->assertNotFinal($target);
    }

    public function isActiveSuperAdmin(PlatformUser $user): bool
    {
        return $user->status === PlatformUserStatus::Active && $user->isSuperAdmin();
    }

    private function assertNotFinal(PlatformUser $target): void
    {
        $remaining = $this->lockActiveSuperAdmins()
            ->filter(fn (PlatformUser $user): bool => (int) $user->id !== (int) $target->id)
            ->count();

        if ($remaining < 1) {
            throw new ApiException(
                'FINAL_PLATFORM_ADMIN_REQUIRED',
                'At least one active Super Admin is required.',
                403,
            );
        }
    }
}
