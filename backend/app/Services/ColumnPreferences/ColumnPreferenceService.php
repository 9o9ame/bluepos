<?php

namespace App\Services\ColumnPreferences;

use App\Exceptions\ApiException;
use App\Models\ColumnPreference;
use App\Models\Membership;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class ColumnPreferenceService
{
    /**
     * Resolve layout for a screen: user overlay → first role default → null (client defaults).
     *
     * @return array{scope: string, role_ulid: ?string, columns: list<array<string, mixed>>}
     */
    public function resolve(int $tenantId, Membership $membership, string $screenKey): array
    {
        $role = $this->primaryRole($membership);

        $userRow = ColumnPreference::query()
            ->forTenant($tenantId)
            ->where('scope', ColumnPreference::SCOPE_USER)
            ->where('scope_id', $membership->user_id)
            ->where('screen_key', $screenKey)
            ->first();

        if ($userRow !== null) {
            return [
                'scope' => ColumnPreference::SCOPE_USER,
                'role_ulid' => $role?->ulid,
                'columns' => $this->normalizeLayout($userRow->layout ?? []),
            ];
        }

        if ($role !== null) {
            $roleRow = ColumnPreference::query()
                ->forTenant($tenantId)
                ->where('scope', ColumnPreference::SCOPE_ROLE)
                ->where('scope_id', $role->id)
                ->where('screen_key', $screenKey)
                ->first();

            if ($roleRow !== null) {
                return [
                    'scope' => ColumnPreference::SCOPE_ROLE,
                    'role_ulid' => $role->ulid,
                    'columns' => $this->normalizeLayout($roleRow->layout ?? []),
                ];
            }
        }

        return [
            'scope' => 'default',
            'role_ulid' => $role?->ulid,
            'columns' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @return array{scope: string, role_ulid: ?string, columns: list<array<string, mixed>>}
     */
    public function save(
        int $tenantId,
        Membership $membership,
        string $screenKey,
        string $scope,
        array $columns,
        ?string $roleUlid = null,
    ): array {
        $layout = $this->normalizeLayout($columns);

        return DB::transaction(function () use ($tenantId, $membership, $screenKey, $scope, $layout, $roleUlid): array {
            if ($scope === ColumnPreference::SCOPE_ROLE) {
                $role = $this->resolveRoleForSave($tenantId, $membership, $roleUlid);
                $row = ColumnPreference::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'scope' => ColumnPreference::SCOPE_ROLE,
                        'scope_id' => $role->id,
                        'screen_key' => $screenKey,
                    ],
                    ['layout' => $layout],
                );

                return [
                    'scope' => ColumnPreference::SCOPE_ROLE,
                    'role_ulid' => $role->ulid,
                    'columns' => $this->normalizeLayout($row->layout ?? []),
                ];
            }

            $row = ColumnPreference::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'scope' => ColumnPreference::SCOPE_USER,
                    'scope_id' => $membership->user_id,
                    'screen_key' => $screenKey,
                ],
                ['layout' => $layout],
            );

            return [
                'scope' => ColumnPreference::SCOPE_USER,
                'role_ulid' => $this->primaryRole($membership)?->ulid,
                'columns' => $this->normalizeLayout($row->layout ?? []),
            ];
        });
    }

    public function primaryRole(Membership $membership): ?Role
    {
        return $membership->roles()
            ->where('roles.tenant_id', $membership->tenant_id)
            ->where('roles.is_active', true)
            ->orderBy('roles.id')
            ->first();
    }

    private function resolveRoleForSave(int $tenantId, Membership $membership, ?string $roleUlid): Role
    {
        if ($roleUlid) {
            $role = Role::query()
                ->forTenant($tenantId)
                ->where('ulid', $roleUlid)
                ->where('is_active', true)
                ->first();

            if ($role === null) {
                throw new ApiException('NOT_FOUND', 'Role was not found.', 404);
            }

            return $role;
        }

        $role = $this->primaryRole($membership);
        if ($role === null) {
            throw new ApiException('VALIDATION_FAILED', 'No role available to save column defaults.', 422);
        }

        return $role;
    }

    /**
     * @param  mixed  $layout
     * @return list<array{key: string, visible: bool, position: int, width: ?int, locked: bool}>
     */
    private function normalizeLayout(mixed $layout): array
    {
        if (! is_array($layout)) {
            return [];
        }

        $normalized = [];
        foreach ($layout as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = isset($row['key']) && is_string($row['key']) ? trim($row['key']) : '';
            if ($key === '' || strlen($key) > 64) {
                continue;
            }

            $width = $row['width'] ?? null;
            $normalized[] = [
                'key' => $key,
                'visible' => (bool) ($row['visible'] ?? true),
                'position' => (int) ($row['position'] ?? $index),
                'width' => is_numeric($width) ? max(40, min(800, (int) $width)) : null,
                'locked' => (bool) ($row['locked'] ?? false),
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return array_values($normalized);
    }
}
