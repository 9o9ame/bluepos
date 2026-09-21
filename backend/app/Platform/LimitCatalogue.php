<?php

namespace App\Platform;

final class LimitCatalogue
{
    public const MAX_USERS = 'max_users';

    public const MAX_BRANCHES = 'max_branches';

    public const MAX_WAREHOUSES = 'max_warehouses';

    public const MAX_DEVICES = 'max_devices';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            self::MAX_USERS,
            self::MAX_BRANCHES,
            self::MAX_WAREHOUSES,
            self::MAX_DEVICES,
        ];
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
