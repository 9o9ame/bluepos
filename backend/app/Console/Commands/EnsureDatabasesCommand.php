<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class EnsureDatabasesCommand extends Command
{
    protected $signature = 'bluepos:ensure-databases {--only= : Create only an allow-listed database name}';

    protected $description = 'Create allow-listed PostgreSQL databases if they do not exist. Never drops databases.';

    public function handle(): int
    {
        $connection = (string) config('database.default');
        if ($connection !== 'pgsql') {
            $this->error('DB_CONNECTION must be pgsql.');

            return self::FAILURE;
        }

        $allowed = ['bluepos_dev', 'bluepos_test'];
        $only = (string) $this->option('only');
        $names = $only !== '' ? [$only] : $allowed;

        foreach ($names as $name) {
            if (! in_array($name, $allowed, true)) {
                $this->error('Refusing to create unlisted database '.$name.'.');

                return self::FAILURE;
            }
        }

        config(['database.connections.pgsql.database' => 'postgres']);
        DB::purge('pgsql');

        try {
            DB::connection('pgsql')->getPdo();
        } catch (Throwable $e) {
            $this->error('Could not connect to PostgreSQL maintenance database "postgres". Set DB_USERNAME and DB_PASSWORD in backend/.env.');
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($names as $name) {
            if (! preg_match('/^[a-z_]+$/', $name)) {
                $this->error('Refusing invalid database name.');

                return self::FAILURE;
            }

            $exists = DB::connection('pgsql')->selectOne(
                'select 1 as present from pg_database where datname = ?',
                [$name],
            );

            if ($exists) {
                $this->line("Database {$name} already exists.");

                continue;
            }

            DB::connection('pgsql')->statement("CREATE DATABASE {$name}");
            $this->info("Created database {$name}.");
        }

        return self::SUCCESS;
    }
}
