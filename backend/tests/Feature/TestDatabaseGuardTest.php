<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestDatabaseGuardTest extends TestCase
{
    public function test_phpunit_is_configured_for_bluepos_test_postgres(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('pgsql', config('database.connections.pgsql.driver'));
        $this->assertSame('bluepos_test', config('database.connections.pgsql.database'));
        $this->assertNotSame('bluepos_dev', config('database.connections.pgsql.database'));
        $this->assertNotSame('sqlite', config('database.default'));
    }

    public function test_live_connection_is_bluepos_test(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('bluepos_test', DB::connection()->getDatabaseName());

        $connected = DB::selectOne('select current_database() as name');
        $this->assertSame('bluepos_test', $connected->name);
    }
}
