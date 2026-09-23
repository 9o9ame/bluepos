<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'branch_id',
    'warehouse_id',
    'product_id',
    'quantity',
    'average_cost',
    'stock_value',
    'last_movement_id',
])]
class StockBalance extends Model
{
    use Concerns\BelongsToTenant;

    protected $table = 'stock_balances';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'average_cost' => 'decimal:4',
            'stock_value' => 'decimal:4',
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

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function lastMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'last_movement_id');
    }
}
