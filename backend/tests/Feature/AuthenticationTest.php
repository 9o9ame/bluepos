<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use DatabaseTransactions;

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
}
