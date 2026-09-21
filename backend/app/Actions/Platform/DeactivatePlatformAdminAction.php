<?php

namespace App\Actions\Platform;

use App\Enums\PlatformUserStatus;
use App\Exceptions\ApiException;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformCatalogSync;
use App\Platform\PlatformPermissionCatalogue;
use Illuminate\Support\Facades\DB;

class DeactivatePlatformAdminAction
{
    public function __construct(
        private readonly PlatformCatalogSync $catalog,
        private readonly PlatformAuditLogger $audit,
    ) {}

    public function execute(PlatformUser $target): PlatformUser
    {
        $this->catalog->ensure();

        return DB::transaction(function () use ($target): PlatformUser {
            $target = PlatformUser::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($target->isSuperAdmin()) {
                $remaining = PlatformUser::query()
                    ->where('status', PlatformUserStatus::Active)
                    ->whereKeyNot($target->id)
                    ->whereHas('roles', function ($query): void {
                        $query->where('platform_roles.code', PlatformPermissionCatalogue::SUPER_ADMIN)
                            ->where('platform_roles.is_active', true);
                    })
                    ->lockForUpdate()
                    ->get(['id'])
                    ->count();

                if ($remaining < 1) {
                    throw new ApiException(
                        'FINAL_PLATFORM_ADMIN_REQUIRED',
                        'At least one active Super Admin is required.',
                        403,
                    );
                }
            }

            $target->status = PlatformUserStatus::Suspended;
            $target->save();
            $target->bumpSecurityVersion();

            $target->sessions()->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $this->audit->record('PLATFORM_ADMIN_DEACTIVATED', [
                'resource_type' => 'platform_user',
                'resource_ulid' => $target->ulid,
            ]);

            return $target->fresh() ?? $target;
        });
    }
}
