<?php

namespace App\Models;

use App\Enums\SaleLineKind;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'sale_return_id',
    'sale_item_id',
    'product_id',
    'unit_id',
    'line_kind',
    'barcode',
    'quantity',
    'conversion_factor',
    'stock_quantity',
    'unit_price',
    'gross_amount',
    'discount_amount',
    'tax_amount',
    'line_total',
    'reason',
    'notes',
])]
class SaleReturnLine extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'line_kind' => SaleLineKind::class,
            'quantity' => 'decimal:6',
            'conversion_factor' => 'decimal:8',
            'stock_quantity' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'gross_amount' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SaleReturn, $this> */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
