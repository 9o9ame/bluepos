<?php

namespace App\Catalog;

use App\Exceptions\ApiException;
use App\Models\BarcodeGroup;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\Unit;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

class TenantCatalog
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function category(string $ulid): Category
    {
        return $this->find(Category::class, $ulid);
    }

    public function subcategory(string $ulid): Subcategory
    {
        return $this->find(Subcategory::class, $ulid);
    }

    public function brand(string $ulid): Brand
    {
        return $this->find(Brand::class, $ulid);
    }

    public function barcodeGroup(string $ulid): BarcodeGroup
    {
        return $this->find(BarcodeGroup::class, $ulid);
    }

    public function unit(string $ulid): Unit
    {
        return $this->find(Unit::class, $ulid);
    }

    public function product(string $ulid): Product
    {
        return $this->find(Product::class, $ulid);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel
     */
    private function find(string $class, string $ulid): Model
    {
        $model = $class::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('ulid', $ulid)
            ->first();

        if (! $model) {
            throw new ApiException('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        return $model;
    }
}
