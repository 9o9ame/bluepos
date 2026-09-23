<?php

namespace App\Actions\Products;

use App\Catalog\TenantCatalog;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class UpdateProductAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly SyncProductPrimarySupplierAction $syncPrimarySupplier,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data): Product {
            $tenantId = $this->tenantContext->tenantId();
            $product = Product::query()
                ->forTenant($tenantId)
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $payload = [];

            foreach (['product_number', 'sku', 'name', 'alternate_name', 'tax_percent', 'is_taxable', 'track_batch', 'track_expiry', 'reorder_level', 'minimum_stock', 'maximum_stock', 'rack_location', 'description', 'secondary_conversion_factor'] as $field) {
                if (array_key_exists($field, $data)) {
                    $payload[$field] = $data[$field];
                }
            }

            if (array_key_exists('category_ulid', $data)) {
                $payload['category_id'] = $data['category_ulid']
                    ? $this->catalog->category($data['category_ulid'])->id
                    : null;
            }
            if (array_key_exists('subcategory_ulid', $data)) {
                $payload['subcategory_id'] = $data['subcategory_ulid']
                    ? $this->catalog->subcategory($data['subcategory_ulid'])->id
                    : null;
            }
            if (array_key_exists('brand_ulid', $data)) {
                $payload['brand_id'] = $data['brand_ulid']
                    ? $this->catalog->brand($data['brand_ulid'])->id
                    : null;
            }
            if (array_key_exists('barcode_group_ulid', $data)) {
                $payload['barcode_group_id'] = $data['barcode_group_ulid']
                    ? $this->catalog->barcodeGroup($data['barcode_group_ulid'])->id
                    : null;
            }
            if (isset($data['base_unit_ulid'])) {
                $payload['base_unit_id'] = $this->catalog->unit($data['base_unit_ulid'])->id;
            }
            if (array_key_exists('secondary_unit_ulid', $data)) {
                $payload['secondary_unit_id'] = $data['secondary_unit_ulid']
                    ? $this->catalog->unit($data['secondary_unit_ulid'])->id
                    : null;
            }
            if (array_key_exists('is_active', $data)) {
                $payload['is_active'] = $data['is_active'];
                $payload['status'] = $data['is_active'] ? ProductStatus::Active : ProductStatus::Discontinued;
            }

            $payload['updated_by'] = $this->tenantContext->userId();
            $product->fill($payload);
            $product->save();

            if (array_key_exists('primary_supplier_ulid', $data)) {
                $this->syncPrimarySupplier->execute(
                    $product,
                    $data['primary_supplier_ulid'],
                    array_key_exists('supplier_product_code', $data)
                        ? $data['supplier_product_code']
                        : null,
                );
            }

            return $product->fresh(CreateProductAction::with());
        });
    }
}
