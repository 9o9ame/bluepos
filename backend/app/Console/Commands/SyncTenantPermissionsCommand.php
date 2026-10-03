<?php

namespace App\Console\Commands;

use App\Authz\TenantRoleProvisioner;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SyncTenantPermissionsCommand extends Command
{
    protected $signature = 'bluepos:sync-tenant-permissions';

    protected $description = 'Sync permission catalogue and attach missing keys to system roles for all tenants';

    public function handle(TenantRoleProvisioner $provisioner): int
    {
        $count = 0;
        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use ($provisioner, &$count): void {
            $provisioner->provision($tenant);
            $count++;
            $this->line('Synced permissions for tenant '.$tenant->ulid);
        });

        $this->info("Done. Updated {$count} tenant(s).");

        return self::SUCCESS;
    }
}
