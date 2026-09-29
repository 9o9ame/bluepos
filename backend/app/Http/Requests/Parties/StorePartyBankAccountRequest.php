<?php

namespace App\Http\Requests\Parties;

use Illuminate\Foundation\Http\FormRequest;

class StorePartyBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bank_name' => trim((string) $this->input('bank_name')),
            'branch_name' => $this->filled('branch_name') ? trim((string) $this->input('branch_name')) : null,
            'branch_code' => $this->filled('branch_code') ? trim((string) $this->input('branch_code')) : null,
            'city' => $this->filled('city') ? trim((string) $this->input('city')) : null,
            'account_number' => $this->filled('account_number')
                ? trim((string) $this->input('account_number'))
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:180'],
            'branch_name' => ['nullable', 'string', 'max:180'],
            'branch_code' => ['nullable', 'string', 'max:64'],
            'city' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999999'],
        ];
    }
}
