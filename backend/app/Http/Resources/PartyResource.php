<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unified party payload for /definition/parties.
 *
 * @mixin \App\Models\Supplier|\App\Models\Customer|\App\Models\Account
 */
class PartyResource extends JsonResource
{
    public function __construct($resource, private readonly string $partyType)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $accountType = $this->relationLoaded('accountType') ? $this->accountType : $this->accountType()->first();
        $isAccount = $this->partyType === 'account';

        return [
            'ulid' => $this->ulid,
            'party_type' => $this->partyType,
            'code' => $this->code,
            'name' => $this->name,
            'deals_in' => $isAccount ? null : $this->deals_in,
            'contact_person' => $isAccount ? null : $this->contact_person,
            'mobile' => $isAccount ? null : $this->mobile,
            'mobile_secondary' => $isAccount ? null : $this->mobile_secondary,
            'phone' => $isAccount ? null : $this->phone,
            'phone_secondary' => $isAccount ? null : $this->phone_secondary,
            'email' => $isAccount ? null : $this->email,
            'address' => $this->address,
            'billing_address' => $isAccount ? null : $this->billing_address,
            'is_active' => (bool) $this->is_active,
            'area' => $this->area,
            'invoice_restricted' => (bool) $this->invoice_restricted,
            'credit_limit_amount' => bcadd((string) ($this->credit_limit_amount ?? '0'), '0', 4),
            'credit_limit_days' => (int) ($this->credit_limit_days ?? 0),
            'account_type_ulid' => $accountType?->ulid,
            'account_type' => $accountType ? [
                'ulid' => $accountType->ulid,
                'name' => $accountType->name,
            ] : null,
            'license_number' => $isAccount ? null : $this->license_number,
            'license_issued_on' => $isAccount ? null : optional($this->license_issued_on)?->format('Y-m-d'),
            'license_type' => $isAccount ? null : $this->license_type,
            'license_expires_on' => $isAccount ? null : optional($this->license_expires_on)?->format('Y-m-d'),
            'ignore_warranty' => $isAccount ? false : (bool) $this->ignore_warranty,
            'print_license' => $isAccount ? false : (bool) $this->print_license,
            'rf_id' => $isAccount ? null : $this->rf_id,
            'store_allowed' => $isAccount ? null : $this->store_allowed,
            'image_url' => $this->image_path
                ? '/api/parties/'.$this->ulid.'/image?type='.$this->partyType.'&v='.($this->updated_at?->getTimestamp() ?? 0)
                : null,
        ];
    }
}
