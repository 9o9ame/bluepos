<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class TenantLimitOverrideRequest extends FormRequest
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
            'value' => ['nullable', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
