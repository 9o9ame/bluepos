<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformContext;
use App\Models\Platform\PlatformMfaChallenge;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PlatformPersistentLoginTest extends TestCase
{
    use DatabaseTransactions;

    public function test_email_password_without_code_keeps_mfa_and_defaults_to_remember_after_verification(): void
    {
        $user = $this->createPlatformAdmin('persist-default');
        $this->postJson('/api/platform/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnauthorized()->assertJsonPath('error.key', 'INVALID_CREDENTIALS');
        $this->signInPlatformUser($user);
        $this->assertTrue(PlatformMfaChallenge::query()->sole()->remember);
        $this->assertNotEmpty($user->fresh()->getRememberToken());
        $this->getJson('/api/platform/auth/me')->assertOk()->assertJsonPath('email', $user->email);
        $this->getJson('/api/platform/auth/me')->assertOk();
        $this->postJson('/api/platform/auth/logout')->assertOk();
        $this->assertGuest('platform');
        $this->getJson('/api/platform/auth/me')->assertUnauthorized();
    }

    public function test_normal_tenant_login_and_logout_still_work(): void
    {
        $this->signInOwner('persist-tenant')->assertOk();
        $this->assertAuthenticated('web');
        $this->assertGuest('platform');
        $this->getJson('/api/auth/me')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_default_remember_cookie_restores_session_and_revocation_prevents_restoration(): void
    {
        $user = $this->createPlatformAdmin('persist-restore');
        $this->signInPlatformUser($user);
        $user->refresh();
        $recaller = Auth::guard('platform')->getRecallerName();
        $payload = implode('|', [$user->getAuthIdentifier(), $user->getRememberToken(), $user->getAuthPassword()]);
        $this->flushSession();
        Auth::forgetGuards();
        $this->withCredentials()->withCookie($recaller, $payload)->getJson('/api/platform/auth/me')
            ->assertOk()->assertJsonPath('email', $user->email);
        $this->assertNull(session(EnsurePlatformContext::MFA_AT));
        $user->bumpSecurityVersion();
        $this->flushSession();
        Auth::forgetGuards();
        $this->withCookie($recaller, $payload)->getJson('/api/platform/auth/me')->assertUnauthorized();
    }
}
