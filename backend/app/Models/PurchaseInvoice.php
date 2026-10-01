<?php

namespace App\Models;

use App\Enums\PurchaseInvoiceStatus;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'branch_id',
    'warehouse_id',
    'supplier_id',
    'document_number',
    'supplier_invoice_number',
    'po_number',
    'invoice_type',
    'currency_code',
    'calculation_method',
    'default_sales_tax_pct',
    'default_further_tax_pct',
    'default_advance_tax_pct',
    'default_price_type',
    'brand_label',
    'invoice_date',
    'due_date',
    'status',
    'subtotal',
    'discount_amount',
    'tax_amount',
    'further_tax_amount',
    'freight_amount',
    'loading_amount',
    'other_charges',
    'other_discount',
    'trade_offer',
    'advance_tax_amount',
    'round_off',
    'grand_total',
    'notes',
    'tax_type',
    'payment_terms',
    'created_by',
    'updated_by',
    'posted_by',
    'posted_at',
])]
class PurchaseInvoice extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected $table = 'purchase_invoices';

    protected function casts(): array
    {
        return [
            'status' => PurchaseInvoiceStatus::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'further_tax_amount' => 'decimal:4',
            'freight_amount' => 'decimal:4',
            'loading_amount' => 'decimal:4',
            'other_charges' => 'decimal:4',
            'other_discount' => 'decimal:4',
            'trade_offer' => 'decimal:4',
            'advance_tax_amount' => 'decimal:4',
            'round_off' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'default_sales_tax_pct' => 'decimal:8',
            'default_further_tax_pct' => 'decimal:8',
            'default_advance_tax_pct' => 'decimal:8',
            'posted_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseInvoiceStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === PurchaseInvoiceStatus::Posted;
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<PurchaseInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class);
    }
}
