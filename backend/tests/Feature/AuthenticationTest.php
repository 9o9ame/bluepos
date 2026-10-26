<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_verified_network_fingerprint_requires_reverification_on_change(): void
    {
        $this->signInOwner('owner-ip-trust')->assertOk();
        $tenantId = \App\Models\Tenant::query()->where('code', $this->tenantCode('owner-ip-trust'))->value('id');
        $device = \App\Models\Device::query()->where('tenant_id', $tenantId)->firstOrFail();
        $network = app(\App\Security\DeviceNetworkTrust::class);
        $request = \Illuminate\Http\Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.4']);
        $device->trusted_ip_hash = $network->fingerprint($request);
        $device->save();

        $this->assertSame(64, strlen((string) $device->trusted_ip_hash));
        $this->assertFalse($network->requiresReverification($device, $request));

        $otherNetwork = \Illuminate\Http\Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.14']);
        $this->assertTrue($network->requiresReverification($device, $otherNetwork));
    }

    public function test_non_owner_pending_device_requires_tenant_approval_not_email_otp(): void
    {
        $this->signInOwner('staff-pending')->assertOk();
        $this->restrictCurrentMembershipWithoutPermission('devices.approve');
        $this->withoutDevice();

        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('staff-pending'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED');
    }

    public function test_tenant_cannot_approve_unassigned_device(): void
    {
        $this->signInOwner('device-unassigned')->assertOk();
        $device = \App\Models\Device::query()->create([
            'name' => 'Unassigned device',
            'device_type' => 'browser',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'unused',
            'registered_at' => now(),
        ]);

        $this->postJson('/api/devices/'.$device->ulid.'/approve')->assertNotFound();
    }

    public function test_device_access_request_inbox_is_tenant_scoped_and_uses_public_ulids(): void
    {
        $first = $this->provisionOwner('request-inbox-a');
        $second = $this->provisionOwner('request-inbox-b');
        $device = \App\Models\Device::query()->create([
            'tenant_id' => $second->tenant->id,
            'name' => 'Employee browser',
            'device_type' => 'browser',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $second->tenant->id,
            'membership_id' => $second->membership->id,
            'device_id' => $device->id,
            'network_hash' => hash('sha256', 'test-network'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->attachTrustedDevice((int) $first->tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('request-inbox-a'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $response = $this->getJson('/api/devices/access-requests')->assertOk()->assertJsonCount(0, 'requests');
        $this->assertNoInternalIds($response->json());
    }

    public function test_tenant_owner_can_review_only_own_pending_employee_access_requests(): void
    {
        $first = $this->provisionOwner('review-own-a');
        $second = $this->provisionOwner('review-own-b');
        $device = \App\Models\Device::query()->create([
            'tenant_id' => $second->tenant->id,
            'name' => 'Second tenant terminal',
            'device_type' => 'browser',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        $requestUlid = (string) \Illuminate\Support\Str::ulid();
        \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
            'ulid' => $requestUlid,
            'tenant_id' => $second->tenant->id,
            'membership_id' => $second->membership->id,
            'device_id' => $device->id,
            'network_hash' => hash('sha256', 'review-network'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->attachTrustedDevice((int) $first->tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('review-own-a'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $this->postJson('/api/devices/access-requests/'.$requestUlid.'/review', [
            'decision' => 'approved',
        ])->assertNotFound();

        $this->assertSame('pending', \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('ulid', $requestUlid)->value('status'));
    }

    public function test_employee_device_approval_email_only_targets_own_tenant_owner(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $employeeTenant = $this->provisionOwner('approval-mail-tenant-a');
        $this->provisionOwner('approval-mail-tenant-b');

        app(\App\Security\EmployeeDeviceApprovalNotifier::class)
            ->notifyOwners($employeeTenant->membership, 'Pending browser');

        \Illuminate\Support\Facades\Notification::assertSentOnDemand(
            \App\Notifications\EmployeeDeviceApprovalNotification::class,
            function ($notification, $channels, $notifiable) {
                return ($notifiable->routes['mail'] ?? null) === 'owner-approval-mail-tenant-a@example.com'
                    && $notification->deviceName === 'Pending browser';
            }
        );
        \Illuminate\Support\Facades\Notification::assertSentOnDemandTimes(
            \App\Notifications\EmployeeDeviceApprovalNotification::class, 1
        );
    }

    public function test_generic_device_approval_cannot_bypass_employee_specific_review(): void
    {
        $session = $this->provisionOwner('generic-bypass');
        $this->attachTrustedDevice((int) $session->tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('generic-bypass'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $device = \App\Models\Device::query()->create([
            'tenant_id' => $session->tenant->id,
            'name' => 'Pending employee device',
            'device_type' => 'browser',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        $requestUlid = (string) \Illuminate\Support\Str::ulid();
        \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
            'ulid' => $requestUlid,
            'tenant_id' => $session->tenant->id,
            'membership_id' => $session->membership->id,
            'device_id' => $device->id,
            'network_hash' => hash('sha256', 'pending-staff-network'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/devices/'.$device->ulid.'/approve')
            ->assertStatus(409)
            ->assertJsonPath('error.key', 'EMPLOYEE_ACCESS_REVIEW_REQUIRED');
        $this->assertSame(\App\Enums\DeviceStatus::Pending, $device->fresh()->status);
        $this->assertSame('pending', \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('ulid', $requestUlid)->value('status'));

        // Generic approval still works for a separate device without employee requests.
        $unmanaged = \App\Models\Device::query()->create([
            'tenant_id' => $session->tenant->id,
            'name' => 'Unmanaged new terminal',
            'device_type' => 'pos_terminal',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        $this->postJson('/api/devices/'.$unmanaged->ulid.'/approve')->assertOk();
        $this->assertSame(\App\Enums\DeviceStatus::Active, $unmanaged->fresh()->status);
    }

    public function test_unmanaged_legacy_terminal_retains_existing_login_access_during_migration(): void
    {
        $owner = $this->provisionOwner('legacy-terminal');
        $staff = \App\Models\User::query()->create([
            'name' => 'Legacy Cashier',
            'email' => 'legacy-cashier@example.com',
            'password' => 'password123',
            'status' => \App\Enums\UserStatus::Active,
            'must_change_password' => false,
            'security_version' => 1,
        ]);
        $member = \App\Models\Membership::query()->create([
            'tenant_id' => $owner->tenant->id,
            'user_id' => $staff->id,
            'username' => 'legacycashier',
            'status' => \App\Enums\MembershipStatus::Active,
            'is_owner' => false,
            'security_version' => 1,
        ]);
        $role = \App\Models\Role::query()->where('tenant_id', $owner->tenant->id)
            ->where('code', \App\Authz\PermissionCatalogue::CASHIER)->firstOrFail();
        $member->roles()->attach($role->id, ['ulid' => (string) \Illuminate\Support\Str::ulid()]);
        $member->branches()->attach($owner->branch->id, ['ulid' => (string) \Illuminate\Support\Str::ulid()]);
        $this->attachTrustedDevice((int) $owner->tenant->id);

        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('legacy-terminal'),
            'username' => 'legacycashier',
            'password' => 'password123',
        ])->assertOk();
        $this->getJson('/api/auth/me')->assertOk();

        $device = \App\Models\Device::query()->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $this->assertSame('pos_terminal', $device->device_type);
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('device_id', $device->id)->count());
    }

    public function test_employee_login_requires_approved_network_and_rejects_other_network(): void
    {
        $owner = $this->provisionOwner('staff-network-gate');
        $staff = \App\Models\User::query()->create([
            'name' => 'Staff Gate',
            'email' => 'staff-network-gate@example.com',
            'password' => 'password123',
            'status' => \App\Enums\UserStatus::Active,
            'must_change_password' => false,
            'security_version' => 1,
        ]);
        $member = \App\Models\Membership::query()->create([
            'tenant_id' => $owner->tenant->id,
            'user_id' => $staff->id,
            'username' => 'staffgate',
            'status' => \App\Enums\MembershipStatus::Active,
            'is_owner' => false,
            'security_version' => 1,
        ]);
        $role = \App\Models\Role::query()->where('tenant_id', $owner->tenant->id)
            ->where('code', \App\Authz\PermissionCatalogue::CASHIER)->firstOrFail();
        $member->roles()->attach($role->id, ['ulid' => (string) \Illuminate\Support\Str::ulid()]);
        $member->branches()->attach($owner->branch->id, ['ulid' => (string) \Illuminate\Support\Str::ulid()]);
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $device->device_type = 'browser';
        $device->save();

        $request = \Illuminate\Http\Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $networkHash = app(\App\Security\DeviceNetworkTrust::class)->fingerprint($request);
        \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $owner->tenant->id,
            'membership_id' => $member->id,
            'device_id' => $device->id,
            'network_hash' => $networkHash,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $credentials = [
            'tenant_code' => $this->tenantCode('staff-network-gate'),
            'username' => 'staffgate',
            'password' => 'password123',
        ];
        $this->postJson('/api/auth/login', $credentials)
            ->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED');

        \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('membership_id', $member->id)->update(['status' => 'approved']);
        $this->postJson('/api/auth/login', $credentials)->assertOk();
        $this->getJson('/api/auth/me')->assertOk();

        // A valid session must not survive an employee network change.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.78']);
        $this->getJson('/api/auth/me')
            ->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->getJson('/api/auth/me')->assertOk();

        // A revoked access decision must immediately invalidate an existing session.
        \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('membership_id', $member->id)
            ->where('network_hash', $networkHash)
            ->update(['status' => 'rejected', 'reviewed_at' => now()]);
        $this->getJson('/api/auth/me')
            ->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED');
        \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('membership_id', $member->id)
            ->where('network_hash', $networkHash)
            ->update(['status' => 'approved', 'reviewed_at' => null]);
        $this->getJson('/api/auth/me')->assertOk();

        // A shared browser approval must not authorize another employee.
        $secondUser = \App\Models\User::query()->create([
            'name' => 'Other Staff',
            'email' => 'staff-other-gate@example.com',
            'password' => 'password123',
            'status' => \App\Enums\UserStatus::Active,
            'must_change_password' => false,
            'security_version' => 1,
        ]);
        $secondMember = \App\Models\Membership::query()->create([
            'tenant_id' => $owner->tenant->id,
            'user_id' => $secondUser->id,
            'username' => 'otherstaff',
            'status' => \App\Enums\MembershipStatus::Active,
            'is_owner' => false,
            'security_version' => 1,
        ]);
        $secondMember->roles()->attach($role->id, ['ulid' => (string) \Illuminate\Support\Str::ulid()]);
        $secondMember->branches()->attach($owner->branch->id, ['ulid' => (string) \Illuminate\Support\Str::ulid()]);
        $this->postJson('/api/auth/login', array_merge($credentials, ['username' => 'otherstaff']))
            ->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED');

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.78']);
        $this->postJson('/api/auth/login', $credentials)
            ->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED');

        $otherRequest = \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('membership_id', $member->id)
            ->where('device_id', $device->id)
            ->where('network_hash', '!=', $networkHash)
            ->first();
        $this->assertNotNull($otherRequest);
        \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('id', $otherRequest->id)
            ->update(['status' => 'rejected', 'reviewed_at' => now()]);

        $this->postJson('/api/auth/login', $credentials)
            ->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED');
        $this->assertSame('rejected', \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('id', $otherRequest->id)->value('status'));

        \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('id', $otherRequest->id)
            ->update(['reviewed_at' => now()->subDays(2)]);
        $this->postJson('/api/auth/login', $credentials)
            ->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED');
        $this->assertSame('pending', \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('id', $otherRequest->id)->value('status'));

    }

    public function test_rejected_employee_access_review_cannot_be_replayed(): void
    {
        $owner = $this->provisionOwner('review-once');
        $employee = \App\Models\User::query()->create([
            'name' => 'Review Employee',
            'email' => 'review-once-staff@example.com',
            'password' => 'password123',
            'status' => \App\Enums\UserStatus::Active,
            'must_change_password' => false,
            'security_version' => 1,
        ]);
        $membership = \App\Models\Membership::query()->create([
            'tenant_id' => $owner->tenant->id,
            'user_id' => $employee->id,
            'username' => 'reviewstaff',
            'status' => \App\Enums\MembershipStatus::Active,
            'is_owner' => false,
            'security_version' => 1,
        ]);
        $device = \App\Models\Device::query()->create([
            'tenant_id' => $owner->tenant->id,
            'name' => 'Review device',
            'device_type' => 'browser',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        $ulid = (string) \Illuminate\Support\Str::ulid();
        \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
            'ulid' => $ulid,
            'tenant_id' => $owner->tenant->id,
            'membership_id' => $membership->id,
            'device_id' => $device->id,
            'network_hash' => hash('sha256', 'review-once-network'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->attachTrustedDevice((int) $owner->tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('review-once'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $url = '/api/devices/access-requests/'.$ulid.'/review';
        $this->postJson($url, ['decision' => 'rejected'])
            ->assertOk()->assertJsonPath('status', 'rejected');
        $this->postJson($url, ['decision' => 'approved'])
            ->assertStatus(409)
            ->assertJsonPath('error.key', 'ACCESS_REQUEST_ALREADY_REVIEWED');
        $this->assertSame('rejected', \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('ulid', $ulid)->value('status'));
        $this->assertSame(\App\Enums\DeviceStatus::Pending, $device->fresh()->status);
    }

    public function test_login_succeeds(): void
    {
        $this->provisionOwner('golf');
        $this->attachTrustedDevice((int) \App\Models\Tenant::query()->where('code', $this->tenantCode('golf'))->value('id'));

        $this->postJson('/api/auth/logout');

        $response = $this->loginAs('golf', 'owner');

        $response->assertOk()
            ->assertJsonPath('user.email', 'owner-golf@example.com')
            ->assertJsonPath('membership.username', 'owner')
            ->assertJsonPath('tenant.code', $this->tenantCode('golf'))
            ->assertJsonPath('membership.is_owner', true);

        $this->assertNoInternalIds($response->json());
        $this->assertNotNull(User::query()->where('email', 'owner-golf@example.com')->value('last_login_at'));
    }

    public function test_invalid_login_fails_safely(): void
    {
        $this->provisionOwner('hotel');
        $this->attachTrustedDevice((int) \App\Models\Tenant::query()->where('code', $this->tenantCode('hotel'))->value('id'));

        $unknown = $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('hotel'),
            'username' => 'nobody',
            'password' => 'password123',
        ]);

        $unknown->assertUnauthorized()
            ->assertJsonPath('error.key', 'INVALID_CREDENTIALS')
            ->assertJsonMissingPath('error.sql')
            ->assertJsonMissing(['password' => 'password123']);

        $wrong = $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('hotel'),
            'username' => 'owner',
            'password' => 'wrong-password',
        ]);

        $wrong->assertUnauthorized()
            ->assertJsonPath('error.key', 'INVALID_CREDENTIALS')
            ->assertJsonPath('error.message', $unknown->json('error.message'));
    }

    public function test_logout_invalidates_session(): void
    {
        $this->signInOwner('india')->assertOk();

        $this->getJson('/api/auth/me')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.key', 'UNAUTHORIZED');
    }

    public function test_auth_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.key', 'UNAUTHORIZED')
            ->assertJsonStructure(['error' => ['key', 'message']]);
    }

    public function test_authenticated_me_returns_session(): void
    {
        $this->signInOwner('india-me')->assertOk();

        $me = $this->getJson('/api/auth/me')->assertOk();

        $me->assertJsonPath('user.email', 'owner-india-me@example.com')
            ->assertJsonPath('membership.is_owner', true)
            ->assertJsonPath('branch.code', 'MAIN')
            ->assertJsonPath('warehouse.code', 'MAIN');
        $this->assertNoInternalIds($me->json());
    }
    public function test_user_can_update_font_appearance_preference(): void
    {
        $this->signInOwner('appearance-font')->assertOk();

        $this->patchJson('/api/auth/appearance', [
            'theme' => 'light',
            'skin' => 'advanced',
            'font' => 'inter',
            'primaryTheme' => 'emerald',
            'density' => 'compact',
            'radius' => 'large',
            'shadow' => '3d',
            'animations' => false,
        ])->assertOk()
            ->assertJsonPath('appearance.theme', 'light')
            ->assertJsonPath('appearance.skin', 'advanced')
            ->assertJsonPath('appearance.font', 'inter')
            ->assertJsonPath('appearance.primaryTheme', 'emerald')
            ->assertJsonPath('appearance.density', 'compact')
            ->assertJsonPath('appearance.radius', 'large')
            ->assertJsonPath('appearance.shadow', '3d')
            ->assertJsonPath('appearance.animations', false);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('appearance.font', 'inter');

        $this->patchJson('/api/auth/appearance', [
            'theme' => 'light',
            'skin' => 'advanced',
            'font' => 'not-a-font',
            'primaryTheme' => 'emerald',
            'density' => 'compact',
            'radius' => 'large',
            'shadow' => 'normal',
            'animations' => false,
        ])->assertUnprocessable();
    }

}
