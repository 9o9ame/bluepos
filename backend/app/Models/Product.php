<?php

namespace App\Models;

use App\Enums\ProductStatus;
use App\Support\HasPublicUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id',
    'product_number',
    'sku',
    'name',
    'alternate_name',
    'category_id',
    'subcategory_id',
    'brand_id',
    'barcode_group_id',
    'base_unit_id',
    'secondary_unit_id',
    'secondary_conversion_factor',
    'tax_percent',
    'is_taxable',
    'track_batch',
    'track_expiry',
    'reorder_level',
    'minimum_stock',
    'maximum_stock',
    'rack_location',
    'description',
    'status',
    'is_active',
    'created_by',
    'updated_by',
])]
class Product extends Model
{
    use Concerns\BelongsToTenant, HasPublicUlid;

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'is_taxable' => 'boolean',
            'track_batch' => 'boolean',
            'track_expiry' => 'boolean',
            'is_active' => 'boolean',
            'tax_percent' => 'decimal:8',
            'secondary_conversion_factor' => 'decimal:8',
            'reorder_level' => 'decimal:6',
            'minimum_stock' => 'decimal:6',
            'maximum_stock' => 'decimal:6',
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
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Subcategory, $this>
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<BarcodeGroup, $this>
     */
    public function barcodeGroup(): BelongsTo
    {
        return $this->belongsTo(BarcodeGroup::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function secondaryUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'secondary_unit_id');
    }

    /**
     * @return HasMany<ProductBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /**
     * @return HasMany<ProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * @return HasMany<ProductSupplier, $this>
     */
    public function productSuppliers(): HasMany
    {
        return $this->hasMany(ProductSupplier::class);
    }

    /**
     * @return HasOne<ProductSupplier, $this>
     */
    public function primaryProductSupplier(): HasOne
    {
        return $this->hasOne(ProductSupplier::class)->where('is_primary', true);
    }

    /**
     * @return BelongsToMany<Supplier, $this>
     */
    public function suppliers(): BelongsToMany
    {
        return $this->belongsToMany(Supplier::class, 'product_suppliers')
            ->withPivot([
                'ulid',
                'supplier_product_code',
                'is_primary',
                'is_active',
            ])
            ->withTimestamps();
    }
}
