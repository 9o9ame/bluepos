<?php

namespace Tests\Feature;

use App\Actions\Auth\ProvisionTenantAction;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_public_registration_is_disabled(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registrationPayload('alpha'));

        $response->assertForbidden()
            ->assertJsonPath('error.key', 'REGISTRATION_DISABLED');
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_provisioning_creates_tenant(): void
    {
        $this->provisionOwner('bravo');

        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseHas('tenants', [
            'name' => 'Mart bravo',
            'slug' => 'mart-bravo',
            'code' => $this->tenantCode('bravo'),
            'currency_code' => 'PKR',
        ]);
    }

    public function test_provisioning_creates_owner_membership(): void
    {
        $this->provisionOwner('charlie');

        $user = User::query()->where('recovery_email', 'owner-charlie@example.com')->firstOrFail();
        $tenant = Tenant::query()->where('name', 'Mart charlie')->firstOrFail();

        $this->assertDatabaseHas('memberships', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'username' => 'owner',
            'is_owner' => true,
            'status' => 'active',
        ]);
        $this->assertSame(1, Membership::query()->count());
    }

    public function test_provisioning_creates_default_branch(): void
    {
        $this->provisionOwner('delta');

        $tenant = Tenant::query()->where('name', 'Mart delta')->firstOrFail();

        $this->assertDatabaseHas('branches', [
            'tenant_id' => $tenant->id,
            'code' => 'MAIN',
            'name' => 'Main',
            'is_default' => true,
            'status' => 'active',
        ]);
        $this->assertSame(1, Branch::query()->count());
    }

    public function test_provisioning_creates_default_warehouse(): void
    {
        $this->provisionOwner('echo');

        $tenant = Tenant::query()->where('name', 'Mart echo')->firstOrFail();
        $branch = Branch::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->assertDatabaseHas('warehouses', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'is_default' => true,
            'status' => 'active',
        ]);
        $this->assertSame(1, Warehouse::query()->count());
    }

    public function test_provisioning_rollback_works_on_failure(): void
    {
        $this->app->instance('registration.force_failure', true);

        try {
            app(ProvisionTenantAction::class)->execute([
                'name' => 'Owner foxtrot',
                'username' => 'owner',
                'recovery_email' => 'owner-foxtrot@example.com',
                'password' => 'password123',
                'tenant_name' => 'Mart foxtrot',
                'tenant_code' => 'FOXTROT',
            ]);
            $this->fail('Provisioning should have rolled back.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Forced registration failure', $e->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('branches', 0);
        $this->assertDatabaseCount('warehouses', 0);
    }
}
