<?php

namespace App\Models;

use App\Enums\InventoryAdjustmentStatus;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id',
    'branch_id',
    'warehouse_id',
    'account_id',
    'document_number',
    'adjustment_date',
    'adjustment_type',
    'status',
    'notes',
    'created_by',
    'posted_by',
    'posted_at',
])]
class InventoryAdjustment extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected $table = 'inventory_adjustments';

    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'status' => InventoryAdjustmentStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === InventoryAdjustmentStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === InventoryAdjustmentStatus::Posted;
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryAdjustmentLine::class, 'adjustment_id');
    }

    /** @return HasOne<DocumentRevision, $this> */
    public function latestRevision(): HasOne
    {
        return $this->hasOne(DocumentRevision::class, 'document_ulid', 'ulid')
            ->where('document_type', 'inventory_adjustment')
            ->ofMany('revision_number', 'max');
    }
}
