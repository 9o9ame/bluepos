<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockBalance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SaleDiscountTaxTest extends TestCase
{
    use DatabaseTransactions;

    public function test_percentage_discount_and_product_tax_are_calculated_server_side(): void
    {
        $this->signInOwner('sale-discount-tax-1')->assertOk();

        $product = $this->createProduct('Taxed Discount Item', '100.0000', true, '15.00000000');
        $this->giveStock($product, '10');

        $response = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '2',
                'discount_percent' => '10',
                // These client-calculated values must not become authoritative.
                'tax_amount' => '9999.0000',
                'line_total' => '1.0000',
            ]],
        ], ['Idempotency-Key' => 'sale-discount-tax-1-a'])->assertCreated();

        // Gross 200; 10% discount = 20; taxable = 180; 15% tax = 27; net = 207.
        $response->assertJsonPath('subtotal', '200.0000');
        $response->assertJsonPath('discount_amount', '20.0000');
        $response->assertJsonPath('tax_amount', '27.0000');
        $response->assertJsonPath('grand_total', '207.0000');
        $response->assertJsonPath('items.0.gross_amount', '200.0000');
        $response->assertJsonPath('items.0.discount_percent', '10.00000000');
        $response->assertJsonPath('items.0.discount_amount', '20.0000');
        $response->assertJsonPath('items.0.tax_percent', '15.00000000');
        $response->assertJsonPath('items.0.tax_amount', '27.0000');
        $response->assertJsonPath('items.0.net_amount', '207.0000');
        $response->assertJsonPath('items.0.line_total', '207.0000');
    }

    public function test_fixed_discount_derives_percent_and_non_taxable_product_has_zero_tax(): void
    {
        $this->signInOwner('sale-discount-tax-2')->assertOk();

        $product = $this->createProduct('Fixed Discount Item', '50.0000', false, '17.00000000');
        $this->giveStock($product, '10');

        $response = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '4',
                'discount_amount' => '25.0000',
            ]],
        ], ['Idempotency-Key' => 'sale-discount-tax-2-a'])->assertCreated();

        $response->assertJsonPath('subtotal', '200.0000');
        $response->assertJsonPath('discount_amount', '25.0000');
        $response->assertJsonPath('tax_amount', '0.0000');
        $response->assertJsonPath('grand_total', '175.0000');
        $response->assertJsonPath('items.0.discount_percent', '12.50000000');
        $response->assertJsonPath('items.0.tax_percent', '0.00000000');
        $response->assertJsonPath('items.0.net_amount', '175.0000');
    }

    public function test_discount_percent_and_amount_cannot_both_be_positive(): void
    {
        $this->signInOwner('sale-discount-tax-3')->assertOk();

        $product = $this->createProduct('Double Discount Item', '100.0000');
        $this->giveStock($product, '10');

        $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '1',
                'discount_percent' => '5',
                'discount_amount' => '5.0000',
            ]],
        ], ['Idempotency-Key' => 'sale-discount-tax-3-a'])
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');
    }

    public function test_fixed_discount_cannot_exceed_gross_line_amount(): void
    {
        $this->signInOwner('sale-discount-tax-4')->assertOk();

        $product = $this->createProduct('Excess Discount Item', '100.0000');
        $this->giveStock($product, '10');

        $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product->ulid,
                'quantity' => '1',
                'discount_amount' => '101.0000',
            ]],
        ], ['Idempotency-Key' => 'sale-discount-tax-4-a'])
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');
    }

    private function createProduct(
        string $name,
        string $retailPrice,
        bool $taxable = false,
        string $taxPercent = '0.00000000'
    ): Product {
        $response = $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'is_taxable' => $taxable,
            'tax_percent' => $taxPercent,
            'prices' => [[
                'price_type' => 'retail',
                'amount' => $retailPrice,
            ]],
        ])->assertCreated();

        return Product::query()
            ->where('ulid', $response->json('ulid'))
            ->firstOrFail();
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
