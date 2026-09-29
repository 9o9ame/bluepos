<?php

namespace App\Http\Requests\Coa;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountSubHeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'main_head_ulid' => trim((string) $this->input('main_head_ulid')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'main_head_ulid' => [
                'required',
                'string',
                'size:26',
                Rule::exists('account_main_heads', 'ulid')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:180'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
