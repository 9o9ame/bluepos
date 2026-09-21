<?php

namespace Tests\Feature;

use App\Enums\PlatformUserStatus;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\PlatformPermission;
use App\Models\Platform\PlatformRole;
use App\Models\Platform\PlatformSession;
use App\Models\Tenant;
use App\Platform\PlatformCatalogSync;
use App\Platform\PlatformPermissionCatalogue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformAccessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_super_admin_system_role_exists_and_receives_catalogue(): void
    {
        $this->signInPlatformAdmin('sa-cat');
        app(PlatformCatalogSync::class)->ensure();

        $role = PlatformRole::query()->where('code', PlatformPermissionCatalogue::SUPER_ADMIN)->first();
        $this->assertNotNull($role);
        $this->assertTrue((bool) $role->is_system);
        $this->assertTrue((bool) $role->is_active);
        $this->assertEqualsCanonicalizing(
            PlatformPermissionCatalogue::keys(),
            $role->permissions()->pluck('platform_permissions.key')->all()
        );
    }

    public function test_permission_catalogue_sync_grants_new_keys_to_super_admin(): void
    {
        $this->signInPlatformAdmin('sa-sync');
        $role = PlatformRole::query()->where('code', PlatformPermissionCatalogue::SUPER_ADMIN)->firstOrFail();
        $permission = PlatformPermission::query()->where('key', 'platform.settings.manage')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->assertFalse($role->fresh()?->permissions()->where('platform_permissions.key', 'platform.settings.manage')->exists());

        app(PlatformCatalogSync::class)->ensure();

        $this->assertTrue($role->fresh()?->permissions()->where('platform_permissions.key', 'platform.settings.manage')->exists());
    }

    public function test_super_admin_role_cannot_be_deleted_or_deactivated(): void
    {
        $this->signInPlatformAdmin('sa-protect');
        $role = PlatformRole::query()->where('code', PlatformPermissionCatalogue::SUPER_ADMIN)->firstOrFail();

        $this->deleteJson('/api/platform/roles/'.$role->ulid)
            ->assertForbidden()
            ->assertJsonPath('error.key', 'SYSTEM_ROLE_PROTECTED');
        $this->patchJson('/api/platform/roles/'.$role->ulid, ['is_active' => false])
            ->assertForbidden()
            ->assertJsonPath('error.key', 'SYSTEM_ROLE_PROTECTED');
        $this->putJson('/api/platform/roles/'.$role->ulid.'/permissions', ['permissions' => ['platform.dashboard.view']])
            ->assertForbidden()
            ->assertJsonPath('error.key', 'SYSTEM_ROLE_PROTECTED');
    }

    public function test_second_super_admin_may_be_deactivated_while_one_remains(): void
    {
        $first = $this->signInPlatformAdmin('sa-keep');
        $superUlid = PlatformRole::query()->where('code', PlatformPermissionCatalogue::SUPER_ADMIN)->value('ulid');

        $created = $this->postJson('/api/platform/users', [
            'name' => 'Second Super',
            'email' => 'second-super@example.com',
            'role_ulids' => [$superUlid],
            'reason' => 'Need a backup Super Admin',
        ])->assertCreated();

        $this->assertTrue(
            PlatformAuditLog::query()->where('event', 'PLATFORM_SUPER_ADMIN_GRANTED')->where('resource_ulid', $created->json('ulid'))->exists()
        );

        $this->postJson('/api/platform/users/'.$created->json('ulid').'/deactivate')
            ->assertOk()
            ->assertJsonPath('status', 'inactive');

        $this->postJson('/api/platform/users/'.$first->ulid.'/deactivate')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'FINAL_PLATFORM_ADMIN_REQUIRED');
    }

    public function test_assigning_super_admin_requires_recent_mfa_and_reason(): void
    {
        $this->signInPlatformAdmin('sa-mfa');
        $superUlid = PlatformRole::query()->where('code', PlatformPermissionCatalogue::SUPER_ADMIN)->value('ulid');
        $staff = $this->createPlatformStaff('elevate', ['platform.dashboard.view']);

        $this->putJson('/api/platform/users/'.$staff->ulid.'/roles', [
            'role_ulids' => [$superUlid],
        ])->assertUnprocessable();

        $this->travel(31)->minutes();

        $this->putJson('/api/platform/users/'.$staff->ulid.'/roles', [
            'role_ulids' => [$superUlid],
            'reason' => 'Need emergency Super Admin',
        ])->assertForbidden()->assertJsonPath('error.key', 'MFA_REQUIRED');
    }

    public function test_support_user_cannot_assign_super_admin(): void
    {
        $this->createPlatformAdmin('sa-base');
        $support = $this->createPlatformStaff('support', [
            'platform.dashboard.view',
            'platform.users.view',
            'platform.users.edit',
            'platform.users.assign_roles',
            'platform.tenants.view',
        ]);
        $this->signInPlatformUser($support);
        $superUlid = PlatformRole::query()->where('code', PlatformPermissionCatalogue::SUPER_ADMIN)->value('ulid');
        $target = $this->createPlatformStaff('target', ['platform.dashboard.view']);

        $this->putJson('/api/platform/users/'.$target->ulid.'/roles', [
            'role_ulids' => [$superUlid],
            'reason' => 'Trying to escalate',
        ])->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
    }

    public function test_user_lacking_manage_permissions_cannot_modify_role_permissions(): void
    {
        $this->createPlatformAdmin('sa-roles');
        $staff = $this->createPlatformStaff('role-edit', [
            'platform.roles.view',
            'platform.roles.edit',
        ]);
        $this->signInPlatformUser($staff);
        $role = PlatformRole::query()->where('code', $staff->roles->first()?->code)->firstOrFail();

        $this->putJson('/api/platform/roles/'.$role->ulid.'/permissions', [
            'permissions' => ['platform.dashboard.view'],
        ])->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
    }

    public function test_user_lacking_assign_roles_cannot_change_user_roles(): void
    {
        $this->createPlatformAdmin('sa-norole');
        $staff = $this->createPlatformStaff('no-assign', [
            'platform.users.view',
            'platform.users.edit',
        ]);
        $this->signInPlatformUser($staff);
        $target = $this->createPlatformStaff('no-assign-t', ['platform.dashboard.view']);

        $this->putJson('/api/platform/users/'.$target->ulid.'/roles', [
            'role_ulids' => [$staff->roles->first()?->ulid],
        ])->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
    }

    public function test_tenant_admin_cannot_access_platform_role_apis(): void
    {
        $this->signInOwner('plat-roles')->assertOk();

        $this->getJson('/api/platform/roles')->assertUnauthorized();
        $this->getJson('/api/platform/permissions')->assertUnauthorized();
        $this->postJson('/api/platform/roles', ['code' => 'SUPPORT', 'name' => 'Support'])->assertUnauthorized();
    }

    public function test_invalid_permission_is_rejected_atomically(): void
    {
        $this->signInPlatformAdmin('sa-invperm');
        $role = $this->postJson('/api/platform/roles', [
            'code' => 'SUPPORT',
            'name' => 'Support',
            'description' => 'Tenant support',
        ])->assertCreated();

        $this->putJson('/api/platform/roles/'.$role->json('ulid').'/permissions', [
            'permissions' => ['platform.dashboard.view', 'platform.anything.i.want'],
        ])->assertUnprocessable();

        $fresh = PlatformRole::query()->where('ulid', $role->json('ulid'))->firstOrFail();
        $this->assertSame([], $fresh->permissions()->pluck('platform_permissions.key')->all());
    }

    public function test_platform_user_cannot_assign_nonexistent_role(): void
    {
        $this->signInPlatformAdmin('sa-bogus');
        $target = $this->createPlatformStaff('bogus-t', ['platform.dashboard.view']);

        $this->putJson('/api/platform/users/'.$target->ulid.'/roles', [
            'role_ulids' => [(string) Str::ulid()],
        ])->assertUnprocessable();
    }

    public function test_custom_role_lifecycle_and_system_protection(): void
    {
        $this->signInPlatformAdmin('sa-custom');

        $created = $this->postJson('/api/platform/roles', [
            'code' => 'billing',
            'name' => 'Billing',
        ])->assertCreated()->assertJsonPath('code', 'BILLING')->assertJsonPath('type', 'custom');
        $this->assertNoInternalIds($created->json());
        $this->assertTrue(PlatformAuditLog::query()->where('event', 'PLATFORM_ROLE_CREATED')->where('resource_ulid', $created->json('ulid'))->exists());

        $this->patchJson('/api/platform/roles/'.$created->json('ulid'), [
            'name' => 'Billing Staff',
            'description' => 'Plans and subscriptions',
        ])->assertOk()->assertJsonPath('name', 'Billing Staff');

        $this->putJson('/api/platform/roles/'.$created->json('ulid').'/permissions', [
            'permissions' => ['platform.dashboard.view', 'platform.plans.view', 'platform.subscriptions.view'],
        ])->assertOk();
        $this->assertTrue(PlatformAuditLog::query()->where('event', 'PLATFORM_ROLE_PERMISSIONS_CHANGED')->exists());

        $this->putJson('/api/platform/roles/'.$created->json('ulid').'/permissions', [
            'permissions' => ['platform.plans.view'],
        ])->assertOk();
        $keys = PlatformRole::query()->where('ulid', $created->json('ulid'))->firstOrFail()->permissions()->pluck('platform_permissions.key')->all();
        $this->assertSame(['platform.plans.view'], $keys);

        $this->patchJson('/api/platform/roles/'.$created->json('ulid'), ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);
        $this->assertTrue(PlatformAuditLog::query()->where('event', 'PLATFORM_ROLE_DEACTIVATED')->exists());

        $this->patchJson('/api/platform/roles/'.$created->json('ulid'), ['is_active' => true])->assertOk();
        $this->deleteJson('/api/platform/roles/'.$created->json('ulid'))->assertOk();
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $this->signInPlatformAdmin('sa-inuse');
        $staff = $this->createPlatformStaff('inuse', ['platform.dashboard.view']);
        $roleUlid = $staff->roles->first()?->ulid;

        $this->deleteJson('/api/platform/roles/'.$roleUlid)
            ->assertStatus(409)
            ->assertJsonPath('error.key', 'ROLE_IN_USE');
    }

    public function test_multiple_roles_union_permissions(): void
    {
        $this->signInPlatformAdmin('sa-union');
        $support = $this->postJson('/api/platform/roles', ['code' => 'SUPPORT', 'name' => 'Support'])->assertCreated();
        $audit = $this->postJson('/api/platform/roles', ['code' => 'AUDIT_VIEWER', 'name' => 'Audit viewer'])->assertCreated();

        $this->putJson('/api/platform/roles/'.$support->json('ulid').'/permissions', [
            'permissions' => ['platform.dashboard.view', 'platform.tenants.view'],
        ])->assertOk();
        $this->putJson('/api/platform/roles/'.$audit->json('ulid').'/permissions', [
            'permissions' => ['platform.audit.view'],
        ])->assertOk();

        $user = $this->postJson('/api/platform/users', [
            'name' => 'Ali',
            'email' => 'ali-union@example.com',
            'role_ulids' => [$support->json('ulid'), $audit->json('ulid')],
        ])->assertCreated();

        $permissions = $user->json('permissions');
        $this->assertContains('platform.dashboard.view', $permissions);
        $this->assertContains('platform.tenants.view', $permissions);
        $this->assertContains('platform.audit.view', $permissions);
        $this->assertNotContains('platform.tenants.suspend', $permissions);

        $this->putJson('/api/platform/users/'.$user->json('ulid').'/roles', [
            'role_ulids' => [$audit->json('ulid')],
        ])->assertOk();

        $remaining = $this->getJson('/api/platform/users/'.$user->json('ulid'))->assertOk()->json('permissions');
        $this->assertSame(['platform.audit.view'], $remaining);
        $this->assertTrue(PlatformAuditLog::query()->where('event', 'PLATFORM_USER_ROLE_REMOVED')->exists());
    }

    public function test_creating_platform_user_does_not_default_to_super_admin(): void
    {
        $this->signInPlatformAdmin('sa-nodef');
        $created = $this->postJson('/api/platform/users', [
            'name' => 'Ops',
            'email' => 'ops-none@example.com',
        ])->assertCreated();

        $this->assertSame([], $created->json('roles'));
        $this->assertNotEmpty($created->json('temporary_password'));
        $this->assertTrue($created->json('must_change_password'));

        $this->getJson('/api/platform/users/'.$created->json('ulid'))
            ->assertOk()
            ->assertJsonMissingPath('temporary_password');
    }

    public function test_change_own_password_revokes_other_sessions_and_audits(): void
    {
        $user = $this->signInPlatformAdmin('sa-pw');
        $other = PlatformSession::query()->create([
            'platform_user_id' => $user->id,
            'security_version' => $user->security_version,
            'ip_address' => '10.1.1.8',
            'user_agent' => 'OtherBrowser/1.0',
            'last_seen_at' => now(),
        ]);

        $this->postJson('/api/platform/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-platform-pass',
            'password_confirmation' => 'new-platform-pass',
        ])->assertUnauthorized();

        $this->postJson('/api/platform/auth/change-password', [
            'current_password' => 'platform-pass-123',
            'password' => 'new-platform-pass',
            'password_confirmation' => 'new-platform-pass',
        ])->assertOk();

        $this->assertNotNull($other->fresh()?->revoked_at);
        $this->getJson('/api/platform/auth/me')->assertOk();
        $this->assertTrue(
            PlatformAuditLog::query()->where('event', 'PLATFORM_PASSWORD_CHANGED')->where('resource_ulid', $user->ulid)->exists()
        );
    }

    public function test_active_sessions_list_is_safe_and_revoke_works(): void
    {
        $user = $this->signInPlatformAdmin('sa-sess');
        $other = PlatformSession::query()->create([
            'platform_user_id' => $user->id,
            'security_version' => $user->security_version,
            'ip_address' => '10.2.2.2',
            'user_agent' => 'Tablet/1.0',
            'last_seen_at' => now(),
        ]);

        $list = $this->getJson('/api/platform/auth/sessions')->assertOk()->json();
        $this->assertIsArray($list);
        foreach ($list as $row) {
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayNotHasKey('laravel_session_id', $row);
            $this->assertArrayHasKey('ulid', $row);
            $this->assertArrayHasKey('current', $row);
        }

        $this->deleteJson('/api/platform/auth/sessions/'.$other->ulid)->assertOk();
        $this->assertNotNull($other->fresh()?->revoked_at);

        $this->deleteJson('/api/platform/auth/sessions/others')->assertOk();
        $this->getJson('/api/platform/auth/me')->assertOk();
    }

    public function test_tenant_create_returns_temporary_password_once(): void
    {
        $this->signInPlatformAdmin('sa-tpw');
        app(PlatformCatalogSync::class)->ensure();
        $plan = Plan::query()->where('code', 'STARTER')->firstOrFail();

        $created = $this->postJson('/api/platform/tenants', [
            'tenant_name' => 'Alpha Mart',
            'tenant_code' => $this->tenantCode('alpha-pw'),
            'legal_name' => 'Alpha Mart LLC',
            'recovery_email' => 'owner-alpha-pw@example.com',
            'admin_name' => 'Owner Alpha',
            'admin_username' => 'owner',
            'timezone' => 'Asia/Karachi',
            'currency_code' => 'PKR',
            'plan_ulid' => $plan->ulid,
            'status' => 'active',
        ])->assertCreated();

        $password = $created->json('initial_admin.temporary_password');
        $this->assertNotEmpty($password);
        $this->assertTrue($created->json('initial_admin.must_change_password'));

        $show = $this->getJson('/api/platform/tenants/'.$created->json('ulid'))->assertOk();
        $this->assertArrayNotHasKey('temporary_password', $show->json());
        $this->assertArrayNotHasKey('initial_admin', $show->json());
        $encoded = json_encode($show->json()) ?: '';
        $this->assertStringNotContainsString($password, $encoded);

        $tenant = Tenant::query()->where('ulid', $created->json('ulid'))->firstOrFail();
        $membership = Membership::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertTrue((bool) $membership->user?->must_change_password);
        $this->assertTrue(Hash::check($password, $membership->user?->password));

        $audit = PlatformAuditLog::query()->where('event', 'TENANT_CREATED')->where('resource_ulid', $tenant->ulid)->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($password, json_encode($audit->metadata) ?: '');
    }

    public function test_tenant_admin_first_login_requires_password_change(): void
    {
        $this->signInPlatformAdmin('sa-first');
        app(PlatformCatalogSync::class)->ensure();
        $plan = Plan::query()->where('code', 'STARTER')->firstOrFail();
        $created = $this->postJson('/api/platform/tenants', [
            'tenant_name' => 'Beta Mart',
            'tenant_code' => $this->tenantCode('beta-pw'),
            'recovery_email' => 'owner-beta-pw@example.com',
            'admin_name' => 'Owner Beta',
            'admin_username' => 'owner',
            'timezone' => 'Asia/Karachi',
            'currency_code' => 'PKR',
            'plan_ulid' => $plan->ulid,
            'status' => 'active',
        ])->assertCreated();
        $password = $created->json('initial_admin.temporary_password');
        $tenant = Tenant::query()->where('ulid', $created->json('ulid'))->firstOrFail();

        $this->postJson('/api/platform/auth/logout')->assertOk();
        $this->attachTrustedDevice((int) $tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $tenant->code,
            'username' => 'owner',
            'password' => $password,
        ])->assertOk()->assertJsonPath('must_change_password', true);

        $this->getJson('/api/roles')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_reset_admin_access_issues_new_password_and_revokes_sessions(): void
    {
        $this->signInPlatformAdmin('sa-reset');
        app(PlatformCatalogSync::class)->ensure();
        $plan = Plan::query()->where('code', 'STARTER')->firstOrFail();
        $created = $this->postJson('/api/platform/tenants', [
            'tenant_name' => 'Gamma Mart',
            'tenant_code' => $this->tenantCode('gamma-pw'),
            'recovery_email' => 'owner-gamma-pw@example.com',
            'admin_name' => 'Owner Gamma',
            'admin_username' => 'owner',
            'timezone' => 'Asia/Karachi',
            'currency_code' => 'PKR',
            'plan_ulid' => $plan->ulid,
            'status' => 'active',
        ])->assertCreated();
        $oldPassword = $created->json('initial_admin.temporary_password');
        $tenant = Tenant::query()->where('ulid', $created->json('ulid'))->firstOrFail();
        $membership = Membership::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $membership->user->must_change_password = false;
        $membership->user->password = 'password123';
        $membership->user->save();

        $this->postJson('/api/platform/auth/logout')->assertOk();
        $this->attachTrustedDevice((int) $tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $tenant->code,
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();
        $this->getJson('/api/auth/me')->assertOk();

        $this->signInPlatformAdmin('sa-reset');
        $reset = $this->postJson('/api/platform/tenants/'.$tenant->ulid.'/admins/reset', [
            'membership_ulid' => $membership->ulid,
        ])->assertOk();
        $newPassword = $reset->json('temporary_password');
        $this->assertNotEmpty($newPassword);
        $this->assertNotSame($oldPassword, $newPassword);
        $this->assertTrue(
            PlatformAuditLog::query()->where('event', 'TENANT_ADMIN_RESET')->where('resource_ulid', $membership->ulid)->exists()
        );

        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/platform/auth/logout')->assertOk();
        $this->attachTrustedDevice((int) $tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $tenant->code,
            'username' => 'owner',
            'password' => 'password123',
        ])->assertUnauthorized();
    }

    public function test_permissions_catalogue_is_read_only(): void
    {
        $this->signInPlatformAdmin('sa-pcat');
        $this->getJson('/api/platform/permissions')->assertOk();
        $this->postJson('/api/platform/permissions', ['key' => 'platform.anything.i.want'])->assertMethodNotAllowed();
    }

    public function test_platform_user_status_uses_inactive_not_scattered_bypass(): void
    {
        $this->signInPlatformAdmin('sa-status');
        $staff = $this->createPlatformStaff('statusu', ['platform.dashboard.view']);

        $this->postJson('/api/platform/users/'.$staff->ulid.'/deactivate')->assertOk()->assertJsonPath('status', PlatformUserStatus::Inactive->value);
        $this->assertTrue(PlatformAuditLog::query()->where('event', 'PLATFORM_USER_DEACTIVATED')->exists());
        $this->postJson('/api/platform/users/'.$staff->ulid.'/activate')->assertOk()->assertJsonPath('status', 'active');
        $this->assertTrue(PlatformAuditLog::query()->where('event', 'PLATFORM_USER_ACTIVATED')->exists());
    }

    public function test_stale_mfa_on_tenant_create_issues_email_challenge(): void
    {
        $this->signInPlatformAdmin('sa-mfa-create');
        app(PlatformCatalogSync::class)->ensure();
        $plan = Plan::query()->where('code', 'STARTER')->firstOrFail();
        $this->travel(31)->minutes();

        $response = $this->postJson('/api/platform/tenants', [
            'tenant_name' => 'Mfa Mart',
            'tenant_code' => $this->tenantCode('mfa-mart'),
            'recovery_email' => 'owner-mfa-mart@example.com',
            'admin_name' => 'Owner',
            'admin_username' => 'owner',
            'timezone' => 'Asia/Karachi',
            'currency_code' => 'PKR',
            'plan_ulid' => $plan->ulid,
            'status' => 'active',
        ]);
        $response->assertForbidden()
            ->assertJsonPath('error.key', 'MFA_REQUIRED')
            ->assertJsonStructure(['error' => ['key', 'message', 'challenge_ulid']]);

        $codes = [];
        \Illuminate\Support\Facades\Notification::assertSentOnDemand(
            \App\Notifications\SecurityCodeNotification::class,
            function ($notification) use (&$codes): bool {
                $codes[] = $notification->code;

                return true;
            },
        );
        $code = $codes[array_key_last($codes)] ?? null;

        $this->postJson('/api/platform/auth/mfa/confirm', [
            'challenge_ulid' => $response->json('error.challenge_ulid'),
            'code' => $code,
        ])->assertOk();

        $this->postJson('/api/platform/tenants', [
            'tenant_name' => 'Mfa Mart',
            'tenant_code' => $this->tenantCode('mfa-mart'),
            'recovery_email' => 'owner-mfa-mart@example.com',
            'admin_name' => 'Owner',
            'admin_username' => 'owner',
            'timezone' => 'Asia/Karachi',
            'currency_code' => 'PKR',
            'plan_ulid' => $plan->ulid,
            'status' => 'active',
        ])->assertCreated();
    }

    public function test_platform_can_edit_and_cancel_tenant(): void
    {
        $this->signInPlatformAdmin('sa-edit-del');
        app(PlatformCatalogSync::class)->ensure();
        $plan = Plan::query()->where('code', 'STARTER')->firstOrFail();
        $created = $this->postJson('/api/platform/tenants', [
            'tenant_name' => 'Edit Mart',
            'tenant_code' => $this->tenantCode('edit-del'),
            'legal_name' => 'Edit Mart LLC',
            'recovery_email' => 'owner-edit-del@example.com',
            'admin_name' => 'Owner',
            'admin_username' => 'owner',
            'timezone' => 'Asia/Karachi',
            'currency_code' => 'PKR',
            'plan_ulid' => $plan->ulid,
            'status' => 'active',
        ])->assertCreated();

        $this->patchJson('/api/platform/tenants/'.$created->json('ulid'), [
            'tenant_name' => 'Edited Mart',
            'legal_name' => 'Edited Mart LLC',
        ])->assertOk()
            ->assertJsonPath('name', 'Edited Mart')
            ->assertJsonPath('legal_name', 'Edited Mart LLC');

        $this->deleteJson('/api/platform/tenants/'.$created->json('ulid'), [
            'reason' => 'No longer needed',
        ])->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertTrue(
            PlatformAuditLog::query()->where('event', 'TENANT_CANCELLED')->where('resource_ulid', $created->json('ulid'))->exists()
        );
        $this->postJson('/api/platform/tenants/'.$created->json('ulid').'/activate')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'TENANT_DISABLED');
    }
}
