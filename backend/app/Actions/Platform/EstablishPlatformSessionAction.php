<?php

namespace App\Actions\Platform;

use App\Enums\DeviceStatus;
use App\Enums\MfaMethod;
use App\Http\Middleware\EnsurePlatformContext;
use App\Models\Platform\PlatformDevice;
use App\Models\Platform\PlatformSession;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;
use App\Platform\PlatformMfaPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class EstablishPlatformSessionAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
        private readonly PlatformMfaPolicy $mfaPolicy,
    ) {}

    public function execute(
        Request $request,
        PlatformUser $user,
        ?PlatformDevice $device,
        bool $remember,
        bool $trustDevice,
        bool $localMfaBypass = false,
    ): PlatformUser {
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
        Auth::guard('platform')->login($user, $remember);
        if (! $remember && $request->cookie(Auth::guard('platform')->getRecallerName()) !== null) {
            cookie()->queue(cookie()->forget(Auth::guard('platform')->getRecallerName()));
        }
        $request->session()->regenerate();

        $mfaEnabled = $this->mfaPolicy->enabled();
        $this->persistContext($request, $user, $device, $mfaEnabled);
        $request->session()->put(EnsurePlatformContext::REMEMBER, $remember);

        if (! $mfaEnabled) {
            $this->audit->record('PLATFORM_MFA_DISABLED', [
                'resource_type' => 'platform_user',
                'resource_ulid' => $user->ulid,
                'auth_mode' => 'email_password',
            ], $request, $user);
        } elseif ($localMfaBypass) {
            $this->audit->record('PLATFORM_MFA_BYPASSED_LOCAL', [
                'resource_type' => 'platform_user',
                'resource_ulid' => $user->ulid,
                'auth_mode' => 'local_development_mfa_bypass',
            ], $request, $user);
        } else {
            $this->audit->record('PLATFORM_MFA_SUCCESS', [
                'resource_type' => 'platform_user',
                'resource_ulid' => $user->ulid,
                'method' => MfaMethod::EmailOtp->value,
            ], $request, $user);
        }

        $this->audit->record('PLATFORM_LOGIN_SUCCESS', [
            'resource_type' => 'platform_user',
            'resource_ulid' => $user->ulid,
            'auth_mode' => ! $mfaEnabled ? 'email_password' : ($localMfaBypass ? 'local_development_mfa_bypass' : 'email_otp'),
            'remembered' => $remember,
        ], $request, $user);

        return $user->fresh(['roles']) ?? $user;
    }

    public function resumeRemembered(Request $request, PlatformUser $user): PlatformUser
    {
        $request->session()->regenerate();
        $localMfaBypass = $this->mfaPolicy->enabled() && $this->mfaPolicy->localBypassEnabled();
        $this->persistContext($request, $user, null, $localMfaBypass);
        $request->session()->put(EnsurePlatformContext::REMEMBER, true);

        $this->audit->record('PLATFORM_REMEMBERED_LOGIN_RESTORED', [
            'resource_type' => 'platform_user',
            'resource_ulid' => $user->ulid,
            'auth_mode' => $localMfaBypass ? 'local_development_mfa_bypass' : 'remember_cookie',
        ], $request, $user);

        return $user->fresh(['roles']) ?? $user;
    }

    private function persistContext(
        Request $request,
        PlatformUser $user,
        ?PlatformDevice $device,
        bool $mfaVerifiedNow,
    ): void {
        $record = PlatformSession::query()->create([
            'platform_user_id' => $user->id,
            'platform_device_id' => $device?->id,
            'laravel_session_id' => $request->session()->getId(),
            'security_version' => (int) $user->security_version,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 180, ''),
            'last_seen_at' => now(),
        ]);

        $updates = ['last_login_at' => now()];
        if ($mfaVerifiedNow) {
            $updates['last_mfa_verified_at'] = now();
        }
        $user->forceFill($updates)->save();

        $session = [
            EnsurePlatformContext::SECURITY_VERSION => (int) $user->security_version,
            EnsurePlatformContext::SESSION_ULID => $record->ulid,
            EnsurePlatformContext::DEVICE_ID => $device?->id,
        ];
        if ($mfaVerifiedNow) {
            $session[EnsurePlatformContext::MFA_AT] = now()->timestamp;
        } else {
            $request->session()->forget(EnsurePlatformContext::MFA_AT);
        }
        $request->session()->put($session);

        $this->context->hydrate($user, $device);
    }
}
