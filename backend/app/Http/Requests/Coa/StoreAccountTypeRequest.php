<?php

namespace App\Http\Requests\Coa;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'code' => strtoupper(trim((string) $this->input('code'))),
            'sub_head_ulid' => trim((string) $this->input('sub_head_ulid')),
            'pnl_grouping_label' => $this->filled('pnl_grouping_label')
                ? trim((string) $this->input('pnl_grouping_label'))
                : null,
            'hint' => $this->filled('hint') ? trim((string) $this->input('hint')) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'sub_head_ulid' => [
                'required',
                'string',
                'size:26',
                Rule::exists('account_sub_heads', 'ulid')->where('tenant_id', $tenantId),
            ],
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('account_types', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:180'],
            'is_cash' => ['sometimes', 'boolean'],
            'is_bank' => ['sometimes', 'boolean'],
            'is_receivable' => ['sometimes', 'boolean'],
            'is_payable' => ['sometimes', 'boolean'],
            'pnl_grouping_label' => ['nullable', 'string', 'max:180'],
            'hint' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
