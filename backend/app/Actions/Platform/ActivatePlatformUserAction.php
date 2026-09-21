<?php

namespace App\Actions\Platform;

use App\Enums\PlatformUserStatus;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use Illuminate\Support\Facades\DB;

class ActivatePlatformUserAction
{
    public function __construct(private readonly PlatformAuditLogger $audit) {}

    public function execute(PlatformUser $target): PlatformUser
    {
        return DB::transaction(function () use ($target): PlatformUser {
            $target = PlatformUser::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $target->status = PlatformUserStatus::Active;
            $target->save();

            $this->audit->record('PLATFORM_USER_ACTIVATED', [
                'resource_type' => 'platform_user',
                'resource_ulid' => $target->ulid,
            ]);

            return $target->fresh(['roles']) ?? $target;
        });
    }
}
