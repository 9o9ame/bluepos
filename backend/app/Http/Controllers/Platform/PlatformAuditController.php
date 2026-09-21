<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\PlatformAuditLogResource;
use App\Models\Platform\PlatformAuditLog;
use Illuminate\Http\Request;

class PlatformAuditController extends Controller
{
    public function index(Request $request): mixed
    {
        $perPage = min(max($request->integer('per_page', 50), 1), 100);
        $query = PlatformAuditLog::query()->with('actor')->orderByDesc('occurred_at');

        if ($request->filled('event')) {
            $query->where('event', (string) $request->string('event'));
        }

        if ($request->filled('resource_ulid')) {
            $query->where('resource_ulid', (string) $request->string('resource_ulid'));
        }

        return PlatformAuditLogResource::collection($query->paginate($perPage));
    }
}
