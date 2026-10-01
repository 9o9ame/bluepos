<?php

namespace App\Console\Commands;

use App\Accounting\ReferenceCoaSeeder;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedReferenceCoaCommand extends Command
{
    protected $signature = 'bluepos:seed-reference-coa
        {tenant? : Tenant ULID or tenant code (optional if --email resolves one tenant, or with --all)}
        {--all : Seed every tenant (idempotent upsert)}
        {--login= : Membership username used at login (requires tenant code/ULID)}
        {--email= : User email (seeds that user\'s tenant; pass tenant if they have several)}';

    protected $description = 'Idempotently seed reference Main Heads, Heads, and Account Types (no leaf Accounts) for one tenant or all tenants.';

    public function handle(ReferenceCoaSeeder $seeder): int
    {
        if ($this->option('all')) {
            return $this->seedAll($seeder);
        }

        $tenant = $this->resolveTenant();
        if (! $tenant) {
            return self::FAILURE;
        }

        $result = $seeder->seed($tenant);
        $this->reportTenant($tenant, $result);

        return self::SUCCESS;
    }

    private function seedAll(ReferenceCoaSeeder $seeder): int
    {
        $tenants = Tenant::query()->orderBy('id')->get();
        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        $this->info('Seeding reference COA for '.$tenants->count().' tenant(s)…');
        foreach ($tenants as $tenant) {
            $result = $seeder->seed($tenant);
            $this->reportTenant($tenant, $result);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{main_heads: int, sub_heads: int, account_types: int, accounts: int}  $result
     */
    private function reportTenant(Tenant $tenant, array $result): void
    {
        $this->info('Reference COA seeded for '.$tenant->code.' ('.$tenant->name.').');
        $this->line('Tenant ULID: '.$tenant->ulid);
        $this->line('Main Heads upserted: '.$result['main_heads']);
        $this->line('Heads upserted: '.$result['sub_heads']);
        $this->line('Account Types upserted: '.$result['account_types']);
        $this->line('End Accounts created: '.$result['accounts']);
    }

    private function resolveTenant(): ?Tenant
    {
        $login = trim((string) $this->option('login'));
        $email = Str::lower(trim((string) $this->option('email')));
        $tenantArg = trim((string) ($this->argument('tenant') ?? ''));

        if ($login !== '') {
            return $this->resolveByLogin($login, $tenantArg);
        }

        if ($email !== '') {
            return $this->resolveByEmail($email, $tenantArg);
        }

        if ($tenantArg === '') {
            $this->error('Provide a tenant ULID/code, --all, or --login=USERNAME with tenant, or --email=...');

            return null;
        }

        return $this->findTenant($tenantArg);
    }

    private function resolveByLogin(string $username, string $tenantArg): ?Tenant
    {
        if ($tenantArg === '') {
            $this->error('When using --login, also pass the tenant code or ULID (same as the login screen).');
            $this->line('Example: php artisan bluepos:seed-reference-coa YOURTENANT --login=owner');

            return null;
        }

        $tenant = $this->findTenant($tenantArg);
        if (! $tenant) {
            return null;
        }

        $membership = Membership::query()
            ->where('tenant_id', $tenant->id)
            ->whereRaw('LOWER(username) = ?', [Str::lower($username)])
            ->first();

        if (! $membership) {
            $this->error('No membership "'.$username.'" on tenant '.$tenant->code.'.');

            return null;
        }

        $this->line('Resolved login '.$membership->username.' → tenant '.$tenant->code);

        return $tenant;
    }

    private function resolveByEmail(string $email, string $tenantArg): ?Tenant
    {
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $this->error('No user with email '.$email);

            return null;
        }

        $memberships = Membership::query()
            ->with('tenant')
            ->where('user_id', $user->id)
            ->get();

        if ($memberships->isEmpty()) {
            $this->error('User '.$email.' has no tenant memberships.');

            return null;
        }

        if ($tenantArg !== '') {
            $tenant = $this->findTenant($tenantArg);
            if (! $tenant) {
                return null;
            }
            $match = $memberships->firstWhere('tenant_id', $tenant->id);
            if (! $match) {
                $this->error('User '.$email.' is not a member of tenant '.$tenant->code);

                return null;
            }
            $this->line('Resolved email '.$email.' → tenant '.$tenant->code);

            return $tenant;
        }

        if ($memberships->count() > 1) {
            $this->error('User belongs to multiple tenants — pass tenant code/ULID as well:');
            foreach ($memberships as $m) {
                $this->line('  - '.$m->tenant?->code.' ('.$m->tenant?->ulid.') as '.$m->username);
            }
            $this->line('Example: php artisan bluepos:seed-reference-coa TENANTCODE --email='.$email);

            return null;
        }

        /** @var Membership $only */
        $only = $memberships->first();
        $tenant = $only->tenant;
        if (! $tenant) {
            $this->error('Membership has no tenant.');

            return null;
        }

        $this->line('Resolved email '.$email.' / login '.$only->username.' → tenant '.$tenant->code);

        return $tenant;
    }

    private function findTenant(string $ulidOrCode): ?Tenant
    {
        $key = trim($ulidOrCode);
        $tenant = Tenant::query()
            ->where(function ($q) use ($key): void {
                $q->where('ulid', strtoupper($key))
                    ->orWhereRaw('LOWER(code) = ?', [Str::lower($key)]);
            })
            ->first();

        if (! $tenant) {
            $this->error('Tenant not found for ULID/code '.$key);
        }

        return $tenant;
    }
}
