<?php

use App\Actions\Platform\AssignTenantPlanAction;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $assign = app(AssignTenantPlanAction::class);

        Tenant::query()
            ->whereDoesntHave('subscription')
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($assign): void {
                $assign->execute($tenant, 'ENTERPRISE');
            });
    }

    public function down(): void
    {
        // Data backfill is not reversed. Removing assigned subscriptions would unlicense live tenants.
    }
};
