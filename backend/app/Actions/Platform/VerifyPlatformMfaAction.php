<?php

namespace App\Actions\Platform;

use App\Enums\PlatformUserStatus;
use App\Exceptions\ApiException;
use App\Models\Platform\PlatformMfaChallenge;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use App\Security\SecurityOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class VerifyPlatformMfaAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly EstablishPlatformSessionAction $establishSession,
    ) {}

    public function execute(Request $request, string $challengeUlid, string $code, bool $trustDevice): PlatformUser
    {
        $challenge = PlatformMfaChallenge::query()
            ->with(['user', 'device'])
            ->where('ulid', $challengeUlid)
            ->first();

        if (! $challenge || $challenge->consumed_at) {
            throw new ApiException('MFA_INVALID', 'The verification code is invalid.', 403);
        }
        if ($challenge->expires_at->isPast()) {
            throw new ApiException('MFA_EXPIRED', 'The verification code has expired.', 403);
        }
        if ($challenge->attempts >= SecurityOtp::maxVerifyAttempts()) {
            throw new ApiException('TOO_MANY_ATTEMPTS', 'Too many attempts. Please wait and try again.', 429);
        }
        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->attempts++;
            $challenge->save();
            $this->audit->record('PLATFORM_MFA_FAILURE', [
                'resource_type' => 'platform_mfa_challenge',
                'resource_ulid' => $challenge->ulid,
            ], $request, $challenge->user);
            throw new ApiException('MFA_INVALID', 'The verification code is invalid.', 403);
        }

        $challenge->consumed_at = now();
        $challenge->save();

        $user = $challenge->user;
        $device = $challenge->device;
        if (! $user || $user->status !== PlatformUserStatus::Active) {
            throw new ApiException('ACCOUNT_DISABLED', 'This account is disabled.', 403);
        }

        return $this->establishSession->execute(
            $request,
            $user,
            $device,
            (bool) $challenge->remember,
            $trustDevice,
        );
    }
}
