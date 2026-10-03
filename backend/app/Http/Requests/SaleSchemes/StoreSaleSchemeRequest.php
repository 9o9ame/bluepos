<?php

namespace App\Http\Requests\SaleSchemes;

use App\Enums\SaleSchemeApplyMode;
use App\Enums\SaleSchemeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleSchemeRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:180'],
            'scheme_type' => ['sometimes', Rule::enum(SaleSchemeType::class)],
            'apply_mode' => ['required', Rule::enum(SaleSchemeApplyMode::class)],
            'min_sale_amount' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/', 'numeric', 'min:0'],
            'reward_product_ulid' => ['required', 'string', 'size:26'],
            'max_reward_qty' => ['required', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/', 'numeric', 'gt:0'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_stackable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
