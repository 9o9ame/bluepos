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
    'customer_id',
    'salesman_party_profile_id',
    'sale_date',
    'price_type',
    'notes',
    'idempotency_key',
    'created_by',
    'updated_by',
])]
class SaleHold extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
        ];
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

    /** @return HasMany<SaleHoldItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleHoldItem::class);
    }
}
