<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BusinessSetting;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class PurchaseExpiryReferenceReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_posted_purchase_expiry_reference_is_not_claimed_as_remaining_stock(): void
    {
        $this->signInOwner('purchase-expiry-ref')->assertOk();
        $this->configurePurchaseClearing();
        $warehouseUlid = $this->getJson('/api/auth/me')->assertOk()->json('warehouse.ulid');
        $units = $this->getJson('/api/units')->assertOk()->json();
        $pcs = collect($units)->firstWhere('code', 'PCS')['ulid'];

        $supplier = $this->postJson('/api/suppliers', [
            'code' => 'EXP-REFERENCE-SUP', 'name' => 'Expiry Purchase Supplier',
        ])->assertCreated()->json('ulid');
        $product = $this->postJson('/api/products', [
            'name' => 'Expiry Reference Product', 'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $draft = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplier, 'warehouse_ulid' => $warehouseUlid,
        ])->assertCreated()->json('ulid');
        $line = $this->postJson('/api/purchases/'.$draft.'/lines', [
            'product_ulid' => $product,
            'unit_ulid' => $pcs,
            'quantity' => '2.750000',
            'unit_cost' => '10.0000',
            'batch_number' => 'EXP-BATCH-01',
            'expiry_date' => '2027-04-01',
        ])->assertCreated()->json('ulid');

        $base = '/api/reports/products/purchase-expiry-reference?report_date=2026-11-08&horizon_days=365&q=EXP-BATCH-01';
        $this->getJson($base)->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson('/api/purchases/'.$draft.'/post')->assertOk();

        $response = $this->getJson($base)->assertOk()
            ->assertJsonPath('meta.report_type', 'purchase_expiry_reference_not_remaining_stock')
            ->assertJsonPath('meta.remaining_stock_available', false)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.purchase_ulid', $draft)
            ->assertJsonPath('data.0.purchase_line_ulid', $line)
            ->assertJsonPath('data.0.product.ulid', $product)
            ->assertJsonPath('data.0.batch_number', 'EXP-BATCH-01')
            ->assertJsonPath('data.0.expiry_date', '2027-04-01')
            ->assertJsonPath('data.0.purchased_base_quantity', '2.750000')
            ->assertJsonPath('data.0.remaining_quantity', null);
        $this->assertNoInternalIds($response->json());

        $this->getJson(str_replace('horizon_days=365', 'horizon_days=30', $base))
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson(str_replace('horizon_days=365', 'horizon_days=0', $base))
            ->assertUnprocessable();

        $this->restrictCurrentMembershipWithoutPermission('reports.inventory');
        $this->getJson($base)->assertForbidden();
    }

    public function test_purchase_expiry_reference_does_not_leak_other_tenant(): void
    {
        $this->signInOwner('purchase-expiry-tenant-a')->assertOk();
        $this->postJson('/api/suppliers', [
            'code' => 'EXP-TENANT-A',
            'name' => 'Private Supplier',
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('purchase-expiry-tenant-b')->assertOk();
        $this->getJson('/api/reports/products/purchase-expiry-reference?report_date=2026-11-08&horizon_days=365')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    private function configurePurchaseClearing(): void
    {
        $context = app(TenantContext::class);
        $type = AccountType::query()->forTenant($context->tenantId())
            ->where('code', '0060')->firstOrFail();
        $account = Account::query()->firstOrCreate(
            ['tenant_id' => $context->tenantId(), 'code' => 'EXP-PUR-CLEAR'],
            [
                'name' => 'Expiry Purchase Clearing',
                'account_type_id' => $type->id,
                'is_active' => true,
                'created_by' => $context->userId(),
            ],
        );
        BusinessSetting::query()->forTenant($context->tenantId())
            ->update(['purchase_clearing_account_id' => $account->id]);
    }
}
