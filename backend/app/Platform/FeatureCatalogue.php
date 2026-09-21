<?php

namespace App\Platform;

final class FeatureCatalogue
{
    public const CATALOG = 'catalog';

    public const SALES = 'sales';

    public const SALES_RETURNS = 'sales_returns';

    public const PURCHASES = 'purchases';

    public const PURCHASE_RETURNS = 'purchase_returns';

    public const INVENTORY = 'inventory';

    public const STOCK_TRANSFERS = 'stock_transfers';

    public const STOCK_TAKE = 'stock_take';

    public const EXPIRY_MANAGEMENT = 'expiry_management';

    public const SUPPLIER_CLAIMS = 'supplier_claims';

    public const ACCOUNTING = 'accounting';

    public const VOUCHERS = 'vouchers';

    public const BASIC_REPORTS = 'basic_reports';

    public const ADVANCED_REPORTS = 'advanced_reports';

    public const OFFLINE_POS = 'offline_pos';

    public const MULTI_BRANCH = 'multi_branch';

    public const MULTI_WAREHOUSE = 'multi_warehouse';

    public const BARCODE_PRINTING = 'barcode_printing';

    /**
     * @return list<array{key: string, name: string, module: string, description: ?string}>
     */
    public static function definitions(): array
    {
        return [
            [self::CATALOG, 'Catalog', 'catalog', 'Categories, brands, units, products'],
            [self::SALES, 'Sales', 'sales', null],
            [self::SALES_RETURNS, 'Sales returns', 'sales', null],
            [self::PURCHASES, 'Purchases', 'purchases', null],
            [self::PURCHASE_RETURNS, 'Purchase returns', 'purchases', null],
            [self::INVENTORY, 'Inventory', 'inventory', null],
            [self::STOCK_TRANSFERS, 'Stock transfers', 'inventory', null],
            [self::STOCK_TAKE, 'Stock take', 'inventory', null],
            [self::EXPIRY_MANAGEMENT, 'Expiry management', 'inventory', null],
            [self::SUPPLIER_CLAIMS, 'Supplier claims', 'purchases', null],
            [self::ACCOUNTING, 'Accounting', 'accounting', null],
            [self::VOUCHERS, 'Vouchers', 'accounting', null],
            [self::BASIC_REPORTS, 'Basic reports', 'reports', null],
            [self::ADVANCED_REPORTS, 'Advanced reports', 'reports', null],
            [self::OFFLINE_POS, 'Offline POS', 'offline', null],
            [self::MULTI_BRANCH, 'Multi branch', 'organization', null],
            [self::MULTI_WAREHOUSE, 'Multi warehouse', 'organization', null],
            [self::BARCODE_PRINTING, 'Barcode printing', 'catalog', null],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (array $row): string => $row[0], self::definitions());
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
