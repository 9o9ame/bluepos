<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgreSqlConcurrencyConnectionTest extends TestCase
{
    public function test_independent_postgresql_connections_contend_for_a_transaction_lock(): void
    {
        // The base TestCase guard verifies APP_ENV=testing and bluepos_test.
        // Keep this test read-only: no shared tenant or device fixtures are needed.
        $primary = DB::connection('pgsql');
        $secondaryName = 'pgsql_concurrency_probe';

        config()->set('database.connections.'.$secondaryName, config('database.connections.pgsql'));
        DB::purge($secondaryName);
        $secondary = DB::connection($secondaryName);

        try {
            $this->assertSame('bluepos_test', $primary->selectOne('select current_database() as name')->name);
            $this->assertSame('bluepos_test', $secondary->selectOne('select current_database() as name')->name);
            $this->assertNotSame(
                (int) $primary->selectOne('select pg_backend_pid() as pid')->pid,
                (int) $secondary->selectOne('select pg_backend_pid() as pid')->pid
            );

            $primary->beginTransaction();
            $secondary->beginTransaction();

            // Unique lock namespace for the test; advisory locks require no schema changes.
            $lockKey = random_int(1, PHP_INT_MAX);
            $this->assertTrue((bool) $primary->selectOne(
                'select pg_try_advisory_xact_lock(?) as acquired',
                [$lockKey]
            )->acquired);
            $this->assertFalse((bool) $secondary->selectOne(
                'select pg_try_advisory_xact_lock(?) as acquired',
                [$lockKey]
            )->acquired);

            $primary->rollBack();

            $this->assertTrue((bool) $secondary->selectOne(
                'select pg_try_advisory_xact_lock(?) as acquired',
                [$lockKey]
            )->acquired);
        } finally {
            if ($secondary->transactionLevel() > 0) {
                $secondary->rollBack();
            }
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::disconnect($secondaryName);
            DB::purge($secondaryName);
            config()->offsetUnset('database.connections.'.$secondaryName);
        }
    }
}
