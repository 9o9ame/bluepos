<?php

namespace App\Models;

use App\Enums\StockTakeStatus;
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
    'count_date',
    'status',
    'notes',
    'created_by',
    'posted_by',
    'posted_at',
])]
class InventoryStockTake extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected $table = 'inventory_stock_takes';

    protected function casts(): array
    {
        return [
            'status' => StockTakeStatus::class,
            'count_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === StockTakeStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === StockTakeStatus::Posted;
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryStockTakeLine::class, 'stock_take_id');
    }
}
