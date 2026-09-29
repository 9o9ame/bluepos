<?php

namespace App\Http\Requests\Catalog;

use App\Models\Supplier;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->exists('code')) {
            $merge['code'] = strtoupper(trim((string) $this->input('code')));
        }
        if ($this->exists('name')) {
            $merge['name'] = trim((string) $this->input('name'));
        }
        if ($this->exists('contact_person')) {
            $merge['contact_person'] = $this->filled('contact_person')
                ? trim((string) $this->input('contact_person'))
                : null;
        }
        if ($this->exists('phone')) {
            $merge['phone'] = $this->filled('phone') ? trim((string) $this->input('phone')) : null;
        }
        if ($this->exists('phone_secondary')) {
            $merge['phone_secondary'] = $this->filled('phone_secondary')
                ? trim((string) $this->input('phone_secondary'))
                : null;
        }
        if ($this->exists('mobile')) {
            $merge['mobile'] = $this->filled('mobile') ? trim((string) $this->input('mobile')) : null;
        }
        if ($this->exists('mobile_secondary')) {
            $merge['mobile_secondary'] = $this->filled('mobile_secondary')
                ? trim((string) $this->input('mobile_secondary'))
                : null;
        }
        if ($this->exists('email')) {
            $merge['email'] = $this->filled('email')
                ? strtolower(trim((string) $this->input('email')))
                : null;
        }
        if ($this->exists('tax_number')) {
            $merge['tax_number'] = $this->filled('tax_number')
                ? trim((string) $this->input('tax_number'))
                : null;
        }
        if ($this->exists('deals_in')) {
            $merge['deals_in'] = $this->filled('deals_in') ? trim((string) $this->input('deals_in')) : null;
        }
        if ($this->exists('billing_address')) {
            $merge['billing_address'] = $this->filled('billing_address')
                ? trim((string) $this->input('billing_address'))
                : null;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $ulid = (string) $this->route('supplierUlid');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('suppliers', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore(Supplier::query()->forTenant($tenantId)->where('ulid', $ulid)->value('id')),
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
            'tax_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
