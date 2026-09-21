<?php

namespace App\Actions\Products;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class DeactivateProductAction
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function execute(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $product = Product::query()
                ->forTenant($this->tenantContext->tenantId())
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $product->status = ProductStatus::Discontinued;
            $product->is_active = false;
            $product->updated_by = $this->tenantContext->userId();
            $product->save();

            return $product->fresh(CreateProductAction::with());
        });
    }
}
