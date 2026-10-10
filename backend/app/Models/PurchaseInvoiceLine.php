<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'purchase_invoice_id',
    'purchase_order_line_id',
    'product_id',
    'unit_id',
    'quantity',
    'conversion_factor',
    'base_quantity',
    'unit_cost',
    'discount_amount',
    'tax_amount',
    'further_tax_amount',
    'line_total',
    'supplier_product_code',
    'batch_number',
    'expiry_date',
    'notes',
    'brand_label',
    'hs_code',
    'pack_size',
    'qty_ctn',
    'free_pcs',
    'price_type',
    'mrp',
    'trade_disc_pct',
    'regular_disc_pct',
    'special_disc_pct',
    'tax_pct',
    'further_tax_pct',
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
            'further_tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'qty_ctn' => 'decimal:6',
            'free_pcs' => 'decimal:6',
            'mrp' => 'decimal:4',
            'trade_disc_pct' => 'decimal:8',
            'regular_disc_pct' => 'decimal:8',
            'special_disc_pct' => 'decimal:8',
            'tax_pct' => 'decimal:8',
            'further_tax_pct' => 'decimal:8',
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

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
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
