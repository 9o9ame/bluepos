<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchasePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'regex:/^(?:0|[1-9]\\d*)(?:\\.\\d{1,4})?$/', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'card', 'bank'])],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
