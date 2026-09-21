<?php

namespace App\Actions\Products;

use App\Enums\PriceType;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncProductPricesAction
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  list<array{price_type: string, amount: string, is_active?: bool, effective_from?: ?string, effective_to?: ?string}>  $rows
     */
    public function execute(Product $product, array $rows): Product
    {
        return DB::transaction(function () use ($product, $rows): Product {
            $tenantId = $this->tenantContext->tenantId();
            $product = Product::query()
                ->forTenant($tenantId)
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currency = $this->tenantContext->tenant()->currency_code;
            $seen = [];

            foreach ($rows as $row) {
                if (bccomp((string) $row['amount'], '0', 4) < 0) {
                    throw ValidationException::withMessages([
                        'prices' => 'Prices cannot be negative.',
                    ]);
                }

                $type = $row['price_type'] instanceof PriceType
                    ? $row['price_type']->value
                    : (string) $row['price_type'];

                if (isset($seen[$type])) {
                    throw ValidationException::withMessages([
                        'prices' => 'Each price type can be saved only once.',
                    ]);
                }
                $seen[$type] = true;

                ProductPrice::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'product_id' => $product->id,
                        'price_type' => $type,
                    ],
                    [
                        'amount' => $row['amount'],
                        'currency_code' => $currency,
                        'is_active' => $row['is_active'] ?? true,
                        'effective_from' => $row['effective_from'] ?? null,
                        'effective_to' => $row['effective_to'] ?? null,
                    ],
                );
            }

            return $product->fresh(CreateProductAction::with());
        });
    }
}
