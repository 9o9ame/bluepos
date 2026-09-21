<?php

namespace App\Platform;

final class PlatformPermissionCatalogue
{
    public const SUPER_ADMIN = 'super_admin';

    /**
     * @return list<array{key: string, name: string, module: string, description: ?string}>
     */
    public static function definitions(): array
    {
        return [
            ['platform.dashboard.view', 'View platform dashboard', 'dashboard', null],
            ['platform.tenants.view', 'View tenants', 'tenants', null],
            ['platform.tenants.create', 'Create tenants', 'tenants', null],
            ['platform.tenants.edit', 'Edit tenants', 'tenants', null],
            ['platform.tenants.activate', 'Activate tenants', 'tenants', null],
            ['platform.tenants.suspend', 'Suspend tenants', 'tenants', null],
            ['platform.tenants.create_admin', 'Create tenant admins', 'tenants', null],
            ['platform.tenants.reset_admin_access', 'Reset tenant admin access', 'tenants', null],
            ['platform.plans.view', 'View plans', 'plans', null],
            ['platform.plans.create', 'Create plans', 'plans', null],
            ['platform.plans.edit', 'Edit plans', 'plans', null],
            ['platform.subscriptions.view', 'View subscriptions', 'subscriptions', null],
            ['platform.subscriptions.assign', 'Assign subscriptions', 'subscriptions', null],
            ['platform.subscriptions.change', 'Change subscriptions', 'subscriptions', null],
            ['platform.features.view', 'View features', 'features', null],
            ['platform.features.manage', 'Manage feature overrides', 'features', null],
            ['platform.limits.manage', 'Manage tenant limits', 'limits', null],
            ['platform.security.view', 'View platform security', 'security', null],
            ['platform.audit.view', 'View platform audit', 'audit', null],
            ['platform.admins.view', 'View platform admins', 'admins', null],
            ['platform.admins.manage', 'Manage platform admins', 'admins', null],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (array $row): string => $row[0], self::definitions());
    }
}
