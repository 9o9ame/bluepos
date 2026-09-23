<?php

namespace App\Http\Requests\Products;

use App\Enums\PriceType;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'product_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('products', 'product_number')->where('tenant_id', $tenantId),
            ],
            'sku' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('products', 'sku')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:180'],
            'alternate_name' => ['nullable', 'string', 'max:180'],
            'category_ulid' => ['nullable', 'string', 'size:26'],
            'subcategory_ulid' => ['nullable', 'string', 'size:26'],
            'brand_ulid' => ['nullable', 'string', 'size:26'],
            'barcode_group_ulid' => ['nullable', 'string', 'size:26'],
            'base_unit_ulid' => ['required', 'string', 'size:26'],
            'secondary_unit_ulid' => ['nullable', 'string', 'size:26'],
            'secondary_conversion_factor' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', 'numeric', 'gt:0'],
            'tax_percent' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', 'numeric', 'min:0', 'max:100'],
            'is_taxable' => ['sometimes', 'boolean'],
            'track_batch' => ['sometimes', 'boolean'],
            'track_expiry' => ['sometimes', 'boolean'],
            'reorder_level' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'minimum_stock' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'maximum_stock' => ['nullable', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/'],
            'rack_location' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:2000'],
            'barcodes' => ['sometimes', 'array'],
            'barcodes.*.barcode' => ['required_with:barcodes', 'string', 'max:64', 'distinct'],
            'barcodes.*.unit_ulid' => ['required_with:barcodes', 'string', 'size:26'],
            'barcodes.*.conversion_factor' => ['required_with:barcodes', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,8})?$/', 'numeric', 'gt:0'],
            'barcodes.*.is_primary' => ['sometimes', 'boolean'],
            'prices' => ['sometimes', 'array'],
            'prices.*.price_type' => ['required_with:prices', Rule::enum(PriceType::class)],
            'prices.*.amount' => ['required_with:prices', 'regex:/^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/'],
        ];
    }
}
