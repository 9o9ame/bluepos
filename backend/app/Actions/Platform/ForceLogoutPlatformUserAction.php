<?php

namespace App\Actions\Platform;

use App\Models\Platform\PlatformSession;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use Illuminate\Support\Facades\DB;

class ForceLogoutPlatformUserAction
{
    public function __construct(private readonly PlatformAuditLogger $audit) {}

    public function execute(PlatformUser $target): PlatformUser
    {
        return DB::transaction(function () use ($target): PlatformUser {
            $target = PlatformUser::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $target->bumpSecurityVersion();
            PlatformSession::query()
                ->where('platform_user_id', $target->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $this->audit->record('PLATFORM_USER_UPDATED', [
                'resource_type' => 'platform_user',
                'resource_ulid' => $target->ulid,
                'force_logout' => true,
            ]);

            return $target->fresh(['roles']) ?? $target;
        });
    }
}
