<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'adjustment_id',
    'product_id',
    'system_quantity',
    'quantity_delta',
    'after_quantity',
    'unit_cost',
    'total_amount',
    'notes',
])]
class InventoryAdjustmentLine extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected $table = 'inventory_adjustment_lines';

    protected function casts(): array
    {
        return [
            'system_quantity' => 'decimal:6',
            'quantity_delta' => 'decimal:6',
            'after_quantity' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'total_amount' => 'decimal:4',
        ];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'adjustment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
