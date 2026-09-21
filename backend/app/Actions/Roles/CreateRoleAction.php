<?php

namespace App\Actions\Roles;

use App\Authz\PermissionCatalogue;
use App\Enums\BranchAccess;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class CreateRoleAction
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array{name: string, code: string, description?: ?string, branch_access?: string}  $data
     */
    public function execute(array $data): Role
    {
        $tenantId = $this->tenantContext->tenantId();
        $code = strtolower(trim($data['code']));

        if (in_array($code, [PermissionCatalogue::OWNER, PermissionCatalogue::ADMIN, PermissionCatalogue::MANAGER, PermissionCatalogue::CASHIER], true)) {
            throw ValidationException::withMessages([
                'code' => 'This role code is reserved for system roles.',
            ]);
        }

        if (Role::query()->forTenant($tenantId)->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => 'A role with this code already exists for the tenant.',
            ]);
        }

        return Role::query()->create([
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'code' => $code,
            'description' => $data['description'] ?? null,
            'branch_access' => BranchAccess::from($data['branch_access'] ?? BranchAccess::SelectedBranches->value),
            'is_system' => false,
            'is_active' => true,
        ]);
    }
}
