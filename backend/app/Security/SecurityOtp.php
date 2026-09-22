<?php

namespace App\Security;

use Illuminate\Support\Facades\Hash;

final class SecurityOtp
{
    public static function generate(): string
    {
        $length = max(6, min(8, (int) config('security.otp.length', 6)));
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    public static function hash(string $otp): string
    {
        return Hash::make($otp);
    }

    public static function ttlMinutes(): int
    {
        return max(1, (int) config('security.otp.ttl_minutes', 10));
    }

    public static function passwordResetTtlMinutes(): int
    {
        return max(1, (int) config('security.password_reset.ttl_minutes', self::ttlMinutes()));
    }

    public static function maxVerifyAttempts(): int
    {
        return max(1, (int) config('security.otp.max_verify_attempts', 5));
    }

    public static function resendCooldownSeconds(): int
    {
        return max(0, (int) config('security.otp.resend_cooldown_seconds', 60));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function assertResendCooldown(?\Illuminate\Support\Carbon $lastSentAt, array $extra = []): void
    {
        $seconds = self::resendCooldownSeconds();
        if ($seconds <= 0 || $lastSentAt === null) {
            return;
        }

        $retryAt = $lastSentAt->copy()->addSeconds($seconds);
        if ($retryAt->isFuture()) {
            throw new \App\Exceptions\ApiException(
                'OTP_RESEND_COOLDOWN',
                'Please wait before requesting another code.',
                429,
                array_merge(['retry_after' => max(1, now()->diffInSeconds($retryAt))], $extra),
            );
        }
    }

    public static function rateLimitPerMinute(): int
    {
        return max(1, (int) config('security.otp.rate_limit_per_minute', 5));
    }

    public static function genericResetMessage(): string
    {
        return (string) config(
            'security.password_reset.generic_message',
            'If the account exists, password reset instructions have been sent.',
        );
    }
}
