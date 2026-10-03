<?php

namespace App\Models;

use App\Enums\SaleLineKind;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'sale_id',
    'product_id',
    'unit_id',
    'line_kind',
    'sale_scheme_id',
    'quantity',
    'unit_price',
    'discount_amount',
    'tax_amount',
    'line_total',
    'notes',
])]
class SaleItem extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected $table = 'sale_items';

    protected function casts(): array
    {
        return [
            'line_kind' => SaleLineKind::class,
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<SaleScheme, $this>
     */
    public function saleScheme(): BelongsTo
    {
        return $this->belongsTo(SaleScheme::class, 'sale_scheme_id');
    }
}
