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
            'account_type_ulid' => $this->filled('account_type_ulid')
                ? trim((string) $this->input('account_type_ulid'))
                : null,
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
        $table = match ($partyType) {
            'customer' => 'customers',
            'account' => 'accounts',
            default => 'suppliers',
        };

        $rules = [
            'party_type' => ['required', 'string', Rule::in(['vendor', 'customer', 'account'])],
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique($table, 'code')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:180'],
            'account_type_ulid' => [
                'required',
                'string',
                'size:26',
                Rule::exists('account_types', 'ulid')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
            'address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($partyType !== 'account') {
            $rules['deals_in'] = ['nullable', 'string', 'max:180'];
            $rules['contact_person'] = ['nullable', 'string', 'max:180'];
            $rules['mobile'] = ['nullable', 'string', 'max:64'];
            $rules['mobile_secondary'] = ['nullable', 'string', 'max:64'];
            $rules['phone'] = ['nullable', 'string', 'max:64'];
            $rules['phone_secondary'] = ['nullable', 'string', 'max:64'];
            $rules['email'] = ['nullable', 'email', 'max:180'];
            $rules['billing_address'] = ['nullable', 'string'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function partyAttributes(): array
    {
        return $this->safe()->except(['party_type', 'account_type_ulid']);
    }
}
