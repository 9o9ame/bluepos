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
    public function test_device_row_lock_blocks_an_independent_connection_until_rollback(): void
    {
        // This fixture is committed so both connections can see it. It belongs to
        // bluepos_test only and is removed in finally, even if assertions fail.
        $primary = DB::connection('pgsql');
        $secondaryName = 'pgsql_device_row_probe';
        config()->set('database.connections.'.$secondaryName, config('database.connections.pgsql'));
        DB::purge($secondaryName);
        $secondary = DB::connection($secondaryName);
        $deviceId = null;

        try {
            $this->assertSame('bluepos_test', $primary->selectOne('select current_database() as name')->name);
            $this->assertSame('bluepos_test', $secondary->selectOne('select current_database() as name')->name);
            $this->assertSame(0, $primary->transactionLevel(), 'Fixture must not be hidden by DatabaseTransactions.');

            $device = \App\Models\Device::query()->create([
                'name' => 'Concurrency row probe',
                'device_type' => 'browser',
                'status' => \App\Enums\DeviceStatus::Pending,
                'credential_hash' => 'unused-test-probe',
                'registered_at' => now(),
            ]);
            $deviceId = $device->id;

            $primary->beginTransaction();
            $secondary->beginTransaction();
            $primary->selectOne('select id from devices where id = ? for update', [$deviceId]);

            // PostgreSQL NOWAIT must report a lock conflict rather than wait indefinitely.
            $secondary->statement("savepoint device_lock_probe");
            try {
                $secondary->selectOne('select id from devices where id = ? for update nowait', [$deviceId]);
                $this->fail('Independent connection unexpectedly acquired the locked device row.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertSame('55P03', $e->errorInfo[0] ?? null);
                $secondary->statement('rollback to savepoint device_lock_probe');
            }

            $primary->rollBack();
            $this->assertSame(
                (int) $deviceId,
                (int) $secondary->selectOne('select id from devices where id = ? for update nowait', [$deviceId])->id
            );
        } finally {
            if ($secondary->transactionLevel() > 0) {
                $secondary->rollBack();
            }
            if ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            if ($deviceId !== null) {
                $primary->table('devices')->where('id', $deviceId)->delete();
            }
            DB::disconnect($secondaryName);
            DB::purge($secondaryName);
            config()->offsetUnset('database.connections.'.$secondaryName);
        }
    }

}
