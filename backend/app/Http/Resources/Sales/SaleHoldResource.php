<?php

namespace App\Http\Resources\Sales;

use App\Enums\PriceType;
use App\Models\SaleHold;
use App\Models\SaleHoldItem;
use App\Models\StockBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleHold */
class SaleHoldResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $stockByProduct = collect();

        if ($this->relationLoaded('items')) {
            $productIds = $this->items->pluck('product_id')->unique()->values();

            $stockByProduct = StockBalance::query()
                ->where('tenant_id', (int) $this->tenant_id)
                ->where('branch_id', (int) $this->branch_id)
                ->where('warehouse_id', (int) $this->warehouse_id)
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy('product_id');
        }

        return [
            'ulid' => $this->ulid,
            'held_at' => $this->created_at?->toIso8601String(),
            'sale_date' => $this->sale_date?->toDateString(),
            'price_type' => $this->price_type,
            'notes' => $this->notes,
            'sale_line_count' => (int) ($this->getAttribute('sale_line_count')
                ?? ($this->relationLoaded('items')
                    ? $this->items->where('line_kind', \App\Enums\SaleLineKind::Sale)->count()
                    : 0)),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'ulid' => $this->customer->ulid,
                'code' => $this->customer->code,
                'name' => $this->customer->name,
            ] : null),
            'salesman' => $this->whenLoaded('salesmanParty', fn () => $this->salesmanParty ? [
                'ulid' => $this->salesmanParty->ulid,
                'code' => $this->salesmanParty->code,
                'name' => $this->salesmanParty->name,
                'address' => $this->salesmanParty->address,
                'mobile' => $this->salesmanParty->mobile ?: $this->salesmanParty->phone,
            ] : null),
            'branch' => $this->whenLoaded('branch', fn () => [
                'ulid' => $this->branch->ulid,
                'code' => $this->branch->code,
                'name' => $this->branch->name,
            ]),
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'ulid' => $this->warehouse->ulid,
                'code' => $this->warehouse->code,
                'name' => $this->warehouse->name,
            ]),
            'lines' => $this->whenLoaded('items', fn () =>
                $this->items
                    ->sortBy('sort_order')
                    ->values()
                    ->map(fn (SaleHoldItem $item) => $this->draftLine(
                        $item,
                        $stockByProduct->get($item->product_id),
                    ))
                    ->all()
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function draftLine(SaleHoldItem $item, mixed $stock): array
    {
        $product = $item->product;

        $retail = $product->prices
            ->first(fn ($price) => $price->is_active && $price->price_type === PriceType::Retail);

        $wholesale = $product->prices
            ->first(fn ($price) => $price->is_active && $price->price_type === PriceType::Wholesale);

        return [
            'line_key' => 'hold:'.$item->ulid,
            'product_ulid' => $product->ulid,
            'product_name' => $product->name,
            'product_number' => $product->product_number,
            'quantity' => $item->quantity,
            'line_kind' => $item->line_kind->value,
            'scheme_ulid' => $item->saleScheme?->ulid,
            'unit_ulid' => $item->unit?->ulid,
            'unit_code' => $item->unit?->code,
            'unit_name' => $item->unit?->name,
            'unit_symbol' => $item->unit?->symbol,
            'unit_allows_decimal' => $item->unit?->allows_decimal,
            'barcode' => $item->barcode,
            'conversion_factor' => $this->conversionFactor($item),
            'available_units' => $this->unitOptions($item),
            'available_base_stock' => $stock?->quantity ?? '0.000000',
            'retail_price' => $retail?->amount,
            'wholesale_price' => $wholesale?->amount,
            'discount_percent' => $item->discount_percent,
            'discount_amount' => $item->discount_amount,
            'tax_percent' => $product->is_taxable ? $product->tax_percent : '0.00000000',
            'notes' => $item->notes,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function unitOptions(SaleHoldItem $item): array
    {
        $product = $item->product;
        $options = [];
        $used = [];

        if ($product->baseUnit) {
            $used[(int) $product->baseUnit->id] = true;
            $options[] = [
                'unit_ulid' => $product->baseUnit->ulid,
                'code' => $product->baseUnit->code,
                'name' => $product->baseUnit->name,
                'symbol' => $product->baseUnit->symbol,
                'allows_decimal' => $product->baseUnit->allows_decimal,
                'conversion_factor' => '1.00000000',
                'barcode' => null,
            ];
        }

        if ($product->secondaryUnit && ! isset($used[(int) $product->secondaryUnit->id])) {
            $used[(int) $product->secondaryUnit->id] = true;
            $options[] = [
                'unit_ulid' => $product->secondaryUnit->ulid,
                'code' => $product->secondaryUnit->code,
                'name' => $product->secondaryUnit->name,
                'symbol' => $product->secondaryUnit->symbol,
                'allows_decimal' => $product->secondaryUnit->allows_decimal,
                'conversion_factor' => (string) ($product->secondary_conversion_factor ?? '1.00000000'),
                'barcode' => null,
            ];
        }

        foreach ($product->barcodes as $barcode) {
            if (! $barcode->is_active || ! $barcode->unit || isset($used[(int) $barcode->unit_id])) {
                continue;
            }

            $used[(int) $barcode->unit_id] = true;
            $options[] = [
                'unit_ulid' => $barcode->unit->ulid,
                'code' => $barcode->unit->code,
                'name' => $barcode->unit->name,
                'symbol' => $barcode->unit->symbol,
                'allows_decimal' => $barcode->unit->allows_decimal,
                'conversion_factor' => $barcode->conversion_factor,
                'barcode' => $barcode->barcode,
            ];
        }

        return $options;
    }

    private function conversionFactor(SaleHoldItem $item): string
    {
        $product = $item->product;

        if ($item->barcode) {
            $barcode = $product->barcodes
                ->first(fn ($row) => $row->is_active && $row->barcode === $item->barcode);

            if ($barcode) {
                return (string) $barcode->conversion_factor;
            }
        }

        if ($item->unit_id !== null && (int) $item->unit_id === (int) $product->secondary_unit_id) {
            return (string) ($product->secondary_conversion_factor ?? '1.00000000');
        }

        if ($item->unit_id !== null && (int) $item->unit_id !== (int) $product->base_unit_id) {
            $barcode = $product->barcodes
                ->first(fn ($row) => $row->is_active && (int) $row->unit_id === (int) $item->unit_id);

            if ($barcode) {
                return (string) $barcode->conversion_factor;
            }
        }

        return '1.00000000';
    }
}
