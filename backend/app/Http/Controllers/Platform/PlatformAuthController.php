<?php

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\ConfirmPlatformMfaAction;
use App\Actions\Platform\LoginPlatformUserAction;
use App\Actions\Platform\RequestPlatformPasswordResetAction;
use App\Actions\Platform\ResendPlatformMfaAction;
use App\Actions\Platform\ResetPlatformPasswordAction;
use App\Actions\Platform\UpdatePlatformUserAction;
use App\Actions\Platform\VerifyPlatformMfaAction;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePlatformContext;
use App\Http\Requests\Platform\PlatformLoginRequest;
use App\Http\Requests\Platform\PlatformMfaVerifyRequest;
use App\Http\Requests\Platform\UpdatePlatformUserRequest;
use App\Http\Resources\Platform\PlatformDeviceResource;
use App\Http\Resources\Platform\PlatformSessionResource;
use App\Http\Resources\Platform\PlatformUserResource;
use App\Models\Platform\PlatformDevice;
use App\Models\Platform\PlatformSession;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformCatalogSync;
use App\Platform\PlatformContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PlatformAuthController extends Controller
{
    public function login(PlatformLoginRequest $request, LoginPlatformUserAction $login): never
    {
        $login->execute($request, $request->validated('email'), $request->validated('password'));
    }

    public function verifyMfa(PlatformMfaVerifyRequest $request, VerifyPlatformMfaAction $verify): PlatformUserResource
    {
        $user = $verify->execute(
            $request,
            $request->validated('challenge_ulid'),
            $request->validated('code'),
            (bool) $request->boolean('trust_device', true),
        );

        return new PlatformUserResource($user->load('roles'));
    }

    public function resendMfa(Request $request, ResendPlatformMfaAction $resend): never
    {
        $data = $request->validate([
            'challenge_ulid' => ['required', 'string', 'size:26'],
        ]);

        $resend->execute($request, $data['challenge_ulid']);
    }

    public function forgotPassword(Request $request, RequestPlatformPasswordResetAction $reset): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        return $reset->execute($request, $data['email']);
    }

    public function resetPassword(Request $request, ResetPlatformPasswordAction $reset): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:12'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        return $reset->execute($request, $data['email'], $data['token'], $data['password']);
    }

    public function me(PlatformContext $context, PlatformCatalogSync $catalog): PlatformUserResource
    {
        $catalog->ensure();

        return new PlatformUserResource($context->user()->load('roles'));
    }

    public function confirmMfa(PlatformMfaVerifyRequest $request, ConfirmPlatformMfaAction $confirm): PlatformUserResource
    {
        return new PlatformUserResource($confirm->execute(
            $request,
            $request->validated('challenge_ulid'),
            $request->validated('code'),
        )->load('roles'));
    }

    public function logout(Request $request): JsonResponse
    {
        $ulid = $request->session()->get(EnsurePlatformContext::SESSION_ULID);
        if (is_string($ulid)) {
            PlatformSession::query()->where('ulid', $ulid)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }

        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function changePassword(Request $request, PlatformContext $context): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $user = $context->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw new ApiException('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        }

        $user->password = $data['password'];
        $user->must_change_password = false;
        $user->password_changed_at = now();
        $user->save();
        $user->bumpSecurityVersion();

        PlatformSession::query()
            ->where('platform_user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('ulid', '!=', (string) $request->session()->get(EnsurePlatformContext::SESSION_ULID))
            ->update(['revoked_at' => now()]);

        $request->session()->put(EnsurePlatformContext::SECURITY_VERSION, (int) $user->security_version);

        $currentUlid = $request->session()->get(EnsurePlatformContext::SESSION_ULID);
        if (is_string($currentUlid)) {
            PlatformSession::query()->where('ulid', $currentUlid)->update([
                'security_version' => (int) $user->security_version,
            ]);
        }

        app(PlatformAuditLogger::class)->record('PLATFORM_PASSWORD_CHANGED', [
            'resource_type' => 'platform_user',
            'resource_ulid' => $user->ulid,
        ], $request, $user);

        return response()->json(['ok' => true]);
    }

    public function updateProfile(UpdatePlatformUserRequest $request, PlatformContext $context, UpdatePlatformUserAction $update): PlatformUserResource
    {
        return new PlatformUserResource(
            $update->execute($request, $context->user(), $request->validated(), true)->load('roles')
        );
    }

    public function devices(PlatformContext $context): mixed
    {
        return PlatformDeviceResource::collection(
            PlatformDevice::query()
                ->where('platform_user_id', $context->user()->id)
                ->orderByDesc('last_seen_at')
                ->get()
        );
    }

    public function destroyDevice(string $deviceUlid, PlatformContext $context): JsonResponse
    {
        $device = PlatformDevice::query()
            ->where('platform_user_id', $context->user()->id)
            ->where('ulid', $deviceUlid)
            ->first();
        if (! $device) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $device->status = \App\Enums\DeviceStatus::Revoked;
        $device->revoked_at = now();
        $device->trusted_until = null;
        $device->save();

        PlatformSession::query()
            ->where('platform_device_id', $device->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function sessions(PlatformContext $context): mixed
    {
        return PlatformSessionResource::collection(
            PlatformSession::query()
                ->where('platform_user_id', $context->user()->id)
                ->orderByDesc('last_seen_at')
                ->get()
        );
    }

    public function destroySession(Request $request, string $sessionUlid, PlatformContext $context): JsonResponse
    {
        $session = PlatformSession::query()
            ->where('platform_user_id', $context->user()->id)
            ->where('ulid', $sessionUlid)
            ->first();

        if (! $session) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        $session->revoked_at = now();
        $session->save();

        return response()->json(['ok' => true]);
    }

    public function destroyOtherSessions(Request $request, PlatformContext $context): JsonResponse
    {
        $current = $request->session()->get(EnsurePlatformContext::SESSION_ULID);

        PlatformSession::query()
            ->where('platform_user_id', $context->user()->id)
            ->whereNull('revoked_at')
            ->when(is_string($current), fn ($query) => $query->where('ulid', '!=', $current))
            ->update(['revoked_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function logoutAll(Request $request, PlatformContext $context): JsonResponse
    {
        $user = $context->user();
        $user->bumpSecurityVersion();
        PlatformSession::query()
            ->where('platform_user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }
}
