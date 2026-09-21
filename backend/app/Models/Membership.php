<?php

namespace App\Models;

use App\Authz\PermissionCatalogue;
use App\Enums\MembershipStatus;
use App\Support\HasPublicUlid;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['tenant_id', 'user_id', 'username', 'status', 'is_owner', 'security_version', 'mfa_required'])]
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use HasFactory, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'is_owner' => 'boolean',
            'security_version' => 'integer',
            'mfa_required' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'membership_roles')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'membership_branches')
            ->withTimestamps();
    }

    /**
     * Tenant-scoped role check via membership_roles. Does not read `is_owner`.
     */
    public function hasRoleCode(string $code, int|string|null $tenantId = null): bool
    {
        $tenantId = (int) ($tenantId ?? $this->tenant_id);

        if ((int) $this->tenant_id !== $tenantId) {
            return false;
        }

        return $this->roles()
            ->where('roles.tenant_id', $tenantId)
            ->where('roles.code', $code)
            ->where('roles.is_active', true)
            ->exists();
    }

    /**
     * Owner authority: active membership, current tenant, active Owner role.
     * `is_owner` is display metadata only and is not consulted.
     */
    public function hasOwnerAuthority(int|string|null $tenantId = null): bool
    {
        $tenantId = (int) ($tenantId ?? $this->tenant_id);

        if ($this->status !== MembershipStatus::Active) {
            return false;
        }

        return $this->hasRoleCode(PermissionCatalogue::OWNER, $tenantId);
    }

    public function currentSecurityVersion(): int
    {
        $tenantVersion = (int) ($this->relationLoaded('tenant')
            ? $this->tenant?->security_version
            : $this->tenant()->value('security_version'));

        return max(
            (int) $this->security_version,
            (int) $this->user?->security_version,
            $tenantVersion,
        );
    }

    public function bumpSecurityVersion(): void
    {
        $this->security_version = (int) $this->security_version + 1;
        $this->save();
        if ($this->user) {
            $this->user->bumpSecurityVersion();
        }
    }
}
