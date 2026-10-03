<?php

namespace App\Models;

use App\Enums\SaleSchemeApplyMode;
use App\Enums\SaleSchemeType;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'name',
    'scheme_type',
    'apply_mode',
    'min_sale_amount',
    'reward_product_id',
    'max_reward_qty',
    'starts_on',
    'ends_on',
    'is_stackable',
    'is_active',
    'created_by',
    'updated_by',
])]
class SaleScheme extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'scheme_type' => SaleSchemeType::class,
            'apply_mode' => SaleSchemeApplyMode::class,
            'min_sale_amount' => 'decimal:4',
            'max_reward_qty' => 'decimal:6',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_stackable' => 'boolean',
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
     * @return BelongsTo<Product, $this>
     */
    public function rewardProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'reward_product_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
