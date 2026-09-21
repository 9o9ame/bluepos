<?php

namespace App\Platform;

use App\Enums\PlanStatus;
use App\Models\FeatureDefinition;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanLimit;
use App\Models\Platform\PlatformPermission;
use App\Models\Platform\PlatformRole;
use Illuminate\Support\Str;

class PlatformCatalogSync
{
    public function ensure(): void
    {
        $this->syncPlatformPermissions();
        $this->syncFeatures();
        $this->syncDefaultPlans();
    }

    private function syncPlatformPermissions(): void
    {
        foreach (PlatformPermissionCatalogue::definitions() as $row) {
            PlatformPermission::query()->firstOrCreate(
                ['key' => $row[0]],
                ['name' => $row[1], 'module' => $row[2], 'description' => $row[3]],
            );
        }

        $role = PlatformRole::query()->firstOrCreate(
            ['code' => PlatformPermissionCatalogue::SUPER_ADMIN],
            [
                'name' => 'Super Admin',
                'description' => 'Full platform control',
                'is_system' => true,
                'is_active' => true,
            ],
        );

        $permissionIds = PlatformPermission::query()->pluck('id', 'key');
        foreach (PlatformPermissionCatalogue::keys() as $key) {
            $permissionId = $permissionIds->get($key);
            if (! $permissionId) {
                continue;
            }
            if (! $role->permissions()->where('platform_permissions.id', $permissionId)->exists()) {
                $role->permissions()->attach($permissionId, ['ulid' => (string) Str::ulid()]);
            }
        }
    }

    private function syncFeatures(): void
    {
        foreach (FeatureCatalogue::definitions() as $row) {
            FeatureDefinition::query()->firstOrCreate(
                ['key' => $row[0]],
                ['name' => $row[1], 'module' => $row[2], 'description' => $row[3], 'is_active' => true],
            );
        }
    }

    private function syncDefaultPlans(): void
    {
        foreach ($this->defaultPlans() as $definition) {
            $plan = Plan::query()->firstOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'status' => PlanStatus::Active,
                ],
            );

            foreach (FeatureCatalogue::keys() as $feature) {
                PlanFeature::query()->firstOrCreate(
                    ['plan_id' => $plan->id, 'feature_key' => $feature],
                    ['enabled' => in_array($feature, $definition['features'], true)],
                );
            }

            foreach (LimitCatalogue::keys() as $limit) {
                PlanLimit::query()->firstOrCreate(
                    ['plan_id' => $plan->id, 'limit_key' => $limit],
                    ['value' => $definition['limits'][$limit] ?? null],
                );
            }
        }
    }

    /**
     * @return list<array{code: string, name: string, description: string, features: list<string>, limits: array<string, int|null>}>
     */
    private function defaultPlans(): array
    {
        $starter = [
            FeatureCatalogue::CATALOG,
            FeatureCatalogue::SALES,
            FeatureCatalogue::PURCHASES,
            FeatureCatalogue::INVENTORY,
            FeatureCatalogue::BASIC_REPORTS,
        ];
        $professional = array_merge($starter, [
            FeatureCatalogue::SALES_RETURNS,
            FeatureCatalogue::PURCHASE_RETURNS,
            FeatureCatalogue::EXPIRY_MANAGEMENT,
            FeatureCatalogue::SUPPLIER_CLAIMS,
            FeatureCatalogue::ACCOUNTING,
            FeatureCatalogue::ADVANCED_REPORTS,
            FeatureCatalogue::MULTI_BRANCH,
            FeatureCatalogue::BARCODE_PRINTING,
        ]);

        return [
            [
                'code' => 'STARTER',
                'name' => 'Starter',
                'description' => 'Core POS operations',
                'features' => $starter,
                'limits' => [
                    LimitCatalogue::MAX_USERS => 5,
                    LimitCatalogue::MAX_BRANCHES => 1,
                    LimitCatalogue::MAX_WAREHOUSES => 1,
                    LimitCatalogue::MAX_DEVICES => 2,
                ],
            ],
            [
                'code' => 'PROFESSIONAL',
                'name' => 'Professional',
                'description' => 'Multi-branch operations with accounting',
                'features' => $professional,
                'limits' => [
                    LimitCatalogue::MAX_USERS => 10,
                    LimitCatalogue::MAX_BRANCHES => 3,
                    LimitCatalogue::MAX_WAREHOUSES => 3,
                    LimitCatalogue::MAX_DEVICES => 8,
                ],
            ],
            [
                'code' => 'ENTERPRISE',
                'name' => 'Enterprise',
                'description' => 'All modules, unlimited scale',
                'features' => FeatureCatalogue::keys(),
                'limits' => [
                    LimitCatalogue::MAX_USERS => null,
                    LimitCatalogue::MAX_BRANCHES => null,
                    LimitCatalogue::MAX_WAREHOUSES => null,
                    LimitCatalogue::MAX_DEVICES => null,
                ],
            ],
        ];
    }
}
