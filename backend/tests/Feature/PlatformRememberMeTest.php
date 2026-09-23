<?php

namespace Tests\Feature;

use App\Actions\Platform\EstablishPlatformSessionAction;
use App\Enums\PlatformUserStatus;
use App\Http\Middleware\EnsurePlatformContext;
use App\Models\Platform\PlatformMfaChallenge;
use App\Models\Platform\PlatformUser;
use App\Notifications\SecurityCodeNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PlatformRememberMeTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        $this->app->detectEnvironment(fn (): string => 'testing');
        config()->set('security.platform_dev_bypass_mfa', false);

        parent::tearDown();
    }

    public function test_local_bypass_establishes_platform_session_without_mfa_or_notification(): void
    {
        $user = $this->createPlatformAdmin('local-direct');
        $this->enableLocalBypass();
        Notification::fake();

        $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
            'remember' => false,
        ])->assertOk()->assertJsonPath('email', $user->email);

        $this->assertAuthenticatedAs($user, 'platform');
        $this->assertDatabaseCount('platform_mfa_challenges', 0);
        $this->assertDatabaseCount('platform_sessions', 1);
        Notification::assertNothingSent();
        $this->assertNotNull(session(EnsurePlatformContext::MFA_AT));
    }

    public function test_local_bypass_still_rejects_wrong_password_and_inactive_user(): void
    {
        $wrongPasswordUser = $this->createPlatformAdmin('local-wrong');
        $inactiveUser = $this->createPlatformAdmin('local-inactive');
        $inactiveUser->forceFill(['status' => PlatformUserStatus::Inactive])->save();
        $this->enableLocalBypass();

        $this->postJson('/api/platform/auth/login', [
            'email' => $wrongPasswordUser->email,
            'password' => 'wrong-password',
            'remember' => true,
        ])->assertUnauthorized()->assertJsonPath('error.key', 'INVALID_CREDENTIALS');

        $this->postJson('/api/platform/auth/login', [
            'email' => $inactiveUser->email,
            'password' => 'platform-pass-123',
            'remember' => true,
        ])->assertForbidden()->assertJsonPath('error.key', 'ACCOUNT_DISABLED');

        $this->assertDatabaseCount('platform_mfa_challenges', 0);
        $this->assertGuest('platform');
    }

    public function test_local_remember_false_does_not_queue_remember_cookie(): void
    {
        $user = $this->createPlatformAdmin('local-session');
        $this->enableLocalBypass();
        $recaller = Auth::guard('platform')->getRecallerName();

        $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
            'remember' => false,
        ])->assertOk()->assertCookieMissing($recaller);
    }

    public function test_local_remember_true_queues_secure_remember_cookie(): void
    {
        $user = $this->createPlatformAdmin('local-remember');
        $this->enableLocalBypass();
        $recaller = Auth::guard('platform')->getRecallerName();

        $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
            'remember' => true,
        ])->assertOk()->assertCookie($recaller);

        $this->assertNotNull($user->fresh()->getRememberToken());
    }

    public function test_remembered_login_reconstructs_server_backed_platform_context(): void
    {
        $user = $this->createPlatformAdmin('local-restore');
        $this->enableLocalBypass();
        $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
            'remember' => true,
        ])->assertOk();

        $this->flushSession();
        app(EstablishPlatformSessionAction::class)->resumeRemembered(request(), $user->fresh());

        $this->assertDatabaseCount('platform_sessions', 2);
        $this->assertSame($user->security_version, session(EnsurePlatformContext::SECURITY_VERSION));
        $this->assertNotNull(session(EnsurePlatformContext::SESSION_ULID));
    }

    public function test_explicit_logout_clears_remembered_authentication(): void
    {
        $user = $this->createPlatformAdmin('local-logout');
        $this->enableLocalBypass();
        $recaller = Auth::guard('platform')->getRecallerName();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
            'remember' => true,
        ])->assertOk()->assertCookie($recaller);
        $rememberToken = $user->fresh()->getRememberToken();
        $rememberCookie = $login->getCookie($recaller, false);
        $this->assertNotNull($rememberCookie);

        $this->withUnencryptedCookie($recaller, (string) $rememberCookie->getValue())
            ->postJson('/api/platform/auth/logout')
            ->assertOk()
            ->assertCookieExpired($recaller);

        $this->assertGuest('platform');
        $this->assertNotSame($rememberToken, $user->fresh()->getRememberToken());
        $this->assertDatabaseMissing('platform_sessions', [
            'platform_user_id' => $user->id,
            'revoked_at' => null,
        ]);
    }

    public function test_bypass_flag_cannot_disable_mfa_outside_local_environment(): void
    {
        $user = $this->createPlatformAdmin('production-guard');
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('security.platform_dev_bypass_mfa', true);
        Notification::fake();

        $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
            'remember' => true,
        ])->assertForbidden()->assertJsonPath('error.key', 'MFA_REQUIRED');

        $challenge = PlatformMfaChallenge::query()->sole();
        $this->assertTrue($challenge->remember);
        Notification::assertSentOnDemand(SecurityCodeNotification::class);
        $this->assertGuest('platform');
        $this->assertDatabaseCount('platform_sessions', 0);
    }

    public function test_production_mfa_applies_remember_preference_only_after_verification(): void
    {
        $user = $this->createPlatformAdmin('production-remember');
        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('security.platform_dev_bypass_mfa', true);
        Notification::fake();
        $recaller = Auth::guard('platform')->getRecallerName();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
            'remember' => true,
        ])->assertForbidden()->assertCookieMissing($recaller);

        $code = $this->capturedCode();
        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $login->json('error.challenge_ulid'),
            'code' => $code,
        ])->assertOk()->assertCookie($recaller);

        $this->assertAuthenticatedAs($user, 'platform');
        $this->assertDatabaseCount('platform_sessions', 1);
    }

    public function test_production_mfa_remember_false_creates_only_normal_session(): void
    {
        $user = $this->createPlatformAdmin('production-session');
        $this->app->detectEnvironment(fn (): string => 'production');
        Notification::fake();
        $recaller = Auth::guard('platform')->getRecallerName();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
            'remember' => false,
        ])->assertForbidden();

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $login->json('error.challenge_ulid'),
            'code' => $this->capturedCode(),
        ])->assertOk()->assertCookieMissing($recaller);
    }

    public function test_security_version_change_invalidates_remember_token(): void
    {
        $user = $this->createPlatformAdmin('remember-revoke');
        $this->enableLocalBypass();
        $recaller = Auth::guard('platform')->getRecallerName();
        $login = $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
            'remember' => true,
        ])->assertOk();
        $rememberCookie = $login->getCookie($recaller, false);
        $this->assertNotNull($rememberCookie);
        $oldToken = $user->fresh()->getRememberToken();
        $oldVersion = $user->security_version;
        $oldRememberPayload = $this->rememberPayload($user->fresh());

        $user->bumpSecurityVersion();
        $user->refresh();
        $this->flushSession();
        Auth::forgetGuards();

        $this->assertSame($oldVersion + 1, $user->security_version);
        $this->assertNotSame($oldToken, $user->getRememberToken());
        $this->withCookie($recaller, $oldRememberPayload)
            ->getJson('/api/platform/auth/me')
            ->assertUnauthorized();
    }

    private function enableLocalBypass(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config()->set('security.platform_dev_bypass_mfa', true);
    }

    private function capturedCode(): string
    {
        $code = null;
        Notification::assertSentOnDemand(
            SecurityCodeNotification::class,
            function ($notification) use (&$code): bool {
                $code = $notification->code;

                return true;
            },
        );

        return (string) $code;
    }

    private function rememberPayload(PlatformUser $user): string
    {
        return implode('|', [
            $user->getAuthIdentifier(),
            $user->getRememberToken(),
            $user->getAuthPassword(),
        ]);
    }
}
