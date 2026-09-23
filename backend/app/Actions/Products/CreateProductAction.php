<?php

namespace App\Actions\Products;

use App\Catalog\TenantCatalog;
use App\Enums\ProductStatus;
use App\Models\BarcodeGroup;
use App\Models\Brand;
use App\Models\BusinessSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\Unit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateProductAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
        private readonly SyncProductBarcodesAction $syncBarcodes,
        private readonly SyncProductPricesAction $syncPrices,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(array $data): Product
    {
        return DB::transaction(function () use ($data): Product {
            $tenantId = $this->tenantContext->tenantId();
            $relations = $this->resolveRelations($data);

            $settings = BusinessSetting::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $productNumber = $data['product_number'] ?? str_pad((string) $settings->next_product_number, 6, '0', STR_PAD_LEFT);

            if (Product::query()->forTenant($tenantId)->where('product_number', $productNumber)->exists()) {
                throw ValidationException::withMessages([
                    'product_number' => 'This product number is already used in the tenant.',
                ]);
            }

            if (! isset($data['product_number'])) {
                $settings->next_product_number = (int) $settings->next_product_number + 1;
                $settings->save();
            }

            $product = Product::query()->create([
                'tenant_id' => $tenantId,
                'product_number' => $productNumber,
                'sku' => $data['sku'] ?? null,
                'name' => $data['name'],
                'alternate_name' => $data['alternate_name'] ?? null,
                'category_id' => $relations['category']?->id,
                'subcategory_id' => $relations['subcategory']?->id,
                'brand_id' => $relations['brand']?->id,
                'barcode_group_id' => $relations['barcodeGroup']?->id,
                'base_unit_id' => $relations['baseUnit']->id,
                'secondary_unit_id' => $relations['secondaryUnit']?->id,
                'secondary_conversion_factor' => $data['secondary_conversion_factor'] ?? null,
                'tax_percent' => $data['tax_percent'] ?? $settings->default_tax_percent,
                'is_taxable' => $data['is_taxable'] ?? true,
                'track_batch' => $data['track_batch'] ?? false,
                'track_expiry' => $data['track_expiry'] ?? false,
                'reorder_level' => $data['reorder_level'] ?? null,
                'minimum_stock' => $data['minimum_stock'] ?? null,
                'maximum_stock' => $data['maximum_stock'] ?? null,
                'rack_location' => $data['rack_location'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => ProductStatus::Active,
                'is_active' => true,
                'created_by' => $this->tenantContext->userId(),
            ]);

            if (! empty($data['barcodes'])) {
                $this->syncBarcodes->execute($product, $data['barcodes']);
            }

            if (! empty($data['prices'])) {
                $this->syncPrices->execute($product, $data['prices']);
            }

            return $product->fresh($this->with());
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{category: ?Category, subcategory: ?Subcategory, brand: ?Brand, barcodeGroup: ?BarcodeGroup, baseUnit: Unit, secondaryUnit: ?Unit}
     */
    private function resolveRelations(array $data): array
    {
        $category = isset($data['category_ulid']) ? $this->catalog->category($data['category_ulid']) : null;
        $subcategory = isset($data['subcategory_ulid']) ? $this->catalog->subcategory($data['subcategory_ulid']) : null;

        if ($subcategory && $category && (int) $subcategory->category_id !== (int) $category->id) {
            throw ValidationException::withMessages([
                'subcategory_ulid' => 'The subcategory does not belong to the selected category.',
            ]);
        }

        if ($subcategory && ! $category) {
            $category = $subcategory->category;
        }

        $secondary = isset($data['secondary_unit_ulid']) ? $this->catalog->unit($data['secondary_unit_ulid']) : null;

        return [
            'category' => $category,
            'subcategory' => $subcategory,
            'brand' => isset($data['brand_ulid']) ? $this->catalog->brand($data['brand_ulid']) : null,
            'barcodeGroup' => isset($data['barcode_group_ulid'])
                ? $this->catalog->barcodeGroup($data['barcode_group_ulid'])
                : null,
            'baseUnit' => $this->catalog->unit($data['base_unit_ulid']),
            'secondaryUnit' => $secondary,
        ];
    }

    /**
     * @return list<string>
     */
    public static function with(): array
    {
        return ['category', 'subcategory', 'brand', 'barcodeGroup', 'baseUnit', 'secondaryUnit', 'barcodes.unit', 'prices'];
    }
}
