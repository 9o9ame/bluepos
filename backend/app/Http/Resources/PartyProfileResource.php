<?php

namespace App\Http\Resources;

use App\Models\PartyProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PartyProfile */
class PartyProfileResource extends JsonResource
{
    public function __construct($resource, private readonly string $primaryType)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $types = $this->types->pluck('type')->values()->all();
        $supplier = $this->supplier;
        $customer = $this->customer;
        $primary = $this->primaryType === 'vendor' ? $supplier : ($this->primaryType === 'customer' ? $customer : null);
        $accountType = $primary?->accountType;
        $publicUlid = $primary?->ulid ?? $this->ulid;
        $imageBacking = $primary ?? $customer ?? $supplier;

        return [
            'ulid' => $publicUlid,
            'identity_ulid' => $this->ulid,
            'party_type' => $this->primaryType,
            'party_types' => $types,
            'vendor_ulid' => $supplier?->ulid,
            'customer_ulid' => $customer?->ulid,
            'vendor_account_type_ulid' => $supplier?->accountType?->ulid,
            'customer_account_type_ulid' => $customer?->accountType?->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'deals_in' => $this->deals_in,
            'contact_person' => $this->contact_person,
            'mobile' => $this->mobile,
            'mobile_secondary' => $this->mobile_secondary,
            'phone' => $this->phone,
            'phone_secondary' => $this->phone_secondary,
            'email' => $this->email,
            'address' => $this->address,
            'billing_address' => $this->billing_address,
            'is_active' => (bool) $this->is_active,
            'area' => $this->area,
            'invoice_restricted' => (bool) $this->invoice_restricted,
            'credit_limit_amount' => bcadd((string) ($this->credit_limit_amount ?? '0'), '0', 4),
            'credit_limit_days' => (int) ($this->credit_limit_days ?? 0),
            'add_percent' => bcadd((string) ($this->add_percent ?? '0'), '0', 8),
            'cnic' => $this->cnic,
            'ntn' => $this->ntn,
            'stn' => $this->stn,
            'formulas' => $this->formulas,
            'account_type_ulid' => $accountType?->ulid,
            'account_type' => $accountType ? ['ulid' => $accountType->ulid, 'name' => $accountType->name] : null,
            'license_number' => $this->license_number,
            'license_issued_on' => optional($this->license_issued_on)?->format('Y-m-d'),
            'license_type' => $this->license_type,
            'license_expires_on' => optional($this->license_expires_on)?->format('Y-m-d'),
            'ignore_warranty' => (bool) $this->ignore_warranty,
            'print_license' => (bool) $this->print_license,
            'rf_id' => $this->rf_id,
            'store_allowed' => $this->store_allowed,
            'image_url' => $imageBacking && $this->image_path
                ? '/api/parties/'.$imageBacking->ulid.'/image?type='
                    .($imageBacking instanceof \App\Models\Customer ? 'customer' : 'vendor')
                    .'&v='.($this->updated_at?->getTimestamp() ?? 0)
                : null,
        ];
    }
}
