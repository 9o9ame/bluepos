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
            'area' => $this->filled('area') ? trim((string) $this->input('area')) : null,
            'billing_address' => $this->filled('billing_address')
                ? trim((string) $this->input('billing_address'))
                : null,
            'license_number' => $this->filled('license_number')
                ? trim((string) $this->input('license_number'))
                : null,
            'license_issued_on' => $this->filled('license_issued_on')
                ? trim((string) $this->input('license_issued_on'))
                : null,
            'license_type' => $this->filled('license_type')
                ? strtoupper(trim((string) $this->input('license_type')))
                : null,
            'license_expires_on' => $this->filled('license_expires_on')
                ? trim((string) $this->input('license_expires_on'))
                : null,
            'rf_id' => $this->filled('rf_id') ? trim((string) $this->input('rf_id')) : null,
            'store_allowed' => $this->filled('store_allowed')
                ? strtoupper(trim((string) $this->input('store_allowed')))
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
            'area' => ['nullable', 'string', 'max:120'],
            'invoice_restricted' => ['sometimes', 'boolean'],
            'credit_limit_amount' => ['sometimes', 'regex:/^\d+(\.\d{1,4})?$/'],
            'credit_limit_days' => ['sometimes', 'integer', 'min:0', 'max:99999'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        // Vendor/customer leaf accounts reuse party code in `accounts` — block collisions early.
        if ($partyType === 'vendor' || $partyType === 'customer') {
            $rules['code'][] = Rule::unique('accounts', 'code')->where('tenant_id', $tenantId);
        }

        if ($partyType !== 'account') {
            $rules['deals_in'] = ['nullable', 'string', 'max:180'];
            $rules['contact_person'] = ['nullable', 'string', 'max:180'];
            $rules['mobile'] = ['nullable', 'string', 'max:64'];
            $rules['mobile_secondary'] = ['nullable', 'string', 'max:64'];
            $rules['phone'] = ['nullable', 'string', 'max:64'];
            $rules['phone_secondary'] = ['nullable', 'string', 'max:64'];
            $rules['email'] = ['nullable', 'email', 'max:180'];
            $rules['billing_address'] = ['nullable', 'string'];
            $rules['license_number'] = ['nullable', 'string', 'max:120'];
            $rules['license_issued_on'] = ['nullable', 'date'];
            $rules['license_type'] = ['nullable', 'string', 'max:8'];
            $rules['license_expires_on'] = ['nullable', 'date', 'after_or_equal:license_issued_on'];
            $rules['ignore_warranty'] = ['sometimes', 'boolean'];
            $rules['print_license'] = ['sometimes', 'boolean'];
            $rules['rf_id'] = ['nullable', 'string', 'max:120'];
            $rules['store_allowed'] = ['nullable', 'string', Rule::in(['ALL', 'BRANCH'])];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'This code is already used by another party or ledger account.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function partyAttributes(): array
    {
        return $this->safe()->except(['party_type', 'account_type_ulid']);
    }
}
