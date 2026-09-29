<?php

namespace App\Http\Requests\Parties;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePartyBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['bank_name', 'branch_name', 'branch_code', 'city', 'account_number'] as $field) {
            if ($this->exists($field)) {
                $merge[$field] = $this->filled($field) ? trim((string) $this->input($field)) : null;
            }
        }
        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank_name' => ['sometimes', 'required', 'string', 'max:180'],
            'branch_name' => ['nullable', 'string', 'max:180'],
            'branch_code' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999999'],
        ];
    }
}
