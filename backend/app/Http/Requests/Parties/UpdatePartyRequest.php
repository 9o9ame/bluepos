<?php

namespace App\Http\Requests\Parties;

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
        $table = $partyType === 'customer' ? 'customers' : 'suppliers';
        $model = $partyType === 'customer' ? Customer::class : Supplier::class;

        return [
            'party_type' => ['required', 'string', Rule::in(['vendor', 'customer'])],
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
        return $this->safe()->except(['party_type']);
    }
}
