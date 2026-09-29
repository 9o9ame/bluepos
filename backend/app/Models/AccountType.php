<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'sub_head_id',
    'code',
    'name',
    'is_cash',
    'is_bank',
    'is_receivable',
    'is_payable',
    'pnl_grouping_label',
    'hint',
    'sort_order',
    'is_active',
])]
class AccountType extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'is_cash' => 'boolean',
            'is_bank' => 'boolean',
            'is_receivable' => 'boolean',
            'is_payable' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
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
     * @return BelongsTo<AccountSubHead, $this>
     */
    public function subHead(): BelongsTo
    {
        return $this->belongsTo(AccountSubHead::class, 'sub_head_id');
    }
}
