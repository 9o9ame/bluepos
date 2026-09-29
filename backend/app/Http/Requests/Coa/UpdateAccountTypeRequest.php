<?php

namespace App\Http\Requests\Coa;

use App\Models\AccountType;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
        if ($this->exists('code')) {
            $raw = $this->input('code');
            $this->merge([
                'code' => $raw === null || $raw === ''
                    ? null
                    : strtoupper(trim((string) $raw)),
            ]);
        }
        if ($this->exists('sub_head_ulid')) {
            $this->merge(['sub_head_ulid' => trim((string) $this->input('sub_head_ulid'))]);
        }
        if ($this->exists('pnl_grouping_label')) {
            $this->merge([
                'pnl_grouping_label' => $this->filled('pnl_grouping_label')
                    ? trim((string) $this->input('pnl_grouping_label'))
                    : null,
            ]);
        }
        if ($this->exists('hint')) {
            $this->merge([
                'hint' => $this->filled('hint') ? trim((string) $this->input('hint')) : null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $ulid = (string) $this->route('accountTypeUlid');
        $ignoreId = AccountType::query()->forTenant($tenantId)->where('ulid', $ulid)->value('id');

        return [
            'sub_head_ulid' => [
                'sometimes',
                'required',
                'string',
                'size:26',
                Rule::exists('account_sub_heads', 'ulid')->where('tenant_id', $tenantId),
            ],
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                Rule::unique('account_types', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($ignoreId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:180'],
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
