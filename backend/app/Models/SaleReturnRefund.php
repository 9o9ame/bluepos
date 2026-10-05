<?php

namespace App\Models;

use App\Enums\SalePaymentMethod;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'branch_id',
    'sale_return_id',
    'account_id',
    'method',
    'reference',
    'amount',
    'journal_entry_ulid',
    'idempotency_key',
    'created_by',
])]
class SaleReturnRefund extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'method' => SalePaymentMethod::class,
            'amount' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SaleReturn, $this> */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
