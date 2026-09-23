<?php

namespace App\Models;

use App\Enums\OpeningBalanceStatus;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'branch_id',
    'warehouse_id',
    'document_number',
    'document_date',
    'status',
    'notes',
    'created_by',
    'posted_by',
    'posted_at',
])]
class InventoryOpeningBalance extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected $table = 'inventory_opening_balances';

    protected function casts(): array
    {
        return [
            'status' => OpeningBalanceStatus::class,
            'document_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === OpeningBalanceStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === OpeningBalanceStatus::Posted;
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

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<InventoryOpeningBalanceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryOpeningBalanceLine::class, 'opening_balance_id');
    }
}
