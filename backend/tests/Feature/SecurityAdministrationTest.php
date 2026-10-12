<?php

namespace Tests\Feature;

use App\Models\AuthSessionRecord;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SecurityAdministrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_can_view_tenant_sessions_with_safe_public_owner_context(): void
    {
        $session = $this->signInOwner('security-admin')->assertOk();

        $response = $this->getJson('/api/security/sessions')
            ->assertOk();

        $this->assertNotEmpty($response->json());
        $current = collect($response->json())->firstWhere('is_current', true);

        $this->assertNotNull($current);
        $this->assertSame('owner', $current['membership']['username']);
        $this->assertSame($session->json('user.name'), $current['membership']['user']['name']);
        $this->assertSame($session->json('user.ulid'), $current['membership']['user']['ulid']);
        $this->assertNoInternalIds($response->json());
    }

    public function test_security_sessions_are_tenant_scoped(): void
    {
        $this->signInOwner('security-tenant-a')->assertOk();

        $foreignUlid = AuthSessionRecord::query()
            ->whereNull('revoked_at')
            ->latest('id')
            ->value('ulid');

        $this->assertIsString($foreignUlid);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('security-tenant-b')->assertOk();

        $this->deleteJson('/api/security/sessions/'.$foreignUlid)
            ->assertNotFound()
            ->assertJsonPath('error.key', 'NOT_FOUND');

        $sessions = $this->getJson('/api/security/sessions')->assertOk();
        $this->assertFalse(
            collect($sessions->json())->contains(fn (array $row) => $row['ulid'] === $foreignUlid),
        );
        $this->assertNoInternalIds($sessions->json());
    }

    public function test_user_can_revoke_other_own_sessions_but_current_session_remains(): void
    {
        $this->signInOwner('security-others')->assertOk();

        $tenantId = app(\App\Tenancy\TenantContext::class)->tenantId();
        $userId = app(\App\Tenancy\TenantContext::class)->userId();

        AuthSessionRecord::query()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'membership_id' => app(\App\Tenancy\TenantContext::class)->membership()->id,
            'device_id' => app(\App\Tenancy\TenantContext::class)->device()->id,
            'laravel_session_id' => 'synthetic-other-session',
            'security_version' => 1,
            'ip_address' => '127.0.0.2',
            'user_agent' => 'BluePOS Security Test',
            'last_seen_at' => now()->subMinute(),
        ]);

        $this->deleteJson('/api/security/sessions/others')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $sessions = $this->getJson('/api/security/sessions')->assertOk();
        $current = collect($sessions->json())->firstWhere('is_current', true);

        $this->assertNotNull($current);
        $this->assertNull($current['revoked_at']);

        $this->assertDatabaseMissing('auth_sessions', [
            'laravel_session_id' => 'synthetic-other-session',
            'revoked_at' => null,
        ]);
    }
}
