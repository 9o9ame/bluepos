<?php

namespace App\Http\Requests\Sales;

use App\Enums\SaleLineKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
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
            'sale_date' => ['nullable', 'date'],
            'customer_ulid' => ['nullable', 'string', 'size:26'],
            'warehouse_ulid' => ['nullable', 'string', 'size:26'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_ulid' => ['required', 'string', 'size:26'],
            'items.*.quantity' => ['required', 'string'],

            // Free lines may not be smuggled in as ordinary items.
            'items.*.line_kind' => [
                'sometimes',
                Rule::in([SaleLineKind::Sale->value]),
            ],

            'items.*.notes' => ['nullable', 'string', 'max:500'],

            // Free packaging lines.
            // Free lines never carry a client price; only product and quantity.
            'free_lines' => ['sometimes', 'array'],
            'free_lines.*.product_ulid' => [
                'required_with:free_lines',
                'string',
                'size:26',
            ],
            'free_lines.*.qty' => [
                'required_with:free_lines',
                'string',
            ],
            'free_lines.*.line_kind' => [
                'sometimes',
                Rule::in([SaleLineKind::FreePackaging->value]),
            ],

            // Schemes explicitly selected by the salesman.
            'applied_schemes' => ['sometimes', 'array'],
            'applied_schemes.*.scheme_ulid' => [
                'required',
                'string',
                'size:26',
            ],
            'applied_schemes.*.qty' => [
                'required',
                'string',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one sale line.',

            'applied_schemes.*.scheme_ulid.required' =>
                'A scheme must be selected.',

            'applied_schemes.*.qty.required' =>
                'A scheme quantity must be selected.',
        ];
    }
}
