<?php

namespace App\Authz;

final class PermissionCatalogue
{
    public const OWNER = 'owner';

    public const ADMIN = 'admin';

    public const MANAGER = 'manager';

    public const CASHIER = 'cashier';

    public const ACCOUNTANT = 'accountant';

    public const STOCK = 'stock';

    public const PURCHASE = 'purchase';

    /**
     * @return list<array{key: string, name: string, module: string, description: ?string, is_platform: bool}>
     */
    public static function definitions(): array
    {
        $tenant = [
            ['dashboard.view', 'View dashboard', 'dashboard', 'Open the tenant workspace'],
            ['users.view', 'View users', 'users', 'List tenant memberships'],
            ['users.create', 'Create users', 'users', 'Create tenant memberships'],
            ['users.edit', 'Edit users', 'users', 'Update membership profile and status'],
            ['users.deactivate', 'Deactivate users', 'users', 'Suspend tenant memberships'],
            ['users.activate', 'Activate users', 'users', 'Reactivate tenant memberships'],
            ['users.reset_password', 'Reset staff passwords', 'users', 'Set a temporary password'],
            ['users.force_logout', 'Force logout', 'users', 'Revoke staff sessions'],
            ['users.manage_roles', 'Manage user roles', 'users', 'Assign roles to memberships'],
            ['users.manage_branches', 'Manage user branches', 'users', 'Assign branch access to memberships'],
            ['roles.view', 'View roles', 'roles', 'List tenant roles'],
            ['roles.create', 'Create roles', 'roles', 'Create custom tenant roles'],
            ['roles.edit', 'Edit roles', 'roles', 'Rename and update custom roles'],
            ['roles.delete', 'Delete roles', 'roles', 'Delete custom tenant roles'],
            ['roles.manage_permissions', 'Manage role permissions', 'roles', 'Grant and revoke role permissions'],
            ['branches.view', 'View branches', 'branches', 'List branches'],
            ['branches.switch', 'Switch branch', 'branches', 'Change the active branch'],
            ['branches.manage', 'Manage branches', 'branches', 'Create and edit branches (future)'],
            ['warehouses.view', 'View warehouses', 'warehouses', null],
            ['warehouses.manage', 'Manage warehouses', 'warehouses', null],
            ['settings.view', 'View settings', 'settings', null],
            ['settings.manage', 'Manage settings', 'settings', 'Update tenant business settings'],
            ['settings.financial', 'Financial settings', 'settings', 'COA, costing, tax, period close'],
            ['settings.security', 'Security settings', 'settings', 'Password and session policies'],
            ['devices.view', 'View devices', 'devices', 'List POS terminals'],
            ['devices.approve', 'Approve devices', 'devices', 'Activate pending terminals'],
            ['devices.revoke', 'Revoke devices', 'devices', 'Revoke or disable terminals'],
            ['devices.assign_branch', 'Assign device branch', 'devices', null],
            ['devices.assign_warehouse', 'Assign device warehouse', 'devices', null],
            ['security.sessions.view', 'View sessions', 'security', null],
            ['security.sessions.revoke', 'Revoke sessions', 'security', null],
            ['security.audit.view', 'View security audit log', 'security', null],
            ['security.mfa.manage_own', 'Manage own MFA', 'security', null],
            ['security.mfa.manage_staff', 'Manage staff MFA', 'security', null],
            ['sales.backdate', 'Backdate sales', 'sales', 'Create historical sale dates'],
            ['products.view', 'View products', 'products', null],
            ['products.create', 'Create products', 'products', null],
            ['products.edit', 'Edit products', 'products', null],
            ['products.delete', 'Delete products', 'products', null],
            ['products.manage_prices', 'Manage product prices', 'products', null],
            ['products.manage_barcodes', 'Manage product barcodes', 'products', null],
            ['categories.view', 'View categories', 'products', null],
            ['categories.create', 'Create categories', 'products', null],
            ['categories.edit', 'Edit categories', 'products', null],
            ['categories.delete', 'Delete categories', 'products', null],
            ['categories.manage', 'Manage categories', 'products', null],
            ['brands.view', 'View brands', 'products', null],
            ['brands.create', 'Create brands', 'products', null],
            ['brands.edit', 'Edit brands', 'products', null],
            ['brands.delete', 'Delete brands', 'products', null],
            ['brands.manage', 'Manage brands', 'products', null],
            ['units.view', 'View units', 'products', null],
            ['units.create', 'Create units', 'products', null],
            ['units.edit', 'Edit units', 'products', null],
            ['units.delete', 'Delete units', 'products', null],
            ['units.manage', 'Manage units', 'products', null],
            ['barcode_groups.view', 'View barcode groups', 'products', null],
            ['barcode_groups.create', 'Create barcode groups', 'products', null],
            ['barcode_groups.edit', 'Edit barcode groups', 'products', null],
            ['barcode_groups.delete', 'Deactivate barcode groups', 'products', null],
            ['suppliers.view', 'View suppliers', 'products', null],
            ['suppliers.create', 'Create suppliers', 'products', null],
            ['suppliers.edit', 'Edit suppliers', 'products', null],
            ['suppliers.delete', 'Deactivate suppliers', 'products', null],
            ['suppliers.manage', 'Manage suppliers', 'products', null],
            ['customers.view', 'View customers', 'parties', null],
            ['customers.create', 'Create customers', 'parties', null],
            ['vendors.view', 'View vendors', 'parties', null],
            ['sales.view', 'View sales', 'sales', null],
            ['sales.create', 'Create sales', 'sales', null],
            ['sales.edit_draft', 'Edit draft sales', 'sales', null],
            ['sales.post', 'Post sales', 'sales', null],
            ['sales.void', 'Void sales', 'sales', null],
            ['sales.discount', 'Apply sales discounts', 'sales', null],
            ['sales.override_price', 'Override sale price', 'sales', null],
            ['sales.return', 'Create sale returns', 'sales', null],
            ['sales.return_without_invoice', 'Return without invoice', 'sales', null],
            ['sales.print', 'Print sales', 'sales', null],
            ['sales.hold', 'Hold sales', 'sales', null],
            ['sales.recall', 'Recall held sales', 'sales', null],
            ['payments.create', 'Collect payments', 'sales', null],
            ['purchases.view', 'View purchases', 'purchases', null],
            ['purchases.create', 'Create purchases', 'purchases', null],
            ['purchases.post', 'Post purchases', 'purchases', null],
            ['purchases.void', 'Void purchases', 'purchases', null],
            ['inventory.view', 'View inventory', 'inventory', null],
            ['inventory.adjust', 'Adjust inventory', 'inventory', null],
            ['inventory.transfer.dispatch', 'Dispatch transfers', 'inventory', null],
            ['inventory.transfer.receive', 'Receive transfers', 'inventory', null],
            ['inventory.stock_take.approve', 'Approve stock takes', 'inventory', null],
            ['accounting.journal.view', 'View journals', 'accounting', null],
            ['accounting.journal.create', 'Create journals', 'accounting', null],
            ['accounting.journal.post', 'Post journals', 'accounting', null],
            ['accounting.journal.reverse', 'Reverse journals', 'accounting', null],
            ['vouchers.create', 'Create vouchers', 'accounting', null],
            ['vouchers.approve', 'Approve vouchers', 'accounting', null],
            ['reports.sales', 'Sales reports', 'reports', null],
            ['reports.purchases', 'Purchase reports', 'reports', null],
            ['reports.inventory', 'Inventory reports', 'reports', null],
            ['reports.accounting', 'Accounting reports', 'reports', null],
            ['reports.profit_loss', 'Profit and loss', 'reports', null],
            ['cash_sessions.open', 'Open cash session', 'cash', null],
            ['cash_sessions.close', 'Close cash session', 'cash', null],
            ['cash_sessions.view', 'View cash sessions', 'cash', null],
            ['database.backup', 'Create backups', 'database', null],
            ['database.restore', 'Restore backups', 'database', null],
        ];

        $platform = [
            ['platform.tenants.view', 'View tenants', 'platform', 'Super Admin only'],
            ['platform.tenants.manage', 'Manage tenants', 'platform', 'Super Admin only'],
            ['platform.impersonate.start', 'Start impersonation', 'platform', 'Super Admin only'],
            ['superadmin.impersonate', 'Impersonate tenant users', 'platform', 'Super Admin only'],
        ];

        $map = static function (array $rows, bool $platformFlag): array {
            return array_map(static fn (array $row): array => [
                'key' => $row[0],
                'name' => $row[1],
                'module' => $row[2],
                'description' => $row[3],
                'is_platform' => $platformFlag,
            ], $rows);
        };

        return array_merge($map($tenant, false), $map($platform, true));
    }

    /**
     * @return list<string>
     */
    public static function tenantKeys(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['key'],
            array_filter(self::definitions(), static fn (array $row): bool => $row['is_platform'] === false),
        ));
    }

    public static function isTenantKey(string $key): bool
    {
        return in_array($key, self::tenantKeys(), true);
    }

    /**
     * @return list<string>
     */
    public static function keysForSystemRole(string $code): array
    {
        return match ($code) {
            self::OWNER, self::ADMIN => self::tenantKeys(),
            self::MANAGER => [
                'dashboard.view',
                'users.view',
                'roles.view',
                'branches.view',
                'branches.switch',
                'settings.view',
                'devices.view',
                'devices.approve',
                'devices.assign_branch',
                'security.sessions.view',
                'categories.view',
                'categories.create',
                'categories.edit',
                'brands.view',
                'brands.create',
                'brands.edit',
                'units.view',
                'units.create',
                'units.edit',
                'barcode_groups.view',
                'barcode_groups.create',
                'barcode_groups.edit',
                'suppliers.view',
                'suppliers.create',
                'suppliers.edit',
                'products.view',
                'products.create',
                'products.edit',
                'products.manage_prices',
                'products.manage_barcodes',
                'customers.view',
                'vendors.view',
                'sales.view',
                'sales.create',
                'sales.edit_draft',
                'sales.post',
                'sales.print',
                'sales.hold',
                'sales.recall',
                'sales.return',
                'payments.create',
                'purchases.view',
                'purchases.create',
                'inventory.view',
                'reports.sales',
                'reports.purchases',
                'reports.inventory',
                'cash_sessions.view',
                'cash_sessions.open',
                'cash_sessions.close',
            ],
            self::CASHIER => [
                'dashboard.view',
                'branches.view',
                'branches.switch',
                'products.view',
                'customers.view',
                'sales.view',
                'sales.create',
                'sales.edit_draft',
                'sales.post',
                'sales.print',
                'sales.hold',
                'sales.recall',
                'payments.create',
                'inventory.view',
                'cash_sessions.view',
                'cash_sessions.open',
                'cash_sessions.close',
            ],
            self::ACCOUNTANT => [
                'dashboard.view',
                'branches.view',
                'reports.accounting',
                'reports.profit_loss',
                'accounting.journal.view',
            ],
            self::STOCK => [
                'dashboard.view',
                'branches.view',
                'branches.switch',
                'products.view',
                'inventory.view',
                'reports.inventory',
            ],
            self::PURCHASE => [
                'dashboard.view',
                'branches.view',
                'vendors.view',
                'purchases.view',
                'purchases.create',
                'reports.purchases',
            ],
            default => [],
        };
    }

    /**
     * @return list<array{name: string, code: string, description: string, branch_access: string, is_system: bool}>
     */
    public static function defaultRoles(): array
    {
        return [
            [
                'name' => 'Owner',
                'code' => self::OWNER,
                'description' => 'Full tenant control',
                'branch_access' => 'all_branches',
                'is_system' => true,
            ],
            [
                'name' => 'Admin',
                'code' => self::ADMIN,
                'description' => 'Operational administration',
                'branch_access' => 'all_branches',
                'is_system' => true,
            ],
            [
                'name' => 'Manager',
                'code' => self::MANAGER,
                'description' => 'Branch operations management',
                'branch_access' => 'selected_branches',
                'is_system' => true,
            ],
            [
                'name' => 'Cashier',
                'code' => self::CASHIER,
                'description' => 'Point of sale operations',
                'branch_access' => 'selected_branches',
                'is_system' => true,
            ],
            [
                'name' => 'Accountant',
                'code' => self::ACCOUNTANT,
                'description' => 'Accounting and financial reports',
                'branch_access' => 'all_branches',
                'is_system' => true,
            ],
            [
                'name' => 'Stock Staff',
                'code' => self::STOCK,
                'description' => 'Inventory visibility',
                'branch_access' => 'selected_branches',
                'is_system' => true,
            ],
            [
                'name' => 'Purchase Staff',
                'code' => self::PURCHASE,
                'description' => 'Purchasing operations',
                'branch_access' => 'selected_branches',
                'is_system' => true,
            ],
        ];
    }

    public static function isPrivilegedSystemRole(string $code): bool
    {
        return in_array($code, [self::OWNER, self::ADMIN], true);
    }
}
