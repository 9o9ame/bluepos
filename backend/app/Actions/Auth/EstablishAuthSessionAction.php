<?php

namespace App\Actions\Auth;

use App\Auth\AuthenticatedSession;
use App\Logging\AuthEventLogger;
use App\Models\AuthSessionRecord;
use App\Security\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class EstablishAuthSessionAction
{
    public const TENANT_ID = 'tenant_id';

    public const MEMBERSHIP_ID = 'membership_id';

    public const BRANCH_ID = 'branch_id';

    public const WAREHOUSE_ID = 'warehouse_id';

    public const DEVICE_ID = 'device_id';

    public const SECURITY_VERSION = 'security_version';

    public const AUTH_SESSION_ULID = 'auth_session_ulid';

    public function __construct(
        private readonly AuthEventLogger $logger,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Request $request, AuthenticatedSession $session, bool $logSuccess = true): void
    {
        Auth::guard('web')->login($session->user);
        $request->session()->regenerate();

        $version = $session->membership->currentSecurityVersion();

        $record = AuthSessionRecord::query()->create([
            'tenant_id' => $session->tenant->id,
            'user_id' => $session->user->id,
            'membership_id' => $session->membership->id,
            'device_id' => $session->device?->id,
            'laravel_session_id' => $request->session()->getId(),
            'security_version' => $version,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 180, ''),
            'last_seen_at' => now(),
        ]);

        $request->session()->put([
            self::TENANT_ID => $session->tenant->id,
            self::MEMBERSHIP_ID => $session->membership->id,
            self::BRANCH_ID => $session->branch->id,
            self::WAREHOUSE_ID => $session->warehouse->id,
            self::DEVICE_ID => $session->device?->id,
            self::SECURITY_VERSION => $version,
            self::AUTH_SESSION_ULID => $record->ulid,
        ]);

        $this->tenantContext->hydrate(
            $session->user,
            $session->tenant,
            $session->membership,
            $session->branch,
            $session->warehouse,
            $session->device,
        );

        $session->user->forceFill([
            'last_login_at' => now(),
        ])->save();

        if ($logSuccess) {
            $this->logger->loginSucceeded($session->user, $request);
            $this->audit->record('LOGIN_SUCCESS', [
                'resource_type' => 'membership',
                'resource_ulid' => $session->membership->ulid,
            ], $request);
        }
    }
}
