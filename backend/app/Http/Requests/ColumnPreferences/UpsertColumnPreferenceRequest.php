<?php

namespace App\Http\Requests\ColumnPreferences;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertColumnPreferenceRequest extends FormRequest
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
            'scope' => ['required', Rule::in(['user', 'role'])],
            'role_ulid' => ['nullable', 'string', 'size:26'],
            'columns' => ['required', 'array', 'min:1', 'max:120'],
            'columns.*.key' => ['required', 'string', 'max:64'],
            'columns.*.visible' => ['required', 'boolean'],
            'columns.*.position' => ['required', 'integer', 'min:0', 'max:500'],
            'columns.*.width' => ['nullable', 'integer', 'min:40', 'max:800'],
            'columns.*.locked' => ['required', 'boolean'],
        ];
    }
}
