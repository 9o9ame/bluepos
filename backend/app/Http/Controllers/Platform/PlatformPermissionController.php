<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\PlatformPermissionResource;
use App\Models\Platform\PlatformPermission;
use App\Platform\PlatformCatalogSync;
use Illuminate\Http\Request;

class PlatformPermissionController extends Controller
{
    public function index(Request $request, PlatformCatalogSync $catalog): mixed
    {
        $catalog->ensure();
        $query = PlatformPermission::query()->orderBy('module')->orderBy('key');

        if ($request->filled('module')) {
            $query->where('module', (string) $request->string('module'));
        }
        if ($request->filled('q')) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $request->string('q')).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('key', 'ilike', $term)
                    ->orWhere('name', 'ilike', $term)
                    ->orWhere('description', 'ilike', $term);
            });
        }

        return PlatformPermissionResource::collection($query->get());
    }
}
