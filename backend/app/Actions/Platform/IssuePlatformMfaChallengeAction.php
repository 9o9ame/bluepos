<?php

namespace App\Actions\Platform;

use App\Enums\MfaMethod;
use App\Enums\SecurityNotificationType;
use App\Exceptions\ApiException;
use App\Models\Platform\PlatformDevice;
use App\Models\Platform\PlatformMfaChallenge;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use App\Security\EmailNormalizer;
use App\Security\SecurityMailService;
use App\Security\SecurityOtp;
use Illuminate\Http\Request;

class IssuePlatformMfaChallengeAction
{
    public function __construct(
        private readonly SecurityMailService $mail,
        private readonly PlatformAuditLogger $audit,
    ) {}

    public function execute(
        PlatformUser $user,
        ?PlatformDevice $device,
        string $purpose = 'login',
        bool $resend = false,
        bool $remember = false,
    ): never {
        $email = EmailNormalizer::normalize($user->email);
        if ($email === null) {
            throw $this->mail->failed();
        }

        PlatformMfaChallenge::query()
            ->where('platform_user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = SecurityOtp::generate();
        $challenge = PlatformMfaChallenge::query()->create([
            'platform_user_id' => $user->id,
            'platform_device_id' => $device?->id,
            'method' => MfaMethod::EmailOtp,
            'purpose' => $purpose,
            'code_hash' => SecurityOtp::hash($otp),
            'remember' => $remember,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(SecurityOtp::ttlMinutes()),
        ]);

        $type = SecurityNotificationType::forPlatformMfaPurpose($purpose);
        $request = request();
        $auditRequest = $request instanceof Request ? $request : null;
        $event = $resend ? 'PLATFORM_MFA_OTP_RESENT' : 'PLATFORM_MFA_OTP_REQUESTED';

        try {
            $this->mail->sendOtp($email, $type, $otp);
        } catch (ApiException $e) {
            if ($e->errorKey === 'EMAIL_DELIVERY_FAILED') {
                $this->audit->record('PLATFORM_MFA_EMAIL_DELIVERY_FAILED', [
                    'resource_type' => 'platform_mfa_challenge',
                    'resource_ulid' => $challenge->ulid,
                    'mailer' => config('mail.default'),
                ], $auditRequest, $user);

                throw $this->mail->failed($this->challengePayload($challenge, $email));
            }

            throw $e;
        }

        $this->audit->record($event, [
            'resource_type' => 'platform_mfa_challenge',
            'resource_ulid' => $challenge->ulid,
            'method' => MfaMethod::EmailOtp->value,
            'purpose' => $purpose,
        ], $auditRequest, $user);

        throw new ApiException('MFA_REQUIRED', 'Additional verification is required.', 403, $this->challengePayload($challenge, $email));
    }

    /**
     * @return array<string, mixed>
     */
    private function challengePayload(PlatformMfaChallenge $challenge, string $email): array
    {
        return [
            'challenge_ulid' => $challenge->ulid,
            'method' => MfaMethod::EmailOtp->value,
            'recovery_hint' => EmailNormalizer::mask($email),
        ];
    }
}
