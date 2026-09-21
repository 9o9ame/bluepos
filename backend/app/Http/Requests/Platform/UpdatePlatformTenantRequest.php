<?php

namespace App\Http\Requests\Platform;

use App\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformTenantRequest extends FormRequest
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
        return [
            'tenant_name' => ['sometimes', 'string', 'max:160'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', Rule::enum(TenantStatus::class)],
        ];
    }
}
