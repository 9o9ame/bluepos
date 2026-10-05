<?php

namespace App\Actions\Parties;

use App\Accounting\PartyLeafAccountSync;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Customer;
use App\Models\PartyProfile;
use App\Models\PartyProfileType;
use App\Models\Supplier;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertPartyProfileAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PartyLeafAccountSync $leafSync,
    ) {}

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $types
     */
    public function execute(
        ?PartyProfile $profile,
        array $attributes,
        array $types,
        ?string $vendorAccountTypeUlid,
        ?string $customerAccountTypeUlid,
    ): PartyProfile {
        $types = array_values(array_unique($types));
        sort($types);

        return DB::transaction(function () use (
            $profile,
            $attributes,
            $types,
            $vendorAccountTypeUlid,
            $customerAccountTypeUlid,
        ): PartyProfile {
            $tenantId = $this->tenantContext->tenantId();
            $isNew = $profile === null;
            $originalCode = $profile?->code;

            if ($profile && (int) $profile->tenant_id !== $tenantId) {
                throw ValidationException::withMessages([
                    'party' => 'The requested party was not found.',
                ]);
            }

            $profile ??= new PartyProfile([
                'tenant_id' => $tenantId,
                'created_by' => $this->tenantContext->userId(),
            ]);

            $profile->fill($attributes);
            $profile->tenant_id = $tenantId;
            $profile->updated_by = $this->tenantContext->userId();
            if ($isNew && ! $profile->created_by) {
                $profile->created_by = $this->tenantContext->userId();
            }

            $this->assertProfileCodeAvailable($profile, $originalCode);
            $profile->save();

            $supplier = Supplier::query()
                ->forTenant($tenantId)
                ->where('party_profile_id', $profile->id)
                ->first();
            $customer = Customer::query()
                ->forTenant($tenantId)
                ->where('party_profile_id', $profile->id)
                ->first();

            $vendorAccountTypeId = in_array('vendor', $types, true)
                ? $this->resolveAccountTypeId($vendorAccountTypeUlid, $supplier?->account_type_id, 'vendor_account_type_ulid')
                : null;
            $customerAccountTypeId = in_array('customer', $types, true)
                ? $this->resolveAccountTypeId($customerAccountTypeUlid, $customer?->account_type_id, 'customer_account_type_ulid')
                : null;

            $this->assertBackingCodesAvailable($profile, $types, $supplier, $customer);

            PartyProfileType::query()->where('party_profile_id', $profile->id)->delete();
            foreach ($types as $type) {
                PartyProfileType::query()->create([
                    'party_profile_id' => $profile->id,
                    'type' => $type,
                ]);
            }

            if (! in_array('vendor', $types, true) && $supplier) {
                $this->deactivateRemovedBacking($supplier, 'vendor', $profile, in_array('customer', $types, true));
            }

            if (! in_array('customer', $types, true) && $customer) {
                $this->deactivateRemovedBacking($customer, 'customer', $profile, in_array('vendor', $types, true));
            }

            if (in_array('vendor', $types, true)) {
                $supplier = $this->upsertSupplier($profile, $supplier, (int) $vendorAccountTypeId);
                $this->leafSync->syncSupplier($supplier);
            }

            if (in_array('customer', $types, true)) {
                $customer = $this->upsertCustomer($profile, $customer, (int) $customerAccountTypeId);
                $this->leafSync->syncCustomer($customer);
            }

            return $profile->fresh(['types', 'supplier.accountType', 'customer.accountType']) ?? $profile;
        });
    }

    private function resolveAccountTypeId(?string $ulid, ?int $existingId, string $field): int
    {
        if ((! is_string($ulid) || trim($ulid) === '') && $existingId) {
            return $existingId;
        }

        $id = AccountType::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('ulid', trim((string) $ulid))
            ->where('is_active', true)
            ->value('id');

        if (! $id) {
            throw ValidationException::withMessages([
                $field => 'Select an active account type for this party role.',
            ]);
        }

        return (int) $id;
    }

    private function assertProfileCodeAvailable(PartyProfile $profile, ?string $originalCode): void
    {
        if ($profile->exists && $originalCode === $profile->code) {
            return;
        }

        $exists = PartyProfile::query()
            ->forTenant($this->tenantContext->tenantId())
            ->where('code', $profile->code)
            ->when($profile->exists, fn ($q) => $q->where('id', '<>', $profile->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => 'This code is already used by another party identity.',
            ]);
        }
    }

    /**
     * @param list<string> $types
     */
    private function assertBackingCodesAvailable(
        PartyProfile $profile,
        array $types,
        ?Supplier $supplier,
        ?Customer $customer,
    ): void {
        $tenantId = $this->tenantContext->tenantId();
        $code = (string) $profile->code;

        if (in_array('vendor', $types, true)) {
            $exists = Supplier::query()
                ->forTenant($tenantId)
                ->where('code', $code)
                ->when($supplier, fn ($q) => $q->where('id', '<>', $supplier->id))
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages(['code' => 'This code is already used by another vendor.']);
            }
        }

        if (in_array('customer', $types, true)) {
            $exists = Customer::query()
                ->forTenant($tenantId)
                ->where('code', $code)
                ->when($customer, fn ($q) => $q->where('id', '<>', $customer->id))
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages(['code' => 'This code is already used by another customer.']);
            }
        }

        $leafIds = collect();
        if ($supplier || $customer) {
            $leafIds = Account::query()
                ->forTenant($tenantId)
                ->where(function ($q) use ($supplier, $customer): void {
                    if ($supplier) {
                        $q->orWhere('supplier_id', $supplier->id);
                    }
                    if ($customer) {
                        $q->orWhere('customer_id', $customer->id);
                    }
                })
                ->pluck('id');
        }

        $both = in_array('vendor', $types, true) && in_array('customer', $types, true);
        if ($both && mb_strlen($code) > 62) {
            throw ValidationException::withMessages([
                'code' => 'Vendor + Customer party codes may be at most 62 characters.',
            ]);
        }
        $codes = [];
        if (in_array('vendor', $types, true)) {
            $codes[] = $both ? $code.'-V' : $code;
        }
        if (in_array('customer', $types, true)) {
            $codes[] = $both ? $code.'-C' : $code;
        }

        foreach (array_unique($codes) as $leafCode) {
            $conflict = Account::query()
                ->forTenant($tenantId)
                ->where('code', $leafCode)
                ->when($leafIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $leafIds))
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'code' => 'A ledger account already uses '.$leafCode.'. Choose another party code.',
                ]);
            }
        }
    }

    private function upsertSupplier(PartyProfile $profile, ?Supplier $supplier, int $accountTypeId): Supplier
    {
        $payload = $this->commonBackingPayload($profile);
        $payload['account_type_id'] = $accountTypeId;
        $payload['party_profile_id'] = $profile->id;

        if ($supplier) {
            $supplier->fill($payload);
            $supplier->updated_by = $this->tenantContext->userId();
            $supplier->save();
            return $supplier;
        }

        return Supplier::query()->create([
            'tenant_id' => $profile->tenant_id,
            ...$payload,
            'created_by' => $this->tenantContext->userId(),
            'updated_by' => $this->tenantContext->userId(),
        ]);
    }

    private function upsertCustomer(PartyProfile $profile, ?Customer $customer, int $accountTypeId): Customer
    {
        $payload = $this->commonBackingPayload($profile);
        $payload['account_type_id'] = $accountTypeId;
        $payload['party_profile_id'] = $profile->id;

        if ($customer) {
            $customer->fill($payload);
            $customer->updated_by = $this->tenantContext->userId();
            $customer->save();
            return $customer;
        }

        return Customer::query()->create([
            'tenant_id' => $profile->tenant_id,
            ...$payload,
            'created_by' => $this->tenantContext->userId(),
            'updated_by' => $this->tenantContext->userId(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function commonBackingPayload(PartyProfile $profile): array
    {
        return [
            'code' => $profile->code,
            'name' => $profile->name,
            'deals_in' => $profile->deals_in,
            'contact_person' => $profile->contact_person,
            'mobile' => $profile->mobile,
            'mobile_secondary' => $profile->mobile_secondary,
            'phone' => $profile->phone,
            'phone_secondary' => $profile->phone_secondary,
            'email' => $profile->email,
            'address' => $profile->address,
            'billing_address' => $profile->billing_address,
            'area' => $profile->area,
            'invoice_restricted' => (bool) $profile->invoice_restricted,
            'credit_limit_amount' => $profile->credit_limit_amount,
            'credit_limit_days' => $profile->credit_limit_days,
            'add_percent' => $profile->add_percent,
            'cnic' => $profile->cnic,
            'ntn' => $profile->ntn,
            'stn' => $profile->stn,
            'formulas' => $profile->formulas,
            'license_number' => $profile->license_number,
            'license_issued_on' => $profile->license_issued_on,
            'license_type' => $profile->license_type,
            'license_expires_on' => $profile->license_expires_on,
            'ignore_warranty' => (bool) $profile->ignore_warranty,
            'print_license' => (bool) $profile->print_license,
            'rf_id' => $profile->rf_id,
            'store_allowed' => $profile->store_allowed,
            'image_path' => $profile->image_path,
            'is_active' => (bool) $profile->is_active,
        ];
    }

    private function deactivateRemovedBacking(
        Supplier|Customer $backing,
        string $type,
        PartyProfile $profile,
        bool $oppositeTypeActive,
    ): void {
        $backing->is_active = false;
        $backing->updated_by = $this->tenantContext->userId();
        $backing->save();

        $account = Account::query()
            ->forTenant($this->tenantContext->tenantId())
            ->when($type === 'vendor', fn ($q) => $q->where('supplier_id', $backing->id))
            ->when($type === 'customer', fn ($q) => $q->where('customer_id', $backing->id))
            ->first();

        if (! $account) {
            return;
        }

        $account->is_active = false;
        if ($oppositeTypeActive && $account->code === $profile->code) {
            $historicalCode = $profile->code.($type === 'vendor' ? '-V' : '-C');
            $conflict = Account::query()
                ->forTenant($this->tenantContext->tenantId())
                ->where('code', $historicalCode)
                ->where('id', '<>', $account->id)
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'code' => 'A ledger account already uses '.$historicalCode.'. Choose another party code before changing types.',
                ]);
            }

            $account->code = $historicalCode;
        }
        $account->updated_by = $this->tenantContext->userId();
        $account->save();
    }
}
