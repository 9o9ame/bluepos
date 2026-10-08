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
    'purchase_invoice_id',
    'account_id',
    'method',
    'reference',
    'amount',
    'journal_entry_ulid',
    'idempotency_key',
    'created_by',
])]
class PurchasePayment extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected $table = 'purchase_payments';

    protected function casts(): array
    {
        return [
            'method' => SalePaymentMethod::class,
            'amount' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
