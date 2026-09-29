<?php

namespace App\Http\Resources;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unified party payload for /definition/parties.
 *
 * @mixin Supplier|Customer|Account
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

        return [
            'ulid' => $this->ulid,
            'party_type' => $this->partyType,
            'code' => $this->code,
            'name' => $this->name,
            'deals_in' => $this->partyType === 'account' ? null : $this->deals_in,
            'contact_person' => $this->partyType === 'account' ? null : $this->contact_person,
            'mobile' => $this->partyType === 'account' ? null : $this->mobile,
            'mobile_secondary' => $this->partyType === 'account' ? null : $this->mobile_secondary,
            'phone' => $this->partyType === 'account' ? null : $this->phone,
            'phone_secondary' => $this->partyType === 'account' ? null : $this->phone_secondary,
            'email' => $this->partyType === 'account' ? null : $this->email,
            'address' => $this->address,
            'billing_address' => $this->partyType === 'account' ? null : $this->billing_address,
            'is_active' => (bool) $this->is_active,
            'account_type_ulid' => $accountType?->ulid,
            'account_type' => $accountType ? [
                'ulid' => $accountType->ulid,
                'name' => $accountType->name,
            ] : null,
        ];
    }
}
