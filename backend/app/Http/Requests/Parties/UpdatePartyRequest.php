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
        ] as $field) {
            if ($this->exists($field)) {
                $merge[$field] = $this->filled($field) ? trim((string) $this->input($field)) : null;
            }
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
