<?php

namespace App\Http\Controllers\Api;

use App\Actions\Roles\CreateRoleAction;
use App\Actions\Roles\DeleteRoleAction;
use App\Actions\Roles\SyncRolePermissionsAction;
use App\Actions\Roles\UpdateRoleAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\SyncRolePermissionsRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(TenantContext $tenantContext): mixed
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()
            ->forTenant($tenantContext->tenantId())
            ->with('permissions')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return RoleResource::collection($roles);
    }

    public function store(StoreRoleRequest $request, CreateRoleAction $createRole): JsonResponse
    {
        $this->authorize('create', Role::class);

        $role = $createRole->execute($request->validated());

        return (new RoleResource($role->load('permissions')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $roleUlid, TenantContext $tenantContext): RoleResource
    {
        $role = $this->findTenantRole($tenantContext, $roleUlid);
        $this->authorize('view', $role);

        return new RoleResource($role->load('permissions'));
    }

    public function update(
        UpdateRoleRequest $request,
        string $roleUlid,
        TenantContext $tenantContext,
        UpdateRoleAction $updateRole,
    ): RoleResource {
        $role = $this->findTenantRole($tenantContext, $roleUlid);
        $this->authorize('update', $role);

        return new RoleResource($updateRole->execute($role, $request->validated()));
    }

    public function destroy(
        string $roleUlid,
        TenantContext $tenantContext,
        DeleteRoleAction $deleteRole,
    ): JsonResponse {
        $role = $this->findTenantRole($tenantContext, $roleUlid);
        $this->authorize('delete', $role);

        $deleteRole->execute($role);

        return response()->json(['ok' => true]);
    }

    public function syncPermissions(
        SyncRolePermissionsRequest $request,
        string $roleUlid,
        TenantContext $tenantContext,
        SyncRolePermissionsAction $syncRolePermissions,
    ): RoleResource {
        $role = $this->findTenantRole($tenantContext, $roleUlid);
        $this->authorize('managePermissions', $role);

        return new RoleResource($syncRolePermissions->execute(
            $role,
            $request->validated('permissions'),
        ));
    }

    private function findTenantRole(TenantContext $tenantContext, string $roleUlid): Role
    {
        $role = Role::query()
            ->forTenant($tenantContext->tenantId())
            ->where('ulid', $roleUlid)
            ->first();

        if (! $role) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $role;
    }
}
