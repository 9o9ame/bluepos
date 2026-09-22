<?php

namespace App\Actions\Auth;

use App\Enums\SecurityNotificationType;
use App\Models\Membership;
use App\Models\PasswordResetChallenge;
use App\Models\Tenant;
use App\Security\AuditLogger;
use App\Security\EmailNormalizer;
use App\Security\SecurityMailService;
use App\Security\SecurityOtp;
use App\Support\IdentityNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequestTenantPasswordResetAction
{
    public function __construct(
        private readonly SecurityMailService $mail,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Request $request, string $tenantCode, string $username): JsonResponse
    {
        $code = IdentityNormalizer::tenantCode($tenantCode);
        $name = IdentityNormalizer::username($username);

        $this->audit->record('PASSWORD_RESET_REQUESTED', [
            'tenant_code_hash' => hash('sha256', $code),
            'username_hash' => hash('sha256', $name),
        ], $request);

        $tenant = Tenant::query()->where('code', $code)->first();
        $membership = $tenant
            ? Membership::query()->with('user')->where('tenant_id', $tenant->id)->where('username', $name)->first()
            : null;

        $address = EmailNormalizer::normalize($membership?->user?->recoveryAddress());
        if ($membership && $address) {
            PasswordResetChallenge::query()
                ->where('membership_id', $membership->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $otp = SecurityOtp::generate();
            PasswordResetChallenge::query()->create([
                'tenant_id' => $membership->tenant_id,
                'membership_id' => $membership->id,
                'token_hash' => SecurityOtp::hash($otp),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(SecurityOtp::passwordResetTtlMinutes()),
            ]);

            $sent = $this->mail->trySendOtp($address, SecurityNotificationType::PasswordReset, $otp);
            $this->audit->record($sent ? 'PASSWORD_RESET_EMAIL_SENT' : 'PASSWORD_RESET_EMAIL_FAILED', [
                'tenant_id' => $membership->tenant_id,
                'resource_type' => 'membership',
                'resource_ulid' => $membership->ulid,
                'mailer' => config('mail.default'),
            ], $request);
        }

        return response()->json([
            'ok' => true,
            'message' => SecurityOtp::genericResetMessage(),
        ]);
    }
}
