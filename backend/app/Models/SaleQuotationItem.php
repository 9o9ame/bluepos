<?php

namespace App\Models;

use App\Enums\PriceType;
use App\Enums\SaleLineKind;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'sale_quotation_id',
    'product_id',
    'unit_id',
    'sale_scheme_id',
    'barcode',
    'conversion_factor',
    'line_kind',
    'quantity',
    'stock_quantity',
    'price_type',
    'unit_price',
    'gross_amount',
    'discount_percent',
    'discount_amount',
    'tax_percent',
    'tax_amount',
    'line_total',
    'notes',
    'sort_order',
])]
class SaleQuotationItem extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:8',
            'line_kind' => SaleLineKind::class,
            'quantity' => 'decimal:6',
            'stock_quantity' => 'decimal:6',
            'price_type' => PriceType::class,
            'unit_price' => 'decimal:4',
            'gross_amount' => 'decimal:4',
            'discount_percent' => 'decimal:8',
            'discount_amount' => 'decimal:4',
            'tax_percent' => 'decimal:8',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SaleQuotation::class, 'sale_quotation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function saleScheme(): BelongsTo
    {
        return $this->belongsTo(SaleScheme::class);
    }
}
