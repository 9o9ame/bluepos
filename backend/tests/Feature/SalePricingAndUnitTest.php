<?php

namespace Tests\Feature;

use App\Enums\PriceType;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\StockBalance;
use App\Models\Unit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SalePricingAndUnitTest extends TestCase
{
    use DatabaseTransactions;

    public function test_wholesale_price_type_uses_wholesale_price_and_is_snapshotted(): void
    {
        $this->signInOwner('sale-price-1')->assertOk();

        $product = $this->createProduct('Wholesale Item', '100.0000');
        $this->addPrice($product, PriceType::Wholesale, '80.0000');
        $this->giveStock($product, '10');

        $response = $this->postJson('/api/sales', [
            'price_type' => 'wholesale',
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '2',
            ]],
        ], ['Idempotency-Key' => 'sale-price-1-a'])->assertCreated();

        $response->assertJsonPath('price_type', 'wholesale');
        $response->assertJsonPath('subtotal', '160.0000');
        $response->assertJsonPath('grand_total', '160.0000');
        $response->assertJsonPath('items.0.price_type', 'wholesale');
        $response->assertJsonPath('items.0.unit_price', '80.0000');
        $response->assertJsonPath('items.0.quantity', '2.000000');
        $response->assertJsonPath('items.0.stock_quantity', '2.000000');
        $response->assertJsonPath('items.0.conversion_factor', '1.00000000');

        $this->assertSame('8.000000', $this->stockFor($product));
    }

    public function test_barcode_resolves_product_unit_conversion_price_and_stock_quantity(): void
    {
        $this->signInOwner('sale-price-2')->assertOk();

        $product = $this->createProduct('Boxed Item', '50.0000');
        $box = $this->createUnit('BOXSALE', 'Box', 'BOX', false);

        $product->secondary_unit_id = $box->id;
        $product->secondary_conversion_factor = '12.00000000';
        $product->save();

        $barcode = ProductBarcode::query()->create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'unit_id' => $box->id,
            'barcode' => 'SALE-BOX-0001',
            'conversion_factor' => '12.00000000',
            'is_primary' => true,
            'is_active' => true,
        ]);

        $this->giveStock($product, '24');

        $response = $this->postJson('/api/sales', [
            'items' => [[
                'barcode' => $barcode->barcode,
                'quantity' => '2',
            ]],
        ], ['Idempotency-Key' => 'sale-price-2-a'])->assertCreated();

        // 50 per base unit x 12 units per box = 600 per box.
        $response->assertJsonPath('items.0.unit.code', 'BOXSALE');
        $response->assertJsonPath('items.0.barcode', 'SALE-BOX-0001');
        $response->assertJsonPath('items.0.conversion_factor', '12.00000000');
        $response->assertJsonPath('items.0.quantity', '2.000000');
        $response->assertJsonPath('items.0.stock_quantity', '24.000000');
        $response->assertJsonPath('items.0.unit_price', '600.0000');
        $response->assertJsonPath('items.0.line_total', '1200.0000');
        $response->assertJsonPath('grand_total', '1200.0000');

        $this->assertSame('0.000000', $this->stockFor($product));
    }

    public function test_secondary_unit_can_be_selected_without_a_barcode(): void
    {
        $this->signInOwner('sale-price-3')->assertOk();

        $product = $this->createProduct('Manual Unit Item', '25.0000');
        $carton = $this->createUnit('CTNSALE', 'Carton', 'CTN', false);

        $product->secondary_unit_id = $carton->id;
        $product->secondary_conversion_factor = '6.00000000';
        $product->save();

        $this->giveStock($product, '12');

        $response = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product->ulid,
                'unit_ulid' => $carton->ulid,
                'quantity' => '2',
            ]],
        ], ['Idempotency-Key' => 'sale-price-3-a'])->assertCreated();

        $response->assertJsonPath('items.0.unit.code', 'CTNSALE');
        $response->assertJsonPath('items.0.conversion_factor', '6.00000000');
        $response->assertJsonPath('items.0.stock_quantity', '12.000000');
        $response->assertJsonPath('items.0.unit_price', '150.0000');
        $response->assertJsonPath('items.0.line_total', '300.0000');

        $this->assertSame('0.000000', $this->stockFor($product));
    }

    public function test_non_decimal_unit_rejects_fractional_sale_quantity(): void
    {
        $this->signInOwner('sale-price-4')->assertOk();

        $product = $this->createProduct('Whole Box Item', '10.0000');
        $box = $this->createUnit('WHOLEBOX', 'Whole Box', 'BOX', false);

        $product->secondary_unit_id = $box->id;
        $product->secondary_conversion_factor = '10.00000000';
        $product->save();

        $this->giveStock($product, '20');

        $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product->ulid,
                'unit_ulid' => $box->ulid,
                'quantity' => '1.5',
            ]],
        ], ['Idempotency-Key' => 'sale-price-4-a'])
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertSame('20.000000', $this->stockFor($product));
    }

    private function createProduct(string $name, string $retailPrice): Product
    {
        $response = $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'prices' => [[
                'price_type' => 'retail',
                'amount' => $retailPrice,
            ]],
        ])->assertCreated();

        return Product::query()
            ->where('ulid', $response->json('ulid'))
            ->firstOrFail();
    }

    private function addPrice(Product $product, PriceType $type, string $amount): void
    {
        ProductPrice::query()->create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'price_type' => $type,
            'amount' => $amount,
            'currency_code' => 'PKR',
            'is_active' => true,
        ]);
    }

    private function createUnit(
        string $code,
        string $name,
        string $symbol,
        bool $allowsDecimal
    ): Unit {
        return Unit::query()->create([
            'tenant_id' => app(\App\Tenancy\TenantContext::class)->tenantId(),
            'code' => $code,
            'name' => $name,
            'symbol' => $symbol,
            'allows_decimal' => $allowsDecimal,
            'is_active' => true,
        ]);
    }

    private function giveStock(Product $product, string $quantity): void
    {
        $balance = StockBalance::query()->firstOrCreate(
            [
                'tenant_id' => $product->tenant_id,
                'warehouse_id' => $this->warehouseId(),
                'product_id' => $product->id,
            ],
            [
                'branch_id' => app(\App\Tenancy\TenantContext::class)->branchId(),
                'quantity' => '0.000000',
            ],
        );

        $balance->quantity = bcadd((string) $balance->quantity, $quantity, 6);
        $balance->average_cost = '10.0000';
        $balance->stock_value = bcmul((string) $balance->quantity, '10.0000', 4);
        $balance->save();
    }

    private function stockFor(Product $product): string
    {
        return (string) StockBalance::query()
            ->where('warehouse_id', $this->warehouseId())
            ->where('product_id', $product->id)
            ->value('quantity');
    }

    private function warehouseId(): int
    {
        return (int) app(\App\Tenancy\TenantContext::class)->warehouseId();
    }

    private function unitUlid(string $code): string
    {
        $units = $this->getJson('/api/units')->assertOk()->json();

        foreach ($units as $unit) {
            if ($unit['code'] === $code) {
                return $unit['ulid'];
            }
        }

        $this->fail('Missing unit '.$code);
    }
}
