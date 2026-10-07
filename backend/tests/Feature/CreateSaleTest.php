<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 4 — sales save path.
 *
 * Server recalculates every money figure, free lines are priced at zero but
 * still consume stock, and a repeated Idempotency-Key replays instead of
 * creating a second sale.
 */
class CreateSaleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sale_totals_are_recalculated_from_the_tenant_price_list(): void
    {
        $this->signInOwner('sale-1')->assertOk();
        $product = $this->createProduct('Shampoo', ['retail' => '250.0000']);
        $this->giveStock($product, '50');

        $response = $this->postJson('/api/sales', [
            'items' => [
                ['product_ulid' => $product, 'quantity' => '2'],
            ],
        ], $this->idem('sale-1-a'))->assertCreated();

        // Client never sent a total; server derived 2 x 250.
        $response->assertJsonPath('subtotal', '500.0000');
        $response->assertJsonPath('grand_total', '500.0000');
        $response->assertJsonPath('items.0.unit_price', '250.0000');
        $response->assertJsonPath('items.0.line_total', '500.0000');
        $response->assertJsonPath('items.0.line_kind', 'sale');
        $this->assertNoInternalIds($response->json());
    }

    public function test_salesman_dropdown_data_and_sale_selection_are_tenant_scoped(): void
    {
        $this->signInOwner('sale-salesman-a')->assertOk();

        $salesman = $this->postJson('/api/party-profiles', [
            'party_types' => ['salesman'],
            'primary_type' => 'salesman',
            'code' => 'SM-A',
            'name' => 'Salesman A',
        ])->assertCreated();

        $salesmanUlid = $salesman->json('identity_ulid');
        $product = $this->createProduct('Salesman Item A', ['retail' => '25.0000']);
        $this->giveStock($product, '10');

        $this->getJson('/api/sales/salesmen')
            ->assertOk()
            ->assertJsonFragment(['ulid' => $salesmanUlid, 'name' => 'Salesman A']);

        $this->postJson('/api/sales', [
            'salesman_ulid' => $salesmanUlid,
            'items' => [
                ['product_ulid' => $product, 'quantity' => '1'],
            ],
        ], $this->idem('sale-salesman-a-1'))
            ->assertCreated()
            ->assertJsonPath('salesman.ulid', $salesmanUlid)
            ->assertJsonPath('salesman.name', 'Salesman A');

        $this->getJson('/api/sales?salesman_ulid='.$salesmanUlid)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.salesman.ulid', $salesmanUlid);

        $this->getJson('/api/sales?q='.urlencode('Salesman A'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.salesman.name', 'Salesman A');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('sale-salesman-b')->assertOk();

        $otherProduct = $this->createProduct('Salesman Item B', ['retail' => '25.0000']);
        $this->giveStock($otherProduct, '10');

        $this->postJson('/api/sales', [
            'salesman_ulid' => $salesmanUlid,
            'items' => [
                ['product_ulid' => $otherProduct, 'quantity' => '1'],
            ],
        ], $this->idem('sale-salesman-b-1'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');
    }

    public function test_inactive_salesman_cannot_be_selected_for_sale(): void
    {
        $this->signInOwner('sale-salesman-inactive')->assertOk();

        $salesman = $this->postJson('/api/party-profiles', [
            'party_types' => ['salesman'],
            'primary_type' => 'salesman',
            'code' => 'SM-INACTIVE',
            'name' => 'Inactive Salesman',
        ])->assertCreated();

        $salesmanUlid = (string) $salesman->json('identity_ulid');

        $this->patchJson('/api/party-profiles/'.$salesmanUlid, [
            'party_types' => ['salesman'],
            'primary_type' => 'salesman',
            'code' => 'SM-INACTIVE',
            'name' => 'Inactive Salesman',
            'is_active' => false,
        ])->assertOk();

        $product = $this->createProduct('Inactive Salesman Item', ['retail' => '25.0000']);
        $this->giveStock($product, '10');

        $this->getJson('/api/sales/salesmen')
            ->assertOk()
            ->assertJsonMissing(['ulid' => $salesmanUlid]);

        $this->postJson('/api/sales', [
            'salesman_ulid' => $salesmanUlid,
            'items' => [
                ['product_ulid' => $product, 'quantity' => '1'],
            ],
        ], $this->idem('sale-salesman-inactive-1'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('10.000000', $this->stockFor($product));
    }

    public function test_payment_due_requires_a_real_customer(): void
    {
        $this->signInOwner('sale-due-customer-required')->assertOk();
        $product = $this->createProduct('Due Customer Required', ['retail' => '100.0000']);
        $this->giveStock($product, '10');

        $this->postJson('/api/sales', [
            'payment_due' => true,
            'items' => [
                ['product_ulid' => $product, 'quantity' => '1'],
            ],
        ], $this->idem('sale-due-customer-required-1'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('10.000000', $this->stockFor($product));
    }

    public function test_restricted_customer_cannot_be_invoiced(): void
    {
        $this->signInOwner('sale-restricted-customer')->assertOk();
        $product = $this->createProduct('Restricted Customer Item', ['retail' => '100.0000']);
        $this->giveStock($product, '10');
        $customer = $this->createCustomer('Restricted Customer', true);

        $this->postJson('/api/sales', [
            'customer_ulid' => $customer,
            'payment_due' => true,
            'items' => [
                ['product_ulid' => $product, 'quantity' => '1'],
            ],
        ], $this->idem('sale-restricted-customer-1'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('10.000000', $this->stockFor($product));
    }

    public function test_payment_due_sale_persists_selected_customer_and_balance(): void
    {
        $this->signInOwner('sale-due-valid')->assertOk();
        $product = $this->createProduct('Valid Due Customer Item', ['retail' => '125.0000']);
        $this->giveStock($product, '10');
        $customer = $this->createCustomer('Due Customer');

        $response = $this->postJson('/api/sales', [
            'customer_ulid' => $customer,
            'payment_due' => true,
            'items' => [
                ['product_ulid' => $product, 'quantity' => '2'],
            ],
        ], $this->idem('sale-due-valid-1'))
            ->assertCreated()
            ->assertJsonPath('customer.ulid', $customer)
            ->assertJsonPath('grand_total', '250.0000')
            ->assertJsonPath('balance_due', '250.0000');

        $this->assertNoInternalIds($response->json());
    }

    public function test_client_sent_price_and_totals_are_ignored(): void
    {
        $this->signInOwner('sale-2')->assertOk();
        $product = $this->createProduct('Soap', ['retail' => '100.0000']);
        $this->giveStock($product, '50');

        $response = $this->postJson('/api/sales', [
            'subtotal' => '1.0000',
            'grand_total' => '1.0000',
            'items' => [
                ['product_ulid' => $product, 'quantity' => '3', 'unit_price' => '0.0100', 'line_total' => '0.0300'],
            ],
        ], $this->idem('sale-2-a'))->assertCreated();

        $response->assertJsonPath('grand_total', '300.0000');
        $response->assertJsonPath('items.0.unit_price', '100.0000');
    }

    public function test_replayed_idempotency_key_returns_the_same_sale(): void
    {
        $this->signInOwner('sale-3')->assertOk();
        $product = $this->createProduct('Shampoo R', ['retail' => '150.0000']);
        $this->giveStock($product, '50');

        $payload = ['items' => [['product_ulid' => $product, 'quantity' => '1']]];
        $headers = ['Idempotency-Key' => 'sale-3-replay'];

        $first = $this->postJson('/api/sales', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/sales', $payload, $headers)->assertCreated();

        $this->assertSame($first->json('ulid'), $second->json('ulid'));
        $this->assertSame(1, Sale::query()->count());
        // Stock must not move twice on a replay.
        $this->assertSame('49.000000', $this->stockFor($product));
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        $this->signInOwner('sale-4')->assertOk();
        $product = $this->createProduct('No Key', ['retail' => '50.0000']);

        $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
        ])->assertStatus(422)
            ->assertJsonPath('error.key', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_free_scheme_item_is_priced_zero_but_consumes_stock(): void
    {
        $this->signInOwner('sale-5')->assertOk();
        $paid = $this->createProduct('Bulk Item', ['retail' => '3000.0000']);
        $reward = $this->createProduct('Free Soap', ['retail' => '400.0000']);

        $this->giveStock($paid, '100');
        $this->giveStock($reward, '10');

        $scheme = $this->createScheme('Spend 5000 free soap', '5000.0000', $reward);

        $response = $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $paid, 'quantity' => '2']],
            'applied_scheme_ulids' => [$scheme],
        ], $this->idem('sale-5-a'))->assertCreated();

        // The reward is free: it must not change what the customer pays.
        $response->assertJsonPath('grand_total', '6000.0000');
        $response->assertJsonPath('items.1.line_kind', 'free_scheme');
        $response->assertJsonPath('items.1.unit_price', '0.0000');
        $response->assertJsonPath('items.1.line_total', '0.0000');
        $response->assertJsonPath('items.1.quantity', '1.000000');
        $this->assertSame($scheme, $response->json('items.1.sale_scheme.ulid'));

        // Stock still goes out for the free item.
        $this->assertSame('9.000000', $this->stockFor($reward));
        $this->assertSame('98.000000', $this->stockFor($paid));
    }

    public function test_scheme_is_not_added_when_the_salesman_skips_it(): void
    {
        $this->signInOwner('sale-6')->assertOk();
        $paid = $this->createProduct('Skipped Bulk', ['retail' => '3000.0000']);
        $reward = $this->createProduct('Skipped Reward', ['retail' => '400.0000']);

        $this->giveStock($paid, '100');
        $this->giveStock($reward, '10');
        $this->createScheme('Spend 5000 free reward', '5000.0000', $reward);

        $response = $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $paid, 'quantity' => '2']],
        ], $this->idem('sale-6-a'))->assertCreated();

        $this->assertCount(1, $response->json('items'));
        $response->assertJsonPath('grand_total', '6000.0000');
        // Reward untouched — Skip means the free stock is not consumed.
        $this->assertSame('10.000000', $this->stockFor($reward));
    }

    public function test_scheme_applied_below_its_threshold_is_rejected(): void
    {
        $this->signInOwner('sale-7')->assertOk();
        $paid = $this->createProduct('Small Cart', ['retail' => '100.0000']);
        $reward = $this->createProduct('Too Early Reward', ['retail' => '400.0000']);

        $this->giveStock($paid, '100');
        $this->giveStock($reward, '10');
        $scheme = $this->createScheme('Spend 5000', '5000.0000', $reward);

        $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $paid, 'quantity' => '1']],
            'applied_scheme_ulids' => [$scheme],
        ], $this->idem('sale-7-a'))->assertStatus(422);

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('100.000000', $this->stockFor($paid));
    }

    public function test_free_packaging_line_is_priced_zero_and_consumes_stock(): void
    {
        $this->signInOwner('sale-8')->assertOk();
        $paid = $this->createProduct('Cart Item', ['retail' => '500.0000']);
        $bag = $this->createProduct('Shopping Bag', [
            'retail' => '20.0000',
            'is_packaging' => true,
            'max_free_qty_per_sale' => '2.000000',
        ]);

        $this->giveStock($paid, '50');
        $this->giveStock($bag, '500');

        $response = $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $paid, 'quantity' => '1']],
            'free_lines' => [[
                'product_ulid' => $bag,
                'qty' => '1',
                'line_kind' => 'free_packaging',
            ]],
        ], $this->idem('sale-8-a'))->assertCreated();

        $response->assertJsonPath('grand_total', '500.0000');
        $response->assertJsonPath('items.1.line_kind', 'free_packaging');
        $response->assertJsonPath('items.1.line_total', '0.0000');
        $this->assertSame('499.000000', $this->stockFor($bag));
    }

    public function test_scheme_reward_without_enough_stock_is_rejected(): void
    {
        $this->signInOwner('sale-9')->assertOk();
        $paid = $this->createProduct('Deep Cart', ['retail' => '3000.0000']);
        $reward = $this->createProduct('Scarce Reward', ['retail' => '400.0000']);

        $this->giveStock($paid, '100');
        // No stock at all for the reward, and negative stock is not allowed.
        $scheme = $this->createScheme('Spend 5000 scarce', '5000.0000', $reward);

        $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $paid, 'quantity' => '2']],
            'applied_scheme_ulids' => [$scheme],
        ], $this->idem('sale-9-a'))->assertStatus(422);

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('100.000000', $this->stockFor($paid));
    }

    public function test_sale_cannot_smuggle_a_free_line_through_items(): void
    {
        $this->signInOwner('sale-10')->assertOk();
        $product = $this->createProduct('Smuggled', ['retail' => '100.0000']);
        $this->giveStock($product, '10');

        $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product,
                'quantity' => '1',
                'line_kind' => 'free_scheme',
            ]],
        ], $this->idem('sale-10-a'))->assertStatus(422)
            // BluePOS uses its own error envelope; dotted keys need array access.
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $fields = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $product,
                'quantity' => '1',
                'line_kind' => 'free_scheme',
            ]],
        ], $this->idem('sale-10-b'))->assertStatus(422)->json('error.fields');

        $this->assertArrayHasKey('items.0.line_kind', $fields);

        // Nothing was sold and no stock moved.
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('10.000000', $this->stockFor($product));
    }

    public function test_product_without_retail_price_is_rejected(): void
    {
        $this->signInOwner('sale-11')->assertOk();
        $product = $this->createProduct('No Price');

        $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
        ], $this->idem('sale-11-a'))->assertStatus(422);

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_sale_scoped_to_the_session_tenant_and_branch(): void
    {
        $this->signInOwner('sale-12a')->assertOk();
        $product = $this->createProduct('Isolated Item', ['retail' => '100.0000']);
        $this->giveStock($product, '10');

        $saleUlid = $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
        ], $this->idem('sale-12-a'))->assertCreated()->json('ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('sale-12b')->assertOk();

        $this->getJson('/api/sales/'.$saleUlid)->assertNotFound();
        $this->getJson('/api/sales')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sales_list_filters_by_date_range(): void
    {
        $this->signInOwner('sale-14')->assertOk();
        $product = $this->createProduct('Dated Sale', ['retail' => '10.0000']);
        $this->giveStock($product, '50');

        $this->postJson('/api/sales', [
            'sale_date' => '2026-09-01',
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
        ], $this->idem('sale-14-a'))->assertCreated();

        $this->postJson('/api/sales', [
            'sale_date' => '2026-10-02',
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
        ], $this->idem('sale-14-b'))->assertCreated();

        $this->getJson('/api/sales?date_from=2026-10-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/sales?date_from=2026-09-01&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_posted_sales_search_filters_customer_salesman_and_paginates(): void
    {
        $this->signInOwner('sale-history-filters')->assertOk();

        $product = $this->createProduct('History Filter Item', ['retail' => '25.0000']);
        $this->giveStock($product, '20');

        $customerA = $this->createCustomer('History Customer Alpha');
        $customerB = $this->createCustomer('History Customer Beta');

        $salesmanA = $this->postJson('/api/party-profiles', [
            'party_type' => 'salesman',
            'name' => 'History Salesman Alpha',
            'mobile' => '03000000001',
        ])->assertCreated()->json('ulid');

        $salesmanB = $this->postJson('/api/party-profiles', [
            'party_type' => 'salesman',
            'name' => 'History Salesman Beta',
            'mobile' => '03000000002',
        ])->assertCreated()->json('ulid');

        $saleA = $this->postJson('/api/sales', [
            'customer_ulid' => $customerA,
            'salesman_ulid' => $salesmanA,
            'sale_date' => '2026-10-01',
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
        ], $this->idem('sale-history-filter-a'))->assertCreated()->json('ulid');

        $saleB = $this->postJson('/api/sales', [
            'customer_ulid' => $customerB,
            'salesman_ulid' => $salesmanB,
            'sale_date' => '2026-10-02',
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
        ], $this->idem('sale-history-filter-b'))->assertCreated()->json('ulid');

        $this->postJson('/api/sales', [
            'customer_ulid' => $customerA,
            'salesman_ulid' => $salesmanB,
            'sale_date' => '2026-10-03',
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
        ], $this->idem('sale-history-filter-c'))->assertCreated();

        $this->getJson('/api/sales?q='.urlencode('History Customer Beta'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $saleB);

        $this->getJson('/api/sales?q='.urlencode('History Salesman Alpha'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $saleA);

        $this->getJson('/api/sales?customer_ulid='.$customerA)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/sales?salesman_ulid='.$salesmanB)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $firstPage = $this->getJson('/api/sales?status=posted&per_page=2&page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $secondPage = $this->getJson('/api/sales?status=posted&per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);

        $pageUlids = array_merge(
            collect($firstPage->json('data'))->pluck('ulid')->all(),
            collect($secondPage->json('data'))->pluck('ulid')->all(),
        );

        $this->assertCount(3, array_unique($pageUlids));
    }

    public function test_posted_sales_search_returns_payment_summary(): void
    {
        $this->signInOwner('sale-history-summary')->assertOk();
        $product = $this->createProduct('History Search Item', ['retail' => '100.0000']);
        $this->giveStock($product, '10');

        $sale = $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
        ], $this->idem('sale-history-summary-create'))->assertCreated();

        $saleUlid = (string) $sale->json('ulid');
        $documentNumber = (string) $sale->json('document_number');

        $saleModel = Sale::query()->where('ulid', $saleUlid)->firstOrFail();
        $context = app(TenantContext::class);

        SalePayment::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'sale_id' => $saleModel->id,
            'account_id' => null,
            'method' => 'cash',
            'reference' => null,
            'amount' => '40.0000',
            'journal_entry_ulid' => null,
            'idempotency_key' => 'sale-history-summary-payment',
            'created_by' => $context->userId(),
        ]);

        $this->getJson('/api/sales?q='.urlencode($documentNumber))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $saleUlid)
            ->assertJsonPath('data.0.paid_amount', '40.0000')
            ->assertJsonPath('data.0.balance_due', '60.0000');

        $this->getJson('/api/sales/'.$saleUlid)
            ->assertOk()
            ->assertJsonPath('paid_amount', '40.0000')
            ->assertJsonPath('balance_due', '60.0000');
    }

    public function test_posted_sales_list_and_detail_are_scoped_to_active_branch(): void
    {
        $this->signInOwner('sale-history-branch')->assertOk();

        $context = app(TenantContext::class);

        $otherBranch = Branch::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'OTHER',
            'name' => 'Other Branch',
            'status' => 'active',
            'is_default' => false,
        ]);

        $otherWarehouse = Warehouse::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $otherBranch->id,
            'code' => 'OTHER-WH',
            'name' => 'Other Warehouse',
            'status' => 'active',
            'is_default' => true,
        ]);

        $foreignBranchSale = Sale::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $otherBranch->id,
            'warehouse_id' => $otherWarehouse->id,
            'customer_id' => null,
            'document_number' => 'S-OTHER-BRANCH-001',
            'status' => 'posted',
            'sale_date' => '2026-10-05',
            'price_type' => 'retail',
            'subtotal' => '10.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'grand_total' => '10.0000',
            'notes' => null,
            'idempotency_key' => 'sale-history-other-branch',
            'created_by' => $context->userId(),
            'updated_by' => $context->userId(),
            'posted_at' => now(),
        ]);

        $this->getJson('/api/sales?q=S-OTHER-BRANCH-001')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/sales/'.$foreignBranchSale->ulid)
            ->assertNotFound();
    }

    public function test_sale_from_another_tenant_product_is_not_found(): void
    {
        $this->signInOwner('sale-13a')->assertOk();
        $product = $this->createProduct('Foreign Item', ['retail' => '100.0000']);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('sale-13b')->assertOk();

        $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
        ], $this->idem('sale-13-b'))->assertNotFound();
    }

    /**
     * `withHeaders()` mutates and returns the test case, so build the header
     * array separately to pass as the request argument.
     *
     * @return array<string, string>
     */
    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProduct(string $name, array $overrides = []): string
    {
        $payload = array_merge([
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ], $overrides);

        if (isset($overrides['retail'])) {
            unset($payload['retail']);
            $payload['prices'] = [['price_type' => 'retail', 'amount' => $overrides['retail']]];
        }

        $response = $this->postJson('/api/products', $payload)->assertCreated();

        return $response->json('ulid');
    }

    private function createCustomer(string $name, bool $invoiceRestricted = false): string
    {
        $context = app(TenantContext::class);

        return Customer::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'CUS-'.strtoupper(substr(md5($name.microtime(true)), 0, 8)),
            'name' => $name,
            'is_active' => true,
            'invoice_restricted' => $invoiceRestricted,
            'created_by' => $context->userId(),
            'updated_by' => $context->userId(),
        ])->ulid;
    }

    private function createScheme(string $name, string $minAmount, string $rewardProductUlid): string
    {
        return $this->postJson('/api/sale-schemes', [
            'name' => $name,
            'apply_mode' => 'salesman',
            'min_sale_amount' => $minAmount,
            'reward_product_ulid' => $rewardProductUlid,
            'max_reward_qty' => '1.000000',
        ])->assertCreated()->json('ulid');
    }

    /**
     * Put stock in through a real opening balance so average cost and the
     * balance row exist the way production would have them.
     */
    private function giveStock(string $productUlid, string $quantity): void
    {
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

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

    private function stockFor(string $productUlid): string
    {
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

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
