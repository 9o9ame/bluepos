<?php

namespace App\Http\Requests\Sales;

use App\Enums\SalePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleReturnRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'amount' => [
                'required',
                'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/',
                'numeric',
                'gt:0',
            ],
            'method' => ['required', Rule::enum(SalePaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
        ];
    }
}
