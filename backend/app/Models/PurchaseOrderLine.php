<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'purchase_order_id',
    'product_id',
    'unit_id',
    'quantity',
    'conversion_factor',
    'base_quantity',
    'unit_price',
    'gross_amount',
    'discount_percent',
    'discount_amount',
    'line_total',
    'notes',
])]
class PurchaseOrderLine extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'conversion_factor' => 'decimal:8',
            'base_quantity' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'gross_amount' => 'decimal:4',
            'discount_percent' => 'decimal:8',
            'discount_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function purchaseInvoiceLines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class);
    }
}
