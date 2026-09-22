<?php

namespace App\Actions\Platform;

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsurePlatformContext;
use App\Models\Platform\PlatformPasswordResetChallenge;
use App\Models\Platform\PlatformSession;
use App\Models\Platform\PlatformUser;
use App\Platform\PlatformAuditLogger;
use App\Security\EmailNormalizer;
use App\Security\SecurityOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ResetPlatformPasswordAction
{
    public function __construct(private readonly PlatformAuditLogger $audit) {}

    public function execute(Request $request, string $email, string $token, string $password): JsonResponse
    {
        $generic = fn () => throw new ApiException('INVALID_CREDENTIALS', 'Invalid email or password.', 401);

        $normalized = EmailNormalizer::normalize($email);
        $user = $normalized ? PlatformUser::query()->where('email', $normalized)->first() : null;
        if (! $user) {
            $generic();
        }

        $challenge = PlatformPasswordResetChallenge::query()
            ->where('platform_user_id', $user->id)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (! $challenge || $challenge->expires_at->isPast()) {
            $generic();
        }

        if ($challenge->attempts >= SecurityOtp::maxVerifyAttempts()) {
            throw new ApiException('TOO_MANY_ATTEMPTS', 'Too many attempts. Please wait and try again.', 429);
        }

        if (! Hash::check($token, $challenge->token_hash)) {
            $challenge->attempts++;
            $challenge->save();
            $generic();
        }

        $challenge->consumed_at = now();
        $challenge->save();

        $user->password = $password;
        $user->must_change_password = false;
        $user->password_changed_at = now();
        $user->save();
        $user->bumpSecurityVersion();

        PlatformSession::query()
            ->where('platform_user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if (Auth::guard('platform')->id() === $user->id) {
            Auth::guard('platform')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->forget([
                EnsurePlatformContext::SECURITY_VERSION,
                EnsurePlatformContext::SESSION_ULID,
                EnsurePlatformContext::DEVICE_ID,
                EnsurePlatformContext::MFA_AT,
            ]);
        }

        $this->audit->record('PLATFORM_PASSWORD_RESET_COMPLETED', [
            'resource_type' => 'platform_user',
            'resource_ulid' => $user->ulid,
        ], $request, $user);

        return response()->json(['ok' => true]);
    }
}
