<?php

namespace App\Models;

use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'party_profile_id',
    'code',
    'name',
    'deals_in',
    'contact_person',
    'mobile',
    'mobile_secondary',
    'phone',
    'phone_secondary',
    'email',
    'address',
    'billing_address',
    'account_type_id',
    'area',
    'invoice_restricted',
    'credit_limit_amount',
    'credit_limit_days',
    'add_percent',
    'cnic',
    'ntn',
    'stn',
    'formulas',
    'license_number',
    'license_issued_on',
    'license_type',
    'license_expires_on',
    'ignore_warranty',
    'print_license',
    'rf_id',
    'store_allowed',
    'tax_number',
    'notes',
    'image_path',
    'is_active',
    'created_by',
    'updated_by',
])]
class Supplier extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ignore_warranty' => 'boolean',
            'print_license' => 'boolean',
            'invoice_restricted' => 'boolean',
            'credit_limit_amount' => 'decimal:4',
            'credit_limit_days' => 'integer',
            'add_percent' => 'decimal:8',
            'license_issued_on' => 'date',
            'license_expires_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function partyProfile(): BelongsTo
    {
        return $this->belongsTo(PartyProfile::class);
    }

    /**
     * @return BelongsTo<AccountType, $this>
     */
    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    /**
     * @return HasMany<ProductSupplier, $this>
     */
    public function productSuppliers(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_suppliers')
            ->withPivot([
                'ulid',
                'supplier_product_code',
                'is_primary',
                'is_active',
            ])
            ->withTimestamps();
    }
}
