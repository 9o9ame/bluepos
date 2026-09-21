<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\CreatePlatformRoleAction;
use App\Actions\Platform\DeletePlatformRoleAction;
use App\Actions\Platform\SyncPlatformRolePermissionsAction;
use App\Actions\Platform\UpdatePlatformRoleAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformRoleRequest;
use App\Http\Requests\Platform\SyncPlatformRolePermissionsRequest;
use App\Http\Requests\Platform\UpdatePlatformRoleRequest;
use App\Http\Resources\Platform\PlatformRoleResource;
use App\Models\Platform\PlatformRole;
use Illuminate\Http\JsonResponse;

class PlatformRoleController extends Controller
{
    public function index(): mixed
    {
        return PlatformRoleResource::collection(
            PlatformRole::query()
                ->withCount('users')
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StorePlatformRoleRequest $request, CreatePlatformRoleAction $create): JsonResponse
    {
        $role = $create->execute($request->validated());

        return (new PlatformRoleResource($role->load('permissions')->loadCount('users')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $roleUlid): PlatformRoleResource
    {
        return new PlatformRoleResource($this->findRole($roleUlid)->load('permissions')->loadCount('users'));
    }

    public function update(UpdatePlatformRoleRequest $request, string $roleUlid, UpdatePlatformRoleAction $update): PlatformRoleResource
    {
        return new PlatformRoleResource(
            $update->execute($this->findRole($roleUlid), $request->validated())->load('permissions')->loadCount('users')
        );
    }

    public function destroy(string $roleUlid, DeletePlatformRoleAction $delete): JsonResponse
    {
        $delete->execute($this->findRole($roleUlid));

        return response()->json(['ok' => true]);
    }

    public function syncPermissions(
        SyncPlatformRolePermissionsRequest $request,
        string $roleUlid,
        SyncPlatformRolePermissionsAction $sync,
    ): PlatformRoleResource {
        return new PlatformRoleResource(
            $sync->execute($this->findRole($roleUlid), $request->validated('permissions'))->loadCount('users')
        );
    }

    private function findRole(string $roleUlid): PlatformRole
    {
        $role = PlatformRole::query()->where('ulid', $roleUlid)->first();
        if (! $role) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $role;
    }
}
