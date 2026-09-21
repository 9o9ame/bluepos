<?php

namespace App\Models;

use App\Enums\WarehouseStatus;
use App\Support\HasPublicUlid;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'branch_id', 'code', 'name', 'status', 'is_default'])]
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'status' => WarehouseStatus::class,
            'is_default' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Warehouse>  $query
     * @return Builder<Warehouse>
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

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
