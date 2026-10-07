<?php

namespace App\Models;

use App\Enums\PriceType;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'branch_id',
    'warehouse_id',
    'customer_id',
    'salesman_party_profile_id',
    'document_number',
    'quotation_date',
    'price_type',
    'subtotal',
    'discount_amount',
    'tax_amount',
    'grand_total',
    'idempotency_key',
    'notes',
    'created_by',
])]
class SaleQuotation extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'quotation_date' => 'date',
            'price_type' => PriceType::class,
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'grand_total' => 'decimal:4',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesmanParty(): BelongsTo
    {
        return $this->belongsTo(PartyProfile::class, 'salesman_party_profile_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleQuotationItem::class, 'sale_quotation_id');
    }
}
