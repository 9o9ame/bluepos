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
use App\Security\AuditLogger;
use App\Support\IdentityNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProvisionTenantAction
{
    public function __construct(
        private readonly TenantRoleProvisioner $roleProvisioner,
        private readonly TenantCatalogProvisioner $catalogProvisioner,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, username: string, recovery_email?: ?string, password: string, tenant_name: string, tenant_code: string, timezone?: string, currency_code?: string, must_change_password?: bool}  $data
     */
    public function execute(array $data): AuthenticatedSession
    {
        try {
            return DB::transaction(function () use ($data): AuthenticatedSession {
                $code = IdentityNormalizer::tenantCode($data['tenant_code']);
                $username = IdentityNormalizer::username($data['username']);

                if (! IdentityNormalizer::isValidTenantCode($code)) {
                    throw ValidationException::withMessages([
                        'tenant_code' => 'Tenant code must be 2-32 letters or numbers.',
                    ]);
                }

                if (! IdentityNormalizer::isValidUsername($username)) {
                    throw ValidationException::withMessages([
                        'username' => 'Username must be 2-63 letters, numbers, dots, underscores, or hyphens.',
                    ]);
                }

                if (Tenant::query()->where('code', $code)->exists()) {
                    throw ValidationException::withMessages([
                        'tenant_code' => 'This tenant code is already in use.',
                    ]);
                }

                $recovery = isset($data['recovery_email']) && $data['recovery_email']
                    ? strtolower(trim((string) $data['recovery_email']))
                    : null;

                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $recovery,
                    'recovery_email' => $recovery,
                    'password' => $data['password'],
                    'status' => UserStatus::Active,
                    'must_change_password' => (bool) ($data['must_change_password'] ?? false),
                    'password_changed_at' => now(),
                    'security_version' => 1,
                ]);

                $tenant = Tenant::query()->create([
                    'name' => $data['tenant_name'],
                    'code' => $code,
                    'slug' => $this->uniqueSlug($data['tenant_name']),
                    'status' => TenantStatus::Active,
                    'timezone' => $data['timezone'] ?? 'Asia/Karachi',
                    'currency_code' => strtoupper($data['currency_code'] ?? 'PKR'),
                ]);

                $membership = Membership::query()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'username' => $username,
                    'status' => MembershipStatus::Active,
                    'is_owner' => false,
                    'security_version' => 1,
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

                $this->audit->record('USER_CREATED', [
                    'tenant_id' => $tenant->id,
                    'actor_user_id' => $user->id,
                    'actor_membership_id' => $membership->id,
                    'resource_type' => 'membership',
                    'resource_ulid' => $membership->ulid,
                    'source' => 'provision',
                ]);

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
