<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\EstablishAuthSessionAction;
use App\Actions\Auth\LoginUserAction;
use App\Actions\Auth\LogoutUserAction;
use App\Enums\DeviceStatus;
use App\Enums\MfaMethod;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthSessionResource;
use App\Models\Device;
use App\Models\MfaChallenge;
use App\Models\PasswordResetChallenge;
use App\Notifications\SecurityCodeNotification;
use App\Security\AuditLogger;
use App\Security\DeviceCredentialService;
use App\Security\SessionRevocationService;
use App\Support\IdentityNormalizer;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(): JsonResponse
    {
        throw new ApiException('REGISTRATION_DISABLED', 'Public registration is disabled.', 403);
    }

    public function login(
        LoginRequest $request,
        LoginUserAction $loginUser,
        EstablishAuthSessionAction $establishSession,
    ): JsonResponse {
        $session = $loginUser->execute(
            $request,
            $request->validated('tenant_code'),
            $request->validated('username'),
            $request->validated('password'),
        );
        $establishSession->execute($request, $session);

        return (new AuthSessionResource($session))->response();
    }

    public function logout(Request $request, LogoutUserAction $logoutUser, SessionRevocationService $revocation): JsonResponse
    {
        $ulid = $request->session()->get(EstablishAuthSessionAction::AUTH_SESSION_ULID);
        if (is_string($ulid)) {
            $record = \App\Models\AuthSessionRecord::query()->where('ulid', $ulid)->first();
            if ($record) {
                $revocation->revokeRecord($record);
            }
        }

        $logoutUser->execute($request);

        app(AuditLogger::class)->record('LOGOUT', [], $request);

        return response()->json(['ok' => true]);
    }

    public function me(TenantContext $tenantContext): AuthSessionResource
    {
        return AuthSessionResource::fromContext($tenantContext);
    }

    public function changePassword(Request $request, TenantContext $tenantContext, SessionRevocationService $revocation): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $user = $tenantContext->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw new ApiException('INVALID_CREDENTIALS', 'Invalid tenant code, username, or password.', 401);
        }

        $user->password = $data['password'];
        $user->must_change_password = false;
        $user->password_changed_at = now();
        $user->save();
        $tenantContext->membership()->bumpSecurityVersion();

        $revocation->revokeMembership($tenantContext->membership()->fresh() ?? $tenantContext->membership(), false);

        app(AuditLogger::class)->record('PASSWORD_CHANGED', [
            'resource_type' => 'user',
            'resource_ulid' => $user->ulid,
        ], $request);

        app(EstablishAuthSessionAction::class)->execute($request, new \App\Auth\AuthenticatedSession(
            user: $user->fresh() ?? $user,
            tenant: $tenantContext->tenant(),
            membership: $tenantContext->membership()->fresh(['user']) ?? $tenantContext->membership(),
            branch: $tenantContext->branch(),
            warehouse: $tenantContext->warehouse(),
            device: $tenantContext->hasDevice() ? $tenantContext->device() : null,
        ), false);

        return response()->json(['ok' => true]);
    }

    public function forgotPassword(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'tenant_code' => ['required', 'string'],
            'username' => ['required', 'string'],
        ]);

        $code = IdentityNormalizer::tenantCode($data['tenant_code']);
        $username = IdentityNormalizer::username($data['username']);
        $tenant = \App\Models\Tenant::query()->where('code', $code)->first();
        $membership = $tenant
            ? \App\Models\Membership::query()->with('user')->where('tenant_id', $tenant->id)->where('username', $username)->first()
            : null;

        if ($membership?->user?->recoveryAddress()) {
            $token = Str::random(40);
            PasswordResetChallenge::query()->create([
                'tenant_id' => $membership->tenant_id,
                'membership_id' => $membership->id,
                'token_hash' => Hash::make($token),
                'expires_at' => now()->addMinutes(30),
            ]);
            Notification::route('mail', $membership->user->recoveryAddress())
                ->notify(new SecurityCodeNotification($token, 'password reset'));
        }

        $audit->record('PASSWORD_RESET_REQUESTED', [
            'tenant_code_hash' => hash('sha256', $code),
            'username_hash' => hash('sha256', $username),
        ], $request);

        return response()->json([
            'ok' => true,
            'message' => 'If the account exists, password reset instructions have been sent.',
        ]);
    }

    public function resetPassword(Request $request, SessionRevocationService $revocation): JsonResponse
    {
        $data = $request->validate([
            'tenant_code' => ['required', 'string'],
            'username' => ['required', 'string'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $generic = fn () => throw new ApiException('INVALID_CREDENTIALS', 'Invalid tenant code, username, or password.', 401);

        $tenant = \App\Models\Tenant::query()->where('code', IdentityNormalizer::tenantCode($data['tenant_code']))->first();
        if (! $tenant) {
            $generic();
        }
        $membership = \App\Models\Membership::query()->with('user')
            ->where('tenant_id', $tenant->id)
            ->where('username', IdentityNormalizer::username($data['username']))
            ->first();
        if (! $membership?->user) {
            $generic();
        }

        $challenge = PasswordResetChallenge::query()
            ->where('membership_id', $membership->id)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (! $challenge || $challenge->expires_at->isPast() || ! Hash::check($data['token'], $challenge->token_hash)) {
            $generic();
        }

        $challenge->consumed_at = now();
        $challenge->save();

        $user = $membership->user;
        $user->password = $data['password'];
        $user->must_change_password = false;
        $user->password_changed_at = now();
        $user->save();
        $membership->bumpSecurityVersion();
        $revocation->revokeMembership($membership->fresh() ?? $membership);

        app(AuditLogger::class)->record('PASSWORD_RESET_COMPLETED', [
            'tenant_id' => $membership->tenant_id,
            'resource_type' => 'membership',
            'resource_ulid' => $membership->ulid,
        ], $request);

        return response()->json(['ok' => true]);
    }

    public function verifyMfa(
        Request $request,
        EstablishAuthSessionAction $establishSession,
    ): JsonResponse {
        $data = $request->validate([
            'challenge_ulid' => ['required', 'string', 'size:26'],
            'code' => ['required', 'string'],
            'trust_device' => ['sometimes', 'boolean'],
        ]);

        $challenge = MfaChallenge::query()
            ->with(['membership.user', 'membership.tenant', 'device'])
            ->where('ulid', $data['challenge_ulid'])
            ->first();

        if (! $challenge || $challenge->consumed_at) {
            throw new ApiException('MFA_INVALID', 'The verification code is invalid.', 403);
        }

        if ($challenge->expires_at->isPast()) {
            throw new ApiException('MFA_EXPIRED', 'The verification code has expired.', 403);
        }

        if ($challenge->attempts >= 5) {
            throw new ApiException('TOO_MANY_ATTEMPTS', 'Too many attempts. Please wait and try again.', 429);
        }

        if (! Hash::check($data['code'], $challenge->code_hash)) {
            $challenge->attempts++;
            $challenge->save();
            app(AuditLogger::class)->record('MFA_FAILURE', [
                'tenant_id' => $challenge->tenant_id,
                'resource_ulid' => $challenge->ulid,
                'resource_type' => 'mfa_challenge',
            ], $request);
            throw new ApiException('MFA_INVALID', 'The verification code is invalid.', 403);
        }

        $challenge->consumed_at = now();
        $challenge->save();

        $device = $challenge->device;
        if ($device) {
            $device->status = DeviceStatus::Active;
            $device->approved_at = now();
            $device->approved_by = $challenge->user_id;
            $device->tenant_id = $challenge->tenant_id;
            if ($request->boolean('trust_device')) {
                $device->trusted_until = now()->addDays(30);
            }
            $device->save();
        }

        $membership = $challenge->membership;
        $branch = app(\App\Authz\MembershipAccess::class)->resolveBranch($membership, $device?->branch_id ? (int) $device->branch_id : null);
        $warehouse = \App\Models\Warehouse::query()->forTenant((int) $membership->tenant_id)->where('branch_id', $branch?->id)->orderByDesc('is_default')->first();
        if (! $branch || ! $warehouse) {
            throw new ApiException('NO_BRANCH', 'No branch is available for this tenant.', 403);
        }

        $session = new \App\Auth\AuthenticatedSession(
            user: $membership->user,
            tenant: $membership->tenant,
            membership: $membership,
            branch: $branch,
            warehouse: $warehouse,
            device: $device,
        );
        $establishSession->execute($request, $session);

        app(AuditLogger::class)->record('MFA_SUCCESS', [
            'resource_type' => 'mfa_challenge',
            'resource_ulid' => $challenge->ulid,
            'method' => MfaMethod::EmailOtp->value,
        ], $request);

        return (new AuthSessionResource($session))->response();
    }
}
