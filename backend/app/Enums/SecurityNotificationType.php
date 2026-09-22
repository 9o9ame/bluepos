<?php

namespace App\Enums;

enum SecurityNotificationType: string
{
    case PlatformMfa = 'platform_mfa';
    case PlatformStepUp = 'platform_step_up';
    case NewDeviceVerification = 'new_device_verification';
    case PasswordReset = 'password_reset';
    case PlatformPasswordReset = 'platform_password_reset';

    public function subject(): string
    {
        return match ($this) {
            self::PasswordReset, self::PlatformPasswordReset => 'BluePOS Password Reset Code',
            default => 'BluePOS Security Verification Code',
        };
    }

    public function heading(): string
    {
        return match ($this) {
            self::PasswordReset, self::PlatformPasswordReset => 'Password Reset',
            default => 'Security Verification',
        };
    }

    public function reason(): string
    {
        return match ($this) {
            self::PlatformMfa => 'sign in to BluePOS Platform Administration',
            self::PlatformStepUp => 'confirm a sensitive platform action',
            self::NewDeviceVerification => 'sign in from a new or untrusted device',
            self::PasswordReset => 'reset your BluePOS password',
            self::PlatformPasswordReset => 'reset your BluePOS platform password',
        };
    }

    public static function forPlatformMfaPurpose(string $purpose): self
    {
        return $purpose === 'step_up' ? self::PlatformStepUp : self::PlatformMfa;
    }
}
