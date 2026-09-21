<?php

namespace Tests\Support;

use RuntimeException;

final class TestDatabaseGuard
{
    public const ALLOWED_DATABASE = 'bluepos_test';

    public static function assertApplication(): void
    {
        self::assert([
            'app_env' => (string) app()->environment(),
            'connection' => (string) config('database.default'),
            'driver' => (string) config('database.connections.pgsql.driver'),
            'database' => config('database.connections.pgsql.database'),
            'config_cached' => app()->configurationIsCached(),
        ]);
    }

    /**
     * @param  array{app_env: string, connection: string, driver: string, database: ?string, config_cached: bool}  $resolved
     */
    public static function assert(array $resolved): void
    {
        if ($resolved['config_cached'] === true) {
            throw new RuntimeException(
                'Cached Laravel configuration is forbidden during tests because it can pin bluepos_dev. Run `php artisan config:clear`.'
            );
        }

        if ($resolved['app_env'] !== 'testing') {
            throw new RuntimeException(
                'Tests must run with APP_ENV=testing. Got '.$resolved['app_env'].'.'
            );
        }

        if ($resolved['connection'] !== 'pgsql' || $resolved['driver'] !== 'pgsql') {
            throw new RuntimeException(
                'Tests must use PostgreSQL. Got connection='.$resolved['connection'].' driver='.$resolved['driver'].'.'
            );
        }

        $database = $resolved['database'];

        if (! is_string($database) || $database === '') {
            throw new RuntimeException('Test database name is empty or null.');
        }

        if ($database !== self::ALLOWED_DATABASE) {
            throw new RuntimeException(
                'Tests must use PostgreSQL database '.self::ALLOWED_DATABASE.' only. Got '.$database.'.'
            );
        }
    }
}
