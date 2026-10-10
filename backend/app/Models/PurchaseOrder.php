<?php

namespace App\Models;

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
    'order_date',
    'status',
    'subtotal',
    'discount_amount',
    'grand_total',
    'notes',
    'created_by',
])]
class PurchaseOrder extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'grand_total' => 'decimal:4',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function purchaseInvoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class);
    }
}
