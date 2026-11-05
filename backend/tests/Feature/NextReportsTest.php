<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockBalance;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NextReportsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_all_six_next_report_endpoints_require_authenticated_tenant(): void
    {
        foreach ([
            '/api/reports/next/advance-sales',
            '/api/reports/next/day-book',
            '/api/reports/next/product-transactions',
            '/api/reports/next/detailed-transactions',
            '/api/reports/next/fbr/sales',
            '/api/reports/next/fbr/purchases',
        ] as $url) {
            $this->getJson($url.'?from=2026-11-04&to=2026-11-04')->assertUnauthorized();
        }
    }

    public function test_dates_and_tenant_scoped_ulid_filters_are_validated(): void
    {
        $this->signInOwner('next-reports-dates')->assertOk();
        $this->getJson('/api/reports/next/advance-sales?from=2026-11-06&to=2026-11-04')
            ->assertUnprocessable();
        $this->getJson('/api/reports/next/day-book?from=2026-01-01&to=2026-11-04')
            ->assertUnprocessable();
        $this->getJson('/api/reports/next/product-transactions?from=bad&to=2026-11-04')
            ->assertUnprocessable();
        $this->getJson('/api/reports/next/fbr/sales?from=2026-11-04&to=2026-11-04&product_ulid=01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertUnprocessable();
        $this->getJson('/api/reports/next/detailed-transactions?from=2026-11-04&to=2026-11-04&types%5B%5D=malicious')
            ->assertUnprocessable();
    }

    public function test_empty_reports_have_zero_totals_and_fbr_is_not_filing_ready(): void
    {
        $this->signInOwner('next-reports-empty')->assertOk();
        $q='?from=2026-11-04&to=2026-11-04';

        $this->getJson('/api/reports/next/advance-sales'.$q)
            ->assertOk()->assertJsonCount(0, 'rows')->assertJsonPath('totals.amount','0.0000');
        $this->getJson('/api/reports/next/day-book'.$q)
            ->assertOk()->assertJsonPath('totals.sale','0.0000');
        $this->getJson('/api/reports/next/product-transactions'.$q)
            ->assertOk()->assertJsonCount(0,'rows')->assertJsonPath('totals.net','0.000000');
        $this->getJson('/api/reports/next/detailed-transactions'.$q)
            ->assertOk()->assertJsonCount(0,'rows')->assertJsonPath('totals.count',0);
        foreach (['sales','purchases'] as $type) {
            $response=$this->getJson('/api/reports/next/fbr/'.$type.$q)
                ->assertOk()->assertJsonCount(0,'rows')
                ->assertJsonPath('compliance_ready',false)
                ->assertJsonPath('totals.tax','0.0000');
            $this->assertNoInternalIds($response->json());
        }
    }

    public function test_posted_sale_feeds_advance_daybook_details_and_internal_tax_register(): void
    {
        $this->signInOwner('next-reports-posted')->assertOk();
        $units=collect($this->getJson('/api/units')->assertOk()->json());
        $unit=$units->firstWhere('code','PCS');
        $this->assertNotNull($unit);
        $created=$this->postJson('/api/products', [
            'name'=>'Next Reports Sale Item',
            'base_unit_ulid'=>$unit['ulid'],
            'prices'=>[['price_type'=>'retail','amount'=>'120.0000']],
        ])->assertCreated();
        $product=Product::query()->where('ulid',$created->json('ulid'))->firstOrFail();
        $context=app(TenantContext::class);
        StockBalance::query()->updateOrCreate([
            'tenant_id'=>$context->tenantId(), 'warehouse_id'=>$context->warehouseId(),
            'product_id'=>$product->id,
        ],[
            'branch_id'=>$context->branchId(),'quantity'=>'10.000000',
            'average_cost'=>'30.0000','stock_value'=>'300.0000',
        ]);
        $this->postJson('/api/sales',[
            'sale_date'=>'2026-11-04',
            'items'=>[['product_ulid'=>$product->ulid,'quantity'=>'2']],
        ],['Idempotency-Key'=>'next-reports-posted-01'])->assertCreated();

        $q='?from=2026-11-04&to=2026-11-04';
        $advance=$this->getJson('/api/reports/next/advance-sales'.$q)
            ->assertOk()->assertJsonCount(1,'rows')
            ->assertJsonPath('rows.0.product.ulid',$product->ulid)
            ->assertJsonPath('rows.0.sold','2.000000')
            ->assertJsonPath('totals.amount','240.0000');
        $this->assertNoInternalIds($advance->json());
        $this->getJson('/api/reports/next/day-book'.$q)->assertOk()
            ->assertJsonPath('totals.sale','240.0000');
        $this->getJson('/api/reports/next/detailed-transactions'.$q.'&format=detail')
            ->assertOk()->assertJsonPath('totals.amount','240.0000')
            ->assertJsonPath('rows.0.lines.0.product.ulid',$product->ulid);
        $fbr=$this->getJson('/api/reports/next/fbr/sales'.$q)
            ->assertOk()->assertJsonPath('compliance_ready',false)
            ->assertJsonPath('rows.0.quantity','2.000000');
        $this->assertNoInternalIds($fbr->json());
        $this->getJson('/api/reports/next/product-transactions'.$q)
            ->assertOk()->assertJsonCount(1,'rows')
            ->assertJsonPath('totals.out','2.000000');
    }

    public function test_role_permission_denial_on_sensitive_register_and_stock_views(): void
    {
        $this->signInOwner('next-reports-authz')->assertOk();
        $this->restrictCurrentMembershipWithoutPermission('reports.accounting');
        $this->getJson('/api/reports/next/fbr/sales?from=2026-11-04&to=2026-11-04')
            ->assertForbidden();
        $this->getJson('/api/reports/next/day-book?from=2026-11-04&to=2026-11-04')
            ->assertForbidden();
    }

    public function test_another_tenant_cannot_access_first_tenant_product_filter(): void
    {
        $this->signInOwner('next-reports-tenant-a')->assertOk();
        $unit=collect($this->getJson('/api/units')->assertOk()->json())->firstWhere('code','PCS');
        $this->assertNotNull($unit);
        $created=$this->postJson('/api/products',[
            'name'=>'Protected Product',
            'base_unit_ulid'=>$unit['ulid'],
        ])->assertCreated();
        $ulid=$created->json('ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('next-reports-tenant-b')->assertOk();
        $this->getJson('/api/reports/next/product-transactions?from=2026-11-04&to=2026-11-04&product_ulid='.$ulid)
            ->assertUnprocessable();
        $this->getJson('/api/reports/next/fbr/sales?from=2026-11-04&to=2026-11-04&product_ulid='.$ulid)
            ->assertUnprocessable();
    }
}
