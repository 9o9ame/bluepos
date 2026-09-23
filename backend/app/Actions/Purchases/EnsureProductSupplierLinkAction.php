<?php

namespace App\Actions\Purchases;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Models\Supplier;
use App\Tenancy\TenantContext;

class EnsureProductSupplierLinkAction
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function execute(Product $product, Supplier $supplier, ?string $supplierProductCode = null): ProductSupplier
    {
        $row = ProductSupplier::query()->firstOrNew([
            'product_id' => $product->id,
            'supplier_id' => $supplier->id,
        ]);

        if (! $row->exists) {
            $row->tenant_id = $this->tenantContext->tenantId();
            $row->is_primary = false;
            $row->created_by = $this->tenantContext->userId();
        }

        $row->is_active = true;
        if (is_string($supplierProductCode) && trim($supplierProductCode) !== '') {
            $row->supplier_product_code = trim($supplierProductCode);
        }
        $row->updated_by = $this->tenantContext->userId();
        $row->save();

        return $row;
    }
}
