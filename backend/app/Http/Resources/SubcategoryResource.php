<?php

namespace App\Http\Resources;

use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subcategory
 */
class SubcategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'category_ulid' => $this->whenLoaded('category', fn () => $this->category?->ulid),
            'category' => new CategoryResource($this->whenLoaded('category')),
        ];
    }
}
