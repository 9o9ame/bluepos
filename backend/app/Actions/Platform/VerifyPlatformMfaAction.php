<?php

namespace App\Actions\Platform;

use App\Enums\DeviceStatus;
use App\Enums\MfaMethod;
use App\Exceptions\ApiException;
use App\Http\Middleware\EnsurePlatformContext;
use App\Models\Platform\PlatformMfaChallenge;
use App\Models\Platform\PlatformSession;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class VerifyPlatformMfaAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
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
        if ($challenge->attempts >= \App\Security\SecurityOtp::maxVerifyAttempts()) {
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
        if ($device) {
            $device->status = DeviceStatus::Active;
            $device->platform_user_id = $user->id;
            if ($trustDevice) {
                $device->trusted_until = now()->addDays(30);
            }
            $device->last_seen_at = now();
            $device->save();
        }

        Auth::guard('web')->logout();
        Auth::guard('platform')->login($user);
        $request->session()->regenerate();

        $record = PlatformSession::query()->create([
            'platform_user_id' => $user->id,
            'platform_device_id' => $device?->id,
            'laravel_session_id' => $request->session()->getId(),
            'security_version' => (int) $user->security_version,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 180, ''),
            'last_seen_at' => now(),
        ]);

        $user->forceFill([
            'last_login_at' => now(),
            'last_mfa_verified_at' => now(),
        ])->save();

        $request->session()->put([
            EnsurePlatformContext::SECURITY_VERSION => (int) $user->security_version,
            EnsurePlatformContext::SESSION_ULID => $record->ulid,
            EnsurePlatformContext::DEVICE_ID => $device?->id,
            EnsurePlatformContext::MFA_AT => now()->timestamp,
        ]);

        $this->context->hydrate($user, $device);
        $this->audit->record('PLATFORM_MFA_SUCCESS', [
            'resource_type' => 'platform_user',
            'resource_ulid' => $user->ulid,
            'method' => MfaMethod::EmailOtp->value,
        ], $request, $user);
        $this->audit->record('PLATFORM_LOGIN_SUCCESS', [
            'resource_type' => 'platform_user',
            'resource_ulid' => $user->ulid,
        ], $request, $user);

        return $user->fresh(['roles']) ?? $user;
    }
}
