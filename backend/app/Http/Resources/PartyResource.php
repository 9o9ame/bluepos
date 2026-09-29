<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unified party payload for /definition/parties.
 *
 * @mixin Supplier|Customer
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
        return [
            'ulid' => $this->ulid,
            'party_type' => $this->partyType,
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
        ];
    }
}
