<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $primary = $this->whenLoaded('barcodes', function () {
            return $this->barcodes->firstWhere('is_primary', true);
        });

        return [
            'ulid' => $this->ulid,
            'product_number' => $this->product_number,
            'sku' => $this->sku,
            'name' => $this->name,
            'alternate_name' => $this->alternate_name,
            'tax_percent' => $this->tax_percent,
            'is_taxable' => $this->is_taxable,
            'track_batch' => $this->track_batch,
            'track_expiry' => $this->track_expiry,
            'reorder_level' => $this->reorder_level,
            'minimum_stock' => $this->minimum_stock,
            'maximum_stock' => $this->maximum_stock,
            'rack_location' => $this->rack_location,
            'description' => $this->description,
            'status' => $this->status->value,
            'is_active' => $this->is_active,
            'secondary_conversion_factor' => $this->secondary_conversion_factor,
            'primary_barcode' => $primary?->barcode,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'subcategory' => new SubcategoryResource($this->whenLoaded('subcategory')),
            'brand' => new BrandResource($this->whenLoaded('brand')),
            'barcode_group' => new BarcodeGroupResource($this->whenLoaded('barcodeGroup')),
            'primary_supplier' => $this->when(
                $this->relationLoaded('primaryProductSupplier'),
                function () {
                    $link = $this->primaryProductSupplier;
                    $supplier = $link?->supplier;

                    if (! $supplier) {
                        return null;
                    }

                    return [
                        'ulid' => $supplier->ulid,
                        'code' => $supplier->code,
                        'name' => $supplier->name,
                        'is_active' => $supplier->is_active,
                    ];
                },
            ),
            'supplier_product_code' => $this->when(
                $this->relationLoaded('primaryProductSupplier'),
                fn () => $this->primaryProductSupplier?->supplier_product_code,
            ),
            'base_unit' => new UnitResource($this->whenLoaded('baseUnit')),
            'secondary_unit' => new UnitResource($this->whenLoaded('secondaryUnit')),
            'barcodes' => ProductBarcodeResource::collection($this->whenLoaded('barcodes')),
            'prices' => ProductPriceResource::collection($this->whenLoaded('prices')),
        ];
    }
}
