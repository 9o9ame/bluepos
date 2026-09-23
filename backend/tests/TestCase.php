<?php

namespace Tests;

use App\Actions\Auth\ProvisionTenantAction;
use App\Actions\Platform\AssignTenantPlanAction;
use App\Auth\AuthenticatedSession;
use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Tenant;
use App\Security\DeviceCredentialService;
use App\Support\IdentityNormalizer;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Tests\Support\TestDatabaseGuard;

abstract class TestCase extends BaseTestCase
{
    /** @var array<int, string> */
    protected array $deviceCredentials = [];

    protected function setUpTraits()
    {
        $this->assertUsingBlueposTestDatabase();

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->withHeaders([
            'Accept' => 'application/json',
            'Origin' => 'http://localhost:5173',
            'Referer' => 'http://localhost:5173/',
        ]);
    }

    protected function assertUsingBlueposTestDatabase(): void
    {
        TestDatabaseGuard::assertApplication();
    }

    /**
     * @param  array<string, mixed>|array<int, mixed>  $payload
     */
    protected function assertNoInternalIds(array $payload, string $path = ''): void
    {
        $forbidden = [
            'id',
            'tenant_id',
            'user_id',
            'membership_id',
            'branch_id',
            'warehouse_id',
            'role_id',
            'permission_id',
            'category_id',
            'subcategory_id',
            'brand_id',
            'barcode_group_id',
            'unit_id',
            'product_id',
            'base_unit_id',
            'secondary_unit_id',
            'created_by',
            'updated_by',
            'device_id',
            'approved_by',
            'actor_user_id',
            'actor_membership_id',
            'platform_user_id',
            'plan_id',
            'actor_platform_user_id',
            'subscription_id',
        ];

        foreach ($payload as $key => $value) {
            $current = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && in_array($key, $forbidden, true)) {
                $this->fail("Public API exposed internal identifier at {$current}.");
            }

            if (is_array($value)) {
                $this->assertNoInternalIds($value, $current);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function registrationPayload(string $suffix = 'aa'): array
    {
        return [
            'name' => 'Owner '.$suffix,
            'email' => "owner-{$suffix}@example.com",
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'tenant_name' => 'Mart '.$suffix,
            'timezone' => 'Asia/Karachi',
            'currency_code' => 'PKR',
        ];
    }

    protected function tenantCode(string $suffix): string
    {
        $code = IdentityNormalizer::tenantCode($suffix);

        return strlen($code) < 2 ? 'T'.$code : $code;
    }

    protected function staffUsername(string $suffix): string
    {
        return IdentityNormalizer::username('cashier-'.$suffix);
    }

    protected function provisionOwner(string $suffix = 'aa', bool $assignPlan = true): AuthenticatedSession
    {
        $session = app(ProvisionTenantAction::class)->execute([
            'name' => 'Owner '.$suffix,
            'username' => 'owner',
            'recovery_email' => "owner-{$suffix}@example.com",
            'password' => 'password123',
            'tenant_name' => 'Mart '.$suffix,
            'tenant_code' => $this->tenantCode($suffix),
            'timezone' => 'Asia/Karachi',
            'currency_code' => 'PKR',
            'must_change_password' => false,
        ]);

        if ($assignPlan) {
            $this->assignExplicitPlan($session->tenant);
        }

        return $session;
    }

    protected function provisionOwnerWithoutSubscription(string $suffix = 'aa'): AuthenticatedSession
    {
        return $this->provisionOwner($suffix, false);
    }

    protected function assignExplicitPlan(Tenant $tenant, string $planCode = 'ENTERPRISE'): void
    {
        $subscription = app(AssignTenantPlanAction::class)->execute($tenant, $planCode);
        $tenant->setRelation('subscription', $subscription);
    }

    protected function attachTrustedDevice(int $tenantId): string
    {
        if (! isset($this->deviceCredentials[$tenantId])) {
            $device = Device::query()->create([
                'tenant_id' => $tenantId,
                'name' => 'Test POS',
                'device_type' => 'pos_terminal',
                'status' => DeviceStatus::Active,
                'credential_hash' => 'pending',
                'registered_at' => now(),
                'approved_at' => now(),
                'trusted_until' => now()->addDays(30),
            ]);
            $this->deviceCredentials[$tenantId] = app(DeviceCredentialService::class)->issue($device);
        }

        $credential = $this->deviceCredentials[$tenantId];
        $this->withHeader(DeviceCredentialService::HEADER, $credential);

        return $credential;
    }

    protected function withoutDevice(): static
    {
        unset($this->defaultHeaders[DeviceCredentialService::HEADER]);
        unset($this->defaultHeaders[strtolower(DeviceCredentialService::HEADER)]);

        return $this;
    }

    protected function signInOwner(string $suffix = 'aa'): TestResponse
    {
        $session = $this->provisionOwner($suffix);
        $this->attachTrustedDevice((int) $session->tenant->id);

        return $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode($suffix),
            'username' => 'owner',
            'password' => 'password123',
        ]);
    }

    protected function loginAs(string $suffix, string $username, string $password = 'password123'): TestResponse
    {
        $tenant = Tenant::query()->where('code', $this->tenantCode($suffix))->firstOrFail();
        $this->attachTrustedDevice((int) $tenant->id);

        return $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode($suffix),
            'username' => IdentityNormalizer::username($username),
            'password' => $password,
        ]);
    }

    protected function createPlatformAdmin(string $suffix = 'sa', bool $mustChange = false): \App\Models\Platform\PlatformUser
    {
        app(\App\Platform\PlatformCatalogSync::class)->ensure();

        return app(\App\Actions\Platform\CreatePlatformAdminAction::class)->execute([
            'name' => 'Platform '.$suffix,
            'email' => "platform-{$suffix}@example.com",
            'password' => 'platform-pass-123',
            'must_change_password' => $mustChange,
        ]);
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    protected function createPlatformStaff(string $suffix, array $permissionKeys): \App\Models\Platform\PlatformUser
    {
        app(\App\Platform\PlatformCatalogSync::class)->ensure();

        $role = \App\Models\Platform\PlatformRole::query()->create([
            'code' => \App\Support\IdentityNormalizer::platformRoleCode('STAFF_'.$suffix),
            'name' => 'Staff '.$suffix,
            'is_system' => false,
            'is_active' => true,
        ]);
        $permissionIds = \App\Models\Platform\PlatformPermission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id');
        foreach ($permissionIds as $permissionId) {
            $role->permissions()->attach($permissionId, ['ulid' => (string) \Illuminate\Support\Str::ulid()]);
        }

        $user = \App\Models\Platform\PlatformUser::query()->create([
            'name' => 'Staff '.$suffix,
            'email' => "staff-{$suffix}@example.com",
            'password' => 'platform-pass-123',
            'status' => \App\Enums\PlatformUserStatus::Active,
            'must_change_password' => false,
            'security_version' => 1,
        ]);
        $user->roles()->attach($role->id, ['ulid' => (string) \Illuminate\Support\Str::ulid()]);

        return $user->fresh(['roles']) ?? $user;
    }

    protected function signInPlatformUser(\App\Models\Platform\PlatformUser $user, string $password = 'platform-pass-123'): \App\Models\Platform\PlatformUser
    {
        \Illuminate\Support\Facades\Notification::fake();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ]);
        $login->assertForbidden()->assertJsonPath('error.key', 'MFA_REQUIRED');
        $challengeUlid = $login->json('error.challenge_ulid');

        $code = null;
        \Illuminate\Support\Facades\Notification::assertSentOnDemand(
            \App\Notifications\SecurityCodeNotification::class,
            function ($notification) use (&$code): bool {
                $code = $notification->code;

                return true;
            },
        );

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $challengeUlid,
            'code' => $code,
            'trust_device' => true,
        ])->assertOk();

        return $user->fresh(['roles']) ?? $user;
    }

    protected function signInPlatformAdmin(string $suffix = 'sa'): \App\Models\Platform\PlatformUser
    {
        $user = \App\Models\Platform\PlatformUser::query()
            ->where('email', "platform-{$suffix}@example.com")
            ->first() ?? $this->createPlatformAdmin($suffix);

        \Illuminate\Support\Facades\Notification::fake();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
        ]);
        $login->assertForbidden()->assertJsonPath('error.key', 'MFA_REQUIRED');
        $challengeUlid = $login->json('error.challenge_ulid');

        $code = null;
        \Illuminate\Support\Facades\Notification::assertSentOnDemand(
            \App\Notifications\SecurityCodeNotification::class,
            function ($notification) use (&$code): bool {
                $code = $notification->code;

                return true;
            },
        );

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $challengeUlid,
            'code' => $code,
            'trust_device' => true,
        ])->assertOk();

        return $user->fresh(['roles']) ?? $user;
    }
}
