<?php

namespace App\Actions\Platform;

use App\Enums\PlatformUserStatus;
use App\Enums\SecurityNotificationType;
use App\Models\Platform\PlatformPasswordResetChallenge;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use App\Security\EmailNormalizer;
use App\Security\SecurityMailService;
use App\Security\SecurityOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequestPlatformPasswordResetAction
{
    public function __construct(
        private readonly SecurityMailService $mail,
        private readonly PlatformAuditLogger $audit,
    ) {}

    public function execute(Request $request, string $email): JsonResponse
    {
        $normalized = EmailNormalizer::normalize($email);

        $this->audit->record('PLATFORM_PASSWORD_RESET_REQUESTED', [
            'email_hash' => hash('sha256', (string) $normalized),
        ], $request);

        $user = $normalized
            ? PlatformUser::query()->where('email', $normalized)->first()
            : null;

        if ($user && $user->status === PlatformUserStatus::Active) {
            PlatformPasswordResetChallenge::query()
                ->where('platform_user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $otp = SecurityOtp::generate();
            PlatformPasswordResetChallenge::query()->create([
                'platform_user_id' => $user->id,
                'token_hash' => SecurityOtp::hash($otp),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(SecurityOtp::passwordResetTtlMinutes()),
            ]);

            $sent = $this->mail->trySendOtp($user->email, SecurityNotificationType::PlatformPasswordReset, $otp);
            $this->audit->record($sent ? 'PLATFORM_PASSWORD_RESET_EMAIL_SENT' : 'PLATFORM_PASSWORD_RESET_EMAIL_FAILED', [
                'resource_type' => 'platform_user',
                'resource_ulid' => $user->ulid,
                'mailer' => config('mail.default'),
            ], $request, $user);
        }

        return response()->json([
            'ok' => true,
            'message' => SecurityOtp::genericResetMessage(),
        ]);
    }
}
