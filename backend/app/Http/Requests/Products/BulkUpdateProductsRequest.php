<?php

namespace App\Http\Requests\Products;

use App\Enums\PriceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkUpdateProductsRequest extends FormRequest
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
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*' => ['required', 'array'],
            'rows.*.product_ulid' => ['required', 'string', 'size:26', 'distinct'],
            'rows.*.product' => ['sometimes', 'array'],
            'rows.*.product.reorder_level' => [
                'nullable',
                'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/',
            ],
            'rows.*.prices' => ['sometimes', 'array', 'min:1', 'max:3'],
            'rows.*.prices.*' => ['required', 'array'],
            'rows.*.prices.*.price_type' => ['required', Rule::enum(PriceType::class), 'distinct'],
            'rows.*.prices.*.amount' => [
                'nullable',
                'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/',
            ],
            'rows.*.prices.*.formula' => [
                'nullable',
                Rule::in(['trade_price_plus_percent']),
            ],
            'rows.*.prices.*.percent' => [
                'nullable',
                'regex:/^(?:0|[1-9]\d{0,3})(?:\.\d{1,8})?$/',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('rows', []) as $rowIndex => $row) {
                $product = (array) ($row['product'] ?? []);
                $prices = (array) ($row['prices'] ?? []);

                if ($product === [] && $prices === []) {
                    $validator->errors()->add(
                        "rows.{$rowIndex}",
                        'Each row must contain a product field or price change.',
                    );
                }

                foreach ($prices as $priceIndex => $price) {
                    $price = (array) $price;
                    $hasAmount = array_key_exists('amount', $price) && $price['amount'] !== null && $price['amount'] !== '';
                    $hasFormula = isset($price['formula']) && $price['formula'] !== '';

                    if ($hasAmount === $hasFormula) {
                        $validator->errors()->add(
                            "rows.{$rowIndex}.prices.{$priceIndex}",
                            'Provide exactly one of amount or formula.',
                        );
                        continue;
                    }

                    if (! $hasFormula) {
                        continue;
                    }

                    if (($price['formula'] ?? null) === 'trade_price_plus_percent') {
                        if (($price['price_type'] ?? null) !== PriceType::Retail->value) {
                            $validator->errors()->add(
                                "rows.{$rowIndex}.prices.{$priceIndex}.price_type",
                                'Trade Price formulas can only update Sale Price.',
                            );
                        }

                        if (! isset($price['percent']) || $price['percent'] === '') {
                            $validator->errors()->add(
                                "rows.{$rowIndex}.prices.{$priceIndex}.percent",
                                'A percentage is required for this formula.',
                            );
                        }
                    }
                }
            }
        });
    }
}
