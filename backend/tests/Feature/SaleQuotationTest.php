<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleQuotation;
use App\Models\StockBalance;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SaleQuotationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_quotation_is_server_priced_idempotent_and_has_no_stock_side_effect(): void
    {
        $this->signInOwner('sale-quotation-create')->assertOk();

        $productUlid = $this->createProduct(
            'Quoted Product',
            ['retail' => '125.0000'],
        );
        $this->giveStock($productUlid, '20');

        $product = Product::query()
            ->where('ulid', $productUlid)
            ->firstOrFail();

        $stockBefore = StockBalance::query()
            ->where('product_id', $product->id)
            ->sum('quantity');
        $movementCountBefore = StockMovement::query()->count();

        $payload = [
            'quotation_date' => '2026-10-07',
            'price_type' => 'retail',
            'items' => [
                [
                    'product_ulid' => $productUlid,
                    'quantity' => '2',
                    'unit_price' => '0.0100',
                    'discount_amount' => '10.0000',
                ],
            ],
            'grand_total' => '0.0100',
            'notes' => 'Quotation snapshot',
        ];

        $headers = $this->idem('quotation-create-a');

        $first = $this->postJson('/api/sales/quotations', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('document_number', 'QUO-000001')
            ->assertJsonPath('quotation_date', '2026-10-07')
            ->assertJsonPath('subtotal', '250.0000')
            ->assertJsonPath('discount_amount', '10.0000')
            ->assertJsonPath('grand_total', '240.0000')
            ->assertJsonPath('items.0.unit_price', '125.0000')
            ->assertJsonPath('items.0.line_total', '240.0000');

        $quotationUlid = (string) $first->json('ulid');

        $replay = $this->postJson('/api/sales/quotations', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('ulid', $quotationUlid);

        $this->assertSame(1, SaleQuotation::query()->count());
        $this->assertSame(0, Sale::query()->count());

        $stockAfter = StockBalance::query()
            ->where('product_id', $product->id)
            ->sum('quantity');

        $this->assertSame((string) $stockBefore, (string) $stockAfter);
        $this->assertSame($movementCountBefore, StockMovement::query()->count());
        $this->assertNoInternalIds($first->json());
        $this->assertNoInternalIds($replay->json());
    }

    public function test_quotation_list_search_show_and_tenant_scope(): void
    {
        $this->signInOwner('sale-quotation-list-a')->assertOk();

        $productUlid = $this->createProduct(
            'Quotation Search Product',
            ['retail' => '50.0000'],
        );
        $this->giveStock($productUlid, '10');

        $created = $this->postJson('/api/sales/quotations', [
            'quotation_date' => '2026-10-07',
            'items' => [
                ['product_ulid' => $productUlid, 'quantity' => '1'],
            ],
            'notes' => 'Searchable quote',
        ], $this->idem('quotation-list-a'))
            ->assertCreated();

        $quotationUlid = (string) $created->json('ulid');
        $number = (string) $created->json('document_number');

        $this->getJson('/api/sales/quotations?q='.urlencode($number))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $quotationUlid);

        $this->getJson('/api/sales/quotations?date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $show = $this->getJson('/api/sales/quotations/'.$quotationUlid)
            ->assertOk()
            ->assertJsonPath('ulid', $quotationUlid)
            ->assertJsonCount(1, 'items');

        $this->assertNoInternalIds($show->json());

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('sale-quotation-list-b')->assertOk();

        $this->getJson('/api/sales/quotations/'.$quotationUlid)
            ->assertNotFound();

        $this->getJson('/api/sales/quotations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_quotation_requires_idempotency_key(): void
    {
        $this->signInOwner('sale-quotation-idempotency')->assertOk();

        $productUlid = $this->createProduct(
            'Quotation Idempotency Product',
            ['retail' => '20.0000'],
        );

        $this->postJson('/api/sales/quotations', [
            'items' => [
                ['product_ulid' => $productUlid, 'quantity' => '1'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertSame(0, SaleQuotation::query()->count());
    }

    /**
     * @return array<string, string>
     */
    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createProduct(string $name, array $overrides = []): string
    {
        $payload = array_merge([
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ], $overrides);

        if (isset($overrides['retail'])) {
            unset($payload['retail']);
            $payload['prices'] = [[
                'price_type' => 'retail',
                'amount' => $overrides['retail'],
            ]];
        }

        return $this->postJson('/api/products', $payload)
            ->assertCreated()
            ->json('ulid');
    }

    private function giveStock(string $productUlid, string $quantity): void
    {
        $product = Product::query()
            ->where('ulid', $productUlid)
            ->firstOrFail();

        $context = app(\App\Tenancy\TenantContext::class);

        $balance = StockBalance::query()->firstOrCreate(
            [
                'tenant_id' => $product->tenant_id,
                'warehouse_id' => $context->warehouseId(),
                'product_id' => $product->id,
            ],
            [
                'branch_id' => $context->branchId(),
                'quantity' => '0.000000',
            ],
        );

        $balance->quantity = bcadd((string) $balance->quantity, $quantity, 6);
        $balance->average_cost = '10.0000';
        $balance->stock_value = bcmul((string) $balance->quantity, '10.0000', 4);
        $balance->save();
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
