<?php

namespace App\Http\Resources;

use App\Models\ProductBarcode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProductBarcode
 */
class ProductBarcodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'barcode' => $this->barcode,
            'conversion_factor' => $this->conversion_factor,
            'is_primary' => $this->is_primary,
            'is_active' => $this->is_active,
            'unit' => new UnitResource($this->whenLoaded('unit')),
        ];
    }
}
