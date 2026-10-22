<?php

namespace Tests\Feature;

use App\Authz\PermissionService;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BusinessSetting;
use App\Models\DocumentRevision;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SaleAmendmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_posted_sale_can_be_amended_repeatedly_without_mutating_original_rows(): void
    {
        $this->signInOwner('sale-amend-basic')->assertOk();

        $productUlid = $this->createProduct('Amendable Item', '100.0000');
        $this->giveStock($productUlid, '10.000000', '20.0000');

        $created = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $productUlid,
                'quantity' => '2.000000',
            ]],
        ], $this->idem('sale-amend-create'))
            ->assertCreated()
            ->assertJsonPath('grand_total', '200.0000');

        $saleUlid = (string) $created->json('ulid');
        $sale = Sale::query()->where('ulid', $saleUlid)->firstOrFail();
        $originalItem = SaleItem::query()->where('sale_id', $sale->id)->firstOrFail();

        $first = $this->patchJson('/api/sales/'.$saleUlid.'/amend', [
            'items' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $created->json('items.0.unit.ulid'),
                'quantity' => '3.000000',
            ]],
            'revision_reason' => 'Customer added one more item',
        ], $this->idem('sale-amend-r1'))
            ->assertOk()
            ->assertJsonPath('ulid', $saleUlid)
            ->assertJsonPath('document_number', $created->json('document_number'))
            ->assertJsonPath('grand_total', '300.0000')
            ->assertJsonPath('revision_number', 1)
            ->assertJsonPath('is_amended', true)
            ->assertJsonPath('items.0.quantity', '3.000000');

        $this->assertSame('200.0000', (string) $sale->fresh()->grand_total);
        $this->assertSame('2.000000', (string) $originalItem->fresh()->quantity);
        $this->assertSame('7.000000', $this->stockFor($productUlid));
        $this->assertSame(
            '-1.000000',
            (string) StockMovement::query()
                ->where('reference_type', 'sale_amendment')
                ->where('reference_ulid', $saleUlid)
                ->value('quantity'),
        );

        $this->patchJson('/api/sales/'.$saleUlid.'/amend', [
            'items' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $created->json('items.0.unit.ulid'),
                'quantity' => '3.000000',
            ]],
            'revision_reason' => 'Duplicate retry',
        ], $this->idem('sale-amend-r1'))
            ->assertOk()
            ->assertJsonPath('revision_number', 1);

        $this->assertSame(
            1,
            DocumentRevision::query()
                ->where('document_type', 'sale_invoice')
                ->where('document_ulid', $saleUlid)
                ->count(),
        );
        $this->assertSame('7.000000', $this->stockFor($productUlid));

        $second = $this->patchJson('/api/sales/'.$saleUlid.'/amend', [
            'items' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $created->json('items.0.unit.ulid'),
                'quantity' => '1.000000',
            ]],
            'revision_reason' => 'Corrected excessive quantity',
        ], $this->idem('sale-amend-r2'))
            ->assertOk()
            ->assertJsonPath('grand_total', '100.0000')
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('items.0.quantity', '1.000000');

        $this->assertSame('9.000000', $this->stockFor($productUlid));
        $this->assertSame('200.0000', (string) $sale->fresh()->grand_total);
        $this->assertSame('2.000000', (string) $originalItem->fresh()->quantity);
        $this->assertSame(
            2,
            DocumentRevision::query()
                ->where('document_type', 'sale_invoice')
                ->where('document_ulid', $saleUlid)
                ->count(),
        );

        $this->getJson('/api/sales/'.$saleUlid)
            ->assertOk()
            ->assertJsonPath('grand_total', '100.0000')
            ->assertJsonPath('revision_number', 2)
            ->assertJsonPath('items.0.quantity', '1.000000');

        $this->assertNoInternalIds($first->json());
        $this->assertNoInternalIds($second->json());
    }

    public function test_sale_amendment_requires_explicit_permission(): void
    {
        $this->signInOwner('sale-amend-permission')->assertOk();

        $productUlid = $this->createProduct('Protected Amend Item', '25.0000');
        $this->giveStock($productUlid, '5.000000', '10.0000');

        $sale = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $productUlid,
                'quantity' => '1.000000',
            ]],
        ], $this->idem('sale-amend-permission-create'))
            ->assertCreated();

        $permission = Permission::query()
            ->where('key', 'sales.amend_posted')
            ->firstOrFail();
        $membership = app(TenantContext::class)->membership();

        foreach ($membership->roles()->get() as $role) {
            $role->permissions()->detach($permission->id);
        }

        app(PermissionService::class)->clearCache();

        $this->patchJson('/api/sales/'.$sale->json('ulid').'/amend', [
            'items' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $sale->json('items.0.unit.ulid'),
                'quantity' => '2.000000',
            ]],
            'revision_reason' => 'Should be denied',
        ], $this->idem('sale-amend-permission-denied'))
            ->assertForbidden();

        $this->assertSame(0, DocumentRevision::query()->count());
        $this->assertSame('4.000000', $this->stockFor($productUlid));
    }

    public function test_sale_amendment_cannot_reduce_total_below_collected_payments(): void
    {
        $this->signInOwner('sale-amend-paid-floor')->assertOk();

        $productUlid = $this->createProduct('Paid Floor Item', '100.0000');
        $this->giveStock($productUlid, '5.000000', '15.0000');

        $response = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $productUlid,
                'quantity' => '1.000000',
            ]],
        ], $this->idem('sale-amend-paid-floor-create'))
            ->assertCreated();

        $sale = Sale::query()->where('ulid', $response->json('ulid'))->firstOrFail();
        $context = app(TenantContext::class);

        SalePayment::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'sale_id' => $sale->id,
            'account_id' => null,
            'method' => 'cash',
            'reference' => null,
            'amount' => '80.0000',
            'journal_entry_ulid' => null,
            'idempotency_key' => 'sale-amend-paid-floor-payment',
            'created_by' => $context->userId(),
        ]);

        $this->patchJson('/api/sales/'.$sale->ulid.'/amend', [
            'items' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $response->json('items.0.unit.ulid'),
                'quantity' => '1.000000',
                'unit_price_override' => '50.0000',
            ]],
            'revision_reason' => 'Would create an overpayment',
        ], $this->idem('sale-amend-paid-floor-r1'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'SALE_AMENDMENT_BELOW_PAID');

        $this->assertSame(0, DocumentRevision::query()->count());
        $this->assertSame('4.000000', $this->stockFor($productUlid));
    }

    public function test_sale_amendment_reverses_previous_effective_journal_and_posts_new_effective_journal(): void
    {
        $this->signInOwner('sale-amend-accounting')->assertOk();
        $this->configureSalesAccounting();

        $productUlid = $this->createProduct('Accounting Amend Item', '100.0000');
        $this->giveStock($productUlid, '10.000000', '25.0000');

        $created = $this->postJson('/api/sales', [
            'items' => [[
                'product_ulid' => $productUlid,
                'quantity' => '1.000000',
            ]],
        ], $this->idem('sale-amend-accounting-create'))
            ->assertCreated()
            ->assertJsonPath('grand_total', '100.0000');

        $sale = Sale::query()->where('ulid', $created->json('ulid'))->firstOrFail();

        $originalJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE)
            ->where('document_id', $sale->id)
            ->with('lines')
            ->firstOrFail();

        $this->patchJson('/api/sales/'.$sale->ulid.'/amend', [
            'items' => [[
                'product_ulid' => $productUlid,
                'unit_ulid' => $created->json('items.0.unit.ulid'),
                'quantity' => '2.000000',
            ]],
            'revision_reason' => 'Increase quantity after posting',
        ], $this->idem('sale-amend-accounting-r1'))
            ->assertOk()
            ->assertJsonPath('grand_total', '200.0000')
            ->assertJsonPath('revision_number', 1);

        $reversal = JournalEntry::query()
            ->where('reverses_journal_entry_id', $originalJournal->id)
            ->with('lines')
            ->firstOrFail();

        $amendmentJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_SALE_INVOICE_AMENDMENT)
            ->where('document_id', $sale->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertBalanced($reversal);
        $this->assertBalanced($amendmentJournal);

        foreach ($originalJournal->lines as $originalLine) {
            $reversedLine = $reversal->lines
                ->first(fn ($line) => (int) $line->account_id === (int) $originalLine->account_id);

            $this->assertNotNull($reversedLine);
            $this->assertSame((string) $originalLine->credit, (string) $reversedLine->debit);
            $this->assertSame((string) $originalLine->debit, (string) $reversedLine->credit);
        }

        $this->assertSame('8.000000', $this->stockFor($productUlid));
        $cogsLine = $amendmentJournal->lines
            ->firstWhere('description', 'Cost of goods sold');

        $this->assertNotNull($cogsLine);
        $this->assertSame('50.0000', (string) $cogsLine->debit);
    }

    private function configureSalesAccounting(): void
    {
        $clearing = $this->leafAccount('AMEND SALES CLEARING', 'AM-SC');
        $revenue = $this->leafAccount('AMEND SALES REVENUE', 'AM-SR');
        $inventory = $this->leafAccount('AMEND INVENTORY', 'AM-INV');
        $cogs = $this->leafAccount('AMEND COGS', 'AM-COGS');

        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update([
                'sales_clearing_account_id' => $clearing->id,
                'sales_revenue_account_id' => $revenue->id,
                'inventory_account_id' => $inventory->id,
                'cogs_account_id' => $cogs->id,
            ]);
    }

    private function leafAccount(string $name, string $code): Account
    {
        $context = app(TenantContext::class);
        $type = AccountType::query()
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

    private function createProduct(string $name, string $retailPrice): string
    {
        return $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'prices' => [[
                'price_type' => 'retail',
                'amount' => $retailPrice,
            ]],
        ])->assertCreated()->json('ulid');
    }

    private function giveStock(
        string $productUlid,
        string $quantity,
        string $averageCost,
    ): void {
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();
        $context = app(TenantContext::class);

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
        $balance->average_cost = $averageCost;
        $balance->stock_value = bcmul((string) $balance->quantity, $averageCost, 4);
        $balance->save();
    }

    private function stockFor(string $productUlid): string
    {
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        return (string) StockBalance::query()
            ->where('tenant_id', $product->tenant_id)
            ->where('warehouse_id', app(TenantContext::class)->warehouseId())
            ->where('product_id', $product->id)
            ->value('quantity');
    }

    private function assertBalanced(JournalEntry $journal): void
    {
        $debit = '0.0000';
        $credit = '0.0000';

        foreach ($journal->lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 4);
            $credit = bcadd($credit, (string) $line->credit, 4);
        }

        $this->assertSame($debit, $credit);
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
