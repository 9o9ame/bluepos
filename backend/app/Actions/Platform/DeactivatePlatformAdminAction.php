<?php

namespace App\Actions\Platform;

use App\Enums\DeviceStatus;
use App\Enums\PlatformUserStatus;
use App\Models\Platform\PlatformDevice;
use App\Models\Platform\PlatformUser;
use App\Platform\FinalPlatformAdminGuard;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformCatalogSync;
use Illuminate\Support\Facades\DB;

class DeactivatePlatformAdminAction
{
    public function __construct(
        private readonly PlatformCatalogSync $catalog,
        private readonly PlatformAuditLogger $audit,
        private readonly FinalPlatformAdminGuard $integrity,
    ) {}

    public function execute(PlatformUser $target): PlatformUser
    {
        $this->catalog->ensure();

        return DB::transaction(function () use ($target): PlatformUser {
            $target = PlatformUser::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->integrity->assertCanDeactivate($target);

            $target->status = PlatformUserStatus::Inactive;
            $target->save();
            $target->bumpSecurityVersion();
            $target->sessions()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            PlatformDevice::query()
                ->where('platform_user_id', $target->id)
                ->whereNull('revoked_at')
                ->update([
                    'status' => DeviceStatus::Revoked,
                    'revoked_at' => now(),
                    'trusted_until' => null,
                ]);

            $this->audit->record('PLATFORM_USER_DEACTIVATED', [
                'resource_type' => 'platform_user',
                'resource_ulid' => $target->ulid,
            ]);

            return $target->fresh(['roles']) ?? $target;
        });
    }
}
