<?php

namespace Tests\Feature;

use App\Enums\PlatformUserStatus;
use App\Http\Middleware\EnsurePlatformContext;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\PlatformSession;
use App\Models\Platform\PlatformUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PlatformMfaDisabledTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.platform_mfa_enabled' => false]);
        Notification::fake();
    }

    public function test_password_login_reaches_dashboard_without_mfa_or_fake_verification(): void
    {
        $user = $this->createPlatformAdmin('disabled-direct');
        $this->login($user)->assertOk()->assertJsonPath('mfa.enabled', false);
        $this->getJson('/api/platform/auth/me')->assertOk()->assertJsonPath('email', $user->email);
        $this->getJson('/api/platform/dashboard')->assertOk();
        $this->assertDatabaseCount('platform_mfa_challenges', 0);
        $this->assertNull(session(EnsurePlatformContext::MFA_AT));
        $this->assertNull($user->fresh()->last_mfa_verified_at);
        $this->assertFalse(PlatformAuditLog::query()->where('event', 'PLATFORM_MFA_SUCCESS')->exists());
        Notification::assertNothingSent();
    }

    public function test_wrong_password_and_disabled_account_still_cannot_login(): void
    {
        $user = $this->createPlatformAdmin('disabled-invalid');
        $this->postJson('/api/platform/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnauthorized()->assertJsonPath('error.key', 'INVALID_CREDENTIALS');
        $user->forceFill(['status' => PlatformUserStatus::Inactive])->save();
        $this->login($user)->assertForbidden()->assertJsonPath('error.key', 'ACCOUNT_DISABLED');
        $this->assertGuest('platform');
        Notification::assertNothingSent();
    }

    public function test_selected_remember_cookie_restores_access_after_sign_out(): void
    {
        $user = $this->createPlatformAdmin('disabled-retain');
        $recaller = Auth::guard('platform')->getRecallerName();
        $login = $this->login($user)->assertOk();
        $cookie = $login->getCookie($recaller, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $token = $user->fresh()->getRememberToken();
        $sessionUlid = session(EnsurePlatformContext::SESSION_ULID);

        $this->withCredentials()->withUnencryptedCookies([$recaller => $cookie->getValue()])
            ->postJson('/api/platform/auth/logout')->assertOk()->assertCookieMissing($recaller);
        $this->assertGuest('platform');
        $this->assertSame($token, $user->fresh()->getRememberToken());
        $this->assertNotNull(PlatformSession::query()->where('ulid', $sessionUlid)->sole()->revoked_at);

        Auth::forgetGuards();
        $this->getJson('/api/platform/auth/me')->assertOk()->assertJsonPath('email', $user->email);
        $this->assertNotSame($sessionUlid, session(EnsurePlatformContext::SESSION_ULID));
        $this->assertNull(session(EnsurePlatformContext::MFA_AT));
        Notification::assertNothingSent();
    }

    public function test_unchecked_remember_does_not_issue_a_cookie_or_restore_after_logout(): void
    {
        $user = $this->createPlatformAdmin('disabled-session');
        $recaller = Auth::guard('platform')->getRecallerName();
        $this->login($user, false)->assertOk()->assertCookieMissing($recaller);
        $this->postJson('/api/platform/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/platform/auth/me')->assertUnauthorized();
    }

    public function test_unchecking_remember_clears_an_existing_browser_cookie(): void
    {
        $user = $this->createPlatformAdmin('disabled-uncheck');
        $recaller = Auth::guard('platform')->getRecallerName();
        $cookie = $this->login($user)->assertOk()->getCookie($recaller, false);
        $this->withCredentials()->withUnencryptedCookies([$recaller => $cookie->getValue()]);
        $this->login($user, false)->assertOk()->assertCookieExpired($recaller);
        $this->postJson('/api/platform/auth/logout')->assertOk()->assertCookieExpired($recaller);
    }

    public function test_logout_all_revokes_the_retained_browser_cookie(): void
    {
        $user = $this->createPlatformAdmin('disabled-all');
        $recaller = Auth::guard('platform')->getRecallerName();
        $cookie = $this->login($user)->assertOk()->getCookie($recaller, false);
        $this->withCredentials()->withUnencryptedCookies([$recaller => $cookie->getValue()])
            ->postJson('/api/platform/auth/logout-all')->assertOk()->assertCookieExpired($recaller);
        $this->flushSession();
        Auth::forgetGuards();
        $this->getJson('/api/platform/auth/me')->assertUnauthorized();
    }

    public function test_account_disablement_blocks_remembered_access(): void
    {
        $user = $this->createPlatformAdmin('disabled-revoke');
        $recaller = Auth::guard('platform')->getRecallerName();
        $cookie = $this->login($user)->assertOk()->getCookie($recaller, false);
        $user->forceFill(['status' => PlatformUserStatus::Inactive])->save();
        $this->flushSession();
        Auth::forgetGuards();
        $this->withCredentials()->withUnencryptedCookies([$recaller => $cookie->getValue()])
            ->getJson('/api/platform/auth/me')->assertForbidden()->assertJsonPath('error.key', 'ACCOUNT_DISABLED');
    }

    public function test_step_up_protected_operations_remain_authorized_without_an_mfa_challenge(): void
    {
        $user = $this->createPlatformAdmin('disabled-stepup');
        $this->login($user)->assertOk();
        $this->postJson('/api/platform/tenants', [])->assertUnprocessable()->assertJsonPath('error.key', 'VALIDATION_ERROR');
        $this->assertDatabaseCount('platform_mfa_challenges', 0);
        Notification::assertNothingSent();
    }

    public function test_disabling_mfa_does_not_grant_missing_platform_permissions(): void
    {
        $user = $this->createPlatformAdmin('disabled-permissions');
        $user->roles()->detach();
        $this->login($user)->assertOk();
        $this->getJson('/api/platform/dashboard')->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
        $this->postJson('/api/platform/tenants', [])->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');
        $this->assertDatabaseCount('platform_mfa_challenges', 0);
    }

    public function test_mfa_endpoints_are_frozen_and_cannot_create_or_consume_challenges(): void
    {
        $user = $this->createPlatformAdmin('disabled-endpoints');
        $this->login($user)->assertOk();
        foreach (['verify', 'resend', 'confirm'] as $endpoint) {
            $this->postJson('/api/platform/auth/mfa/'.$endpoint, [
                'challenge_ulid' => (string) Str::ulid(), 'code' => '123456',
            ])->assertStatus(409)->assertJsonPath('error.key', 'MFA_DISABLED');
        }
        $this->assertDatabaseCount('platform_mfa_challenges', 0);
        Notification::assertNothingSent();
    }

    public function test_forced_password_change_remains_required(): void
    {
        $user = $this->createPlatformAdmin('disabled-password');
        $user->forceFill(['must_change_password' => true])->save();
        $this->login($user)->assertOk()->assertJsonPath('must_change_password', true);
        $this->getJson('/api/platform/dashboard')->assertForbidden()->assertJsonPath('error.key', 'PASSWORD_CHANGE_REQUIRED');
    }

    private function login(PlatformUser $user, bool $remember = true): TestResponse
    {
        return $this->postJson('/api/platform/auth/login', [
            'email' => $user->email, 'password' => 'platform-pass-123', 'remember' => $remember,
        ]);
    }
}
