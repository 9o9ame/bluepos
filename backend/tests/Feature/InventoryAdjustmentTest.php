<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\BusinessSetting;
use App\Models\InventoryAdjustment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class InventoryAdjustmentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_adjustment_posts_append_only_stock_and_balanced_accounting_idempotently(): void
    {
        $this->signInOwner('inventory-adjustment-post')->assertOk();

        $inventory = $this->leafAccount('ADJ INVENTORY', 'ADJ-INV');
        $offset = $this->leafAccount('ADJ OFFSET', 'ADJ-OFF');

        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update(['inventory_account_id' => $inventory->id]);

        $plusProduct = $this->createProduct('Adjustment Plus Product');
        $minusProduct = $this->createProduct('Adjustment Minus Product');
        $this->giveStock($plusProduct, '10.000000', '25.0000');
        $this->giveStock($minusProduct, '10.000000', '25.0000');

        $document = $this->postJson('/api/inventory/adjustments', [
            'account_ulid' => $offset->ulid,
            'adjustment_date' => '2026-10-11',
            'adjustment_type' => 'stock_adjustment',
            'notes' => 'Cycle correction',
        ])->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('account.ulid', $offset->ulid)
            ->assertJsonPath('warehouse.ulid', app(TenantContext::class)->warehouse()->ulid);

        $adjustmentUlid = $document->json('ulid');

        $plus = $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/lines', [
            'product_ulid' => $plusProduct->ulid,
            'qty_plus' => '2.000000',
            'qty_minus' => '0',
            'notes' => 'Found stock',
        ])->assertCreated()
            ->assertJsonPath('in_stock', '10.000000')
            ->assertJsonPath('qty_plus', '2.000000')
            ->assertJsonPath('qty_minus', '0.000000')
            ->assertJsonPath('after_stock', '12.000000')
            ->assertJsonPath('price', '25.0000')
            ->assertJsonPath('amount', '50.0000');

        $minus = $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/lines', [
            'product_ulid' => $minusProduct->ulid,
            'qty_plus' => '0',
            'qty_minus' => '3.000000',
            'notes' => 'Damaged stock',
        ])->assertCreated()
            ->assertJsonPath('in_stock', '10.000000')
            ->assertJsonPath('qty_plus', '0.000000')
            ->assertJsonPath('qty_minus', '3.000000')
            ->assertJsonPath('after_stock', '7.000000')
            ->assertJsonPath('price', '25.0000')
            ->assertJsonPath('amount', '75.0000');

        $posted = $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/post')
            ->assertOk()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('net_amount', '-25.0000');

        $this->assertSame('12.000000', $this->stock($plusProduct));
        $this->assertSame('7.000000', $this->stock($minusProduct));

        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => StockMovementType::AdjustmentIn->value,
            'reference_type' => 'inventory_adjustment',
            'reference_ulid' => $adjustmentUlid,
            'reference_line_ulid' => $plus->json('ulid'),
            'quantity' => '2.000000',
            'unit_cost' => '25.0000',
            'total_cost' => '50.0000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'movement_type' => StockMovementType::AdjustmentOut->value,
            'reference_type' => 'inventory_adjustment',
            'reference_ulid' => $adjustmentUlid,
            'reference_line_ulid' => $minus->json('ulid'),
            'quantity' => '-3.000000',
            'unit_cost' => '25.0000',
            'total_cost' => '-75.0000',
        ]);

        $adjustment = InventoryAdjustment::query()
            ->where('ulid', $adjustmentUlid)
            ->firstOrFail();

        $journal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_INVENTORY_ADJUSTMENT)
            ->where('document_id', $adjustment->id)
            ->with('lines')
            ->firstOrFail();

        $this->assertSame('125.0000', $this->sumJournal($journal->id, 'debit'));
        $this->assertSame('125.0000', $this->sumJournal($journal->id, 'credit'));

        $this->assertSame('50.0000', $this->sumAccountSide($journal->id, $inventory->id, 'debit'));
        $this->assertSame('75.0000', $this->sumAccountSide($journal->id, $inventory->id, 'credit'));
        $this->assertSame('75.0000', $this->sumAccountSide($journal->id, $offset->id, 'debit'));
        $this->assertSame('50.0000', $this->sumAccountSide($journal->id, $offset->id, 'credit'));

        $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/post')
            ->assertOk()
            ->assertJsonPath('status', 'posted');

        $this->assertSame(
            2,
            StockMovement::query()
                ->where('reference_type', 'inventory_adjustment')
                ->where('reference_ulid', $adjustmentUlid)
                ->count(),
        );
        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('document_type', JournalEntry::DOCUMENT_INVENTORY_ADJUSTMENT)
                ->where('document_id', $adjustment->id)
                ->count(),
        );

        $this->patchJson('/api/inventory/adjustments/'.$adjustmentUlid, [
            'notes' => 'Cannot change',
        ])->assertStatus(422)->assertJsonPath('error.key', 'DOCUMENT_POSTED');

        $this->deleteJson(
            '/api/inventory/adjustments/'.$adjustmentUlid.'/lines/'.$plus->json('ulid'),
        )->assertStatus(422)->assertJsonPath('error.key', 'DOCUMENT_POSTED');

        $this->assertNoInternalIds($posted->json());
    }

    public function test_post_time_stock_change_rolls_back_adjustment(): void
    {
        $this->signInOwner('inventory-adjustment-race')->assertOk();

        $inventory = $this->leafAccount('RACE INVENTORY', 'RACE-INV');
        $offset = $this->leafAccount('RACE OFFSET', 'RACE-OFF');

        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update([
                'inventory_account_id' => $inventory->id,
                'negative_stock_allowed' => false,
            ]);

        $product = $this->createProduct('Adjustment Race Product');
        $this->giveStock($product, '10.000000', '20.0000');

        $adjustmentUlid = $this->postJson('/api/inventory/adjustments', [
            'account_ulid' => $offset->ulid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/lines', [
            'product_ulid' => $product->ulid,
            'qty_minus' => '8.000000',
        ])->assertCreated()
            ->assertJsonPath('after_stock', '2.000000');

        StockBalance::query()
            ->where('tenant_id', app(TenantContext::class)->tenantId())
            ->where('warehouse_id', app(TenantContext::class)->warehouseId())
            ->where('product_id', $product->id)
            ->update([
                'quantity' => '5.000000',
                'stock_value' => '100.0000',
            ]);

        $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/post')
            ->assertStatus(422);

        $this->assertSame('5.000000', $this->stock($product));
        $this->assertSame(
            'draft',
            InventoryAdjustment::query()->where('ulid', $adjustmentUlid)->value('status'),
        );
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('reference_type', 'inventory_adjustment')
                ->where('reference_ulid', $adjustmentUlid)
                ->count(),
        );
        $this->assertSame(
            0,
            JournalEntry::query()
                ->where('document_type', JournalEntry::DOCUMENT_INVENTORY_ADJUSTMENT)
                ->count(),
        );
    }

    public function test_missing_inventory_account_rolls_back_posting(): void
    {
        $this->signInOwner('inventory-adjustment-config')->assertOk();

        $offset = $this->leafAccount('CONFIG OFFSET', 'CFG-OFF');
        BusinessSetting::query()
            ->forTenant(app(TenantContext::class)->tenantId())
            ->update(['inventory_account_id' => null]);

        $product = $this->createProduct('Adjustment Config Product');
        $this->giveStock($product, '4.000000', '10.0000');

        $adjustmentUlid = $this->postJson('/api/inventory/adjustments', [
            'account_ulid' => $offset->ulid,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/lines', [
            'product_ulid' => $product->ulid,
            'qty_plus' => '1.000000',
        ])->assertCreated();

        $this->postJson('/api/inventory/adjustments/'.$adjustmentUlid.'/post')
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'INVENTORY_ACCOUNT_REQUIRED');

        $this->assertSame('4.000000', $this->stock($product));
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('reference_type', 'inventory_adjustment')
                ->where('reference_ulid', $adjustmentUlid)
                ->count(),
        );
    }

    public function test_adjustment_account_is_tenant_scoped(): void
    {
        $this->signInOwner('inventory-adjustment-tenant-a')->assertOk();
        $foreignAccount = $this->leafAccount('TENANT A OFFSET', 'TA-OFF');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('inventory-adjustment-tenant-b')->assertOk();

        $this->postJson('/api/inventory/adjustments', [
            'account_ulid' => $foreignAccount->ulid,
        ])->assertNotFound();
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

    private function sumJournal(int $journalId, string $side): string
    {
        return bcadd(
            (string) JournalLine::query()
                ->where('journal_entry_id', $journalId)
                ->sum($side),
            '0',
            4,
        );
    }

    private function sumAccountSide(int $journalId, int $accountId, string $side): string
    {
        return bcadd(
            (string) JournalLine::query()
                ->where('journal_entry_id', $journalId)
                ->where('account_id', $accountId)
                ->sum($side),
            '0',
            4,
        );
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
