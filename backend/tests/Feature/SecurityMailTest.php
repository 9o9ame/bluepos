<?php

namespace Tests\Feature;

use App\Enums\SecurityNotificationType;
use App\Mail\MailTransportTestMail;
use App\Models\MfaChallenge;
use App\Models\PasswordResetChallenge;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\PlatformMfaChallenge;
use App\Notifications\SecurityCodeNotification;
use App\Security\SecurityOtp;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SecurityMailTest extends TestCase
{
    use DatabaseTransactions;

    public function test_phpunit_uses_array_mailer_and_does_not_send_real_email(): void
    {
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('testing', app()->environment());
    }

    public function test_platform_mfa_sends_otp_to_stored_platform_email_only(): void
    {
        $user = $this->createPlatformAdmin('mail-dest');
        Notification::fake();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => $user->email,
            'password' => 'platform-pass-123',
            'otp_email' => 'attacker@example.com',
            'recovery_email' => 'attacker@example.com',
        ]);
        $login->assertForbidden()->assertJsonPath('error.key', 'MFA_REQUIRED');

        $code = null;
        Notification::assertSentOnDemand(SecurityCodeNotification::class, function ($notification, $channels, $notifiable) use ($user, &$code): bool {
            $code = $notification->code;
            $this->assertSame(SecurityNotificationType::PlatformMfa, $notification->type);
            $this->assertSame($user->email, $notifiable->routes['mail'] ?? null);

            return true;
        });

        $challenge = PlatformMfaChallenge::query()->where('ulid', $login->json('error.challenge_ulid'))->firstOrFail();
        $this->assertNotSame($code, $challenge->getAttributes()['code_hash'] ?? null);
        $this->assertStringNotContainsString((string) $code, strtolower((string) json_encode($challenge->getAttributes())));
        $this->assertTrue(Hash::check((string) $code, $challenge->code_hash));

        foreach (PlatformAuditLog::query()->get() as $log) {
            $this->assertStringNotContainsString((string) $code, (string) json_encode($log->toArray()));
        }
    }

    public function test_used_or_invalid_platform_otp_is_rejected_and_cannot_be_reused(): void
    {
        $this->createPlatformAdmin('mail-reuse');
        Notification::fake();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => 'platform-mail-reuse@example.com',
            'password' => 'platform-pass-123',
        ]);
        $challengeUlid = $login->json('error.challenge_ulid');
        $code = $this->lastSecurityCode();

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $challengeUlid,
            'code' => '000000',
        ])->assertForbidden()->assertJsonPath('error.key', 'MFA_INVALID');

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $challengeUlid,
            'code' => $code,
            'trust_device' => true,
        ])->assertOk();

        $this->postJson('/api/platform/auth/logout')->assertOk();

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $challengeUlid,
            'code' => $code,
        ])->assertForbidden()->assertJsonPath('error.key', 'MFA_INVALID');
    }

    public function test_platform_otp_expires(): void
    {
        $this->createPlatformAdmin('mail-exp');
        Notification::fake();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => 'platform-mail-exp@example.com',
            'password' => 'platform-pass-123',
        ]);
        $code = $this->lastSecurityCode();
        $this->travel(SecurityOtp::ttlMinutes() + 1)->minutes();

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $login->json('error.challenge_ulid'),
            'code' => $code,
        ])->assertForbidden()->assertJsonPath('error.key', 'MFA_EXPIRED');
    }

    public function test_platform_resend_enforces_cooldown_then_replaces_challenge(): void
    {
        $this->createPlatformAdmin('mail-cd');
        Notification::fake();

        $login = $this->postJson('/api/platform/auth/login', [
            'email' => 'platform-mail-cd@example.com',
            'password' => 'platform-pass-123',
        ]);
        $oldUlid = $login->json('error.challenge_ulid');

        $this->postJson('/api/platform/auth/mfa/resend', [
            'challenge_ulid' => $oldUlid,
            'email' => 'attacker@example.com',
        ])->assertStatus(429)->assertJsonPath('error.key', 'OTP_RESEND_COOLDOWN');

        $this->travel(SecurityOtp::resendCooldownSeconds() + 1)->seconds();

        $resend = $this->postJson('/api/platform/auth/mfa/resend', ['challenge_ulid' => $oldUlid]);
        $resend->assertForbidden()->assertJsonPath('error.key', 'MFA_REQUIRED');
        $newUlid = $resend->json('error.challenge_ulid');
        $this->assertNotSame($oldUlid, $newUlid);

        $codes = [];
        Notification::assertSentOnDemand(SecurityCodeNotification::class, function ($notification) use (&$codes): bool {
            $codes[] = $notification->code;

            return true;
        });
        $fresh = (string) end($codes);

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $oldUlid,
            'code' => $fresh,
        ])->assertForbidden()->assertJsonPath('error.key', 'MFA_INVALID');

        $this->postJson('/api/platform/auth/mfa/verify', [
            'challenge_ulid' => $newUlid,
            'code' => $fresh,
        ])->assertOk();
    }

    public function test_mail_provider_failure_returns_safe_email_delivery_error(): void
    {
        $this->createPlatformAdmin('mail-fail');
        $this->mock(Dispatcher::class, function ($mock): void {
            $mock->shouldReceive('send')->andThrow(new \RuntimeException(
                'SMTP AUTH failed user=mailer password=super-secret host=smtp.gmail.com'
            ));
        });

        $response = $this->postJson('/api/platform/auth/login', [
            'email' => 'platform-mail-fail@example.com',
            'password' => 'platform-pass-123',
        ]);
        $response->assertStatus(503)
            ->assertJsonPath('error.key', 'EMAIL_DELIVERY_FAILED');
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('super-secret', $body);
        $this->assertStringNotContainsString('smtp.gmail.com', $body);
        $this->assertTrue(
            PlatformAuditLog::query()->where('event', 'PLATFORM_MFA_EMAIL_DELIVERY_FAILED')->exists()
        );
    }

    public function test_forgot_password_is_generic_and_uses_stored_recovery_email(): void
    {
        Notification::fake();
        $this->provisionOwner('mail-reset');

        $unknown = $this->postJson('/api/auth/forgot-password', [
            'tenant_code' => 'NOSUCH',
            'username' => 'ghost',
            'email' => 'attacker@example.com',
        ])->assertOk();
        $known = $this->postJson('/api/auth/forgot-password', [
            'tenant_code' => $this->tenantCode('mail-reset'),
            'username' => 'owner',
            'email' => 'attacker@example.com',
        ])->assertOk();
        $this->assertSame($unknown->json('message'), $known->json('message'));
        $this->assertSame(SecurityOtp::genericResetMessage(), $known->json('message'));

        Notification::assertSentOnDemand(SecurityCodeNotification::class, function ($notification, $channels, $notifiable): bool {
            $this->assertSame(SecurityNotificationType::PasswordReset, $notification->type);
            $this->assertSame('owner-mail-reset@example.com', $notifiable->routes['mail'] ?? null);

            return true;
        });

        $challenge = PasswordResetChallenge::query()->orderByDesc('id')->firstOrFail();
        $code = $this->lastSecurityCode();
        $this->assertStringNotContainsString($code, strtolower((string) json_encode($challenge->getAttributes())));
        $this->assertTrue(Hash::check($code, $challenge->token_hash));
    }

    public function test_platform_forgot_password_is_generic_and_separate_from_tenant_lookup(): void
    {
        $user = $this->createPlatformAdmin('mail-pw');
        $this->provisionOwner('mail-pw-tenant');
        Notification::fake();

        $unknown = $this->postJson('/api/platform/auth/forgot-password', [
            'email' => 'missing-platform@example.com',
        ])->assertOk();
        $known = $this->postJson('/api/platform/auth/forgot-password', [
            'email' => $user->email,
        ])->assertOk();
        $this->assertSame($unknown->json('message'), $known->json('message'));

        Notification::assertSentOnDemand(SecurityCodeNotification::class, function ($notification, $channels, $notifiable) use ($user): bool {
            $this->assertSame(SecurityNotificationType::PlatformPasswordReset, $notification->type);
            $this->assertSame($user->email, $notifiable->routes['mail'] ?? null);

            return true;
        });

        $code = $this->lastSecurityCode();
        $this->postJson('/api/platform/auth/reset-password', [
            'email' => $user->email,
            'token' => $code,
            'password' => 'brand-new-platform',
            'password_confirmation' => 'brand-new-platform',
        ])->assertOk();
    }

    public function test_tenant_mfa_resend_replaces_challenge_after_cooldown(): void
    {
        Notification::fake();
        $this->provisionOwner('mail-tmfa');

        $first = $this->withoutDevice()->postJson('/api/auth/login', [
            'tenant_code' => $this->tenantCode('mail-tmfa'),
            'username' => 'owner',
            'password' => 'password123',
        ]);
        $first->assertForbidden()->assertJsonPath('error.key', 'MFA_REQUIRED');
        $oldUlid = $first->json('error.challenge_ulid');

        $this->postJson('/api/auth/mfa/resend', ['challenge_ulid' => $oldUlid])
            ->assertStatus(429)
            ->assertJsonPath('error.key', 'OTP_RESEND_COOLDOWN');

        $this->travel(SecurityOtp::resendCooldownSeconds() + 1)->seconds();
        $resend = $this->postJson('/api/auth/mfa/resend', ['challenge_ulid' => $oldUlid]);
        $resend->assertForbidden()->assertJsonPath('error.key', 'MFA_REQUIRED');
        $this->assertNotSame($oldUlid, $resend->json('error.challenge_ulid'));
        $this->assertTrue(MfaChallenge::query()->where('ulid', $oldUlid)->whereNotNull('consumed_at')->exists());
    }

    public function test_mail_test_command_does_not_print_secrets_or_create_mfa(): void
    {
        Mail::fake();
        $before = PlatformMfaChallenge::query()->count();

        $this->artisan('bluepos:mail-test', ['email' => 'ops@example.com'])
            ->expectsOutputToContain('Result: success')
            ->doesntExpectOutputToContain('MAIL_PASSWORD')
            ->assertSuccessful();

        Mail::assertSent(MailTransportTestMail::class, function (MailTransportTestMail $mail): bool {
            return $mail->hasTo('ops@example.com');
        });
        $this->assertSame($before, PlatformMfaChallenge::query()->count());
    }

    private function lastSecurityCode(): string
    {
        $code = null;
        Notification::assertSentOnDemand(SecurityCodeNotification::class, function ($notification) use (&$code): bool {
            $code = $notification->code;

            return true;
        });

        $this->assertNotEmpty($code);

        return (string) $code;
    }
}
