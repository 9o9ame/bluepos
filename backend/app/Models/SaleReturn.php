<?php

namespace App\Models;

use App\Enums\SaleReturnStatus;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'branch_id',
    'warehouse_id',
    'sale_id',
    'customer_id',
    'salesman_party_profile_id',
    'document_number',
    'return_date',
    'status',
    'subtotal',
    'discount_amount',
    'tax_amount',
    'grand_total',
    'refund_amount',
    'reason',
    'notes',
    'idempotency_key',
    'created_by',
    'updated_by',
    'posted_by',
    'posted_at',
])]
class SaleReturn extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'status' => SaleReturnStatus::class,
            'return_date' => 'date',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'refund_amount' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === SaleReturnStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === SaleReturnStatus::Posted;
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<PartyProfile, $this> */
    public function salesmanParty(): BelongsTo
    {
        return $this->belongsTo(PartyProfile::class, 'salesman_party_profile_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return HasMany<SaleReturnLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }
}
