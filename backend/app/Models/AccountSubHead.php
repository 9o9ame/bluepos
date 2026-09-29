<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'main_head_id',
    'name',
    'sort_order',
    'is_active',
])]
class AccountSubHead extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
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
     * @return BelongsTo<AccountMainHead, $this>
     */
    public function mainHead(): BelongsTo
    {
        return $this->belongsTo(AccountMainHead::class, 'main_head_id');
    }

    /**
     * @return HasMany<AccountType, $this>
     */
    public function accountTypes(): HasMany
    {
        return $this->hasMany(AccountType::class, 'sub_head_id');
    }
}
