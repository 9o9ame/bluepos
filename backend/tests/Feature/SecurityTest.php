<?php

namespace Tests\Feature;

use App\Authz\PermissionCatalogue;
use App\Enums\BranchStatus;
use App\Enums\DeviceStatus;
use App\Enums\WarehouseStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Notifications\SecurityCodeNotification;
use App\Security\DeviceCredentialService;
use App\Security\OfflineAuthorizationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_same_username_can_exist_in_two_tenants_and_is_not_cross_authenticated(): void
    {
        $this->signInOwner('alnoor')->assertOk();
        $this->postJson('/api/memberships', [
            'name' => 'Cashier',
            'username' => 'cashier',
            'recovery_email' => 'cashier-alnoor@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$this->roleUlid(PermissionCatalogue::CASHIER)],
            'branches' => [$this->getJson('/api/auth/me')->json('branch.ulid')],
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();

        $this->signInOwner('citymart')->assertOk();
        $this->postJson('/api/memberships', [
            'name' => 'Cashier',
            'username' => 'cashier',
            'recovery_email' => 'cashier-citymart@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$this->roleUlid(PermissionCatalogue::CASHIER)],
            'branches' => [$this->getJson('/api/auth/me')->json('branch.ulid')],
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();

        $city = $this->loginAs('citymart', 'cashier')->assertOk();
        $this->assertSame($this->tenantCode('citymart'), $city->json('tenant.code'));
        $this->postJson('/api/auth/logout')->assertOk();

        $alnoor = $this->loginAs('alnoor', 'cashier')->assertOk();
        $this->assertSame($this->tenantCode('alnoor'), $alnoor->json('tenant.code'));
        $this->assertNotSame($city->json('tenant.ulid'), $alnoor->json('tenant.ulid'));
    }

    public function test_duplicate_username_in_one_tenant_is_rejected(): void
    {
        $owner = $this->signInOwner('dupuser')->assertOk();
        $payload = [
            'name' => 'Cashier',
            'username' => 'cashier01',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$this->roleUlid(PermissionCatalogue::CASHIER)],
            'branches' => [$owner->json('branch.ulid')],
        ];
        $this->postJson('/api/memberships', $payload)->assertCreated();
        $this->postJson('/api/memberships', $payload)->assertUnprocessable();
    }

    public function test_unauthorized_staff_cannot_create_staff(): void
    {
        $owner = $this->signInOwner('staffgate')->assertOk();
        $cashierRole = $this->roleUlid(PermissionCatalogue::CASHIER);
        $this->postJson('/api/memberships', [
            'name' => 'Cashier',
            'username' => 'cashier-staffgate',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$cashierRole],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('staffgate', 'cashier-staffgate')->assertOk();
        $this->postJson('/api/memberships', [
            'name' => 'Other',
            'username' => 'other',
            'password' => 'password123',
            'roles' => [$cashierRole],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertForbidden();
    }

    public function test_temporary_password_forces_change_then_clears(): void
    {
        $owner = $this->signInOwner('tmppass')->assertOk();
        $this->postJson('/api/memberships', [
            'name' => 'Cashier',
            'username' => 'temp-cashier',
            'password' => 'temporary1',
            'must_change_password' => true,
            'roles' => [$this->roleUlid(PermissionCatalogue::CASHIER)],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated()->assertJsonMissingPath('password');
        $this->postJson('/api/auth/logout')->assertOk();

        $this->loginAs('tmppass', 'temp-cashier', 'temporary1')->assertOk();
        $this->getJson('/api/roles')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'PASSWORD_CHANGE_REQUIRED');

        $this->postJson('/api/auth/change-password', [
            'current_password' => 'temporary1',
            'password' => 'changed-password',
            'password_confirmation' => 'changed-password',
        ])->assertOk();

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('must_change_password', false);
        $this->getJson('/api/products')->assertOk();
    }

    public function test_forgot_password_is_generic_and_reset_invalidates_sessions(): void
    {
        Notification::fake();
        $this->signInOwner('resetpw')->assertOk();
        $this->getJson('/api/auth/me')->assertOk();

        $unknown = $this->postJson('/api/auth/forgot-password', [
            'tenant_code' => 'NOSUCH',
            'username' => 'ghost',
        ])->assertOk();
        $known = $this->postJson('/api/auth/forgot-password', [
            'tenant_code' => $this->tenantCode('resetpw'),
            'username' => 'owner',
        ])->assertOk();
        $this->assertSame($unknown->json('message'), $known->json('message'));

        $token = null;
        Notification::assertSentOnDemand(SecurityCodeNotification::class, function (SecurityCodeNotification $notification) use (&$token): bool {
            $token = $notification->code;

            return true;
        });

        $this->postJson('/api/auth/reset-password', [
            'tenant_code' => $this->tenantCode('resetpw'),
            'username' => 'owner',
            'token' => $token,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.key', 'SESSION_REVOKED');
    }

    public function test_unapproved_device_blocks_staff_and_approved_device_allows(): void
    {
        $owner = $this->signInOwner('devstaff')->assertOk();
        $this->postJson('/api/memberships', [
            'name' => 'Cashier',
            'username' => 'cashier-devstaff',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$this->roleUlid(PermissionCatalogue::CASHIER)],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();

        $this->withoutDevice();
        $blocked = $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('devstaff'),
            'username' => 'cashier-devstaff',
            'password' => 'password123',
        ]);
        $blocked->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED');

        $this->loginAs('devstaff', 'cashier-devstaff')->assertOk();
    }

    public function test_revoked_device_blocks_login_and_session(): void
    {
        $this->signInOwner('devrev')->assertOk();
        $devices = $this->getJson('/api/devices')->assertOk()->json();
        $ulid = $devices[0]['ulid'];
        $this->postJson('/api/devices/'.$ulid.'/revoke')->assertOk()->assertJsonPath('status', 'revoked');
        $this->getJson('/api/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'DEVICE_REVOKED');
    }

    public function test_cashier_cannot_approve_device_but_owner_can(): void
    {
        $owner = $this->signInOwner('devappr')->assertOk();
        $this->postJson('/api/memberships', [
            'name' => 'Cashier',
            'username' => 'cashier-devappr',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$this->roleUlid(PermissionCatalogue::CASHIER)],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();

        $enroll = $this->postJson('/api/devices/enrollment', ['name' => 'Home laptop'])->assertOk();
        $deviceUlid = $enroll->json('ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('devappr', 'cashier-devappr')->assertOk();
        $this->postJson('/api/devices/'.$deviceUlid.'/approve')->assertForbidden();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('devappr', 'owner')->assertOk();
        $this->postJson('/api/devices/'.$deviceUlid.'/approve', [
            'branch_ulid' => $owner->json('branch.ulid'),
        ])->assertOk()->assertJsonPath('status', 'active');
        $this->assertNoInternalIds($this->getJson('/api/devices')->assertOk()->json());
    }

    public function test_admin_unknown_device_requires_mfa_and_rejects_invalid_or_expired(): void
    {
        Notification::fake();
        $this->provisionOwner('adminmfa');

        $first = $this->withoutDevice()->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('adminmfa'),
            'username' => 'owner',
            'password' => 'password123',
        ]);
        $first->assertForbidden()->assertJsonPath('error.key', 'MFA_REQUIRED');
        $challenge = $first->json('error.challenge_ulid');
        $this->assertNotEmpty($challenge);
        $this->assertStringContainsString('***', (string) $first->json('error.recovery_hint'));

        $this->postJson('/api/auth/mfa/verify', [
            'challenge_ulid' => $challenge,
            'code' => '000000',
        ])->assertForbidden()->assertJsonPath('error.key', 'MFA_INVALID');

        $code = null;
        Notification::assertSentOnDemand(SecurityCodeNotification::class, function (SecurityCodeNotification $notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        $this->travel(11)->minutes();
        $this->postJson('/api/auth/mfa/verify', [
            'challenge_ulid' => $challenge,
            'code' => $code,
        ])->assertForbidden()->assertJsonPath('error.key', 'MFA_EXPIRED');
        $this->travelBack();

        $fresh = $this->withoutDevice()->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('adminmfa'),
            'username' => 'owner',
            'password' => 'password123',
        ]);
        $freshCode = null;
        Notification::assertSentOnDemand(SecurityCodeNotification::class, function (SecurityCodeNotification $notification) use (&$freshCode): bool {
            $freshCode = $notification->code;

            return true;
        });
        $session = $this->postJson('/api/auth/mfa/verify', [
            'challenge_ulid' => $fresh->json('error.challenge_ulid'),
            'code' => $freshCode,
            'trust_device' => true,
        ]);
        $session->assertOk()->assertJsonPath('membership.is_owner', true);
        $this->assertNoInternalIds($session->json());
    }

    public function test_deactivated_membership_is_rejected_and_audited(): void
    {
        $owner = $this->signInOwner('deact')->assertOk();
        $created = $this->postJson('/api/memberships', [
            'name' => 'Cashier',
            'username' => 'cashier-deact',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$this->roleUlid(PermissionCatalogue::CASHIER)],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('deact', 'cashier-deact')->assertOk();
        $this->getJson('/api/auth/me')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();

        $this->loginAs('deact', 'owner')->assertOk();
        $this->postJson('/api/memberships/'.$created->json('ulid').'/deactivate')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'USER_DEACTIVATED']);
        $this->postJson('/api/auth/logout')->assertOk();

        $this->loginAs('deact', 'cashier-deact')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'ACCOUNT_DISABLED');
    }

    public function test_force_logout_revokes_existing_session_records(): void
    {
        $owner = $this->signInOwner('forcelog')->assertOk();
        $created = $this->postJson('/api/memberships', [
            'name' => 'Cashier',
            'username' => 'cashier-forcelog',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$this->roleUlid(PermissionCatalogue::CASHIER)],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('forcelog', 'cashier-forcelog')->assertOk();
        $this->assertDatabaseHas('auth_sessions', [
            'membership_id' => \App\Models\Membership::query()->where('ulid', $created->json('ulid'))->value('id'),
            'revoked_at' => null,
        ]);
        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('forcelog', 'owner')->assertOk();
        $this->postJson('/api/memberships/'.$created->json('ulid').'/force-logout')->assertOk();
        $this->assertDatabaseMissing('auth_sessions', [
            'membership_id' => \App\Models\Membership::query()->where('ulid', $created->json('ulid'))->value('id'),
            'revoked_at' => null,
        ]);
    }

    public function test_wrong_tenant_code_does_not_authenticate_valid_password(): void
    {
        $owner = $this->signInOwner('alpha-login')->assertOk();
        $this->postJson('/api/memberships', [
            'name' => 'Cashier',
            'username' => 'only-alpha',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$this->roleUlid(PermissionCatalogue::CASHIER)],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('beta-login')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();

        $this->loginAs('beta-login', 'only-alpha')
            ->assertUnauthorized()
            ->assertJsonPath('error.key', 'INVALID_CREDENTIALS');
    }

    public function test_device_and_membership_branch_must_both_match(): void
    {
        $owner = $this->signInOwner('devbranch')->assertOk();
        $tenant = Tenant::query()->where('code', $this->tenantCode('devbranch'))->firstOrFail();
        $east = Branch::factory()->create([
            'tenant_id' => $tenant->id,
            'code' => 'EAST',
            'name' => 'East',
            'status' => BranchStatus::Active,
            'is_default' => false,
        ]);
        Warehouse::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $east->id,
            'code' => 'EAST',
            'name' => 'East Warehouse',
            'status' => WarehouseStatus::Active,
            'is_default' => true,
        ]);

        $this->postJson('/api/memberships', [
            'name' => 'Cashier',
            'username' => 'cashier-devbranch',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$this->roleUlid(PermissionCatalogue::CASHIER)],
            'branches' => [$owner->json('branch.ulid')],
        ])->assertCreated();

        $device = Device::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $device->branch_id = $east->id;
        $device->save();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('devbranch', 'cashier-devbranch')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'BRANCH_ACCESS_DENIED');
    }

    public function test_audit_logs_redact_secrets_and_record_login(): void
    {
        $this->signInOwner('audsec')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'LOGIN_SUCCESS']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'password123']);
        foreach (AuditLog::query()->get() as $log) {
            $encoded = json_encode($log->metadata) ?: '';
            $this->assertStringNotContainsString('password123', $encoded);
            $this->assertStringNotContainsString('otp', strtolower($encoded));
        }
    }

    public function test_expired_offline_lease_is_rejected_and_disabled_membership_cannot_issue(): void
    {
        $session = $this->provisionOwner('lease');
        $device = Device::query()->create([
            'tenant_id' => $session->tenant->id,
            'name' => 'POS',
            'device_type' => 'pos_terminal',
            'status' => DeviceStatus::Active,
            'credential_hash' => Hash::make('x'),
            'registered_at' => now(),
            'approved_at' => now(),
        ]);
        $service = app(OfflineAuthorizationService::class);
        $lease = $service->issue($session->membership, $device, $session->branch, ['sales.create'], 1);
        $lease->expires_at = now()->subMinute();
        $lease->save();

        try {
            $service->assertValid($lease->fresh() ?? $lease, $device, $session->membership);
            $this->fail('Expired lease should reject.');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $e->errorKey);
        }

        $session->membership->status = \App\Enums\MembershipStatus::Suspended;
        $session->membership->save();
        $this->expectException(\App\Exceptions\ApiException::class);
        $service->issue($session->membership, $device, $session->branch, ['sales.create']);
    }

    private function roleUlid(string $code): string
    {
        $roles = $this->getJson('/api/roles')->assertOk()->json();
        foreach ($roles as $role) {
            if ($role['code'] === $code) {
                return $role['ulid'];
            }
        }
        $this->fail('Missing role '.$code);
    }
}
