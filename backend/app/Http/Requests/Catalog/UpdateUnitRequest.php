<?php

namespace App\Http\Requests\Catalog;

use App\Models\Unit;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
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
        $ulid = (string) $this->route('unitUlid');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique('units', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore(Unit::query()->forTenant($tenantId)->where('ulid', $ulid)->value('id')),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'symbol' => ['sometimes', 'required', 'string', 'max:16'],
            'allows_decimal' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
