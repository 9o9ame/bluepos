<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class SyncPlanLimitsRequest extends FormRequest
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
            'limits' => ['required', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
