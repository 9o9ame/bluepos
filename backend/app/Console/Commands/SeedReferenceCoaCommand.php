<?php

namespace App\Console\Commands;

use App\Accounting\ReferenceCoaSeeder;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SeedReferenceCoaCommand extends Command
{
    protected $signature = 'bluepos:seed-reference-coa
        {tenantUlid : Public ULID of the target tenant}';

    protected $description = 'Idempotently seed reference Main Heads, Heads, and Account Types (no leaf Accounts).';

    public function handle(ReferenceCoaSeeder $seeder): int
    {
        $ulid = strtoupper(trim((string) $this->argument('tenantUlid')));
        $tenant = Tenant::query()->where('ulid', $ulid)->first();
        if (! $tenant) {
            $this->error('Tenant not found for ULID '.$ulid);

            return self::FAILURE;
        }

        $result = $seeder->seed($tenant);

        $this->info('Reference COA seeded for '.$tenant->code.' ('.$tenant->name.').');
        $this->line('Main Heads upserted: '.$result['main_heads']);
        $this->line('Heads upserted: '.$result['sub_heads']);
        $this->line('Account Types upserted: '.$result['account_types']);
        $this->line('End Accounts created: '.$result['accounts']);

        return self::SUCCESS;
    }
}
