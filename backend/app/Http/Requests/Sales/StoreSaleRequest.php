<?php

namespace App\Http\Requests\Sales;

use App\Enums\PriceType;
use App\Enums\SaleLineKind;
use App\Enums\SalePaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }


    protected function prepareForValidation(): void
    {
        /*
         * Backward compatibility:
         *
         * Existing Sales tests/frontend may still send:
         *
         * applied_scheme_ulids: ["01...", "01..."]
         *
         * The current normalized contract is:
         *
         * applied_schemes: [
         *     ["scheme_ulid" => "01...", "qty" => "1.000000"],
         * ]
         *
         * Convert the legacy shape only when the newer payload was not sent.
         */
        if (
            ! $this->has('applied_schemes') &&
            is_array($this->input('applied_scheme_ulids'))
        ) {
            $this->merge([
                'applied_schemes' => array_values(array_map(
                    static fn (mixed $ulid): array => [
                        'scheme_ulid' => (string) $ulid,
                        'qty' => '1.000000',
                    ],
                    $this->input('applied_scheme_ulids', [])
                )),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sale_date' => ['nullable', 'date'],
            'customer_ulid' => ['nullable', 'string', 'size:26'],
            'salesman_ulid' => ['nullable', 'string', 'size:26'],
            'payment_due' => ['sometimes', 'boolean'],
            'initial_payment' => ['sometimes', 'array'],
            'initial_payment.amount' => [
                'required_with:initial_payment',
                'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/',
                'numeric',
                'gt:0',
            ],
            'initial_payment.method' => [
                'required_with:initial_payment',
                Rule::enum(SalePaymentMethod::class),
            ],
            'initial_payment.reference' => ['nullable', 'string', 'max:100'],
            'warehouse_ulid' => ['nullable', 'string', 'size:26'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // "default" preserves the current behavior and resolves to retail.
            'price_type' => [
                'nullable',
                Rule::in([
                    'default',
                    PriceType::Retail->value,
                    PriceType::Wholesale->value,
                ]),
            ],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_ulid' => ['nullable', 'string', 'size:26'],
            'items.*.barcode' => ['nullable', 'string', 'max:64'],
            'items.*.unit_ulid' => ['nullable', 'string', 'size:26'],
            'items.*.quantity' => ['required', 'string'],
            'items.*.discount_percent' => ['nullable', 'string'],
            'items.*.discount_amount' => ['nullable', 'string'],

            // Unit price, tax and totals are intentionally NOT accepted as
            // sources of truth. CreateSaleAction calculates them server-side.

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
