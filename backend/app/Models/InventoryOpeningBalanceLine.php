<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'opening_balance_id',
    'product_id',
    'quantity',
    'unit_cost',
    'total_cost',
    'notes',
])]
class InventoryOpeningBalanceLine extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected $table = 'inventory_opening_balance_lines';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
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
     * @return BelongsTo<InventoryOpeningBalance, $this>
     */
    public function openingBalance(): BelongsTo
    {
        return $this->belongsTo(InventoryOpeningBalance::class, 'opening_balance_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
