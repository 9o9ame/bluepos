<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
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
            'expense_date' => ['required', 'date'],
            'expense_account_ulid' => ['required', 'string', 'size:26'],
            'payment_account_ulid' => ['required', 'string', 'size:26'],
            'amount' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
