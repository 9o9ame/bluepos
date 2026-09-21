<?php

namespace App\Actions\Auth;

use App\Auth\AuthenticatedSession;
use App\Authz\PermissionCatalogue;
use App\Authz\TenantRoleProvisioner;
use App\Catalog\TenantCatalogProvisioner;
use App\Enums\BranchStatus;
use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Enums\WarehouseStatus;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RegisterTenantAction
{
    public function __construct(
        private readonly TenantRoleProvisioner $roleProvisioner,
        private readonly TenantCatalogProvisioner $catalogProvisioner,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, tenant_name: string, timezone?: string, currency_code?: string}  $data
     */
    public function execute(array $data): AuthenticatedSession
    {
        try {
            return DB::transaction(function () use ($data): AuthenticatedSession {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'status' => UserStatus::Active,
                ]);

                $tenant = Tenant::query()->create([
                    'name' => $data['tenant_name'],
                    'slug' => $this->uniqueSlug($data['tenant_name']),
                    'status' => TenantStatus::Trial,
                    'timezone' => $data['timezone'] ?? 'Asia/Karachi',
                    'currency_code' => strtoupper($data['currency_code'] ?? 'PKR'),
                ]);

                $membership = Membership::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'status' => MembershipStatus::Active,
                    'is_owner' => false,
                ]);

                $branch = Branch::query()->create([
                    'tenant_id' => $tenant->id,
                    'code' => 'MAIN',
                    'name' => 'Main',
                    'status' => BranchStatus::Active,
                    'is_default' => true,
                ]);

                $warehouse = Warehouse::query()->create([
                    'tenant_id' => $tenant->id,
                    'branch_id' => $branch->id,
                    'code' => 'MAIN',
                    'name' => 'Main Warehouse',
                    'status' => WarehouseStatus::Active,
                    'is_default' => true,
                ]);

                $roles = $this->roleProvisioner->provision($tenant);
                $ownerRole = $roles[PermissionCatalogue::OWNER];
                $membership->roles()->attach($ownerRole->id, [
                    'ulid' => (string) Str::ulid(),
                ]);
                $membership->is_owner = $membership->hasRoleCode(PermissionCatalogue::OWNER, $tenant->id);
                $membership->save();

                $this->catalogProvisioner->provision($tenant);

                if (app()->environment('testing') && app()->bound('registration.force_failure')) {
                    throw new RuntimeException('Forced registration failure');
                }

                return new AuthenticatedSession(
                    user: $user,
                    tenant: $tenant,
                    membership: $membership,
                    branch: $branch,
                    warehouse: $warehouse,
                );
            });
        } catch (Throwable $e) {
            throw $e;
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $i = 1;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
