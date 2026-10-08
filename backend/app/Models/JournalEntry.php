<?php

namespace App\Models;

use App\Enums\JournalStatus;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'branch_id',
    'document_type',
    'document_id',
    'voucher_number',
    'idempotency_key',
    'entry_date',
    'description',
    'status',
    'created_by',
    'posted_by',
    'posted_at',
    'reverses_journal_entry_id',
])]
class JournalEntry extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    public const DOCUMENT_OPENING_BALANCE = 'opening_balance';
    public const DOCUMENT_PURCHASE_INVOICE = 'purchase_invoice';
    public const DOCUMENT_PURCHASE_RETURN = 'purchase_return';
    public const DOCUMENT_PURCHASE_PAYMENT = 'purchase_payment';
    public const DOCUMENT_PAYMENT_VOUCHER = 'payment_voucher';
    public const DOCUMENT_RECEIVING_VOUCHER = 'receiving_voucher';
    public const DOCUMENT_JOURNAL_VOUCHER = 'journal_voucher';

    protected function casts(): array
    {
        return [
            'status' => JournalStatus::class,
            'entry_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === JournalStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === JournalStatus::Posted;
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
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
