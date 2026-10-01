<?php

namespace App\Actions\Purchases;

use App\Actions\Products\SyncProductPricesAction;
use App\Enums\PriceType;
use App\Models\Product;
use App\Models\PurchaseInvoiceLine;

/**
 * After a purchase line posts, keep Define Product retail price (and tax) aligned
 * with the purchase MRP / tax the operator confirmed on the invoice.
 */
class SyncPurchaseLineToProductAction
{
    public function __construct(private readonly SyncProductPricesAction $syncPrices) {}

    public function execute(Product $product, PurchaseInvoiceLine $line): Product
    {
        $mrp = (string) ($line->mrp ?? '0');
        if (bccomp($mrp, '0', 4) === 1) {
            $product = $this->syncPrices->execute($product, [
                [
                    'price_type' => PriceType::Retail->value,
                    'amount' => bcadd($mrp, '0', 4),
                    'is_active' => true,
                ],
            ]);
        }

        $taxPct = (string) ($line->tax_pct ?? '0');
        if (bccomp($taxPct, '0', 8) === 1) {
            $product->tax_percent = bcadd($taxPct, '0', 8);
            $product->is_taxable = true;
            $product->save();
        }

        return $product->fresh() ?? $product;
    }
}
