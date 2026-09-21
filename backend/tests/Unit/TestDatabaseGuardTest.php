<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    public function test_allows_exact_bluepos_test_pgsql_testing_env(): void
    {
        $this->expectNotToPerformAssertions();

        TestDatabaseGuard::assert([
            'app_env' => 'testing',
            'connection' => 'pgsql',
            'driver' => 'pgsql',
            'database' => 'bluepos_test',
            'config_cached' => false,
        ]);
    }

    public function test_blocks_bluepos_dev(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bluepos_test');

        TestDatabaseGuard::assert([
            'app_env' => 'testing',
            'connection' => 'pgsql',
            'driver' => 'pgsql',
            'database' => 'bluepos_dev',
            'config_cached' => false,
        ]);
    }

    public function test_blocks_bluepos_and_postgres_and_suffix_matches(): void
    {
        foreach (['bluepos', 'postgres', 'other_test'] as $database) {
            try {
                TestDatabaseGuard::assert([
                    'app_env' => 'testing',
                    'connection' => 'pgsql',
                    'driver' => 'pgsql',
                    'database' => $database,
                    'config_cached' => false,
                ]);
                $this->fail('Expected rejection of database '.$database);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('bluepos_test', $e->getMessage());
            }
        }
    }

    public function test_blocks_empty_database_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty');

        TestDatabaseGuard::assert([
            'app_env' => 'testing',
            'connection' => 'pgsql',
            'driver' => 'pgsql',
            'database' => '',
            'config_cached' => false,
        ]);
    }

    public function test_blocks_non_testing_environment(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=testing');

        TestDatabaseGuard::assert([
            'app_env' => 'local',
            'connection' => 'pgsql',
            'driver' => 'pgsql',
            'database' => 'bluepos_test',
            'config_cached' => false,
        ]);
    }

    public function test_blocks_cached_configuration(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cached Laravel configuration');

        TestDatabaseGuard::assert([
            'app_env' => 'testing',
            'connection' => 'pgsql',
            'driver' => 'pgsql',
            'database' => 'bluepos_test',
            'config_cached' => true,
        ]);
    }
}
