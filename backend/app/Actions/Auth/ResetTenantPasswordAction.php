<?php

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Models\Membership;
use App\Models\PasswordResetChallenge;
use App\Models\Tenant;
use App\Security\AuditLogger;
use App\Security\SecurityOtp;
use App\Security\SessionRevocationService;
use App\Support\IdentityNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ResetTenantPasswordAction
{
    public function __construct(
        private readonly SessionRevocationService $revocation,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Request $request, string $tenantCode, string $username, string $token, string $password): JsonResponse
    {
        $generic = fn () => throw new ApiException('INVALID_CREDENTIALS', 'Invalid tenant code, username, or password.', 401);

        $tenant = Tenant::query()->where('code', IdentityNormalizer::tenantCode($tenantCode))->first();
        if (! $tenant) {
            $generic();
        }

        $membership = Membership::query()->with('user')
            ->where('tenant_id', $tenant->id)
            ->where('username', IdentityNormalizer::username($username))
            ->first();
        if (! $membership?->user) {
            $generic();
        }

        $challenge = PasswordResetChallenge::query()
            ->where('membership_id', $membership->id)
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

        $user = $membership->user;
        $user->password = $password;
        $user->must_change_password = false;
        $user->password_changed_at = now();
        $user->save();
        $this->revocation->revokeMembership($membership->fresh() ?? $membership);

        $this->audit->record('PASSWORD_RESET_COMPLETED', [
            'tenant_id' => $membership->tenant_id,
            'resource_type' => 'membership',
            'resource_ulid' => $membership->ulid,
        ], $request);

        return response()->json(['ok' => true]);
    }
}
