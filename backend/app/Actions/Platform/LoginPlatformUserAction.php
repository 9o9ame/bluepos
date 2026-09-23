<?php

namespace App\Actions\Platform;

use App\Enums\DeviceStatus;
use App\Enums\PlatformUserStatus;
use App\Exceptions\ApiException;
use App\Models\Platform\PlatformDevice;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformMfaPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginPlatformUserAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly IssuePlatformMfaChallengeAction $issue,
        private readonly EstablishPlatformSessionAction $establishSession,
        private readonly PlatformMfaPolicy $mfaPolicy,
    ) {}

    public function execute(Request $request, string $email, string $password, bool $remember): PlatformUser
    {
        $fail = function () use ($request, $email): never {
            $this->audit->record('PLATFORM_LOGIN_FAILURE', [
                'email_hash' => hash('sha256', strtolower(trim($email))),
            ], $request);

            throw new ApiException('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        };

        $user = PlatformUser::query()->where('email', strtolower(trim($email)))->first();
        if (! $user || ! Hash::check($password, $user->password)) {
            $fail();
        }

        if ($user->status !== PlatformUserStatus::Active) {
            throw new ApiException('ACCOUNT_DISABLED', 'This account is disabled.', 403);
        }

        $device = $this->device($request, $user);

        if ($this->mfaPolicy->localBypassEnabled()) {
            return $this->establishSession->execute(
                $request,
                $user,
                $device,
                $remember,
                true,
                true,
            );
        }

        $this->issue->execute($user, $device, 'login', false, $remember);
    }

    private function device(Request $request, PlatformUser $user): PlatformDevice
    {
        $raw = (string) ($request->header('X-BluePOS-Platform-Device') ?: $request->cookie('bluepos_platform_device'));
        if (str_contains($raw, '.')) {
            [$ulid, $secret] = explode('.', $raw, 2);
            $device = PlatformDevice::query()->where('ulid', $ulid)->first();
            if ($device && Hash::check($secret, $device->credential_hash) && $device->status !== DeviceStatus::Revoked) {
                $device->platform_user_id = $user->id;
                $device->last_seen_at = now();
                $device->save();

                return $device;
            }
        }

        $device = PlatformDevice::query()->create([
            'platform_user_id' => $user->id,
            'name' => 'Platform browser',
            'status' => DeviceStatus::Pending,
            'credential_hash' => 'pending',
            'registered_at' => now(),
        ]);
        $secret = bin2hex(random_bytes(24));
        $device->credential_hash = Hash::make($secret);
        $device->save();
        cookie()->queue(cookie(
            'bluepos_platform_device',
            $device->ulid.'.'.$secret,
            60 * 24 * 400,
            '/',
            null,
            (bool) config('session.secure'),
            true,
            false,
            'lax',
        ));

        return $device;
    }
}
