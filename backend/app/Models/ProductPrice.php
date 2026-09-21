<?php

namespace App\Models;

use App\Enums\PriceType;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'product_id',
    'price_type',
    'amount',
    'currency_code',
    'is_active',
    'effective_from',
    'effective_to',
])]
class ProductPrice extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'price_type' => PriceType::class,
            'amount' => 'decimal:4',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
