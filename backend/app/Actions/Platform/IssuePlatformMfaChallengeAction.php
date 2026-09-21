<?php

namespace App\Actions\Platform;

use App\Enums\MfaMethod;
use App\Exceptions\ApiException;
use App\Models\Platform\PlatformDevice;
use App\Models\Platform\PlatformMfaChallenge;
use App\Models\Platform\PlatformUser;
use App\Notifications\SecurityCodeNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

class IssuePlatformMfaChallengeAction
{
    public function execute(PlatformUser $user, ?PlatformDevice $device): never
    {
        $code = (string) random_int(100000, 999999);
        $challenge = PlatformMfaChallenge::query()->create([
            'platform_user_id' => $user->id,
            'platform_device_id' => $device?->id,
            'method' => MfaMethod::EmailOtp,
            'purpose' => 'login',
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ]);

        Notification::route('mail', $user->email)
            ->notify(new SecurityCodeNotification($code, 'platform login'));

        $extra = [
            'challenge_ulid' => $challenge->ulid,
            'method' => MfaMethod::EmailOtp->value,
            'recovery_hint' => $this->mask($user->email),
        ];

        if (config('mail.default') === 'log') {
            $extra['delivery'] = 'log';
            $extra['delivery_hint'] = 'Mail is logged locally. Open backend/storage/logs/laravel.log and search for "Your code".';
        }

        throw new ApiException('MFA_REQUIRED', 'Additional verification is required.', 403, $extra);
    }

    private function mask(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '***';
        }
        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1).'***@'.$domain;
    }
}
