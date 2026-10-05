<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Branch;
use App\Models\BusinessSetting;
use App\Models\Product;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\SaleReturnRefund;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SaleReturnTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sales_return_posts_stock_back_without_mutating_original_sale(): void
    {
        $this->signInOwner('sr-life')->assertOk();

        $product = $this->createProduct('Return Cola', '100.0000');
        $this->giveStock($product, '10.000000');

        $sale = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product,
                'quantity' => '4.000000',
            ]],
        ], $this->idem('sr-life-sale'))
            ->assertCreated()
            ->assertJsonPath('grand_total', '400.0000');

        $saleUlid = (string) $sale->json('ulid');
        $this->assertSame('6.000000', $this->stockFor($product));

        $returnable = $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->assertJsonPath('data.0.original_quantity', '4.000000')
            ->assertJsonPath('data.0.remaining_returnable_quantity', '4.000000')
            ->assertJsonPath('data.0.unit_price', '100.0000');

        $saleItemUlid = (string) $returnable->json('data.0.sale_item_ulid');
        $this->assertNoInternalIds($returnable->json());

        $return = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
            'reason' => 'Customer returned item',
        ], $this->idem('sr-life-return'))
            ->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('document_number', 'SR-000001');

        $returnUlid = (string) $return->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'sale_item_ulid' => $saleItemUlid,
            'quantity' => '1.000000',
        ])->assertCreated()
            ->assertJsonPath('gross_amount', '100.0000')
            ->assertJsonPath('line_total', '100.0000');

        $posted = $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertOk()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('grand_total', '100.0000')
            ->assertJsonPath('refund_amount', '0.0000');

        $this->assertNoInternalIds($posted->json());
        $this->assertSame('7.000000', $this->stockFor($product));
        $balance = StockBalance::query()
            ->where('warehouse_id', app(TenantContext::class)->warehouseId())
            ->where('product_id', Product::query()->where('ulid', $product)->value('id'))
            ->firstOrFail();
        $this->assertSame('10.0000', (string) $balance->average_cost);
        $this->assertSame('70.0000', (string) $balance->stock_value);

        $movement = StockMovement::query()
            ->where('movement_type', 'sale_return')
            ->where('reference_ulid', $returnUlid)
            ->firstOrFail();

        $this->assertSame('1.000000', (string) $movement->quantity);
        $this->assertSame('sale_return', $movement->reference_type);
        $this->assertSame('sale_return:'.$returnUlid.':'.$movement->reference_line_ulid, $movement->idempotency_key);

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')->assertOk();
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('movement_type', 'sale_return')
                ->where('reference_ulid', $returnUlid)
                ->count(),
        );

        $original = Sale::query()->where('ulid', $saleUlid)->firstOrFail();
        $this->assertSame('posted', $original->status->value);
        $this->assertSame('400.0000', (string) $original->grand_total);

        $this->patchJson('/api/sales-returns/'.$returnUlid, [
            'notes' => 'No edit after post',
        ])->assertStatus(422)->assertJsonPath('error.key', 'DOCUMENT_POSTED');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'sale_item_ulid' => $saleItemUlid,
            'quantity' => '1.000000',
        ])->assertStatus(422);
    }

    public function test_partial_multiple_sales_returns_and_over_return_are_prevented(): void
    {
        $this->signInOwner('sr-multi')->assertOk();

        $product = $this->createProduct('Partial Return Product', '25.0000');
        $this->giveStock($product, '20.000000');

        $saleUlid = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product,
                'quantity' => '10.000000',
            ]],
        ], $this->idem('sr-multi-sale'))->assertCreated()->json('ulid');

        $lineUlid = $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->json('data.0.sale_item_ulid');

        $first = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('sr-multi-1'))->assertCreated()->json('ulid');

        $this->postJson('/api/sales-returns/'.$first.'/lines', [
            'sale_item_ulid' => $lineUlid,
            'quantity' => '4.000000',
        ])->assertCreated();
        $this->postJson('/api/sales-returns/'.$first.'/post')->assertOk();

        $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->assertJsonPath('data.0.already_returned_quantity', '4.000000')
            ->assertJsonPath('data.0.remaining_returnable_quantity', '6.000000');

        $second = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('sr-multi-2'))->assertCreated()->json('ulid');

        $this->postJson('/api/sales-returns/'.$second.'/lines', [
            'sale_item_ulid' => $lineUlid,
            'quantity' => '6.000000',
        ])->assertCreated();
        $this->postJson('/api/sales-returns/'.$second.'/post')->assertOk();

        $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->assertJsonPath('data.0.remaining_returnable_quantity', '0.000000');

        $third = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('sr-multi-3'))->assertCreated()->json('ulid');

        $this->postJson('/api/sales-returns/'.$third.'/lines', [
            'sale_item_ulid' => $lineUlid,
            'quantity' => '1.000000',
        ])->assertStatus(422);

        $this->assertSame('20.000000', $this->stockFor($product));
    }

    public function test_posted_return_can_be_refunded_idempotently_and_balanced(): void
    {
        $this->signInOwner('sr-refund')->assertOk();
        $this->configureAccounts();

        $product = $this->createProduct('Refund Product', '100.0000');
        $this->giveStock($product, '10.000000');

        $saleUlid = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('sr-refund-sale'))->assertCreated()->json('ulid');

        $this->postJson('/api/sales/'.$saleUlid.'/payments', [
            'amount' => '200.0000',
            'method' => 'cash',
        ], $this->idem('sr-refund-payment'))->assertCreated();

        $saleItemUlid = $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->json('data.0.sale_item_ulid');

        $returnUlid = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('sr-refund-return'))->assertCreated()->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'sale_item_ulid' => $saleItemUlid,
            'quantity' => '1.000000',
        ])->assertCreated();

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertOk()
            ->assertJsonPath('grand_total', '100.0000');

        $first = $this->postJson('/api/sales-returns/'.$returnUlid.'/refunds', [
            'amount' => '60.0000',
            'method' => 'cash',
            'reference' => 'CASH-RET-1',
        ], $this->idem('sr-refund-cash'))
            ->assertCreated()
            ->assertJsonPath('amount', '60.0000')
            ->assertJsonPath('method', 'cash');

        $second = $this->postJson('/api/sales-returns/'.$returnUlid.'/refunds', [
            'amount' => '60.0000',
            'method' => 'cash',
            'reference' => 'CASH-RET-1',
        ], $this->idem('sr-refund-cash'))->assertCreated();

        $this->assertSame($first->json('ulid'), $second->json('ulid'));
        $this->assertSame(1, SaleReturnRefund::query()->count());

        $this->getJson('/api/sales-returns/'.$returnUlid)
            ->assertOk()
            ->assertJsonPath('refund_amount', '60.0000')
            ->assertJsonPath('balance_due', '40.0000');

        $refund = SaleReturnRefund::query()->firstOrFail();
        $journal = JournalEntry::query()
            ->where('ulid', $refund->journal_entry_ulid)
            ->with('lines')
            ->firstOrFail();

        $debit = '0.0000';
        $credit = '0.0000';
        foreach ($journal->lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 4);
            $credit = bcadd($credit, (string) $line->credit, 4);
        }

        $this->assertSame(0, bccomp($debit, '60.0000', 4));
        $this->assertSame(0, bccomp($credit, '60.0000', 4));
        $this->assertSame('posted', $journal->status->value);

        $this->postJson('/api/sales-returns/'.$returnUlid.'/refunds', [
            'amount' => '41.0000',
            'method' => 'cash',
        ], $this->idem('sr-refund-over'))->assertStatus(422);

        $this->postJson('/api/sales-returns/'.$returnUlid.'/refunds', [
            'amount' => '40.0000',
            'method' => 'cash',
        ], $this->idem('sr-refund-rest'))->assertCreated();

        $this->getJson('/api/sales-returns/'.$returnUlid)
            ->assertOk()
            ->assertJsonPath('refund_amount', '100.0000')
            ->assertJsonPath('balance_due', '0.0000');

        $this->getJson('/api/sales/'.$saleUlid)
            ->assertOk()
            ->assertJsonPath('returned_amount', '100.0000')
            ->assertJsonPath('net_sale_total', '100.0000')
            ->assertJsonPath('balance_due', '0.0000');
    }

    public function test_refund_method_cannot_exceed_original_collected_method_amount(): void
    {
        $this->signInOwner('sr-refund-method')->assertOk();
        $this->configureAccounts();

        $product = $this->createProduct('Refund Method Product', '100.0000');
        $this->giveStock($product, '10.000000');

        $saleUlid = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product,
                'quantity' => '4.000000',
            ]],
        ], $this->idem('sr-refund-method-sale'))->assertCreated()->json('ulid');

        $this->postJson('/api/sales/'.$saleUlid.'/payments', [
            'amount' => '50.0000',
            'method' => 'cash',
        ], $this->idem('sr-refund-method-pay'))->assertCreated();

        $this->postJson('/api/sales/'.$saleUlid.'/payments', [
            'amount' => '200.0000',
            'method' => 'card',
        ], $this->idem('sr-refund-method-card'))->assertCreated();

        $saleItemUlid = $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->json('data.0.sale_item_ulid');

        $returnUlid = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('sr-refund-method-return'))->assertCreated()->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'sale_item_ulid' => $saleItemUlid,
            'quantity' => '3.000000',
        ])->assertCreated();

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertOk()
            ->assertJsonPath('grand_total', '300.0000')
            ->assertJsonPath('balance_due', '150.0000');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/refunds', [
            'amount' => '60.0000',
            'method' => 'cash',
        ], $this->idem('sr-refund-method-over'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/refunds', [
            'amount' => '50.0000',
            'method' => 'cash',
        ], $this->idem('sr-refund-method-ok'))->assertCreated();

        $this->assertSame(1, SaleReturnRefund::query()->count());
    }

    public function test_posted_return_reduces_future_sale_collection_limit(): void
    {
        $this->signInOwner('sr-net-due')->assertOk();
        $this->configureAccounts();

        $product = $this->createProduct('Net Due Product', '100.0000');
        $this->giveStock($product, '10.000000');

        $saleUlid = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('sr-net-due-sale'))->assertCreated()->json('ulid');

        $saleItemUlid = $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')
            ->assertOk()
            ->json('data.0.sale_item_ulid');

        $returnUlid = $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('sr-net-due-return'))->assertCreated()->json('ulid');

        $this->postJson('/api/sales-returns/'.$returnUlid.'/lines', [
            'sale_item_ulid' => $saleItemUlid,
            'quantity' => '1.000000',
        ])->assertCreated();

        $this->postJson('/api/sales-returns/'.$returnUlid.'/post')
            ->assertOk()
            ->assertJsonPath('balance_due', '0.0000');

        $this->getJson('/api/sales/'.$saleUlid)
            ->assertOk()
            ->assertJsonPath('returned_amount', '100.0000')
            ->assertJsonPath('net_sale_total', '100.0000')
            ->assertJsonPath('balance_due', '100.0000');

        $this->postJson('/api/sales/'.$saleUlid.'/payments', [
            'amount' => '101.0000',
            'method' => 'cash',
        ], $this->idem('sr-net-due-over'))->assertStatus(422);

        $this->postJson('/api/sales/'.$saleUlid.'/payments', [
            'amount' => '100.0000',
            'method' => 'cash',
        ], $this->idem('sr-net-due-ok'))->assertCreated();

        $this->getJson('/api/sales?due_only=1&status=posted&q='.urlencode((string) $saleUlid))
            ->assertOk();
    }

    public function test_sales_returns_are_scoped_to_active_branch_and_warehouse(): void
    {
        $this->signInOwner('sr-branch')->assertOk();
        $context = app(TenantContext::class);

        $otherBranch = Branch::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'SR-B2',
            'name' => 'Sales Return Branch 2',
            'status' => 'active',
            'is_default' => false,
        ]);

        $otherWarehouse = Warehouse::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $otherBranch->id,
            'code' => 'SR-W2',
            'name' => 'Sales Return Warehouse 2',
            'status' => 'active',
            'is_default' => true,
        ]);

        $foreignBranchSale = Sale::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $otherBranch->id,
            'warehouse_id' => $otherWarehouse->id,
            'customer_id' => null,
            'salesman_party_profile_id' => null,
            'document_number' => 'SAL-SR-B2-001',
            'status' => 'posted',
            'sale_date' => '2026-10-05',
            'price_type' => 'retail',
            'subtotal' => '10.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'grand_total' => '10.0000',
            'notes' => null,
            'idempotency_key' => 'sr-branch-sale',
            'created_by' => $context->userId(),
            'posted_at' => now(),
        ]);

        $this->getJson('/api/sales/'.$foreignBranchSale->ulid.'/returnable-lines')
            ->assertNotFound();

        $this->postJson('/api/sales-returns', [
            'sale_ulid' => $foreignBranchSale->ulid,
        ], $this->idem('sr-branch-return'))
            ->assertNotFound();
    }

    public function test_sales_return_creation_is_idempotent_and_tenant_scoped(): void
    {
        $this->signInOwner('sr-iso-a')->assertOk();

        $product = $this->createProduct('Tenant A Return Product', '30.0000');
        $this->giveStock($product, '5.000000');

        $saleUlid = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product,
                'quantity' => '1.000000',
            ]],
        ], $this->idem('sr-iso-sale'))->assertCreated()->json('ulid');

        $payload = ['sale_ulid' => $saleUlid];

        $first = $this->postJson('/api/sales-returns', $payload, $this->idem('sr-iso-return'))
            ->assertCreated();
        $second = $this->postJson('/api/sales-returns', $payload, $this->idem('sr-iso-return'))
            ->assertCreated();

        $this->assertSame($first->json('ulid'), $second->json('ulid'));

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('sr-iso-b')->assertOk();

        $this->getJson('/api/sales/'.$saleUlid.'/returnable-lines')->assertNotFound();

        $this->postJson('/api/sales-returns', [
            'sale_ulid' => $saleUlid,
        ], $this->idem('sr-iso-foreign'))
            ->assertNotFound();

        $this->getJson('/api/sales-returns/'.$first->json('ulid'))->assertNotFound();
    }

    /** @return array{cash:Account,clearing:Account} */
    private function configureAccounts(): array
    {
        $cash = $this->leafAccount('SR TEST CASH', '9401');
        $clearing = $this->leafAccount('SR TEST CLEARING', '9402');

        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update([
                'default_cash_account_id' => $cash->id,
                'sales_clearing_account_id' => $clearing->id,
            ]);

        return ['cash' => $cash, 'clearing' => $clearing];
    }

    private function leafAccount(string $name, string $code): Account
    {
        $context = app(TenantContext::class);
        $type = \App\Models\AccountType::query()
            ->forTenant($context->tenantId())
            ->orderBy('id')
            ->firstOrFail();

        return Account::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => $code,
            'name' => $name,
            'account_type_id' => $type->id,
            'is_active' => true,
            'created_by' => $context->userId(),
        ]);
    }

    private function createProduct(string $name, string $retail): string
    {
        return $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'prices' => [[
                'price_type' => 'retail',
                'amount' => $retail,
            ]],
        ])->assertCreated()->json('ulid');
    }

    private function giveStock(string $productUlid, string $quantity): void
    {
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        $balance = StockBalance::query()->firstOrCreate(
            [
                'tenant_id' => $product->tenant_id,
                'warehouse_id' => app(\App\Tenancy\TenantContext::class)->warehouseId(),
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

    private function stockFor(string $productUlid): string
    {
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        return (string) StockBalance::query()
            ->where('warehouse_id', app(\App\Tenancy\TenantContext::class)->warehouseId())
            ->where('product_id', $product->id)
            ->value('quantity');
    }

    /** @return array<string,string> */
    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
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
