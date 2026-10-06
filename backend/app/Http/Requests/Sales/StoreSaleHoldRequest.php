<?php

namespace App\Http\Requests\Sales;

use App\Enums\SaleLineKind;
use App\Enums\PriceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleHoldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sale_date' => ['nullable', 'date'],
            'customer_ulid' => ['nullable', 'string', 'size:26'],
            'salesman_ulid' => ['nullable', 'string', 'size:26'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'price_type' => [
                'required',
                Rule::in([
                    'default',
                    PriceType::Retail->value,
                    PriceType::Wholesale->value,
                ]),
            ],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_ulid' => ['required', 'string', 'size:26'],
            'lines.*.line_kind' => ['required', Rule::enum(SaleLineKind::class)],
            'lines.*.unit_ulid' => ['nullable', 'string', 'size:26'],
            'lines.*.scheme_ulid' => ['nullable', 'string', 'size:26'],
            'lines.*.barcode' => ['nullable', 'string', 'max:64'],
            'lines.*.quantity' => [
                'required',
                'string',
                'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/',
                'numeric',
                'gt:0',
            ],
            'lines.*.discount_percent' => [
                'nullable',
                'string',
                'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/',
                'numeric',
                'min:0',
                'max:100',
            ],
            'lines.*.discount_amount' => [
                'nullable',
                'string',
                'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/',
                'numeric',
                'min:0',
            ],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
