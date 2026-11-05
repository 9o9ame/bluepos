<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DailyActivitySummaryReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_report_requires_accounting_permission(): void
    {
        $this->signInOwner('daily-summary-denied')->assertOk();
        $this->restrictCurrentMembershipWithoutPermission('reports.accounting');

        $this->getJson('/api/reports/daily/activity-summary?from=2026-11-01&to=2026-11-05')
            ->assertForbidden();
    }

    public function test_report_validates_dates_and_limits_the_range(): void
    {
        $this->signInOwner('daily-summary-dates')->assertOk();

        $this->getJson('/api/reports/daily/activity-summary?from=2026-11-06&to=2026-11-05')
            ->assertUnprocessable();
        $this->getJson('/api/reports/daily/activity-summary?from=2026-01-01&to=2026-11-05')
            ->assertUnprocessable();
        $this->getJson('/api/reports/daily/activity-summary?from=bad&to=2026-11-05')
            ->assertUnprocessable();
    }

    public function test_empty_report_uses_authenticated_context_and_exposes_no_internal_ids(): void
    {
        $this->signInOwner('daily-summary-empty')->assertOk();
        $context = app(TenantContext::class);
        $this->assertGreaterThan(0, $context->tenantId());
        $this->assertGreaterThan(0, $context->branchId());
        $this->assertGreaterThan(0, $context->warehouseId());

        $response = $this->getJson('/api/reports/daily/activity-summary?from=2026-11-04&to=2026-11-04')
            ->assertOk()
            ->assertJsonPath('from', '2026-11-04')
            ->assertJsonPath('to', '2026-11-04')
            ->assertJsonPath('totals.sale', '0.0000')
            ->assertJsonPath('totals.purchase', '0.0000')
            ->assertJsonCount(0, 'documents');

        $this->assertNoInternalIds($response->json());
    }

    public function test_posted_sale_and_stock_sheet_show_effective_document_lines_and_balances(): void
    {
        $this->signInOwner('daily-summary-posted')->assertOk();
        $context = app(TenantContext::class);
        $unit = collect($this->getJson('/api/units')->assertOk()->json())->firstWhere('code', 'PCS');
        $this->assertNotNull($unit);
        $created = $this->postJson('/api/products', [
            'name' => 'Daily Summary Product',
            'base_unit_ulid' => $unit['ulid'],
            'prices' => [['price_type' => 'retail', 'amount' => '100.0000']],
        ])->assertCreated();
        $product = Product::query()->where('ulid', $created->json('ulid'))->firstOrFail();

        StockBalance::query()->updateOrCreate([
            'tenant_id' => $context->tenantId(),
            'warehouse_id' => $context->warehouseId(),
            'product_id' => $product->id,
        ], [
            'branch_id' => $context->branchId(),
            'quantity' => '10.000000',
            'average_cost' => '10.0000',
            'stock_value' => '100.0000',
        ]);
        StockMovement::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $context->warehouseId(),
            'product_id' => $product->id,
            'movement_type' => StockMovementType::OpeningBalance,
            'quantity' => '10.000000',
            'unit_cost' => '10.0000',
            'total_cost' => '100.0000',
            'reference_type' => 'report_test',
            'reference_ulid' => '01DAILYSUMMARYOPEN0000000000',
            'occurred_at' => '2026-11-03 09:00:00',
            'posted_by' => $context->userId(),
        ]);

        $this->postJson('/api/sales', [
            'sale_date' => '2026-11-04',
            'items' => [['product_ulid' => $product->ulid, 'quantity' => '2']],
        ], ['Idempotency-Key' => 'daily-summary-posted-sale'])->assertCreated();

        $response = $this->getJson('/api/reports/daily/activity-summary?from=2026-11-04&to=2026-11-04')
            ->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.type', 'sale')
            ->assertJsonPath('documents.0.amount', '200.0000')
            ->assertJsonPath('documents.0.lines.0.product.ulid', $product->ulid)
            ->assertJsonPath('documents.0.lines.0.quantity', '2.000000')
            ->assertJsonPath('totals.sale', '200.0000')
            ->assertJsonPath('stock.0.opening_quantity', '10.000000')
            ->assertJsonPath('stock.0.quantity_change', '-2.000000')
            ->assertJsonPath('stock.0.closing_quantity', '8.000000');
        $this->assertNoInternalIds($response->json());
    }
}
