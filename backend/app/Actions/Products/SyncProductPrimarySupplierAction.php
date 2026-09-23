<?php

namespace App\Actions\Products;

use App\Catalog\TenantCatalog;
use App\Models\Product;
use App\Models\ProductSupplier;
use App\Tenancy\TenantContext;

class SyncProductPrimarySupplierAction
{
    public function __construct(
        private readonly TenantCatalog $catalog,
        private readonly TenantContext $tenantContext,
    ) {}

    public function execute(Product $product, ?string $primarySupplierUlid, ?string $supplierProductCode = null): void
    {
        $userId = $this->tenantContext->userId();

        ProductSupplier::query()
            ->where('product_id', $product->id)
            ->where('is_primary', true)
            ->update([
                'is_primary' => false,
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);

        if ($primarySupplierUlid === null) {
            return;
        }

        $supplier = $this->catalog->supplier($primarySupplierUlid);

        $row = ProductSupplier::query()->firstOrNew([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
        ]);

        if (! $row->exists) {
            $row->tenant_id = (int) $product->tenant_id;
            $row->created_by = $userId;
        }

        $row->supplier_product_code = $supplierProductCode;
        $row->is_primary = true;
        $row->is_active = true;
        $row->updated_by = $userId;
        $row->save();
    }
}
