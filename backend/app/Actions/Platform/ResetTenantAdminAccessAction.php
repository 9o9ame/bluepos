<?php

namespace App\Actions\Platform;

use App\Authz\PermissionCatalogue;
use App\Exceptions\ApiException;
use App\Models\Membership;
use App\Models\Tenant;
use App\Platform\PlatformAuditLogger;
use App\Platform\PlatformContext;
use App\Security\SessionRevocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResetTenantAdminAccessAction
{
    public function __construct(
        private readonly PlatformAuditLogger $audit,
        private readonly PlatformContext $context,
        private readonly SessionRevocationService $revocation,
    ) {}

    /**
     * @return array{membership: Membership, temporary_password: string}
     */
    public function execute(Tenant $tenant, ?string $membershipUlid = null): array
    {
        return DB::transaction(function () use ($tenant, $membershipUlid): array {
            $query = Membership::query()
                ->with('user')
                ->where('tenant_id', $tenant->id);

            $membership = $membershipUlid
                ? (clone $query)->where('ulid', $membershipUlid)->lockForUpdate()->first()
                : (clone $query)->whereHas('roles', function ($roles) use ($tenant): void {
                    $roles->where('roles.tenant_id', $tenant->id)
                        ->where('roles.code', PermissionCatalogue::OWNER)
                        ->where('roles.is_active', true);
                })->lockForUpdate()->first();

            if (! $membership || ! $membership->user) {
                throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
            }

            $password = Str::password(16);
            $membership->user->password = $password;
            $membership->user->must_change_password = true;
            $membership->user->password_changed_at = now();
            $membership->user->save();
            $membership->bumpSecurityVersion();
            $this->revocation->revokeMembership($membership->fresh() ?? $membership, false);

            $this->audit->record('TENANT_ADMIN_RESET', [
                'resource_type' => 'membership',
                'resource_ulid' => $membership->ulid,
                'tenant_ulid' => $tenant->ulid,
            ], null, $this->context->hasUser() ? $this->context->user() : null);

            $this->audit->record('TENANT_SECURITY_REVOKED', [
                'resource_type' => 'tenant',
                'resource_ulid' => $tenant->ulid,
                'reason' => 'admin_reset',
            ], null, $this->context->hasUser() ? $this->context->user() : null);

            return [
                'membership' => $membership->fresh(['user', 'roles']) ?? $membership,
                'temporary_password' => $password,
            ];
        });
    }
}
