<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MigrateSafeCommand extends Command
{
    protected $signature = 'bluepos:migrate-safe {--force : Allow migrate on production}';

    protected $description = 'Run migrations only against bluepos_dev or bluepos_test after printing connection details.';

    public function handle(): int
    {
        $env = (string) config('app.env');
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $this->line('APP_ENV='.$env);
        $this->line('DB_CONNECTION='.$connection);
        $this->line('DB_DATABASE='.$database);

        $allowed = ['bluepos_dev', 'bluepos_test'];

        if ($connection !== 'pgsql' || ! in_array($database, $allowed, true)) {
            $this->error('Refusing to migrate. Expected PostgreSQL database bluepos_dev or bluepos_test.');

            return self::FAILURE;
        }

        if ($env === 'production' && ! $this->option('force')) {
            $this->error('Refusing to migrate production without --force.');

            return self::FAILURE;
        }

        $params = [];
        if ($this->option('force')) {
            $params['--force'] = true;
        }

        return $this->call('migrate', $params);
    }
}
