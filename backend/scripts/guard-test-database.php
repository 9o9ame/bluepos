<?php

// Run only from the backend directory. Fail closed before any test is executed.
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $driver = config('database.default');
    $configured = config('database.connections.pgsql.database');
    if ($driver !== 'pgsql' || $configured !== 'bluepos_test') {
        fwrite(STDERR, "Test DB guard: expected pgsql/bluepos_test configuration.\n");
        exit(1);
    }

    $actual = \Illuminate\Support\Facades\DB::connection('pgsql')
        ->selectOne('select current_database() as database_name')->database_name ?? null;
    if ($actual !== 'bluepos_test') {
        fwrite(STDERR, "Test DB guard: connected database is not bluepos_test.\n");
        exit(1);
    }
    echo "TEST_DATABASE_OK\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Test DB guard: connection verification failed.\n");
    exit(1);
}
