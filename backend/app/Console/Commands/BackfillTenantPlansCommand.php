<?php

namespace App\Console\Commands;

use App\Actions\Platform\AssignTenantPlanAction;
use App\Models\Tenant;
use Illuminate\Console\Command;

class BackfillTenantPlansCommand extends Command
{
    protected $signature = 'bluepos:backfill-tenant-plans
        {--plan=ENTERPRISE : Plan code to assign to tenants that have no subscription}';

    protected $description = 'Assign an explicit plan to tenants that have no subscription. Existing subscriptions are not changed.';

    public function handle(AssignTenantPlanAction $assign): int
    {
        $planCode = strtoupper((string) $this->option('plan'));
        $tenants = Tenant::query()->whereDoesntHave('subscription')->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants needed a plan assignment.');

            return self::SUCCESS;
        }

        $assigned = 0;
        foreach ($tenants as $tenant) {
            $subscription = $assign->execute($tenant, $planCode);
            $this->line($tenant->code.' → '.$subscription->plan?->code);
            $assigned++;
        }

        $this->info("Assigned {$planCode} to {$assigned} tenant(s).");

        return self::SUCCESS;
    }
}
