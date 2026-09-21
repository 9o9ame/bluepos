<?php

namespace App\Console\Commands;

use App\Actions\Auth\ProvisionTenantAction;
use App\Actions\Platform\AssignTenantPlanAction;
use App\Support\IdentityNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateTenantCommand extends Command
{
    protected $signature = 'bluepos:tenant-create
        {--name= : Business name}
        {--code= : Tenant login code}
        {--admin-name= : Tenant admin full name}
        {--username= : Tenant admin username}
        {--email= : Admin recovery email}
        {--password= : Temporary password (omit to generate once)}
        {--timezone=Asia/Karachi}
        {--currency=PKR}
        {--plan=ENTERPRISE : Explicit plan code to assign}';

    protected $description = 'Provision a tenant and first Tenant Admin with an explicit plan (no public registration).';

    public function handle(ProvisionTenantAction $provision, AssignTenantPlanAction $assignPlan): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Business name'));
        $code = IdentityNormalizer::tenantCode((string) ($this->option('code') ?: $this->ask('Tenant code')));
        $adminName = (string) ($this->option('admin-name') ?: $this->ask('Admin name'));
        $username = IdentityNormalizer::username((string) ($this->option('username') ?: $this->ask('Admin username')));
        $email = (string) ($this->option('email') ?: $this->ask('Admin recovery email'));
        $generated = false;
        $password = (string) $this->option('password');
        if ($password === '') {
            $password = Str::password(16);
            $generated = true;
        }

        $session = $provision->execute([
            'name' => $adminName,
            'username' => $username,
            'recovery_email' => $email !== '' ? $email : null,
            'password' => $password,
            'tenant_name' => $name,
            'tenant_code' => $code,
            'timezone' => (string) $this->option('timezone'),
            'currency_code' => (string) $this->option('currency'),
            'must_change_password' => true,
        ]);

        $subscription = $assignPlan->execute($session->tenant, (string) $this->option('plan'));

        $this->info('Tenant provisioned.');
        $this->line('Tenant code: '.$session->tenant->code);
        $this->line('Admin username: '.$session->membership->username);
        $this->line('Tenant ULID: '.$session->tenant->ulid);
        $this->line('Plan: '.$subscription->plan?->code);

        if ($generated) {
            $this->line('Temporary password (shown once): '.$password);
        }

        return self::SUCCESS;
    }
}
