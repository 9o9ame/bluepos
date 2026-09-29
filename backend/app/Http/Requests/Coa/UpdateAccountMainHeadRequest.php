<?php

namespace App\Http\Requests\Coa;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountMainHeadRequest extends FormRequest
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
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();
        $ulid = (string) $this->route('mainHeadUlid');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:180',
                Rule::unique('account_main_heads', 'name')
                    ->where('tenant_id', $tenantId)
                    ->ignore($ulid, 'ulid'),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
