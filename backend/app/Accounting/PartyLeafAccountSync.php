<?php

namespace App\Accounting;

use App\Models\Account;
use App\Models\Customer;
use App\Models\PartyProfileType;
use App\Models\Supplier;

class PartyLeafAccountSync
{
    public function syncSupplier(Supplier $supplier): Account
    {
        if (! $supplier->account_type_id) {
            throw new \InvalidArgumentException('Supplier requires account_type_id for leaf account sync.');
        }

        $account = Account::query()
            ->forTenant((int) $supplier->tenant_id)
            ->where('supplier_id', $supplier->id)
            ->first();

        $payload = [
            'tenant_id' => $supplier->tenant_id,
            'code' => $this->leafCode($supplier->code, $supplier->party_profile_id, 'vendor'),
            'name' => $supplier->name,
            'address' => $supplier->address,
            'account_type_id' => $supplier->account_type_id,
            'is_active' => (bool) $supplier->is_active,
            'supplier_id' => $supplier->id,
            'customer_id' => null,
            'updated_by' => $supplier->updated_by,
        ];

        if ($account) {
            $account->fill($payload);
            $account->save();

            return $account;
        }

        $payload['created_by'] = $supplier->created_by ?? $supplier->updated_by;

        return Account::query()->create($payload);
    }

    public function syncCustomer(Customer $customer): Account
    {
        if (! $customer->account_type_id) {
            throw new \InvalidArgumentException('Customer requires account_type_id for leaf account sync.');
        }

        $account = Account::query()
            ->forTenant((int) $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->first();

        $payload = [
            'tenant_id' => $customer->tenant_id,
            'code' => $this->leafCode($customer->code, $customer->party_profile_id, 'customer'),
            'name' => $customer->name,
            'address' => $customer->address,
            'account_type_id' => $customer->account_type_id,
            'is_active' => (bool) $customer->is_active,
            'customer_id' => $customer->id,
            'supplier_id' => null,
            'updated_by' => $customer->updated_by,
        ];

        if ($account) {
            $account->fill($payload);
            $account->save();

            return $account;
        }

        $payload['created_by'] = $customer->created_by ?? $customer->updated_by;

        return Account::query()->create($payload);
    }

    private function leafCode(string $code, ?int $profileId, string $type): string
    {
        if (! $profileId) {
            return $code;
        }

        $types = PartyProfileType::query()
            ->where('party_profile_id', $profileId)
            ->whereIn('type', ['vendor', 'customer'])
            ->pluck('type');

        if ($types->contains('vendor') && $types->contains('customer')) {
            return $code.($type === 'vendor' ? '-V' : '-C');
        }

        return $code;
    }
}
