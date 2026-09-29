<?php

namespace App\Accounting;

use App\Catalog\TenantCatalog;
use App\Exceptions\ApiException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;

class PartyLeafAccountResolver
{
    public function __construct(private readonly TenantCatalog $catalog) {}

    /**
     * @return array{0: Supplier|Customer|Account, 1: string, 2: Account}
     */
    public function resolve(string $partyType, string $partyUlid): array
    {
        return match ($partyType) {
            'vendor' => $this->fromSupplier($this->catalog->supplier($partyUlid)),
            'customer' => $this->fromCustomer($this->catalog->customer($partyUlid)),
            'account' => $this->fromManual($this->catalog->account($partyUlid)),
            default => throw new ApiException('VALIDATION_FAILED', 'Invalid party type.', 422),
        };
    }

    /**
     * @return array{0: Supplier, 1: string, 2: Account}
     */
    private function fromSupplier(Supplier $supplier): array
    {
        $leaf = Account::query()
            ->forTenant((int) $supplier->tenant_id)
            ->where('supplier_id', $supplier->id)
            ->first();

        if (! $leaf) {
            $leaf = app(PartyLeafAccountSync::class)->syncSupplier($supplier);
        }

        return [$supplier, 'vendor', $leaf];
    }

    /**
     * @return array{0: Customer, 1: string, 2: Account}
     */
    private function fromCustomer(Customer $customer): array
    {
        $leaf = Account::query()
            ->forTenant((int) $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $leaf) {
            $leaf = app(PartyLeafAccountSync::class)->syncCustomer($customer);
        }

        return [$customer, 'customer', $leaf];
    }

    /**
     * @return array{0: Account, 1: string, 2: Account}
     */
    private function fromManual(Account $account): array
    {
        if ($account->supplier_id || $account->customer_id) {
            throw new ApiException('VALIDATION_FAILED', 'Use vendor/customer party type for linked accounts.', 422);
        }

        return [$account, 'account', $account];
    }
}
