<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'stock_take_id',
    'product_id',
    'system_quantity',
    'counted_quantity',
    'variance_quantity',
    'notes',
])]
class InventoryStockTakeLine extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected $table = 'inventory_stock_take_lines';

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:6',
            'counted_quantity' => 'decimal:6',
            'variance_quantity' => 'decimal:6',
        ];
    }

    public function stockTake(): BelongsTo
    {
        return $this->belongsTo(InventoryStockTake::class, 'stock_take_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
