<?php

namespace App\Parties;

use App\Models\Customer;
use App\Models\PartyProfile;
use App\Models\PartyProfileType;
use App\Models\Supplier;

class PartyProfileBridge
{
    public function ensureSupplier(Supplier $supplier): PartyProfile
    {
        return $this->ensure($supplier, 'vendor');
    }

    public function ensureCustomer(Customer $customer): PartyProfile
    {
        return $this->ensure($customer, 'customer');
    }

    private function ensure(Supplier|Customer $party, string $type): PartyProfile
    {
        $profile = $party->party_profile_id
            ? PartyProfile::query()->whereKey($party->party_profile_id)->with('types')->first()
            : null;

        if (! $profile) {
            $profile = PartyProfile::query()->create([
                'tenant_id' => $party->tenant_id,
                ...$this->profilePayload($party),
                'created_by' => $party->created_by,
                'updated_by' => $party->updated_by,
            ]);

            PartyProfileType::query()->create([
                'party_profile_id' => $profile->id,
                'type' => $type,
            ]);

            $party->party_profile_id = $profile->id;
            $party->saveQuietly();

            return $profile->fresh('types') ?? $profile;
        }

        if ($profile->types->count() === 1 && $profile->types->first()?->type === $type) {
            $profile->fill($this->profilePayload($party));
            $profile->updated_by = $party->updated_by;
            $profile->save();
        }

        if (! $profile->types->contains(fn (PartyProfileType $row): bool => $row->type === $type)) {
            PartyProfileType::query()->create([
                'party_profile_id' => $profile->id,
                'type' => $type,
            ]);
        }

        return $profile->fresh('types') ?? $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(Supplier|Customer $party): array
    {
        return [
            'code' => $party->code,
            'name' => $party->name,
            'deals_in' => $party->deals_in,
            'contact_person' => $party->contact_person,
            'mobile' => $party->mobile,
            'mobile_secondary' => $party->mobile_secondary,
            'phone' => $party->phone,
            'phone_secondary' => $party->phone_secondary,
            'email' => $party->email,
            'address' => $party->address,
            'billing_address' => $party->billing_address,
            'area' => $party->area,
            'invoice_restricted' => (bool) $party->invoice_restricted,
            'credit_limit_amount' => $party->credit_limit_amount,
            'credit_limit_days' => $party->credit_limit_days,
            'add_percent' => $party->add_percent,
            'cnic' => $party->cnic,
            'ntn' => $party->ntn,
            'stn' => $party->stn,
            'formulas' => $party->formulas,
            'license_number' => $party->license_number,
            'license_issued_on' => $party->license_issued_on,
            'license_type' => $party->license_type,
            'license_expires_on' => $party->license_expires_on,
            'ignore_warranty' => (bool) $party->ignore_warranty,
            'print_license' => (bool) $party->print_license,
            'rf_id' => $party->rf_id,
            'store_allowed' => $party->store_allowed,
            'image_path' => $party->image_path,
            'is_active' => (bool) $party->is_active,
        ];
    }
}
