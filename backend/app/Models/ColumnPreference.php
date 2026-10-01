<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'scope', 'scope_id', 'screen_key', 'layout'])]
class ColumnPreference extends Model
{
    public const SCOPE_USER = 'user';

    public const SCOPE_ROLE = 'role';

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'scope_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<ColumnPreference>  $query
     * @return Builder<ColumnPreference>
     */
    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where($this->qualifyColumn('tenant_id'), $tenantId);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
