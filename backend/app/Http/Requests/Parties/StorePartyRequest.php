<?php

namespace App\Http\Requests\Parties;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'party_type' => strtolower(trim((string) $this->input('party_type'))),
            'code' => strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name')),
            'deals_in' => $this->filled('deals_in') ? trim((string) $this->input('deals_in')) : null,
            'contact_person' => $this->filled('contact_person')
                ? trim((string) $this->input('contact_person'))
                : null,
            'mobile' => $this->filled('mobile') ? trim((string) $this->input('mobile')) : null,
            'mobile_secondary' => $this->filled('mobile_secondary')
                ? trim((string) $this->input('mobile_secondary'))
                : null,
            'phone' => $this->filled('phone') ? trim((string) $this->input('phone')) : null,
            'phone_secondary' => $this->filled('phone_secondary')
                ? trim((string) $this->input('phone_secondary'))
                : null,
            'email' => $this->filled('email')
                ? strtolower(trim((string) $this->input('email')))
                : null,
            'address' => $this->filled('address') ? trim((string) $this->input('address')) : null,
            'billing_address' => $this->filled('billing_address')
                ? trim((string) $this->input('billing_address'))
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $partyType = (string) $this->input('party_type');
        $table = $partyType === 'customer' ? 'customers' : 'suppliers';

        return [
            'party_type' => ['required', 'string', Rule::in(['vendor', 'customer'])],
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique($table, 'code')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:180'],
            'deals_in' => ['nullable', 'string', 'max:180'],
            'contact_person' => ['nullable', 'string', 'max:180'],
            'mobile' => ['nullable', 'string', 'max:64'],
            'mobile_secondary' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:64'],
            'phone_secondary' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string'],
            'billing_address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function partyAttributes(): array
    {
        $data = $this->safe()->except(['party_type']);

        return $data;
    }
}
