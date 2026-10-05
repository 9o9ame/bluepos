<?php

namespace App\Http\Requests\Parties;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertPartyProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $types = array_values(array_unique(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            is_array($this->input('party_types')) ? $this->input('party_types') : []
        )));

        $this->merge([
            'party_types' => $types,
            'primary_type' => strtolower(trim((string) $this->input('primary_type', $types[0] ?? ''))),
            'code' => strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name')),
        ]);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $types = $this->input('party_types', []);

        return [
            'party_types' => ['required', 'array', 'min:1', 'max:3'],
            'party_types.*' => ['required', 'string', Rule::in(['vendor', 'customer', 'salesman'])],
            'primary_type' => [
                'required',
                'string',
                Rule::in(['vendor', 'customer', 'salesman']),
                function (string $attribute, mixed $value, \Closure $fail) use ($types): void {
                    if (! in_array($value, is_array($types) ? $types : [], true)) {
                        $fail('Primary type must be one of the selected party types.');
                    }
                },
            ],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:180'],
            'vendor_account_type_ulid' => [
                Rule::requiredIf(fn (): bool => in_array('vendor', is_array($types) ? $types : [], true)),
                'nullable',
                'string',
                'size:26',
                Rule::exists('account_types', 'ulid')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
            'customer_account_type_ulid' => [
                Rule::requiredIf(fn (): bool => in_array('customer', is_array($types) ? $types : [], true)),
                'nullable',
                'string',
                'size:26',
                Rule::exists('account_types', 'ulid')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
            'deals_in' => ['nullable', 'string', 'max:180'],
            'contact_person' => ['nullable', 'string', 'max:180'],
            'mobile' => ['nullable', 'string', 'max:64'],
            'mobile_secondary' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:64'],
            'phone_secondary' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:180'],
            'address' => ['nullable', 'string'],
            'billing_address' => ['nullable', 'string'],
            'area' => ['nullable', 'string', 'max:120'],
            'invoice_restricted' => ['sometimes', 'boolean'],
            'credit_limit_amount' => ['sometimes', 'regex:/^\d+(\.\d{1,4})?$/'],
            'credit_limit_days' => ['sometimes', 'integer', 'min:0', 'max:99999'],
            'add_percent' => ['sometimes', 'regex:/^\d+(\.\d{1,8})?$/'],
            'cnic' => ['nullable', 'string', 'max:32'],
            'ntn' => ['nullable', 'string', 'max:64'],
            'stn' => ['nullable', 'string', 'max:64'],
            'formulas' => ['nullable', 'string', 'max:20000'],
            'license_number' => ['nullable', 'string', 'max:120'],
            'license_issued_on' => ['nullable', 'date'],
            'license_type' => ['nullable', 'string', 'max:8'],
            'license_expires_on' => ['nullable', 'date', 'after_or_equal:license_issued_on'],
            'ignore_warranty' => ['sometimes', 'boolean'],
            'print_license' => ['sometimes', 'boolean'],
            'rf_id' => ['nullable', 'string', 'max:120'],
            'store_allowed' => ['nullable', 'string', Rule::in(['ALL', 'BRANCH'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function profileAttributes(): array
    {
        return $this->safe()->except([
            'party_types',
            'primary_type',
            'vendor_account_type_ulid',
            'customer_account_type_ulid',
        ]);
    }
}
