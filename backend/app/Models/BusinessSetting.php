<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'business_name',
    'legal_name',
    'phone',
    'email',
    'address',
    'city',
    'country_code',
    'currency_code',
    'timezone',
    'date_format',
    'number_format',
    'tax_registration_number',
    'invoice_prefix',
    'receipt_footer',
    'default_tax_percent',
    'negative_stock_allowed',
    'expiry_tracking_enabled',
    'batch_tracking_enabled',
    'default_price_level',
    'next_product_number',
])]
class BusinessSetting extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'default_tax_percent' => 'decimal:8',
            'negative_stock_allowed' => 'boolean',
            'expiry_tracking_enabled' => 'boolean',
            'batch_tracking_enabled' => 'boolean',
            'next_product_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
