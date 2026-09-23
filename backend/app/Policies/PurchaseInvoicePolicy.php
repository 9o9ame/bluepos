<?php

namespace App\Policies;

use App\Models\PurchaseInvoice;
use App\Models\User;

class PurchaseInvoicePolicy extends CatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->permissions->can('purchases.view');
    }

    public function view(User $user, PurchaseInvoice $invoice): bool
    {
        return $this->permissions->can('purchases.view')
            && $this->sameTenant((int) $invoice->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->permissions->can('purchases.create');
    }

    public function update(User $user, PurchaseInvoice $invoice): bool
    {
        return $this->permissions->can('purchases.edit')
            && $this->sameTenant((int) $invoice->tenant_id);
    }

    public function post(User $user, PurchaseInvoice $invoice): bool
    {
        return $this->permissions->can('purchases.post')
            && $this->sameTenant((int) $invoice->tenant_id);
    }
}
