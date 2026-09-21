<?php

namespace App\Actions\Platform;

use App\Models\Platform\PlatformSession;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResetPlatformUserPasswordAction
{
    public function __construct(private readonly PlatformAuditLogger $audit) {}

    /**
     * @return array{user: PlatformUser, temporary_password: string}
     */
    public function execute(PlatformUser $target, ?string $password = null): array
    {
        $password = ($password === null || $password === '') ? Str::password(16) : $password;

        return DB::transaction(function () use ($target, $password): array {
            $target = PlatformUser::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $target->password = $password;
            $target->must_change_password = true;
            $target->password_changed_at = now();
            $target->save();
            $target->bumpSecurityVersion();

            PlatformSession::query()
                ->where('platform_user_id', $target->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $this->audit->record('PLATFORM_USER_PASSWORD_RESET', [
                'resource_type' => 'platform_user',
                'resource_ulid' => $target->ulid,
            ]);

            return [
                'user' => $target->fresh(['roles']) ?? $target,
                'temporary_password' => $password,
            ];
        });
    }
}
