<?php

namespace App\Authz;

use App\Models\Permission;
use Illuminate\Support\Str;

class PermissionCatalogSync
{
    public function ensure(): void
    {
        foreach (PermissionCatalogue::definitions() as $definition) {
            $permission = Permission::query()->firstOrNew(['key' => $definition['key']]);
            $permission->fill([
                'name' => $definition['name'],
                'module' => $definition['module'],
                'description' => $definition['description'],
                'is_platform' => $definition['is_platform'],
            ]);

            if ($permission->ulid === null) {
                $permission->ulid = (string) Str::ulid();
            }

            $permission->save();
        }
    }
}
