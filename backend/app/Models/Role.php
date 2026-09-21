<?php

namespace App\Models;

use App\Authz\PermissionCatalogue;
use App\Enums\BranchAccess;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['tenant_id', 'name', 'code', 'description', 'branch_access', 'is_system', 'is_active'])]
class Role extends Model
{
    use HasPublicUlid;

    protected function casts(): array
    {
        return [
            'branch_access' => BranchAccess::class,
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Role>  $query
     * @return Builder<Role>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where($this->qualifyColumn('tenant_id'), $tenantId);
    }

    public function isOwnerRole(): bool
    {
        return $this->code === PermissionCatalogue::OWNER;
    }

    public function grantsAllBranches(): bool
    {
        return $this->branch_access === BranchAccess::AllBranches;
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Membership, $this>
     */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'membership_roles')
            ->withTimestamps();
    }
}
