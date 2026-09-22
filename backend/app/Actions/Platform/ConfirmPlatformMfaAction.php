<?php

namespace App\Actions\Platform;

use App\Enums\MfaMethod;
use App\Exceptions\ApiException;
use App\Http\Middleware\EnsurePlatformContext;
use App\Models\Platform\PlatformMfaChallenge;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ConfirmPlatformMfaAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
    ) {}

    public function execute(Request $request, string $challengeUlid, string $code): PlatformUser
    {
        $user = $this->context->user();
        $challenge = PlatformMfaChallenge::query()
            ->where('ulid', $challengeUlid)
            ->where('platform_user_id', $user->id)
            ->first();

        if (! $challenge || $challenge->consumed_at) {
            throw new ApiException('MFA_INVALID', 'The verification code is invalid.', 403);
        }
        if ($challenge->expires_at->isPast()) {
            throw new ApiException('MFA_EXPIRED', 'The verification code has expired.', 403);
        }
        if ($challenge->attempts >= \App\Security\SecurityOtp::maxVerifyAttempts()) {
            throw new ApiException('TOO_MANY_ATTEMPTS', 'Too many attempts. Please wait and try again.', 429);
        }
        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->attempts++;
            $challenge->save();
            $this->audit->record('PLATFORM_MFA_FAILURE', [
                'resource_type' => 'platform_mfa_challenge',
                'resource_ulid' => $challenge->ulid,
            ], $request, $user);
            throw new ApiException('MFA_INVALID', 'The verification code is invalid.', 403);
        }

        $challenge->consumed_at = now();
        $challenge->save();

        $user->forceFill(['last_mfa_verified_at' => now()])->save();
        $request->session()->put(EnsurePlatformContext::MFA_AT, now()->timestamp);

        $this->audit->record('PLATFORM_MFA_SUCCESS', [
            'resource_type' => 'platform_user',
            'resource_ulid' => $user->ulid,
            'method' => MfaMethod::EmailOtp->value,
            'purpose' => $challenge->purpose,
        ], $request, $user);

        return $user->fresh(['roles']) ?? $user;
    }
}
