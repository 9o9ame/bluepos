<?php

namespace App\Http\Requests\Parties;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Supplier;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'party_type' => strtolower(trim((string) $this->input('party_type', $this->query('type', '')))),
        ];

        if ($this->exists('code')) {
            $merge['code'] = strtoupper(trim((string) $this->input('code')));
        }
        if ($this->exists('name')) {
            $merge['name'] = trim((string) $this->input('name'));
        }
        if ($this->exists('account_type_ulid')) {
            $merge['account_type_ulid'] = $this->filled('account_type_ulid')
                ? trim((string) $this->input('account_type_ulid'))
                : null;
        }
        foreach ([
            'deals_in',
            'contact_person',
            'mobile',
            'mobile_secondary',
            'phone',
            'phone_secondary',
            'address',
            'billing_address',
            'area',
            'license_number',
            'license_issued_on',
            'license_expires_on',
            'rf_id',
        ] as $field) {
            if ($this->exists($field)) {
                $merge[$field] = $this->filled($field) ? trim((string) $this->input($field)) : null;
            }
        }
        if ($this->exists('license_type')) {
            $merge['license_type'] = $this->filled('license_type')
                ? strtoupper(trim((string) $this->input('license_type')))
                : null;
        }
        if ($this->exists('store_allowed')) {
            $merge['store_allowed'] = $this->filled('store_allowed')
                ? strtoupper(trim((string) $this->input('store_allowed')))
                : null;
        }
        if ($this->exists('email')) {
            $merge['email'] = $this->filled('email')
                ? strtolower(trim((string) $this->input('email')))
                : null;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $partyType = (string) $this->input('party_type');
        $ulid = (string) $this->route('partyUlid');
        [$table, $model] = match ($partyType) {
            'customer' => ['customers', Customer::class],
            'account' => ['accounts', Account::class],
            default => ['suppliers', Supplier::class],
        };

        $rules = [
            'party_type' => ['required', 'string', Rule::in(['vendor', 'customer', 'account'])],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique($table, 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($model::query()->forTenant($tenantId)->where('ulid', $ulid)->value('id')),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'account_type_ulid' => [
                'sometimes',
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
     * @return array<string, mixed>
     */
    public function partyAttributes(): array
    {
        return $this->safe()->except(['party_type', 'account_type_ulid']);
    }
}
