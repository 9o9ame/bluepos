<?php

namespace App\Actions\Products;

use App\Enums\PriceType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BulkUpdateProductsAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UpdateProductAction $updateProduct,
        private readonly SyncProductPricesAction $syncPrices,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{updated: int}
     */
    public function execute(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            $tenantId = $this->tenantContext->tenantId();
            $ulids = collect($rows)
                ->pluck('product_ulid')
                ->map(fn ($ulid) => (string) $ulid)
                ->values();

            $products = Product::query()
                ->forTenant($tenantId)
                ->whereIn('ulid', $ulids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('ulid');

            if ($products->count() !== $ulids->count()) {
                throw (new ModelNotFoundException())->setModel(Product::class);
            }

            foreach ($rows as $rowIndex => $row) {
                /** @var Product $product */
                $product = $products->get((string) $row['product_ulid']);

                $productChanges = (array) ($row['product'] ?? []);
                if ($productChanges !== []) {
                    $product = $this->updateProduct->execute($product, $productChanges);
                }

                $priceChanges = (array) ($row['prices'] ?? []);
                if ($priceChanges === []) {
                    continue;
                }

                $resolvedPrices = [];
                foreach ($priceChanges as $priceIndex => $price) {
                    $price = (array) $price;
                    $amount = $price['amount'] ?? null;

                    if (($price['formula'] ?? null) === 'trade_price_plus_percent') {
                        $tradePrice = ProductPrice::query()
                            ->where('tenant_id', $tenantId)
                            ->where('product_id', $product->id)
                            ->where('price_type', PriceType::Wholesale->value)
                            ->where('is_active', true)
                            ->value('amount');

                        if ($tradePrice === null) {
                            throw ValidationException::withMessages([
                                "rows.{$rowIndex}.prices.{$priceIndex}.formula" =>
                                    'Trade Price is required before this formula can be applied.',
                            ]);
                        }

                        $percent = (string) ($price['percent'] ?? '0');
                        $factor = bcdiv($percent, '100', 8);
                        $increase = bcmul((string) $tradePrice, $factor, 8);
                        $unrounded = bcadd((string) $tradePrice, $increase, 8);
                        $amount = bcadd($unrounded, '0.00005', 4);
                    }

                    $resolvedPrices[] = [
                        'price_type' => (string) $price['price_type'],
                        'amount' => (string) $amount,
                        'is_active' => true,
                    ];
                }

                $this->syncPrices->execute($product, $resolvedPrices);
            }

            return ['updated' => count($rows)];
        });
    }
}
