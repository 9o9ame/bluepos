<?php

namespace App\Http\Controllers\Api;

use App\Actions\Warehouses\CreateWarehouseAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\WarehouseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function store(Request $request, CreateWarehouseAction $create): JsonResponse
    {
        $this->authorize('create', \App\Models\Warehouse::class);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:120'],
            'branch_ulid' => ['required', 'string', 'size:26'],
        ]);

        return (new WarehouseResource($create->execute($data)))
            ->response()
            ->setStatusCode(201);
    }
}
