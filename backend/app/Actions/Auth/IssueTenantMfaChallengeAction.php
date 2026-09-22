<?php

namespace App\Actions\Auth;

use App\Enums\MfaMethod;
use App\Enums\SecurityNotificationType;
use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\Membership;
use App\Models\MfaChallenge;
use App\Security\AuditLogger;
use App\Security\EmailNormalizer;
use App\Security\SecurityMailService;
use App\Security\SecurityOtp;
use Illuminate\Http\Request;

class IssueTenantMfaChallengeAction
{
    public function __construct(
        private readonly SecurityMailService $mail,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Request $request, Membership $membership, Device $device, bool $resend = false): never
    {
        $address = EmailNormalizer::normalize($membership->user?->recoveryAddress());

        MfaChallenge::query()
            ->where('membership_id', $membership->id)
            ->where('purpose', 'login')
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $otp = SecurityOtp::generate();
        $challenge = MfaChallenge::query()->create([
            'tenant_id' => $membership->tenant_id,
            'user_id' => $membership->user_id,
            'membership_id' => $membership->id,
            'device_id' => $device->id,
            'method' => MfaMethod::EmailOtp,
            'purpose' => 'login',
            'code_hash' => SecurityOtp::hash($otp),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(SecurityOtp::ttlMinutes()),
        ]);

        $payload = [
            'challenge_ulid' => $challenge->ulid,
            'method' => MfaMethod::EmailOtp->value,
            'recovery_hint' => EmailNormalizer::mask($address),
        ];

        if ($address) {
            try {
                $this->mail->sendOtp($address, SecurityNotificationType::NewDeviceVerification, $otp);
            } catch (ApiException $e) {
                if ($e->errorKey === 'EMAIL_DELIVERY_FAILED') {
                    $this->audit->record('MFA_EMAIL_DELIVERY_FAILED', [
                        'tenant_id' => $membership->tenant_id,
                        'actor_user_id' => $membership->user_id,
                        'actor_membership_id' => $membership->id,
                        'device_id' => $device->id,
                        'resource_type' => 'mfa_challenge',
                        'resource_ulid' => $challenge->ulid,
                        'mailer' => config('mail.default'),
                    ], $request);

                    throw $this->mail->failed($payload);
                }

                throw $e;
            }
        }

        $this->audit->record($resend ? 'MFA_OTP_RESENT' : 'MFA_OTP_REQUESTED', [
            'tenant_id' => $membership->tenant_id,
            'actor_user_id' => $membership->user_id,
            'actor_membership_id' => $membership->id,
            'device_id' => $device->id,
            'resource_type' => 'mfa_challenge',
            'resource_ulid' => $challenge->ulid,
            'method' => MfaMethod::EmailOtp->value,
        ], $request);

        throw new ApiException('MFA_REQUIRED', 'Additional verification is required for this device.', 403, $payload);
    }
}
