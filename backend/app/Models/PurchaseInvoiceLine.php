<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'purchase_invoice_id',
    'product_id',
    'unit_id',
    'quantity',
    'conversion_factor',
    'base_quantity',
    'unit_cost',
    'discount_amount',
    'tax_amount',
    'line_total',
    'supplier_product_code',
    'batch_number',
    'expiry_date',
    'notes',
])]
class PurchaseInvoiceLine extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected $table = 'purchase_invoice_lines';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'conversion_factor' => 'decimal:8',
            'base_quantity' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'expiry_date' => 'date',
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
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
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
}
