<?php

namespace App\Http\Requests\Catalog;

use App\Models\Brand;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends FormRequest
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
        $ulid = (string) $this->route('brandUlid');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique('brands', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore(Brand::query()->forTenant($tenantId)->where('ulid', $ulid)->value('id')),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
