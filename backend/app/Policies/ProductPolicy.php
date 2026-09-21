<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $this->permissions->can('products.view')
            && $this->sameTenant((int) $product->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('products.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->permissions->can('products.edit')
            && $this->sameTenant((int) $product->tenant_id);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->permissions->can('products.delete')
            && $this->sameTenant((int) $product->tenant_id);
    }

    public function managePrices(User $user, Product $product): bool
    {
        return $this->permissions->can('products.manage_prices')
            && $this->sameTenant((int) $product->tenant_id);
    }

    public function manageBarcodes(User $user, Product $product): bool
    {
        return $this->permissions->can('products.manage_barcodes')
            && $this->sameTenant((int) $product->tenant_id);
    }
}
