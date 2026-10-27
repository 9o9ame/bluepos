<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tenant_entitlements_reports_effective_subscription_status_and_expiry(): void
    {
        $this->signInOwner('entitlement-expiry-view')->assertOk();

        $tenant = \App\Models\Tenant::query()
            ->where('code', $this->tenantCode('entitlement-expiry-view'))
            ->firstOrFail();
        $subscription = \App\Models\TenantSubscription::query()
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $subscription->status = \App\Enums\SubscriptionStatus::Active;
        $subscription->starts_at = now()->subDay();
        $subscription->ends_at = now()->addDay();
        $subscription->save();

        $this->getJson('/api/settings/entitlements')
            ->assertOk()
            ->assertJsonPath('plan.status', 'active')
            ->assertJsonPath('plan.operational', true)
            ->assertJsonPath('plan.ends_at', $subscription->ends_at->toISOString());

        $subscription->ends_at = now()->subMinute();
        $subscription->save();

        // Tenant middleware must block expired subscriptions before reaching this endpoint.
        $this->getJson('/api/settings/entitlements')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'SUBSCRIPTION_INACTIVE');
    }

    public function test_subscription_operational_state_respects_trial_and_active_dates(): void
    {
        $subscription = new \App\Models\TenantSubscription();

        $subscription->status = \App\Enums\SubscriptionStatus::Trial;
        $subscription->trial_starts_at = now()->subDays(3);
        $subscription->trial_ends_at = now()->subMinute();
        $this->assertFalse($subscription->isOperational());

        $subscription->trial_ends_at = now()->addDay();
        $this->assertTrue($subscription->isOperational());

        $subscription->trial_starts_at = now()->addDay();
        $this->assertFalse($subscription->isOperational());

        $subscription->status = \App\Enums\SubscriptionStatus::Active;
        $subscription->starts_at = now()->subDay();
        $subscription->ends_at = now()->subMinute();
        $this->assertFalse($subscription->isOperational());

        $subscription->ends_at = now()->addDay();
        $this->assertTrue($subscription->isOperational());

        $subscription->starts_at = now()->addHour();
        $this->assertFalse($subscription->isOperational());

        $subscription->starts_at = null;
        $subscription->ends_at = null;
        $this->assertTrue($subscription->isOperational());

        $subscription->status = \App\Enums\SubscriptionStatus::PastDue;
        $subscription->grace_ends_at = now()->subMinute();
        $this->assertFalse($subscription->isOperational());
        $subscription->grace_ends_at = now()->addHour();
        $this->assertTrue($subscription->isOperational());

        $subscription->status = \App\Enums\SubscriptionStatus::Suspended;
        $this->assertFalse($subscription->isOperational());
    }

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

    public function test_device_reassignment_rejects_warehouse_from_another_branch_without_mutating_device(): void
    {
        $this->signInOwner('device-reassign-branch');
        $tenant = \App\Models\Tenant::query()->where('code', $this->tenantCode('device-reassign-branch'))->firstOrFail();
        $warehouse = \App\Models\Warehouse::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $otherBranch = \App\Models\Branch::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'SECOND',
            'name' => 'Second branch',
            'status' => \App\Enums\BranchStatus::Active,
            'is_default' => false,
        ]);
        $device = \App\Models\Device::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $oldBranchId = $device->branch_id;
        $oldWarehouseId = $device->warehouse_id;

        $this->patchJson('/api/devices/'.$device->ulid, [
            'branch_ulid' => $otherBranch->ulid,
            'warehouse_ulid' => $warehouse->ulid,
        ])->assertStatus(422)->assertJsonPath('error.key', 'WAREHOUSE_BRANCH_MISMATCH');

        $this->assertSame($oldBranchId, $device->fresh()->branch_id);
        $this->assertSame($oldWarehouseId, $device->fresh()->warehouse_id);
    }

    public function test_device_approval_rejects_warehouse_from_another_branch(): void
    {
        $this->signInOwner('device-warehouse-branch');
        $tenant = \App\Models\Tenant::query()->where('code', $this->tenantCode('device-warehouse-branch'))->firstOrFail();
        $warehouse = \App\Models\Warehouse::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $otherBranch = \App\Models\Branch::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'SECOND',
            'name' => 'Second branch',
            'status' => \App\Enums\BranchStatus::Active,
            'is_default' => false,
        ]);
        $device = \App\Models\Device::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Pending branch device',
            'device_type' => 'pos_terminal',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);

        $this->postJson('/api/devices/'.$device->ulid.'/approve', [
            'branch_ulid' => $otherBranch->ulid,
            'warehouse_ulid' => $warehouse->ulid,
        ])->assertStatus(422)->assertJsonPath('error.key', 'WAREHOUSE_BRANCH_MISMATCH');

        $this->assertSame(\App\Enums\DeviceStatus::Pending, $device->fresh()->status);
        $this->assertNull($device->fresh()->branch_id);
        $this->assertNull($device->fresh()->warehouse_id);
    }

    public function test_generic_approval_rechecks_suspended_tenant_before_activating_device(): void
    {
        $this->signInOwner('generic-approval-suspended')->assertOk();
        $tenant = \App\Models\Tenant::query()
            ->where('code', $this->tenantCode('generic-approval-suspended'))->firstOrFail();
        $device = \App\Models\Device::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Pending generic approval',
            'device_type' => 'pos_terminal',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);

        // The TenantContext may already hold an active tenant model from login.
        $tenant->status = \App\Enums\TenantStatus::Suspended;
        $tenant->save();

        $this->postJson('/api/devices/'.$device->ulid.'/approve')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'TENANT_DISABLED');

        $this->assertSame(\App\Enums\DeviceStatus::Pending, $device->fresh()->status);
        $this->assertNull($device->fresh()->approved_at);
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
        $response->assertJsonPath('pending_count', 0);
        $this->assertNoInternalIds($response->json());
    }

    public function test_non_owner_cannot_read_tenant_employee_network_approval_inbox(): void
    {
        $this->signInOwner('staff-inbox-authz')->assertOk();
        $this->restrictCurrentMembershipWithoutPermission('devices.approve');

        $this->getJson('/api/devices/access-requests')->assertForbidden();
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
            ->notifyOwners($employeeTenant->membership, 'Pending browser', '203.0.113.15');

        \Illuminate\Support\Facades\Notification::assertSentOnDemand(
            \App\Notifications\EmployeeDeviceApprovalNotification::class,
            function ($notification, $channels, $notifiable) {
                return ($notifiable->routes['mail'] ?? null) === 'owner-approval-mail-tenant-a@example.com'
                    && $notification->deviceName === 'Pending browser'
                    && $notification->networkIp === '203.0.113.15';
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
            ->assertNotFound();
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

    public function test_device_list_reports_legacy_and_managed_employee_access_without_switching_policy(): void
    {
        $owner = $this->provisionOwner('device-policy-preview');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $legacy = \App\Models\Device::query()
            ->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $browser = \App\Models\Device::query()->create([
            'tenant_id' => $owner->tenant->id,
            'name' => 'Managed browser',
            'device_type' => 'browser',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('device-policy-preview'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $response = $this->getJson('/api/devices')->assertOk();
        $this->assertNoInternalIds($response->json());
        $policies = collect($response->json())->pluck('employee_access_policy', 'ulid');
        $this->assertSame('legacy_grandfathered', $policies->get($legacy->ulid));
        $this->assertSame('managed', $policies->get($browser->ulid));
        $this->assertSame(\App\Enums\DeviceStatus::Active, $legacy->fresh()->status);
    }

    public function test_device_policy_preview_is_tenant_isolated_and_marks_managed_terminal(): void
    {
        $first = $this->provisionOwner('policy-isolation-a');
        $second = $this->provisionOwner('policy-isolation-b');
        $this->attachTrustedDevice((int) $first->tenant->id);
        $firstDevice = \App\Models\Device::query()->where('tenant_id', $first->tenant->id)->firstOrFail();
        $this->attachTrustedDevice((int) $second->tenant->id);
        $secondDevice = \App\Models\Device::query()->where('tenant_id', $second->tenant->id)->firstOrFail();
        // Restore the first tenant's device credential for its login and subsequent requests.
        $this->attachTrustedDevice((int) $first->tenant->id);

        \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $second->tenant->id,
            'membership_id' => $second->membership->id,
            'device_id' => $secondDevice->id,
            'network_hash' => hash('sha256', 'second-only-network'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('policy-isolation-a'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $firstResponse = $this->getJson('/api/devices')->assertOk();
        $this->assertNoInternalIds($firstResponse->json());
        $firstPolicies = collect($firstResponse->json())->pluck('employee_access_policy', 'ulid');
        $this->assertSame('legacy_grandfathered', $firstPolicies->get($firstDevice->ulid));
        $this->assertFalse($firstPolicies->has($secondDevice->ulid));

        \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $first->tenant->id,
            'membership_id' => $first->membership->id,
            'device_id' => $firstDevice->id,
            'network_hash' => hash('sha256', 'first-network'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $updated = $this->getJson('/api/devices')->assertOk();
        $this->assertSame(
            'managed',
            collect($updated->json())->pluck('employee_access_policy', 'ulid')->get($firstDevice->ulid)
        );
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
            ->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED')->assertJsonPath('error.approval_status', 'pending');

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
            ->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED')
            ->assertJsonPath('error.approval_status', 'pending');

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
            ->assertForbidden()->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED')
            ->assertJsonPath('error.approval_status', 'rejected');
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

    public function test_owner_can_filter_and_paginate_employee_device_review_history(): void
    {
        $owner = $this->provisionOwner('device-review-history');
        $employee = \App\Models\User::query()->create([
            'name' => 'History Employee',
            'email' => 'history-employee@example.com',
            'password' => 'password123',
            'status' => \App\Enums\UserStatus::Active,
            'must_change_password' => false,
            'security_version' => 1,
        ]);
        $member = \App\Models\Membership::query()->create([
            'tenant_id' => $owner->tenant->id,
            'user_id' => $employee->id,
            'username' => 'historyemployee',
            'status' => \App\Enums\MembershipStatus::Active,
            'is_owner' => false,
            'security_version' => 1,
        ]);
        $device = \App\Models\Device::query()->create([
            'tenant_id' => $owner->tenant->id,
            'name' => 'History test device',
            'device_type' => 'browser',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        $ulids = [];
        foreach (['pending', 'approved', 'rejected'] as $status) {
            $ulid = (string) \Illuminate\Support\Str::ulid();
            $ulids[$status] = $ulid;
            \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
                'ulid' => $ulid,
                'tenant_id' => $owner->tenant->id,
                'membership_id' => $member->id,
                'device_id' => $device->id,
                'network_hash' => hash('sha256', 'history-'.$status),
                'status' => $status,
                'request_ip' => '203.0.113.24',
                'request_user_agent' => 'BluePOS test browser',
                'reviewed_at' => $status === 'pending' ? null : now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('device-review-history'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $this->getJson('/api/devices/access-requests')
            ->assertOk()->assertJsonPath('pending_count', 1)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('requests.0.ulid', $ulids['pending'])
            ->assertJsonPath('requests.0.username', 'historyemployee')
            ->assertJsonPath('requests.0.device_name', 'History test device')
            ->assertJsonPath('requests.0.request_ip', '203.0.113.24')
            ->assertJsonPath('requests.0.request_user_agent', 'BluePOS test browser');
        $this->getJson('/api/devices/access-requests?status=approved')
            ->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('requests.0.status', 'approved')
            ->assertJsonPath('requests.0.ulid', $ulids['approved']);
        $this->getJson('/api/devices/access-requests?status=all&per_page=1&page=2')
            ->assertOk()->assertJsonPath('pagination.total', 3)
            ->assertJsonPath('pagination.per_page', 1)
            ->assertJsonCount(1, 'requests');
        $this->getJson('/api/devices/access-requests?status=all&membership_ulid='.$member->ulid)
            ->assertOk()->assertJsonPath('pagination.total', 3);
        $this->getJson('/api/devices/access-requests?status=approved&device_ulid='.$device->ulid)
            ->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('requests.0.reviewer_name', null);
        $this->getJson('/api/devices/access-requests?status=all&device_ulid='.(string) \Illuminate\Support\Str::ulid())
            ->assertOk()->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('pending_count', 1);
        $this->getJson('/api/devices/access-requests?membership_ulid=invalid')->assertUnprocessable();
        $this->getJson('/api/devices/access-requests?device_ulid=invalid')->assertUnprocessable();
        $this->getJson('/api/devices/access-requests?status=invalid')->assertUnprocessable();
        $this->getJson('/api/devices/access-requests?per_page=101')->assertUnprocessable();
    }

    public function test_employee_access_review_rejects_ineligible_membership_or_device_without_mutation(): void
    {
        $owner = $this->provisionOwner('review-ineligible');
        $employee = \App\Models\User::query()->create([
            'name' => 'Ineligible Employee',
            'email' => 'review-ineligible-staff@example.com',
            'password' => 'password123',
            'status' => \App\Enums\UserStatus::Active,
            'must_change_password' => false,
            'security_version' => 1,
        ]);
        $member = \App\Models\Membership::query()->create([
            'tenant_id' => $owner->tenant->id,
            'user_id' => $employee->id,
            'username' => 'ineligibleemployee',
            'status' => \App\Enums\MembershipStatus::Active,
            'is_owner' => false,
            'security_version' => 1,
        ]);
        $device = \App\Models\Device::query()->create([
            'tenant_id' => $owner->tenant->id,
            'name' => 'Ineligible review device',
            'device_type' => 'browser',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        $ulid = (string) \Illuminate\Support\Str::ulid();
        \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
            'ulid' => $ulid,
            'tenant_id' => $owner->tenant->id,
            'membership_id' => $member->id,
            'device_id' => $device->id,
            'network_hash' => hash('sha256', 'review-ineligible-network'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('review-ineligible'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $url = '/api/devices/access-requests/'.$ulid.'/review';
        $member->status = \App\Enums\MembershipStatus::Suspended;
        $member->save();

        foreach (['approved', 'rejected'] as $decision) {
            $this->postJson($url, ['decision' => $decision])
                ->assertStatus(409)->assertJsonPath('error.key', 'ACCESS_REQUEST_INVALID');
        }
        $this->assertSame('pending', \Illuminate\Support\Facades\DB::table('device_access_requests')->where('ulid', $ulid)->value('status'));
        $this->assertSame(\App\Enums\DeviceStatus::Pending, $device->fresh()->status);

        $member->status = \App\Enums\MembershipStatus::Active;
        $member->save();
        $employee->status = \App\Enums\UserStatus::Disabled;
        $employee->save();
        foreach (['approved', 'rejected'] as $decision) {
            $this->postJson($url, ['decision' => $decision])
                ->assertStatus(409)->assertJsonPath('error.key', 'ACCESS_REQUEST_INVALID');
        }
        $this->assertSame('pending', \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('ulid', $ulid)->value('status'));
        $this->assertSame(\App\Enums\DeviceStatus::Pending, $device->fresh()->status);

        $employee->status = \App\Enums\UserStatus::Active;
        $employee->save();
        $device->status = \App\Enums\DeviceStatus::Revoked;
        $device->save();
        foreach (['approved', 'rejected'] as $decision) {
            $this->postJson($url, ['decision' => $decision])
                ->assertStatus(409)->assertJsonPath('error.key', 'DEVICE_NOT_APPROVED');
        }
        $this->assertSame('pending', \Illuminate\Support\Facades\DB::table('device_access_requests')->where('ulid', $ulid)->value('status'));
        $this->assertSame(\App\Enums\DeviceStatus::Revoked, $device->fresh()->status);
    }

    public function test_employee_access_review_rejects_owner_membership_and_invalid_decisions(): void
    {
        $owner = $this->provisionOwner('review-validation');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $ulid = (string) \Illuminate\Support\Str::ulid();
        \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
            'ulid' => $ulid,
            'tenant_id' => $owner->tenant->id,
            'membership_id' => $owner->membership->id,
            'device_id' => $device->id,
            'network_hash' => hash('sha256', 'review-validation-network'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('review-validation'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $url = '/api/devices/access-requests/'.$ulid.'/review';
        foreach (['invalid', 'pending', ''] as $decision) {
            $this->postJson($url, ['decision' => $decision])->assertUnprocessable();
        }
        $this->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['decision' => 'approved'])
            ->assertStatus(409)->assertJsonPath('error.key', 'ACCESS_REQUEST_INVALID');
        $this->assertSame('pending', \Illuminate\Support\Facades\DB::table('device_access_requests')->where('ulid', $ulid)->value('status'));
    }

    public function test_inactive_branch_blocks_existing_and_new_offline_authorization(): void
    {
        $owner = $this->provisionOwner('offline-inactive-branch');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $service = app(\App\Security\OfflineAuthorizationService::class);
        $lease = $service->issue($owner->membership, $device, $owner->branch, []);
        $service->assertValid($lease, $device, $owner->membership);

        $owner->branch->status = \App\Enums\BranchStatus::Inactive;
        $owner->branch->save();

        try {
            $service->assertValid($lease, $device, $owner->membership);
            $this->fail('Inactive branch retained an offline lease.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $exception->errorKey);
        }

        try {
            $service->issue($owner->membership, $device, $owner->branch, []);
            $this->fail('Inactive branch received a new offline lease.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $exception->errorKey);
        }

        $this->assertSame(1, \App\Models\OfflineAuthorizationLease::query()
            ->where('tenant_id', $owner->tenant->id)->count());
    }

    public function test_offline_lease_is_invalid_after_device_branch_reassignment(): void
    {
        $owner = $this->provisionOwner('offline-reassign-branch');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $service = app(\App\Security\OfflineAuthorizationService::class);
        $lease = $service->issue($owner->membership, $device, $owner->branch, []);
        $service->assertValid($lease, $device, $owner->membership);

        $newBranch = \App\Models\Branch::query()->create([
            'tenant_id' => $owner->tenant->id,
            'name' => 'Reassigned branch',
            'code' => 'REASSIGN',
            'status' => \App\Enums\BranchStatus::Active,
            'is_default' => false,
        ]);
        $device->branch_id = $newBranch->id;
        $device->save();

        try {
            $service->assertValid($lease, $device, $owner->membership);
            $this->fail('Branch reassignment must invalidate old offline authorization.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $exception->errorKey);
        }
    }

    public function test_disabling_user_invalidates_existing_offline_lease_and_prevents_new_issuance(): void
    {
        $owner = $this->provisionOwner('offline-disabled-user');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()
            ->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $service = app(\App\Security\OfflineAuthorizationService::class);
        $lease = $service->issue($owner->membership, $device, $owner->branch, []);
        $service->assertValid($lease, $device, $owner->membership);

        $owner->user->status = \App\Enums\UserStatus::Disabled;
        $owner->user->save();

        try {
            $service->assertValid($lease, $device, $owner->membership);
            $this->fail('Disabled user retained a previously valid offline lease.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $exception->errorKey);
        }

        try {
            $service->issue($owner->membership, $device, $owner->branch, []);
            $this->fail('Disabled user received a new offline lease.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $exception->errorKey);
        }

        $this->assertSame(1, \App\Models\OfflineAuthorizationLease::query()
            ->where('tenant_id', $owner->tenant->id)->count());
    }

    public function test_suspending_tenant_invalidates_previously_issued_offline_lease(): void
    {
        $owner = $this->provisionOwner('lease-tenant-suspend');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()
            ->where('tenant_id', $owner->tenant->id)->firstOrFail();

        $offline = app(\App\Security\OfflineAuthorizationService::class);
        $lease = $offline->issue($owner->membership, $device, $owner->branch, []);
        $offline->assertValid($lease, $device, $owner->membership);

        $owner->tenant->status = \App\Enums\TenantStatus::Suspended;
        $owner->tenant->save();

        try {
            $offline->assertValid($lease, $device, $owner->membership);
            $this->fail('A suspended tenant must invalidate an issued offline lease.');
        } catch (\App\Exceptions\ApiException $e) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $e->errorKey);
        }
    }

    public function test_employee_access_review_rolls_back_if_audit_write_fails(): void
    {
        $owner = $this->provisionOwner('review-audit-rollback');
        $employee = \App\Models\User::query()->create([
            'name' => 'Review Employee',
            'email' => 'review-audit-rollback-staff@example.com',
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
            'network_hash' => hash('sha256', 'review-audit-rollback-network'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->attachTrustedDevice((int) $owner->tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('review-audit-rollback'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $logger = \Mockery::mock(\App\Security\AuditLogger::class);
        $logger->shouldReceive('record')->once()->andThrow(new \RuntimeException('Simulated audit storage failure'));
        app()->instance(\App\Security\AuditLogger::class, $logger);

        try {
            $this->postJson('/api/devices/access-requests/'.$ulid.'/review', [
                'decision' => 'approved',
            ])->assertStatus(500);
        } finally {
            app()->forgetInstance(\App\Security\AuditLogger::class);
        }

        $this->assertSame('pending', \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('ulid', $ulid)->value('status'));
        $this->assertSame(\App\Enums\DeviceStatus::Pending, $device->fresh()->status);
        $this->assertSame(0, \App\Models\AuditLog::query()
            ->where('tenant_id', $owner->tenant->id)
            ->where('resource_ulid', $ulid)
            ->count());
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
        $this->assertSame(1, \App\Models\AuditLog::query()
            ->where('tenant_id', $owner->tenant->id)
            ->where('event', 'DEVICE_ACCESS_REJECTED')
            ->where('resource_ulid', $ulid)
            ->count());
        $this->assertSame(0, \App\Models\AuditLog::query()
            ->where('tenant_id', $owner->tenant->id)
            ->where('event', 'DEVICE_ACCESS_APPROVED')
            ->where('resource_ulid', $ulid)
            ->count());

    }

    public function test_approved_employee_access_review_cannot_be_reversed_by_rejection(): void
    {
        $owner = $this->provisionOwner('review-approved-once');
        $employee = \App\Models\User::query()->create([
            'name' => 'Review Employee',
            'email' => 'review-approved-once-staff@example.com',
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
            'network_hash' => hash('sha256', 'review-approved-once-network'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->attachTrustedDevice((int) $owner->tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('review-approved-once'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $url = '/api/devices/access-requests/'.$ulid.'/review';
        $this->postJson($url, ['decision' => 'approved'])
            ->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson($url, ['decision' => 'rejected'])
            ->assertStatus(409)
            ->assertJsonPath('error.key', 'ACCESS_REQUEST_ALREADY_REVIEWED');
        $this->assertSame('approved', \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('ulid', $ulid)->value('status'));
        $this->assertSame(\App\Enums\DeviceStatus::Active, $device->fresh()->status);
        $this->assertSame(1, \App\Models\AuditLog::query()
            ->where('tenant_id', $owner->tenant->id)
            ->where('event', 'DEVICE_ACCESS_APPROVED')
            ->where('resource_ulid', $ulid)
            ->count());
        $this->assertSame(0, \App\Models\AuditLog::query()
            ->where('tenant_id', $owner->tenant->id)
            ->where('event', 'DEVICE_ACCESS_REJECTED')
            ->where('resource_ulid', $ulid)
            ->count());

    }

    public function test_offline_lease_issuance_rejects_disabled_tenant(): void
    {
        $owner = $this->provisionOwner('offline-disabled-tenant');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $owner->tenant->status = \App\Enums\TenantStatus::Suspended;
        $owner->tenant->save();

        try {
            app(\App\Security\OfflineAuthorizationService::class)
                ->issue($owner->membership, $device, $owner->branch, []);
            $this->fail('A disabled tenant was issued an offline lease.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('TENANT_DISABLED', $exception->errorKey);
        }

        $this->assertSame(0, \App\Models\OfflineAuthorizationLease::query()
            ->where('tenant_id', $owner->tenant->id)->count());
    }

    public function test_offline_lease_issuance_rejects_another_tenants_branch(): void
    {
        $first = $this->provisionOwner('offline-branch-first');
        $second = $this->provisionOwner('offline-branch-second');
        $this->attachTrustedDevice((int) $first->tenant->id);
        $device = \App\Models\Device::query()->where('tenant_id', $first->tenant->id)->firstOrFail();

        try {
            app(\App\Security\OfflineAuthorizationService::class)
                ->issue($first->membership, $device, $second->branch, []);
            $this->fail('Cross-tenant offline authorization was issued.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $exception->errorKey);
        }

        $this->assertSame(0, \App\Models\OfflineAuthorizationLease::query()
            ->where('membership_id', $first->membership->id)->count());
    }

    public function test_offline_validation_rejects_stale_models_after_device_revocation(): void
    {
        $owner = $this->provisionOwner('offline-validate-stale');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $membership = $owner->membership->fresh(['user', 'tenant']);
        $service = app(\App\Security\OfflineAuthorizationService::class);
        $lease = $service->issue($membership, $device, $owner->branch, []);
        $service->assertValid($lease, $device, $membership);

        // Keep stale copies deliberately: revocation changes only persisted rows.
        app(\App\Security\SessionRevocationService::class)->revokeDevice($device->fresh());

        try {
            $service->assertValid($lease, $device, $membership);
            $this->fail('Stale lease and device objects bypassed revocation.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $exception->errorKey);
        }
    }

    public function test_offline_lease_cannot_be_issued_from_stale_device_after_revocation(): void
    {
        $owner = $this->provisionOwner('offline-stale-device');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $staleDevice = $device->fresh();
        $this->assertTrue($staleDevice->isActive());

        app(\App\Security\SessionRevocationService::class)->revokeDevice($device);

        try {
            app(\App\Security\OfflineAuthorizationService::class)
                ->issue($owner->membership, $staleDevice, $owner->branch, []);
            $this->fail('A stale device object permitted a new offline lease.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $exception->errorKey);
        }

        $this->assertSame(0, \App\Models\OfflineAuthorizationLease::query()
            ->where('device_id', $device->id)->count());
    }

    public function test_offline_lease_issuance_rejects_revoked_device(): void
    {
        $owner = $this->provisionOwner('offline-issue-revoked');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()->where('tenant_id', $owner->tenant->id)->firstOrFail();
        app(\App\Security\SessionRevocationService::class)->revokeDevice($device);

        try {
            app(\App\Security\OfflineAuthorizationService::class)
                ->issue($owner->membership, $device->fresh(), $owner->branch, []);
            $this->fail('A revoked device was issued an offline lease.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('DEVICE_NOT_APPROVED', $exception->errorKey);
        }

        $this->assertSame(0, \App\Models\OfflineAuthorizationLease::query()
            ->where('device_id', $device->id)->count());
    }

    public function test_offline_lease_rejects_revoked_device_and_changed_membership_security_version(): void
    {
        $owner = $this->provisionOwner('offline-revocation');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()
            ->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $service = app(\App\Security\OfflineAuthorizationService::class);
        $lease = $service->issue($owner->membership, $device, $owner->branch, []);

        $service->assertValid($lease, $device, $owner->membership);
        $owner->membership->bumpSecurityVersion();
        try {
            $service->assertValid($lease, $device, $owner->membership->fresh());
            $this->fail('A stale offline lease was accepted after the membership security version changed.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $exception->errorKey);
        }

        $freshLease = $service->issue($owner->membership->fresh(), $device, $owner->branch, []);
        app(\App\Security\SessionRevocationService::class)->revokeDevice($device);
        $this->assertNotNull($freshLease->fresh()->revoked_at);
        try {
            $service->assertValid($freshLease->fresh(), $device->fresh(), $owner->membership->fresh());
            $this->fail('A revoked device lease was accepted.');
        } catch (\App\Exceptions\ApiException $exception) {
            $this->assertSame('OFFLINE_AUTH_EXPIRED', $exception->errorKey);
        }
    }

    public function test_platform_cannot_bypass_pending_employee_device_review(): void
    {
        $owner = $this->provisionOwner('platform-review-bypass');
        $device = \App\Models\Device::query()->create([
            'tenant_id' => $owner->tenant->id,
            'name' => 'Employee device awaiting owner',
            'device_type' => 'browser',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'tenant_id' => $owner->tenant->id,
            'membership_id' => $owner->membership->id,
            'device_id' => $device->id,
            'network_hash' => hash('sha256', 'platform-review-bypass'),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->createPlatformAdmin('platform-review-bypass');
        $this->signInPlatformAdmin('platform-review-bypass');

        $this->postJson('/api/platform/tenants/'.$owner->tenant->ulid.'/devices/'.$device->ulid.'/approve')
            ->assertNotFound();
        $this->assertSame(\App\Enums\DeviceStatus::Pending, $device->fresh()->status);
    }

    public function test_platform_device_activity_denies_staff_without_audit_permission(): void
    {
        $staff = $this->createPlatformStaff('without-audit-activity', ['platform.dashboard.view']);
        $this->signInPlatformUser($staff);

        $this->getJson('/api/platform/security/device-activity')->assertForbidden();
    }

    public function test_platform_device_activity_requires_platform_audit_authorization_and_exposes_ulids(): void
    {
        $this->getJson('/api/platform/security/device-activity')->assertUnauthorized();

        $owner = $this->provisionOwner('platform-device-overview');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('platform-device-overview'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertOk();

        $this->createPlatformAdmin('device-overview-admin');
        $this->signInPlatformAdmin('device-overview-admin');
        $response = $this->getJson('/api/platform/security/device-activity')->assertOk();
        $this->assertIsArray($response->json('sessions'));
        $this->assertIsArray($response->json('events'));
        $this->assertNoInternalIds($response->json());
        $tenantUlids = collect($response->json('sessions'))
            ->pluck('tenant.ulid')->filter()->all();
        $this->assertContains($owner->tenant->ulid, $tenantUlids);
        // Login success is recorded in the centralized, audit-permission-protected feed.
        $loginEvents = collect($response->json('events'))->where('event', 'LOGIN_SUCCESS');
        $this->assertTrue($loginEvents->contains(fn ($row) => ($row['tenant']['ulid'] ?? null) === $owner->tenant->ulid));
        $filtered = $this->getJson('/api/platform/security/device-activity?tenant_code='.$owner->tenant->code.'&event=LOGIN_SUCCESS')->assertOk();
        $this->assertNotEmpty($filtered->json('events'));
        $this->assertTrue(collect($filtered->json('events'))->every(fn ($row) => $row['event'] === 'LOGIN_SUCCESS' && ($row['tenant']['ulid'] ?? null) === $owner->tenant->ulid));
        $this->assertTrue(collect($filtered->json('sessions'))->every(fn ($row) => ($row['tenant']['ulid'] ?? null) === $owner->tenant->ulid));
        $this->getJson('/api/platform/security/device-activity?username=owner')->assertUnprocessable();
        $this->getJson('/api/platform/security/device-activity?event=INVALID')->assertUnprocessable();
        $this->getJson('/api/platform/security/device-activity?ip_address=not-an-ip')->assertUnprocessable();
        $this->getJson('/api/platform/security/device-activity?since=2026-10-30&until=2026-10-01')->assertUnprocessable();
        $today = now()->toDateString();
        $dateScoped = $this->getJson('/api/platform/security/device-activity?tenant_code='.$owner->tenant->code.'&since='.$today.'&until='.$today)->assertOk();
        $this->assertNotEmpty($dateScoped->json('events'));
        $this->assertTrue(collect($dateScoped->json('events'))->every(fn ($row) => substr($row['occurred_at'] ?? '', 0, 10) === $today));
        $ipFiltered = $this->getJson('/api/platform/security/device-activity?tenant_code='.$owner->tenant->code.'&ip_address=127.0.0.1')->assertOk();
        $this->assertTrue(collect($ipFiltered->json('sessions'))->every(fn ($row) => $row['ip_address'] === '127.0.0.1'));
        $this->assertTrue(collect($ipFiltered->json('events'))->every(fn ($row) => $row['ip_address'] === '127.0.0.1'));
        $this->getJson('/api/platform/security/device-activity?ip_address=203.0.113.254')->assertOk()
            ->assertJsonCount(0, 'sessions')->assertJsonCount(0, 'events');
        $this->getJson('/api/platform/security/device-activity?tenant_code=UNKNOWN_NO_TENANT')->assertOk()
            ->assertJsonCount(0, 'events')->assertJsonCount(0, 'sessions');

        $tenantActivity = $this->getJson('/api/platform/tenants/'.$owner->tenant->ulid.'/login-activity')->assertOk();
        $this->assertIsArray($tenantActivity->json('sessions'));
        $this->assertIsArray($tenantActivity->json('events'));
        $this->assertNoInternalIds($tenantActivity->json());
        $ownerSession = collect($tenantActivity->json('sessions'))
            ->first(fn ($row) => ($row['membership']['username'] ?? null) === 'owner');
        $this->assertNotNull($ownerSession);
        $this->assertContains('owner', collect($ownerSession['membership']['roles'])->pluck('code')->all());
    }

    public function test_platform_tenant_device_review_history_is_read_only_and_tenant_scoped(): void
    {
        $tenantA = $this->provisionOwner('platform-review-a');
        $tenantB = $this->provisionOwner('platform-review-b');
        $device = \App\Models\Device::query()->create([
            'tenant_id' => $tenantA->tenant->id,
            'name' => 'Approval browser',
            'device_type' => 'browser',
            'status' => \App\Enums\DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        $requestUlid = (string) \Illuminate\Support\Str::ulid();
        \Illuminate\Support\Facades\DB::table('device_access_requests')->insert([
            'ulid' => $requestUlid,
            'tenant_id' => $tenantA->tenant->id,
            'membership_id' => $tenantA->membership->id,
            'device_id' => $device->id,
            'network_hash' => hash('sha256', 'platform-monitor-request'),
            'request_ip' => '203.0.113.41',
            'request_user_agent' => 'BluePOS monitoring test',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/platform/tenants/'.$tenantA->tenant->ulid.'/devices')->assertUnauthorized();
        $this->createPlatformAdmin('approval-monitor-admin');
        $this->signInPlatformAdmin('approval-monitor-admin');

        $response = $this->getJson('/api/platform/tenants/'.$tenantA->tenant->ulid.'/devices')
            ->assertOk()->assertJsonPath('access_requests.0.ulid', $requestUlid)
            ->assertJsonPath('access_requests.0.request_ip', '203.0.113.41')
            ->assertJsonPath('access_requests.0.username', 'owner');
        $this->assertNoInternalIds($response->json());
        $this->getJson('/api/platform/tenants/'.$tenantB->tenant->ulid.'/devices')
            ->assertOk()->assertJsonCount(0, 'access_requests');
        $this->assertSame('pending', \Illuminate\Support\Facades\DB::table('device_access_requests')
            ->where('ulid', $requestUlid)->value('status'));
    }

    public function test_failed_login_records_tenant_scope_without_user_identity_leak(): void
    {
        $owner = $this->provisionOwner('failure-monitoring');
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('failure-monitoring'),
            'username' => 'owner',
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $entry = \App\Models\AuditLog::query()->where('event', 'LOGIN_FAILURE')
            ->where('tenant_id', $owner->tenant->id)->latest('id')->firstOrFail();
        $this->assertNull($entry->actor_membership_id);
        $this->assertArrayHasKey('username_hash', $entry->metadata);
        $this->assertArrayNotHasKey('username', $entry->metadata);

        $this->createPlatformAdmin('failure-monitor-admin');
        $this->signInPlatformAdmin('failure-monitor-admin');
        $response = $this->getJson('/api/platform/security/device-activity?tenant_code='.$owner->tenant->code.'&event=LOGIN_FAILURE')
            ->assertOk();
        $this->assertNotEmpty($response->json('events'));
        $this->assertSame($owner->tenant->ulid, $response->json('events.0.tenant.ulid'));
        $this->assertNull($response->json('events.0.membership'));
        $this->assertNoInternalIds($response->json());
    }

    public function test_unapproved_employee_login_is_audited_as_blocked_without_secrets(): void
    {
        $owner = $this->provisionOwner('blocked-monitor');
        $this->attachTrustedDevice((int) $owner->tenant->id);
        $device = \App\Models\Device::query()->where('tenant_id', $owner->tenant->id)->firstOrFail();
        $device->device_type = 'browser';
        $device->save();
        $employee = \App\Models\Membership::query()->where('tenant_id', $owner->tenant->id)->where('username', 'owner')->firstOrFail();
        // A tenant owner's device uses OTP rather than owner review, so exercise a revoked device instead.
        $device->status = \App\Enums\DeviceStatus::Revoked;
        $device->save();
        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('blocked-monitor'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertForbidden()->assertJsonPath('error.key', 'DEVICE_REVOKED');

        $entry = \App\Models\AuditLog::query()->where('tenant_id', $owner->tenant->id)
            ->where('event', 'LOGIN_BLOCKED')->latest('id')->firstOrFail();
        $this->assertSame($employee->id, $entry->actor_membership_id);
        $this->assertSame('DEVICE_REVOKED', $entry->metadata['reason']);
        $this->assertArrayNotHasKey('password', $entry->metadata);

        $this->createPlatformAdmin('blocked-monitor-admin');
        $this->signInPlatformAdmin('blocked-monitor-admin');
        $this->getJson('/api/platform/security/device-activity?tenant_code='.$owner->tenant->code.'&event=LOGIN_BLOCKED')
            ->assertOk()->assertJsonPath('events.0.event', 'LOGIN_BLOCKED')
            ->assertJsonPath('events.0.reason', 'DEVICE_REVOKED')
            ->assertJsonPath('events.0.membership.username', 'owner');
    }

    public function test_disabled_member_login_is_blocked_and_audited_without_credentials(): void
    {
        $owner = $this->provisionOwner('disabled-login-monitor');
        $owner->membership->status = \App\Enums\MembershipStatus::Suspended;
        $owner->membership->save();

        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('disabled-login-monitor'),
            'username' => 'owner',
            'password' => 'password123',
        ])->assertForbidden()->assertJsonPath('error.key', 'ACCOUNT_DISABLED');

        $entry = \App\Models\AuditLog::query()
            ->where('tenant_id', $owner->tenant->id)
            ->where('event', 'LOGIN_BLOCKED')->latest('id')->firstOrFail();
        $this->assertSame($owner->membership->id, $entry->actor_membership_id);
        $this->assertSame('ACCOUNT_DISABLED', $entry->metadata['reason']);
        $this->assertArrayNotHasKey('password', $entry->metadata);
    }

    public function test_suspended_tenant_login_records_block_without_unverified_identity(): void
    {
        $owner = $this->provisionOwner('suspended-login-monitor');
        $owner->tenant->status = \App\Enums\TenantStatus::Suspended;
        $owner->tenant->save();

        $this->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('suspended-login-monitor'),
            'username' => 'owner',
            'password' => 'incorrect-password',
        ])->assertForbidden()->assertJsonPath('error.key', 'TENANT_DISABLED');

        $event = \App\Models\AuditLog::query()->where('tenant_id', $owner->tenant->id)
            ->where('event', 'LOGIN_BLOCKED')->latest('id')->firstOrFail();
        $this->assertNull($event->actor_membership_id);
        $this->assertNull($event->actor_user_id);
        $this->assertSame('TENANT_DISABLED', $event->metadata['reason']);
        $this->assertArrayNotHasKey('password', $event->metadata);

        $this->createPlatformAdmin('suspended-login-admin');
        $this->signInPlatformAdmin('suspended-login-admin');
        $this->getJson('/api/platform/security/device-activity?tenant_code='.$owner->tenant->code.'&event=LOGIN_BLOCKED')
            ->assertOk()->assertJsonPath('events.0.event', 'LOGIN_BLOCKED')
            ->assertJsonPath('events.0.membership', null);
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
