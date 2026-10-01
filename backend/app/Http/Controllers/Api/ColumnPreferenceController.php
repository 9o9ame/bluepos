<?php

namespace App\Http\Controllers\Api;

use App\Authz\PermissionService;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ColumnPreferences\UpsertColumnPreferenceRequest;
use App\Models\ColumnPreference;
use App\Services\ColumnPreferences\ColumnPreferenceService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class ColumnPreferenceController extends Controller
{
    public function show(
        string $screenKey,
        TenantContext $tenantContext,
        ColumnPreferenceService $service,
    ): JsonResponse {
        $this->assertScreenKey($screenKey);

        $resolved = $service->resolve(
            $tenantContext->tenantId(),
            $tenantContext->membership(),
            $screenKey,
        );

        return response()->json([
            'data' => [
                'screen_key' => $screenKey,
                ...$resolved,
            ],
        ]);
    }

    public function upsert(
        string $screenKey,
        UpsertColumnPreferenceRequest $request,
        TenantContext $tenantContext,
        ColumnPreferenceService $service,
        PermissionService $permissions,
    ): JsonResponse {
        $this->assertScreenKey($screenKey);

        $data = $request->validated();
        $scope = $data['scope'];

        if ($scope === ColumnPreference::SCOPE_ROLE
            && ! $permissions->can('roles.edit')
            && ! $permissions->can('roles.manage_permissions')
        ) {
            throw new ApiException('FORBIDDEN', 'You cannot save role column defaults.', 403);
        }

        $saved = $service->save(
            $tenantContext->tenantId(),
            $tenantContext->membership(),
            $screenKey,
            $scope,
            $data['columns'],
            $data['role_ulid'] ?? null,
        );

        return response()->json([
            'data' => [
                'screen_key' => $screenKey,
                ...$saved,
            ],
        ]);
    }

    private function assertScreenKey(string $screenKey): void
    {
        if (! preg_match('/^[a-z][a-z0-9._-]{1,62}$/', $screenKey)) {
            throw new ApiException('VALIDATION_FAILED', 'Invalid screen key.', 422);
        }
    }
}
