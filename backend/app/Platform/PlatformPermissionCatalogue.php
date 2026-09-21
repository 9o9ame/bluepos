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
            ['platform.tenants.delete', 'Cancel tenants', 'tenants', 'Cancels a tenant. Posted history is retained.'],
            ['platform.tenants.reset_admin', 'Reset tenant administrator access', 'tenants', null],
            ['platform.tenants.reset_admin_access', 'Reset tenant administrator access', 'tenants', 'Compatibility key for tenant admin reset'],
            ['platform.tenants.create_admin', 'Create tenant admins', 'tenants', null],
            ['platform.plans.view', 'View plans', 'plans', null],
            ['platform.plans.create', 'Create plans', 'plans', null],
            ['platform.plans.edit', 'Edit plans', 'plans', null],
            ['platform.plans.activate', 'Activate plans', 'plans', null],
            ['platform.features.view', 'View features', 'features', null],
            ['platform.features.manage', 'Manage feature overrides', 'features', null],
            ['platform.limits.view', 'View limits', 'limits', null],
            ['platform.limits.manage', 'Manage tenant limits', 'limits', null],
            ['platform.subscriptions.view', 'View subscriptions', 'subscriptions', null],
            ['platform.subscriptions.assign', 'Assign subscriptions', 'subscriptions', null],
            ['platform.subscriptions.change', 'Change subscriptions', 'subscriptions', null],
            ['platform.users.view', 'View platform users', 'users', null],
            ['platform.users.create', 'Create platform users', 'users', null],
            ['platform.users.edit', 'Edit platform users', 'users', null],
            ['platform.users.activate', 'Activate platform users', 'users', null],
            ['platform.users.deactivate', 'Deactivate platform users', 'users', null],
            ['platform.users.assign_roles', 'Assign platform user roles', 'users', null],
            ['platform.users.force_logout', 'Force logout platform users', 'users', null],
            ['platform.users.reset_password', 'Reset platform user passwords', 'users', null],
            ['platform.admins.view', 'View platform users', 'users', 'Compatibility key for platform user list'],
            ['platform.admins.manage', 'Manage platform users', 'users', 'Compatibility key for platform user mutations'],
            ['platform.roles.view', 'View platform roles', 'roles', null],
            ['platform.roles.create', 'Create platform roles', 'roles', null],
            ['platform.roles.edit', 'Edit platform roles', 'roles', null],
            ['platform.roles.delete', 'Delete platform roles', 'roles', null],
            ['platform.roles.manage_permissions', 'Manage platform role permissions', 'roles', null],
            ['platform.permissions.view', 'View platform permission catalogue', 'roles', null],
            ['platform.audit.view', 'View platform audit', 'audit', null],
            ['platform.security.view', 'View platform security', 'security', null],
            ['platform.security.sessions.view', 'View platform sessions', 'security', null],
            ['platform.security.sessions.revoke', 'Revoke platform sessions', 'security', null],
            ['platform.security.devices.view', 'View platform devices', 'security', null],
            ['platform.security.devices.revoke', 'Revoke platform devices', 'security', null],
            ['platform.settings.view', 'View platform settings', 'settings', null],
            ['platform.settings.manage', 'Manage platform settings', 'settings', null],
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

    /**
     * @return list<string>
     */
    public static function modules(): array
    {
        return array_values(array_unique(array_map(static fn (array $row): string => $row[2], self::definitions())));
    }
}
