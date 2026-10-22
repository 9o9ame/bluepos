<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BusinessSetting;
use App\Models\DocumentRevision;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InventoryAdjustmentAmendmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_posted_adjustment_can_be_amended_without_mutating_base_rows(): void
    {
        $this->signInOwner('inventory-adjustment-amend')->assertOk();

        $inventory = $this->leafAccount('AMEND INVENTORY', 'AMD-INV');
        $offsetOne = $this->leafAccount('AMEND OFFSET ONE', 'AMD-OFF-1');
        $offsetTwo = $this->leafAccount('AMEND OFFSET TWO', 'AMD-OFF-2');

        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update(['inventory_account_id' => $inventory->id]);

        $plusProduct = $this->createProduct('Amend Plus Product');
        $minusProduct = $this->createProduct('Amend Minus Product');
        $this->giveStock($plusProduct, '10.000000', '25.0000');
        $this->giveStock($minusProduct, '10.000000', '10.0000');

        $adjustmentUlid = $this->postJson('/api/inventory/adjustments', [
            'account_ulid' => $offsetOne->ulid,
            'adjustment_date' => '2026-10-20',
        ])->assertCreated()->json('ulid');

        $plusLineUlid = $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/lines', [
            'product_ulid' => $plusProduct->ulid,
            'qty_plus' => '2.000000',
        ])->assertCreated()->json('ulid');

        $minusLineUlid = $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/lines', [
            'product_ulid' => $minusProduct->ulid,
            'qty_minus' => '1.000000',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/post')
            ->assertOk()
            ->assertJsonPath('status', 'posted');

        $document = InventoryAdjustment::query()
            ->where('ulid', $adjustmentUlid)
            ->firstOrFail();
        $originalPlus = InventoryAdjustmentLine::query()
            ->where('ulid', $plusLineUlid)
            ->firstOrFail();
        $originalMinus = InventoryAdjustmentLine::query()
            ->where('ulid', $minusLineUlid)
            ->firstOrFail();
        $originalJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_INVENTORY_ADJUSTMENT)
            ->where('document_id', $document->id)
            ->with('lines')
            ->firstOrFail();

        $response = $this->patchJson('/api/inventory/adjustments/'.$adjustmentUlid.'/amend', [
            'revision_reason' => 'Correct counted quantities and account',
            'account_ulid' => $offsetTwo->ulid,
            'adjustment_date' => '2026-10-21',
            'lines' => [
                [
                    'product_ulid' => $plusProduct->ulid,
                    'qty_plus' => '3.000000',
                ],
                [
                    'product_ulid' => $minusProduct->ulid,
                    'qty_minus' => '2.000000',
                ],
            ],
        ], $this->idem('inventory-adjustment-amend-r1'))
            ->assertOk()
            ->assertJsonPath('ulid', $adjustmentUlid)
            ->assertJsonPath('revision_number', 1)
            ->assertJsonPath('is_amended', true)
            ->assertJsonPath('account.ulid', $offsetTwo->ulid)
            ->assertJsonPath('adjustment_date', '2026-10-21')
            ->assertJsonPath('net_amount', '55.0000');

        $this->assertSame('13.000000', $this->stock($plusProduct));
        $this->assertSame('8.000000', $this->stock($minusProduct));

        $this->assertSame('2.000000', (string) $originalPlus->fresh()->quantity_delta);
        $this->assertSame('-1.000000', (string) $originalMinus->fresh()->quantity_delta);
        $this->assertSame($offsetOne->id, $document->fresh()->account_id);

        $reversal = JournalEntry::query()
            ->where('reverses_journal_entry_id', $originalJournal->id)
            ->with('lines')
            ->firstOrFail();

        $amendmentJournal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_INVENTORY_ADJUSTMENT_AMENDMENT)
            ->where('document_id', $document->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertBalanced($reversal);
        $this->assertBalanced($amendmentJournal);
        $this->assertSame('55.0000', $this->netInventoryAmount($amendmentJournal->id, $inventory->id));
        $this->assertSame('55.0000', $this->netOffsetAmount($amendmentJournal->id, $offsetTwo->id));

        $this->assertSame(
            2,
            StockMovement::query()
                ->where('reference_type', 'inventory_adjustment_amendment')
                ->where('reference_ulid', $adjustmentUlid)
                ->count(),
        );

        $this->patchJson('/api/inventory/adjustments/'.$adjustmentUlid.'/amend', [
            'revision_reason' => 'Idempotent retry',
            'account_ulid' => $offsetTwo->ulid,
            'adjustment_date' => '2026-10-21',
            'lines' => [
                ['product_ulid' => $plusProduct->ulid, 'qty_plus' => '3.000000'],
                ['product_ulid' => $minusProduct->ulid, 'qty_minus' => '2.000000'],
            ],
        ], $this->idem('inventory-adjustment-amend-r1'))
            ->assertOk()
            ->assertJsonPath('revision_number', 1);

        $this->assertSame(
            1,
            DocumentRevision::query()
                ->where('document_type', 'inventory_adjustment')
                ->where('document_ulid', $adjustmentUlid)
                ->count(),
        );
        $this->assertSame(
            2,
            StockMovement::query()
                ->where('reference_type', 'inventory_adjustment_amendment')
                ->where('reference_ulid', $adjustmentUlid)
                ->count(),
        );

        $this->assertNoInternalIds($response->json());
    }

    public function test_adjustment_amendment_requires_idempotency_and_is_tenant_isolated(): void
    {
        $this->signInOwner('inventory-adjustment-amend-tenant-a')->assertOk();

        $inventory = $this->leafAccount('TENANT A INVENTORY', 'TA-INV');
        $offset = $this->leafAccount('TENANT A OFFSET', 'TA-OFF');
        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update(['inventory_account_id' => $inventory->id]);

        $product = $this->createProduct('Tenant A Adjustment Product');
        $this->giveStock($product, '5.000000', '10.0000');

        $adjustmentUlid = $this->postJson('/api/inventory/adjustments', [
            'account_ulid' => $offset->ulid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/lines', [
            'product_ulid' => $product->ulid,
            'qty_plus' => '1.000000',
        ])->assertCreated();

        $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/post')->assertOk();

        $this->patchJson('/api/inventory/adjustments/'.$adjustmentUlid.'/amend', [
            'revision_reason' => 'Missing key',
            'account_ulid' => $offset->ulid,
            'adjustment_date' => now()->toDateString(),
            'lines' => [[
                'product_ulid' => $product->ulid,
                'qty_plus' => '2.000000',
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('inventory-adjustment-amend-tenant-b')->assertOk();

        $foreignOffset = $this->leafAccount('TENANT B OFFSET', 'TB-OFF');

        $this->patchJson('/api/inventory/adjustments/'.$adjustmentUlid.'/amend', [
            'revision_reason' => 'Cross tenant attempt',
            'account_ulid' => $foreignOffset->ulid,
            'adjustment_date' => now()->toDateString(),
            'lines' => [[
                'product_ulid' => $product->ulid,
                'qty_plus' => '2.000000',
            ]],
        ], $this->idem('inventory-adjustment-amend-cross'))
            ->assertNotFound();
    }

    private function createProduct(string $name): Product
    {
        $response = $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated();

        return Product::query()->where('ulid', $response->json('ulid'))->firstOrFail();
    }

    private function giveStock(Product $product, string $quantity, string $averageCost): void
    {
        $context = app(TenantContext::class);

        StockBalance::query()->updateOrCreate(
            [
                'tenant_id' => $context->tenantId(),
                'warehouse_id' => $context->warehouseId(),
                'product_id' => $product->id,
            ],
            [
                'branch_id' => $context->branchId(),
                'quantity' => $quantity,
                'average_cost' => $averageCost,
                'stock_value' => bcmul($quantity, $averageCost, 4),
            ],
        );
    }

    private function stock(Product $product): string
    {
        return (string) StockBalance::query()
            ->where('tenant_id', app(TenantContext::class)->tenantId())
            ->where('warehouse_id', app(TenantContext::class)->warehouseId())
            ->where('product_id', $product->id)
            ->value('quantity');
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

    private function netInventoryAmount(int $journalId, int $inventoryAccountId): string
    {
        $debit = (string) JournalLine::query()
            ->where('journal_entry_id', $journalId)
            ->where('account_id', $inventoryAccountId)
            ->sum('debit');
        $credit = (string) JournalLine::query()
            ->where('journal_entry_id', $journalId)
            ->where('account_id', $inventoryAccountId)
            ->sum('credit');

        return bcsub(bcadd($debit, '0', 4), bcadd($credit, '0', 4), 4);
    }

    private function netOffsetAmount(int $journalId, int $offsetAccountId): string
    {
        $credit = (string) JournalLine::query()
            ->where('journal_entry_id', $journalId)
            ->where('account_id', $offsetAccountId)
            ->sum('credit');
        $debit = (string) JournalLine::query()
            ->where('journal_entry_id', $journalId)
            ->where('account_id', $offsetAccountId)
            ->sum('debit');

        return bcsub(bcadd($credit, '0', 4), bcadd($debit, '0', 4), 4);
    }

    /** @return array<string,string> */
    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    private function unitUlid(string $code): string
    {
        foreach ($this->getJson('/api/units')->assertOk()->json() as $unit) {
            if ($unit['code'] === $code) {
                return $unit['ulid'];
            }
        }

        $this->fail('Missing unit '.$code);
    }
}
