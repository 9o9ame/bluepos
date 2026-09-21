<?php

namespace App\Http\Controllers\Api;

use App\Authz\PermissionCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;

class PermissionController extends Controller
{
    public function index(): mixed
    {
        $this->authorize('viewAny', \App\Models\Role::class);

        $permissions = Permission::query()
            ->where('is_platform', false)
            ->whereIn('key', PermissionCatalogue::tenantKeys())
            ->orderBy('module')
            ->orderBy('key')
            ->get();

        return PermissionResource::collection($permissions);
    }
}
