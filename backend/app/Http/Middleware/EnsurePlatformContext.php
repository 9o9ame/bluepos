<?php

namespace App\Http\Middleware;

use App\Actions\Platform\EstablishPlatformSessionAction;
use App\Enums\PlatformUserStatus;
use App\Exceptions\ApiException;
use App\Models\Platform\PlatformDevice;
use App\Models\Platform\PlatformSession;
use App\Platform\PlatformContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformContext
{
    public const SECURITY_VERSION = 'platform_security_version';

    public const SESSION_ULID = 'platform_session_ulid';

    public const DEVICE_ID = 'platform_device_id';

    public const MFA_AT = 'platform_mfa_verified_at';

    public function __construct(
        private readonly PlatformContext $context,
        private readonly EstablishPlatformSessionAction $establishSession,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('platform');
        $user = $guard->user();
        if (! $user) {
            throw new ApiException('UNAUTHORIZED', 'You are not authorized to perform this action.', 401);
        }

        if ($user->status !== PlatformUserStatus::Active) {
            throw new ApiException('ACCOUNT_DISABLED', 'This account is disabled.', 403);
        }

        $ulid = $request->session()->get(self::SESSION_ULID);
        if (! is_string($ulid) && $guard->viaRemember()) {
            $user = $this->establishSession->resumeRemembered($request, $user);
            $ulid = $request->session()->get(self::SESSION_ULID);
        }

        $sessionVersion = (int) $request->session()->get(self::SECURITY_VERSION, 0);
        if ($sessionVersion !== (int) $user->security_version) {
            throw new ApiException('SESSION_REVOKED', 'This session is no longer valid.', 401);
        }

        $record = is_string($ulid)
            ? PlatformSession::query()->where('ulid', $ulid)->first()
            : null;
        if (! $record || $record->isRevoked() || (int) $record->platform_user_id !== (int) $user->id) {
            throw new ApiException('SESSION_REVOKED', 'This session is no longer valid.', 401);
        }

        $device = null;
        $deviceId = $request->session()->get(self::DEVICE_ID);
        if (is_numeric($deviceId)) {
            $device = PlatformDevice::query()->whereKey((int) $deviceId)->first();
        }

        $record->last_seen_at = now();
        $record->save();

        $this->context->hydrate($user, $device);

        if ($user->must_change_password && $this->requiresPasswordChange($request)) {
            throw new ApiException('PASSWORD_CHANGE_REQUIRED', 'You must change your password before continuing.', 403);
        }

        return $next($request);
    }

    private function requiresPasswordChange(Request $request): bool
    {
        $path = '/'.$request->path();

        return ! in_array($path, [
            '/api/platform/auth/me',
            '/api/platform/auth/logout',
            '/api/platform/auth/change-password',
        ], true);
    }
}
