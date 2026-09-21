<?php

namespace App\Http\Requests\Products;

use App\Enums\PriceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncProductPricesRequest extends FormRequest
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
            'prices' => ['required', 'array', 'min:1'],
            'prices.*.price_type' => ['required', Rule::enum(PriceType::class)],
            'prices.*.amount' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
            'prices.*.is_active' => ['sometimes', 'boolean'],
            'prices.*.effective_from' => ['nullable', 'date'],
            'prices.*.effective_to' => ['nullable', 'date', 'after_or_equal:prices.*.effective_from'],
        ];
    }
}
