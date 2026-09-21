<?php

namespace App\Catalog;

use App\Authz\TenantRoleProvisioner;
use App\Enums\PriceType;
use App\Models\BusinessSetting;
use App\Models\Tenant;
use App\Models\Unit;

class TenantCatalogProvisioner
{
    public function __construct(private readonly TenantRoleProvisioner $roleProvisioner) {}

    /**
     * @return list<array{code: string, name: string, symbol: string, allows_decimal: bool}>
     */
    public static function defaultUnits(): array
    {
        return [
            ['code' => 'PCS', 'name' => 'Piece', 'symbol' => 'pcs', 'allows_decimal' => false],
            ['code' => 'PACK', 'name' => 'Pack', 'symbol' => 'pk', 'allows_decimal' => false],
            ['code' => 'BOX', 'name' => 'Box', 'symbol' => 'box', 'allows_decimal' => false],
            ['code' => 'CARTON', 'name' => 'Carton', 'symbol' => 'ctn', 'allows_decimal' => false],
            ['code' => 'KG', 'name' => 'Kilogram', 'symbol' => 'kg', 'allows_decimal' => true],
            ['code' => 'GRAM', 'name' => 'Gram', 'symbol' => 'g', 'allows_decimal' => true],
            ['code' => 'LITER', 'name' => 'Liter', 'symbol' => 'L', 'allows_decimal' => true],
            ['code' => 'ML', 'name' => 'Milliliter', 'symbol' => 'ml', 'allows_decimal' => true],
            ['code' => 'DOZEN', 'name' => 'Dozen', 'symbol' => 'doz', 'allows_decimal' => false],
            ['code' => 'METER', 'name' => 'Meter', 'symbol' => 'm', 'allows_decimal' => true],
        ];
    }

    public function provision(Tenant $tenant): BusinessSetting
    {
        $this->roleProvisioner->provision($tenant);

        $settings = BusinessSetting::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'business_name' => $tenant->name,
                'country_code' => 'PK',
                'currency_code' => $tenant->currency_code,
                'timezone' => $tenant->timezone,
                'date_format' => 'd/m/Y',
                'number_format' => '1,234.56',
                'default_tax_percent' => '0.00000000',
                'negative_stock_allowed' => false,
                'expiry_tracking_enabled' => false,
                'batch_tracking_enabled' => false,
                'default_price_level' => PriceType::Retail->value,
                'next_product_number' => 1,
            ],
        );

        foreach (self::defaultUnits() as $unit) {
            Unit::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'code' => $unit['code'],
                ],
                [
                    'name' => $unit['name'],
                    'symbol' => $unit['symbol'],
                    'allows_decimal' => $unit['allows_decimal'],
                    'is_active' => true,
                ],
            );
        }

        return $settings;
    }
}
