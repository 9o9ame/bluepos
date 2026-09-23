<?php

namespace App\Http\Requests\Catalog;

use App\Models\Category;
use App\Models\Subcategory;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubcategoryRequest extends FormRequest
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

        $subcategoryUlid = (string) $this->route('subcategoryUlid');

        $subcategory = Subcategory::query()
            ->forTenant($tenantId)
            ->where('ulid', $subcategoryUlid)
            ->first();

        $subcategoryId = $subcategory?->id;
        $categoryId = $subcategory?->category_id;

        /*
         * If the update is also moving the subcategory
         * to another category, validate uniqueness
         * against the new category.
         */
        if ($this->filled('category_ulid')) {
            $requestedCategoryId = Category::query()
                ->forTenant($tenantId)
                ->where(
                    'ulid',
                    (string) $this->input('category_ulid')
                )
                ->value('id');

            if ($requestedCategoryId !== null) {
                $categoryId = $requestedCategoryId;
            }
        }

        return [
            'category_ulid' => [
                'sometimes',
                'required',
                'string',
                'size:26',
            ],

            'code' => [
                'sometimes',
                'required',
                'string',
                'max:64',
                'alpha_dash',

                Rule::unique('subcategories', 'code')
                    ->where(function ($query) use (
                        $tenantId,
                        $categoryId
                    ) {
                        return $query
                            ->where('tenant_id', $tenantId)
                            ->where('category_id', $categoryId);
                    })
                    ->ignore($subcategoryId),
            ],

            'name' => [
                'sometimes',
                'required',
                'string',
                'max:160',
            ],

            'description' => [
                'nullable',
                'string',
                'max:255',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
            ],
        ];
    }
}
