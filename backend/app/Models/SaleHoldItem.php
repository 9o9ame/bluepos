<?php

namespace App\Models;

use App\Enums\SaleLineKind;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'sale_hold_id',
    'product_id',
    'unit_id',
    'sale_scheme_id',
    'line_kind',
    'barcode',
    'quantity',
    'discount_percent',
    'discount_amount',
    'notes',
    'sort_order',
])]
class SaleHoldItem extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'line_kind' => SaleLineKind::class,
            'quantity' => 'decimal:6',
            'discount_percent' => 'decimal:8',
            'discount_amount' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SaleHold, $this> */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(SaleHold::class, 'sale_hold_id');
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

    /** @return BelongsTo<SaleScheme, $this> */
    public function saleScheme(): BelongsTo
    {
        return $this->belongsTo(SaleScheme::class);
    }
}
