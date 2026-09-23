<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'branch_id',
    'warehouse_id',
    'product_id',
    'movement_type',
    'quantity',
    'unit_cost',
    'total_cost',
    'reference_type',
    'reference_ulid',
    'reference_line_ulid',
    'occurred_at',
    'posted_by',
    'idempotency_key',
    'notes',
])]
class StockMovement extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    public $timestamps = false;

    protected $table = 'stock_movements';

    protected function casts(): array
    {
        return [
            'movement_type' => StockMovementType::class,
            'quantity' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StockMovement $movement): void {
            if ($movement->created_at === null) {
                $movement->created_at = now();
            }
        });

        static::updating(function (): void {
            throw new \RuntimeException('Stock movements are immutable.');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Stock movements are immutable.');
        });
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
