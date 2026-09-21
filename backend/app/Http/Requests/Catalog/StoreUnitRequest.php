<?php

namespace App\Http\Requests\Catalog;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'code' => [
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique('units', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:160'],
            'symbol' => ['required', 'string', 'max:16'],
            'allows_decimal' => ['required', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
