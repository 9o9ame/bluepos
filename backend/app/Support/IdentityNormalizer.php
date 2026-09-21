<?php

namespace App\Support;

final class IdentityNormalizer
{
    public static function tenantCode(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    public static function username(string $value): string
    {
        return strtolower(trim($value));
    }

    public static function platformRoleCode(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '', str_replace([' ', '-'], '_', $value)));
    }

    public static function isValidTenantCode(string $value): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{2,32}$/', self::tenantCode($value));
    }

    public static function isValidUsername(string $value): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{1,62}$/', self::username($value));
    }

    public static function isValidPlatformRoleCode(string $value): bool
    {
        return (bool) preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', self::platformRoleCode($value));
    }
}
