<?php

namespace App\Actions\Products;

use App\Catalog\TenantCatalog;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncProductBarcodesAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantCatalog $catalog,
    ) {}

    /**
     * @param  list<array{barcode: string, unit_ulid: string, conversion_factor: string, is_primary?: bool, is_active?: bool}>  $rows
     */
    public function execute(Product $product, array $rows): Product
    {
        return DB::transaction(function () use ($product, $rows): Product {
            $tenantId = $this->tenantContext->tenantId();
            $product = Product::query()
                ->forTenant($tenantId)
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $primaryCount = 0;
            $barcodes = [];
            $seen = [];

            foreach ($rows as $row) {
                $unit = $this->catalog->unit($row['unit_ulid']);
                $isPrimary = (bool) ($row['is_primary'] ?? false);
                if ($isPrimary) {
                    $primaryCount++;
                }

                $barcode = trim($row['barcode']);
                if ($barcode === '' || isset($seen[$barcode])) {
                    throw ValidationException::withMessages([
                        'barcodes' => 'Each barcode on a product must be unique.',
                    ]);
                }
                $seen[$barcode] = true;

                $exists = ProductBarcode::query()
                    ->forTenant($tenantId)
                    ->where('barcode', $barcode)
                    ->where('product_id', '!=', $product->id)
                    ->exists();

                if ($exists) {
                    throw ValidationException::withMessages([
                        'barcodes' => 'Barcode '.$barcode.' is already assigned to another product in this tenant.',
                    ]);
                }

                $barcodes[] = [
                    'barcode' => $barcode,
                    'unit_id' => $unit->id,
                    'conversion_factor' => $row['conversion_factor'],
                    'is_primary' => $isPrimary,
                    'is_active' => $row['is_active'] ?? true,
                ];
            }

            if ($primaryCount !== 1) {
                throw ValidationException::withMessages([
                    'barcodes' => 'A product must have exactly one primary barcode.',
                ]);
            }

            ProductBarcode::query()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->get();

            ProductBarcode::query()->where('product_id', $product->id)->delete();

            foreach ($barcodes as $barcode) {
                ProductBarcode::query()->create([
                    'tenant_id' => $tenantId,
                    'product_id' => $product->id,
                    ...$barcode,
                ]);
            }

            return $product->fresh(CreateProductAction::with());
        });
    }
}
