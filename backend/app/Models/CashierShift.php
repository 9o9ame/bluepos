<?php

namespace App\Models;

use App\Enums\CashierShiftStatus;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'branch_id',
    'device_id',
    'membership_id',
    'opened_at',
    'closed_at',
    'status',
    'opening_cash',
    'expected_cash',
    'actual_cash',
    'difference',
    'notes',
])]
class CashierShift extends Model
{
    use HasPublicUlid;

    protected function casts(): array
    {
        return [
            'status' => CashierShiftStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:4',
            'expected_cash' => 'decimal:4',
            'actual_cash' => 'decimal:4',
            'difference' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Membership, $this>
     */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function isOpen(): bool
    {
        return $this->status === CashierShiftStatus::Open;
    }
}
