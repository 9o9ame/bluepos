<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
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
        $ulid = (string) $this->route('productUlid');
        $productId = Product::query()->forTenant($tenantId)->where('ulid', $ulid)->value('id');

        return [
            'product_number' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                Rule::unique('products', 'product_number')->where('tenant_id', $tenantId)->ignore($productId),
            ],
            'sku' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('products', 'sku')->where('tenant_id', $tenantId)->ignore($productId),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'alternate_name' => ['nullable', 'string', 'max:180'],
            'category_ulid' => ['nullable', 'string', 'size:26'],
            'subcategory_ulid' => ['nullable', 'string', 'size:26'],
            'brand_ulid' => ['nullable', 'string', 'size:26'],
            'barcode_group_ulid' => ['nullable', 'string', 'size:26'],
            'primary_supplier_ulid' => ['nullable', 'string', 'size:26'],
            'supplier_product_code' => ['nullable', 'string', 'max:100'],
            'base_unit_ulid' => ['sometimes', 'required', 'string', 'size:26'],
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
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
