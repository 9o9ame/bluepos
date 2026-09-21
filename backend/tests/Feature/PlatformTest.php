<?php

namespace Tests\Feature;

use App\Authz\PermissionCatalogue;
use App\Enums\DeviceStatus;
use App\Enums\PlanStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Membership;
use App\Models\Plan;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\PlatformMfaChallenge;
use App\Models\Platform\PlatformUser;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Notifications\SecurityCodeNotification;
use App\Platform\FeatureCatalogue;
use App\Platform\LimitCatalogue;
use App\Platform\PlatformCatalogSync;
use App\Security\OfflineAuthorizationService;
use App\Security\TenantEntitlementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use DatabaseTransactions;

    public function test_no_public_super_admin_registration(): void
    {
        $this->postJson('/api/platform/auth/register', [
            'email' => 'new-admin@example.com',
            'password' => 'password123',
        ])->assertNotFound();

        $this->postJson('/api/platform/register', [
            'email' => 'new-admin@example.com',
            'password' => 'password123',
        ])->assertNotFound();
    }

    public function test_valid_platform_password_requires_mfa(): void
    {
        $this->createPlatformAdmin('mfa-req');

        $this->postJson('/api/platform/auth/login', [
            'email' => 'platform-mfa-req@example.com',
            'password' => 'platform-pass-123',
        ])->assertForbidden()
            ->assertJsonPath('error.key', 'MFA_REQUIRED')
            ->assertJsonStructure(['error' => ['key', 'message', 'challenge_ulid']]);
    }

    public function test_platform_mfa_can_be_resent(): void
    {
        $this->createPlatformAdmin('mfa-rs');
        Notification::fake();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => 'platform-mfa-rs@example.com',
            'password' => 'platform-pass-123',
        ]);
        $oldUlid = $login->json('error.challenge_ulid');

        $resend = $this->postJson('/api/platform/auth/mfa/resend', [
            'challenge_ulid' => $oldUlid,
        ]);
        $resend->assertForbidden()->assertJsonPath('error.key', 'MFA_REQUIRED');
        $newUlid = $resend->json('error.challenge_ulid');
        $this->assertNotSame($oldUlid, $newUlid);

        $codes = [];
        Notification::assertSentOnDemand(SecurityCodeNotification::class, function ($notification) use (&$codes): bool {
            $codes[] = $notification->code;

            return true;
        });
        $this->assertNotEmpty($codes);
        $code = (string) end($codes);

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $oldUlid,
            'code' => $code,
        ])->assertForbidden()->assertJsonPath('error.key', 'MFA_INVALID');

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $newUlid,
            'code' => $code,
        ])->assertOk();
    }

    public function test_valid_mfa_creates_platform_session(): void
    {
        $user = $this->signInPlatformAdmin('mfa-ok');

        $me = $this->getJson('/api/platform/auth/me')->assertOk();
        $me->assertJsonPath('email', $user->email)
            ->assertJsonPath('ulid', $user->ulid);
        $this->assertContains('platform.tenants.create', $me->json('permissions'));
        $this->assertNoInternalIds($me->json());
    }

    public function test_invalid_mfa_is_rejected(): void
    {
        $this->createPlatformAdmin('mfa-bad');
        Notification::fake();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => 'platform-mfa-bad@example.com',
            'password' => 'platform-pass-123',
        ]);
        $challengeUlid = $login->json('error.challenge_ulid');

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $challengeUlid,
            'code' => '000000',
        ])->assertForbidden()->assertJsonPath('error.key', 'MFA_INVALID');
    }

    public function test_expired_mfa_is_rejected(): void
    {
        $this->createPlatformAdmin('mfa-exp');
        Notification::fake();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => 'platform-mfa-exp@example.com',
            'password' => 'platform-pass-123',
        ]);
        $challengeUlid = $login->json('error.challenge_ulid');

        PlatformMfaChallenge::query()->where('ulid', $challengeUlid)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $code = null;
        Notification::assertSentOnDemand(SecurityCodeNotification::class, function ($notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $challengeUlid,
            'code' => $code,
        ])->assertForbidden()->assertJsonPath('error.key', 'MFA_EXPIRED');
    }

    public function test_tenant_session_cannot_access_platform_apis(): void
    {
        $this->signInOwner('plat-deny')->assertOk();

        $this->getJson('/api/platform/tenants')
            ->assertUnauthorized()
            ->assertJsonPath('error.key', 'UNAUTHORIZED');
        $this->getJson('/api/platform/auth/me')
            ->assertUnauthorized();
    }

    public function test_platform_session_is_not_a_tenant_membership_session(): void
    {
        $this->signInPlatformAdmin('plat-not-tenant');

        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.key', 'UNAUTHORIZED');
        $this->getJson('/api/memberships')
            ->assertUnauthorized();
    }

    public function test_unauthorized_platform_admin_permission_denied(): void
    {
        app(PlatformCatalogSync::class)->ensure();
        $user = PlatformUser::query()->create([
            'name' => 'Limited',
            'email' => 'limited-plat@example.com',
            'password' => 'platform-pass-123',
            'status' => 'active',
            'must_change_password' => false,
            'security_version' => 1,
        ]);

        Notification::fake();
        $login = $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
        ]);
        $code = null;
        Notification::assertSentOnDemand(SecurityCodeNotification::class, function ($notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });
        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $login->json('error.challenge_ulid'),
            'code' => $code,
        ])->assertOk();

        $this->getJson('/api/platform/tenants')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'FORBIDDEN');
    }

    public function test_platform_permissions_are_separate_from_tenant_permissions(): void
    {
        $this->signInOwner('perm-sep')->assertOk();
        $keys = collect($this->getJson('/api/permissions')->assertOk()->json())->pluck('key');
        $this->assertFalse($keys->contains('platform.tenants.create'));
        $this->assertFalse($keys->contains('platform.admins.manage'));
    }

    public function test_final_platform_super_admin_cannot_be_deactivated(): void
    {
        $user = $this->signInPlatformAdmin('final-sa');

        $this->postJson('/api/platform/admins/'.$user->ulid.'/deactivate')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'FINAL_PLATFORM_ADMIN_REQUIRED');
    }

    public function test_super_admin_creates_tenant_with_defaults(): void
    {
        $this->signInPlatformAdmin('create-t');
        $plan = $this->starterPlan();

        $response = $this->postJson('/api/platform/tenants', $this->tenantPayload('created', $plan->ulid));
        $response->assertCreated()
            ->assertJsonPath('code', $this->tenantCode('created'))
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('initial_admin.username', 'owner')
            ->assertJsonPath('admin_username', 'owner')
            ->assertJsonPath('initial_admin.must_change_password', true);
        $this->assertNotEmpty($response->json('initial_admin.temporary_password'));
        $this->assertNoInternalIds($response->json());

        $tenant = Tenant::query()->where('ulid', $response->json('ulid'))->firstOrFail();
        $this->assertTrue(Membership::query()->where('tenant_id', $tenant->id)->where('username', 'owner')->exists());
        $this->assertTrue(
            Membership::query()->where('tenant_id', $tenant->id)->first()?->user?->must_change_password
        );
        $this->assertTrue(Branch::query()->where('tenant_id', $tenant->id)->where('code', 'MAIN')->exists());
        $this->assertTrue(Warehouse::query()->where('tenant_id', $tenant->id)->where('code', 'MAIN')->exists());
        $this->assertSame(7, Role::query()->where('tenant_id', $tenant->id)->where('is_system', true)->count());
        $this->assertSame('STARTER', $tenant->subscription?->plan?->code);
        $this->assertTrue(
            Membership::query()->where('tenant_id', $tenant->id)->first()?->hasRoleCode(PermissionCatalogue::OWNER)
        );
        $this->getJson('/api/platform/tenants')
            ->assertOk()
            ->assertJsonFragment(['ulid' => $response->json('ulid'), 'admin_username' => 'owner']);
    }

    public function test_tenant_code_must_be_unique(): void
    {
        $this->signInPlatformAdmin('uniq-t');
        $plan = $this->starterPlan();
        $this->postJson('/api/platform/tenants', $this->tenantPayload('dupcode', $plan->ulid))->assertCreated();
        $this->postJson('/api/platform/tenants', $this->tenantPayload('dupcode', $plan->ulid))
            ->assertUnprocessable();
    }

    public function test_recovery_email_must_be_unique_across_tenants(): void
    {
        $this->signInPlatformAdmin('uniq-email');
        $plan = $this->starterPlan();
        $first = $this->tenantPayload('email-a', $plan->ulid);
        $second = $this->tenantPayload('email-b', $plan->ulid);
        $second['recovery_email'] = $first['recovery_email'];

        $this->postJson('/api/platform/tenants', $first)->assertCreated();
        $this->postJson('/api/platform/tenants', $second)
            ->assertUnprocessable()
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonPath('error.message', 'This recovery email is already used by another account. Use a different email for this tenant.');
        $this->assertNull(Tenant::query()->where('code', $this->tenantCode('email-b'))->first());
    }

    public function test_provisioning_rollback_works(): void
    {
        $this->signInPlatformAdmin('rollback');
        $plan = $this->starterPlan();
        app()->instance('registration.force_failure', true);

        $this->postJson('/api/platform/tenants', $this->tenantPayload('rolled', $plan->ulid))
            ->assertStatus(500);

        $this->assertNull(Tenant::query()->where('code', $this->tenantCode('rolled'))->first());
        $this->assertFalse(
            Membership::query()->where('username', 'owner')->whereHas('tenant', function ($query): void {
                $query->where('code', $this->tenantCode('rolled'));
            })->exists()
        );
    }

    public function test_super_admin_suspends_tenant_and_blocks_access(): void
    {
        $this->signInOwner('suspend-me')->assertOk();
        $tenant = Tenant::query()->where('code', $this->tenantCode('suspend-me'))->firstOrFail();
        $this->getJson('/api/auth/me')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();

        $this->signInPlatformAdmin('suspender');
        $this->postJson('/api/platform/tenants/'.$tenant->ulid.'/suspend', [
            'reason' => 'Security incident',
        ])->assertOk()->assertJsonPath('status', 'suspended');

        $this->assertTrue(
            PlatformAuditLog::query()->where('event', 'TENANT_SUSPENDED')->where('resource_ulid', $tenant->ulid)->exists()
        );
        $audit = PlatformAuditLog::query()->where('event', 'TENANT_SUSPENDED')->where('resource_ulid', $tenant->ulid)->first();
        $this->assertStringNotContainsString('password', strtolower((string) json_encode($audit?->metadata)));

        $this->loginAs('suspend-me', 'owner')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'TENANT_DISABLED');
    }

    public function test_existing_suspended_tenant_session_is_rejected(): void
    {
        $this->signInOwner('live-sess')->assertOk();
        $tenant = Tenant::query()->where('code', $this->tenantCode('live-sess'))->firstOrFail();

        app(\App\Actions\Platform\SuspendTenantAction::class)->execute($tenant, 'Force shutdown');

        $this->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'TENANT_DISABLED');
    }

    public function test_suspended_tenant_cannot_receive_offline_lease(): void
    {
        $session = $this->provisionOwner('no-lease');
        $this->signInPlatformAdmin('lease-block');
        $this->postJson('/api/platform/tenants/'.$session->tenant->ulid.'/suspend', [
            'reason' => 'Offline lock',
        ])->assertOk();

        $device = Device::query()->create([
            'tenant_id' => $session->tenant->id,
            'name' => 'POS',
            'device_type' => 'pos_terminal',
            'status' => DeviceStatus::Active,
            'credential_hash' => Hash::make('x'),
            'registered_at' => now(),
            'approved_at' => now(),
        ]);

        $this->expectException(\App\Exceptions\ApiException::class);
        app(OfflineAuthorizationService::class)->issue(
            $session->membership->fresh() ?? $session->membership,
            $device,
            $session->branch,
            ['sales.create'],
        );
    }

    public function test_reactivation_restores_eligibility_but_not_revoked_sessions(): void
    {
        $this->signInOwner('react')->assertOk();
        $tenant = Tenant::query()->where('code', $this->tenantCode('react'))->firstOrFail();
        app(\App\Actions\Platform\SuspendTenantAction::class)->execute($tenant, 'Pause');
        app(\App\Actions\Platform\ActivateTenantAction::class)->execute($tenant->fresh() ?? $tenant);

        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.key', 'SESSION_REVOKED');
        $this->loginAs('react', 'owner')->assertOk();
    }

    public function test_create_plan_and_reject_duplicate_code(): void
    {
        $this->signInPlatformAdmin('plans');
        $created = $this->postJson('/api/platform/plans', [
            'code' => 'CUSTOMX',
            'name' => 'Custom',
            'description' => 'Test plan',
        ])->assertCreated();
        $this->assertNoInternalIds($created->json());

        $this->postJson('/api/platform/plans', [
            'code' => 'CUSTOMX',
            'name' => 'Custom 2',
        ])->assertUnprocessable();
    }

    public function test_assign_plan_features_and_limits(): void
    {
        $this->signInPlatformAdmin('plan-feat');
        $plan = $this->postJson('/api/platform/plans', [
            'code' => 'MATRIX',
            'name' => 'Matrix',
        ])->assertCreated();

        $this->putJson('/api/platform/plans/'.$plan->json('ulid').'/features', [
            'features' => [
                FeatureCatalogue::SALES => true,
                FeatureCatalogue::ACCOUNTING => false,
            ],
        ])->assertOk()->assertJsonPath('features.sales', true)
            ->assertJsonPath('features.accounting', false);

        $this->putJson('/api/platform/plans/'.$plan->json('ulid').'/limits', [
            'limits' => [
                LimitCatalogue::MAX_USERS => 3,
                LimitCatalogue::MAX_BRANCHES => 1,
            ],
        ])->assertOk()->assertJsonPath('limits.max_users', 3);
    }

    public function test_assign_plan_returns_effective_features(): void
    {
        $this->signInPlatformAdmin('sub-assign');
        $professional = Plan::query()->where('code', 'PROFESSIONAL')->first() ?? $this->starterPlan();
        if ($professional->code !== 'PROFESSIONAL') {
            app(PlatformCatalogSync::class)->ensure();
            $professional = Plan::query()->where('code', 'PROFESSIONAL')->firstOrFail();
        }

        $created = $this->postJson('/api/platform/tenants', $this->tenantPayload('entitled', $this->starterPlan()->ulid))->assertCreated();
        $this->putJson('/api/platform/tenants/'.$created->json('ulid').'/subscription', [
            'plan_ulid' => $professional->ulid,
            'status' => SubscriptionStatus::Active->value,
        ])->assertOk();

        $detail = $this->getJson('/api/platform/tenants/'.$created->json('ulid'))->assertOk();
        $this->assertContains('accounting', $detail->json('entitlements.features'));
        $this->assertNoInternalIds($detail->json());
    }

    public function test_suspended_subscription_blocks_entitlement(): void
    {
        $this->signInPlatformAdmin('sub-block');
        $created = $this->postJson('/api/platform/tenants', $this->tenantPayload('blocked', $this->starterPlan()->ulid))->assertCreated();
        $this->putJson('/api/platform/tenants/'.$created->json('ulid').'/subscription', [
            'plan_ulid' => $this->starterPlan()->ulid,
            'status' => SubscriptionStatus::Suspended->value,
        ])->assertOk();

        $tenant = Tenant::query()->where('ulid', $created->json('ulid'))->firstOrFail();
        $service = app(TenantEntitlementService::class);
        $this->assertFalse($service->isTenantOperational($tenant));
        try {
            $service->assertOperational($tenant);
            $this->fail('Expected SUBSCRIPTION_INACTIVE.');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame('SUBSCRIPTION_INACTIVE', $e->errorKey);
        }
    }

    public function test_feature_override_enable_disable_and_wins_over_plan(): void
    {
        $this->signInPlatformAdmin('feat-ov');
        $created = $this->postJson('/api/platform/tenants', $this->tenantPayload('ovtenant', $this->starterPlan()->ulid))->assertCreated();
        $ulid = $created->json('ulid');

        $this->putJson('/api/platform/tenants/'.$ulid.'/features/offline_pos', [
            'enabled' => true,
            'reason' => 'Temporary offline enable',
        ])->assertOk()->assertJsonPath('effective', true);

        $this->putJson('/api/platform/tenants/'.$ulid.'/features/catalog', [
            'enabled' => false,
            'reason' => 'Disable catalog',
        ])->assertOk()->assertJsonPath('effective', false);

        $this->assertTrue(
            PlatformAuditLog::query()->where('event', 'TENANT_FEATURE_OVERRIDE_CHANGED')->where('resource_ulid', $ulid)->exists()
        );

        $tenant = Tenant::query()->where('ulid', $ulid)->firstOrFail();
        $service = app(TenantEntitlementService::class);
        $this->assertTrue($service->hasFeature($tenant, 'offline_pos'));
        $this->assertFalse($service->hasFeature($tenant, 'catalog'));
    }

    public function test_tenant_admin_cannot_modify_override(): void
    {
        $this->signInPlatformAdmin('no-ov');
        $created = $this->postJson('/api/platform/tenants', $this->tenantPayload('noov', $this->starterPlan()->ulid))->assertCreated();
        $password = $created->json('initial_admin.temporary_password');
        $this->postJson('/api/platform/auth/logout')->assertOk();

        $tenant = Tenant::query()->where('ulid', $created->json('ulid'))->firstOrFail();
        $membership = Membership::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $membership->user->must_change_password = false;
        $membership->user->save();

        $this->attachTrustedDevice((int) $tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $tenant->code,
            'username' => 'owner',
            'password' => $password,
        ])->assertOk();

        $this->putJson('/api/platform/tenants/'.$tenant->ulid.'/features/accounting', [
            'enabled' => true,
            'reason' => 'tenant attempt',
        ])->assertUnauthorized();
    }

    public function test_effective_user_limit_and_creation_rejection(): void
    {
        $this->signInPlatformAdmin('lim-users');
        $created = $this->postJson('/api/platform/tenants', $this->tenantPayload('limu', $this->starterPlan()->ulid))->assertCreated();
        $this->putJson('/api/platform/tenants/'.$created->json('ulid').'/limits/max_users', [
            'value' => 1,
            'reason' => 'Cap users',
        ])->assertOk();

        $tenant = Tenant::query()->where('ulid', $created->json('ulid'))->firstOrFail();
        $this->assertSame(1, app(TenantEntitlementService::class)->limit($tenant, LimitCatalogue::MAX_USERS));

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

        $role = Role::query()->where('tenant_id', $tenant->id)->where('code', PermissionCatalogue::CASHIER)->firstOrFail();
        $this->postJson('/api/memberships', [
            'name' => 'Extra',
            'username' => 'extra1',
            'password' => 'password123',
            'roles' => [$role->ulid],
        ])->assertForbidden()->assertJsonPath('error.key', 'PLAN_USER_LIMIT_REACHED');
    }

    public function test_branch_creation_respects_max_branches(): void
    {
        $this->signInPlatformAdmin('lim-br');
        $created = $this->postJson('/api/platform/tenants', $this->tenantPayload('limbr', $this->starterPlan()->ulid))->assertCreated();
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

        $this->postJson('/api/branches', [
            'code' => 'B2',
            'name' => 'Second',
        ])->assertForbidden()->assertJsonPath('error.key', 'PLAN_BRANCH_LIMIT_REACHED');
    }

    public function test_device_approval_respects_max_devices(): void
    {
        $this->signInPlatformAdmin('lim-dev');
        $created = $this->postJson('/api/platform/tenants', $this->tenantPayload('limdev', $this->starterPlan()->ulid))->assertCreated();
        $this->putJson('/api/platform/tenants/'.$created->json('ulid').'/limits/max_devices', [
            'value' => 1,
            'reason' => 'One device',
        ])->assertOk();

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

        $pending = Device::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Extra POS',
            'device_type' => 'pos_terminal',
            'status' => DeviceStatus::Pending,
            'credential_hash' => Hash::make('pending'),
            'registered_at' => now(),
        ]);

        $this->postJson('/api/devices/'.$pending->ulid.'/approve')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'PLAN_DEVICE_LIMIT_REACHED');
    }

    public function test_tenant_specific_limit_override_works(): void
    {
        $this->signInPlatformAdmin('lim-ov');
        $created = $this->postJson('/api/platform/tenants', $this->tenantPayload('limov', $this->starterPlan()->ulid))->assertCreated();
        $this->putJson('/api/platform/tenants/'.$created->json('ulid').'/limits/max_users', [
            'value' => 15,
            'reason' => 'Custom allocation',
        ])->assertOk()->assertJsonPath('effective', 15);

        $tenant = Tenant::query()->where('ulid', $created->json('ulid'))->firstOrFail();
        $this->assertSame(15, app(TenantEntitlementService::class)->limit($tenant, LimitCatalogue::MAX_USERS));
    }

    public function test_platform_tenant_lookup_uses_ulid_only(): void
    {
        $this->signInPlatformAdmin('idor');
        $created = $this->postJson('/api/platform/tenants', $this->tenantPayload('idor', $this->starterPlan()->ulid))->assertCreated();
        $tenant = Tenant::query()->where('ulid', $created->json('ulid'))->firstOrFail();

        $this->getJson('/api/platform/tenants/'.$tenant->id)->assertNotFound();
        $this->getJson('/api/platform/tenants/'.$tenant->ulid)->assertOk()->assertJsonPath('ulid', $tenant->ulid);
    }

    public function test_disabled_catalog_blocks_tenant_api(): void
    {
        $this->signInPlatformAdmin('feat-api');
        $created = $this->postJson('/api/platform/tenants', $this->tenantPayload('featapi', $this->starterPlan()->ulid))->assertCreated();
        $this->putJson('/api/platform/tenants/'.$created->json('ulid').'/features/catalog', [
            'enabled' => false,
            'reason' => 'Module off',
        ])->assertOk();

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

        $this->getJson('/api/products')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'FEATURE_DISABLED');
    }

    public function test_operator_can_reset_platform_admin_password(): void
    {
        $user = $this->createPlatformAdmin('pw-reset');

        $this->artisan('bluepos:platform-admin-reset-password', [
            '--email' => $user->email,
            '--password' => 'NewPlatformPass!1',
        ])->assertSuccessful();

        $user = $user->fresh() ?? $user;
        $this->assertTrue(Hash::check('NewPlatformPass!1', $user->password));
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertTrue(
            PlatformAuditLog::query()->where('event', 'PLATFORM_USER_PASSWORD_RESET')->where('resource_ulid', $user->ulid)->exists()
        );

        $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
        ])->assertUnauthorized()->assertJsonPath('error.key', 'INVALID_CREDENTIALS');

        $this->artisan('bluepos:platform-admin-reset-password', [
            '--email' => 'missing-platform@example.com',
        ])->assertFailed();
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantPayload(string $suffix, string $planUlid): array
    {
        return [
            'tenant_name' => 'Mart '.$suffix,
            'tenant_code' => $this->tenantCode($suffix),
            'legal_name' => 'Mart '.$suffix.' LLC',
            'recovery_email' => "owner-{$suffix}@example.com",
            'admin_name' => 'Owner '.$suffix,
            'admin_username' => 'owner',
            'timezone' => 'Asia/Karachi',
            'currency_code' => 'PKR',
            'plan_ulid' => $planUlid,
            'status' => TenantStatus::Active->value,
        ];
    }

    private function starterPlan(): Plan
    {
        app(PlatformCatalogSync::class)->ensure();

        return Plan::query()->where('code', 'STARTER')->where('status', PlanStatus::Active)->firstOrFail();
    }
}
