<?php

namespace App\Actions\Memberships;

use App\Enums\MembershipStatus;
use App\Enums\UserStatus;
use App\Models\Membership;
use App\Models\User;
use App\Security\AuditLogger;
use App\Security\TenantEntitlementService;
use App\Support\IdentityNormalizer;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateMembershipAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SyncMembershipRolesAction $syncRoles,
        private readonly SyncMembershipBranchesAction $syncBranches,
        private readonly AuditLogger $audit,
        private readonly TenantEntitlementService $entitlements,
    ) {}

    /**
     * @param  array{name: string, username: string, recovery_email?: ?string, recovery_phone?: ?string, password: string, role_ulids: list<string>, branch_ulids?: list<string>, must_change_password?: bool}  $data
     */
    public function execute(array $data): Membership
    {
        $tenantId = $this->tenantContext->tenantId();
        $username = IdentityNormalizer::username($data['username']);
        $recovery = isset($data['recovery_email']) && $data['recovery_email']
            ? strtolower(trim((string) $data['recovery_email']))
            : null;

        return DB::transaction(function () use ($data, $tenantId, $username, $recovery): Membership {
            $this->entitlements->assertCanCreateUser($this->tenantContext->tenant());

            if (! IdentityNormalizer::isValidUsername($username)) {
                throw ValidationException::withMessages([
                    'username' => 'Username must be 2-63 letters, numbers, dots, underscores, or hyphens.',
                ]);
            }

            if (Membership::query()->where('tenant_id', $tenantId)->where('username', $username)->exists()) {
                throw ValidationException::withMessages([
                    'username' => 'This username is already used in the tenant.',
                ]);
            }

            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $recovery,
                'recovery_email' => $recovery,
                'recovery_phone' => $data['recovery_phone'] ?? null,
                'password' => $data['password'],
                'status' => UserStatus::Active,
                'must_change_password' => $data['must_change_password'] ?? true,
                'security_version' => 1,
            ]);

            $membership = Membership::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'username' => $username,
                'status' => MembershipStatus::Active,
                'is_owner' => false,
                'security_version' => 1,
            ]);

            $this->syncRoles->execute($membership, $data['role_ulids']);

            if (isset($data['branch_ulids'])) {
                $this->syncBranches->execute($membership->fresh(), $data['branch_ulids']);
            }

            $fresh = $membership->fresh(['user', 'roles', 'branches']);
            $this->audit->record('USER_CREATED', [
                'resource_type' => 'membership',
                'resource_ulid' => $fresh?->ulid,
            ]);

            return $fresh ?? $membership;
        });
    }
}
