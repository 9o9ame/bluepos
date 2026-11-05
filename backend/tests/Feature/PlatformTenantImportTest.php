<?php

namespace Tests\Feature;

use App\Accounting\PartyLeafAccountSync;
use App\Jobs\ProcessTenantImport;
use App\Platform\Imports\AsyncTenantImportService;
use App\Platform\Imports\TenantImportService;
use App\Catalog\TenantCatalogProvisioner;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryOpeningBalance;
use App\Models\Platform\TenantImport;
use App\Models\Platform\TenantImportRow;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Support\SimpleXlsx;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlatformTenantImportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_import_requires_platform_auth_and_permission(): void
    {
        $tenant = $this->provisionOwner('imp-auth')->tenant;
        $this->post('/api/platform/tenants/'.$tenant->ulid.'/imports', ['file' => $this->accounts()])->assertUnauthorized();
        $this->signInOwner('imp-tenant')->assertOk();
        $this->post('/api/platform/tenants/'.$tenant->ulid.'/imports', ['file' => $this->accounts()])->assertUnauthorized();
        $this->signInPlatformUser($this->createPlatformStaff('imp-denied', ['platform.tenants.view']));
        $this->post('/api/platform/tenants/'.$tenant->ulid.'/imports', ['file' => $this->accounts()])->assertForbidden();
    }

    public function test_accounts_mapping_duplicates_all_and_isolation(): void
    {
        $tenant = $this->readyTenant('imp-accounts');
        $other = $this->readyTenant('imp-other');
        $actor = $this->signInPlatformAdmin('imp-admin');
        $rows = [
            ['1', 'V001', 'Vendor One', '03001234567', 'ACCOUNT PAYABLE', 'VENDORS'],
            ['2', 'C001', 'Customer One', '', 'ACCOUNT RECEIVABLE', 'CUSTOMERS'],
            ['3', 'A001', 'Expense One', '', 'EXPENSES', 'ACCOUNTS'],
            ['4', '', 'Mixed ALL', '', 'CASH', 'ALL'],
            ['5', 'V001', 'Vendor One', '03001234567', 'ACCOUNT PAYABLE', 'VENDORS'],
            ['6', '', 'Ambiguous', '111', 'ACCOUNT PAYABLE', 'VENDORS'],
            ['7', '', 'Ambiguous', '222', 'ACCOUNT PAYABLE', 'VENDORS'],
            ['8', 'A002', 'Missing Type', '', 'NO SUCH TYPE', 'ACCOUNTS'],
        ];
        $response = $this->upload($tenant, $this->accounts($rows))->assertOk()
            ->assertJsonPath('created', 3)->assertJsonPath('reused', 1)->assertJsonPath('skipped', 4)->assertJsonPath('failed', 0);
        $this->assertNoInternalIds($response->json());
        $supplier = Supplier::query()->forTenant($tenant->id)->sole();
        $customer = Customer::query()->forTenant($tenant->id)->sole();
        $this->assertDatabaseHas('accounts', ['tenant_id' => $tenant->id, 'supplier_id' => $supplier->id]);
        $this->assertDatabaseHas('accounts', ['tenant_id' => $tenant->id, 'customer_id' => $customer->id]);
        $this->assertDatabaseHas('accounts', ['tenant_id' => $tenant->id, 'code' => 'A001', 'supplier_id' => null, 'customer_id' => null]);
        $this->assertSame(0, Supplier::query()->forTenant($other->id)->count());
        $this->assertSame($actor->id, TenantImport::query()->sole()->platform_user_id);
        $this->upload($tenant, $this->accounts($rows))->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, Supplier::query()->forTenant($tenant->id)->count());
        $this->assertSame(8, TenantImportRow::query()->count());
        $this->postJson('/api/platform/auth/logout')->assertOk();
        $this->loginAs('imp-accounts', 'owner')->assertOk();
        $this->getJson('/api/parties?type=vendor')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Vendor One');
    }

    public function test_products_sections_prices_stock_and_identical_replay(): void
    {
        $tenant = $this->readyTenant('imp-products');
        $other = $this->readyTenant('imp-products-b');
        $warehouse = Warehouse::query()->forTenant($tenant->id)->firstOrFail();
        $this->signInPlatformAdmin('imp-prod-admin');
        $response = $this->upload($tenant, $this->products(), $warehouse->ulid)->assertOk()
            ->assertJsonPath('created', 3)->assertJsonPath('failed', 0)->assertJsonPath('stock.posted', 1)
            ->assertJsonPath('stock.zero', 1)->assertJsonPath('stock.skipped', 1);
        $this->assertNoInternalIds($response->json());
        $product = Product::query()->forTenant($tenant->id)->where('sku', '001234')->firstOrFail();
        $this->assertSame('AIR FRESHENER', $product->category->name);
        $this->assertSame('PCS', $product->baseUnit->code);
        $this->assertNull($product->created_by);
        $this->assertDatabaseHas('product_barcodes', ['tenant_id' => $tenant->id, 'barcode' => '001234', 'product_id' => $product->id]);
        $this->assertDatabaseHas('product_prices', ['product_id' => $product->id, 'price_type' => 'retail', 'amount' => '390.0000']);
        $balance = StockBalance::query()->forTenant($tenant->id)->sole();
        $this->assertSame('25.000000', $balance->quantity);
        $this->assertSame('295.0000', $balance->average_cost);
        $this->assertSame(0, Product::query()->forTenant($other->id)->count());
        $this->assertTrue(collect($response->json('warnings'))->contains(fn ($w) => str_contains($w['message'], 'Negative source quantity -1')));
        $this->upload($tenant, $this->products(), $warehouse->ulid)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(3, Product::query()->forTenant($tenant->id)->count());
        $this->assertSame(2, Category::query()->forTenant($tenant->id)->count());
        $this->assertDatabaseHas('platform_audit_logs', ['event' => 'OPENING_BALANCE_POSTED',
            'resource_ulid' => InventoryOpeningBalance::query()->sole()->ulid]);
        $this->postJson('/api/platform/auth/logout')->assertOk();
        $this->loginAs('imp-products', 'owner')->assertOk();
        $normal = $this->getJson('/api/products?q=Room%20Spray')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNoInternalIds($normal->json());
    }

    public function test_edited_workbook_skips_existing_products_without_reopening_stock_or_units(): void
    {
        $tenant = $this->readyTenant('imp-edited');
        $warehouse = Warehouse::query()->forTenant($tenant->id)->firstOrFail();
        $this->signInPlatformAdmin('imp-edit-admin');
        $this->upload($tenant, $this->products(), $warehouse->ulid)->assertOk();
        $product = Product::query()->forTenant($tenant->id)->where('sku', '001234')->firstOrFail();
        $kg = Unit::query()->forTenant($tenant->id)->where('code', 'KG')->firstOrFail();
        $product->base_unit_id = $kg->id;
        $product->save();
        $response = $this->upload($tenant, $this->products('400'), $warehouse->ulid)->assertOk()->assertJsonPath('reused', 3)->assertJsonPath('updated', 0)->assertJsonPath('stock.posted', 0);
        $this->assertSame($kg->id, $product->fresh()->base_unit_id);
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame('390.0000', ProductPrice::query()->where('product_id', $product->id)->where('price_type', 'retail')->sole()->amount);
        $this->assertSame(3, ProductBarcode::query()->count());
        $this->assertSame([], $response->json('warnings'));
    }

    public function test_catalog_only_and_missing_pcs_do_not_create_stock_or_partial_products(): void
    {
        $tenant = $this->readyTenant('imp-catalog');
        $this->signInPlatformAdmin('imp-cat-admin');
        $this->upload($tenant, $this->products())->assertOk()->assertJsonPath('created', 3)->assertJsonPath('stock.posted', 0);
        $this->assertSame(0, StockMovement::query()->count());
        $other = $this->readyTenant('imp-nopcs');
        Unit::query()->forTenant($other->id)->where('code', 'PCS')->update(['is_active' => false]);
        $this->upload($other, $this->products())->assertOk()->assertJsonPath('skipped', 3);
        $this->assertSame(0, Product::query()->forTenant($other->id)->count());
        $this->assertSame(0, Category::query()->forTenant($other->id)->count());
    }

    public function test_warehouse_is_scoped_and_replay_cannot_change_destination(): void
    {
        $a = $this->readyTenant('imp-wa');
        $b = $this->readyTenant('imp-wb');
        $wa = Warehouse::query()->forTenant($a->id)->firstOrFail();
        $wb = Warehouse::query()->forTenant($b->id)->firstOrFail();
        $this->signInPlatformAdmin('imp-w-admin');
        $this->getJson('/api/platform/tenants/'.$a->ulid.'/import-warehouses')->assertOk()->assertJsonCount(1)->assertJsonPath('0.ulid', $wa->ulid);
        $this->upload($a, $this->products(), $wb->ulid)->assertNotFound();
        $this->assertSame(0, TenantImport::query()->count());
        $this->upload($a, $this->products())->assertOk();
        $this->upload($a, $this->products(), $wa->ulid)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(0, StockMovement::query()->count());
        $this->post('/api/platform/tenants/'.$a->id.'/imports', ['file' => $this->accounts()])->assertNotFound();
    }

    public function test_unknown_workbook_rejected_without_writes(): void
    {
        $tenant = $this->readyTenant('imp-unknown');
        $this->signInPlatformAdmin('imp-unknown-admin');
        $file = UploadedFile::fake()->createWithContent('unknown.xlsx', app(SimpleXlsx::class)->write(['Something Else']));
        $this->upload($tenant, $file)->assertStatus(422);
        $this->assertSame(0, TenantImport::query()->count());
    }

    public function test_unfinished_batch_resumes_only_unprocessed_rows(): void
    {
        $tenant = $this->readyTenant('imp-resume');
        $this->signInPlatformAdmin('imp-resume-admin');
        $this->upload($tenant, $this->products())->assertOk();
        $batch = TenantImport::query()->sole();
        $batch->status = 'processing';
        $batch->save();
        // Simulate an interruption after two rows: remove only test-fixture metadata
        // for the third row. Its product remains and must be safely reused.
        TenantImportRow::query()->where('import_id', $batch->id)->where('source_row', 10)->delete();
        $this->upload($tenant, $this->products())->assertOk()->assertJsonPath('created', 2)->assertJsonPath('reused', 1);
        $this->assertSame(3, Product::query()->forTenant($tenant->id)->count());
        $this->assertSame(3, TenantImportRow::query()->count());
    }

    public function test_party_leaf_failure_rolls_back_party_and_persists_safe_error(): void
    {
        $tenant = $this->readyTenant('imp-rollback');
        $this->signInPlatformAdmin('imp-rollback-admin');
        $this->mock(PartyLeafAccountSync::class, function ($mock) {
            $mock->shouldReceive('syncSupplier')->once()->andThrow(ValidationException::withMessages(['account' => 'Leaf account validation failed.']));
        });
        $this->upload($tenant, $this->accounts([['1', 'V009', 'Rollback Vendor', '', 'ACCOUNT PAYABLE', 'VENDORS']]))
            ->assertOk()->assertJsonPath('failed', 1);
        $this->assertSame(0, Supplier::query()->forTenant($tenant->id)->count());
        $this->assertSame(1, TenantImportRow::query()->count());
    }

    public function test_existing_non_pcs_product_is_not_given_unitless_opening_stock(): void
    {
        $tenant = $this->readyTenant('imp-unit');
        $this->signInPlatformAdmin('imp-unit-admin');
        $this->upload($tenant, $this->products())->assertOk();
        $product = Product::query()->forTenant($tenant->id)->where('sku', '001234')->firstOrFail();
        $product->base_unit_id = Unit::query()->forTenant($tenant->id)->where('code', 'KG')->sole()->id;
        $product->save();
        $warehouse = Warehouse::query()->forTenant($tenant->id)->firstOrFail();
        $result = $this->upload($tenant, $this->products('410'), $warehouse->ulid)->assertOk()->assertJsonPath('stock.posted', 0);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(3, $result->json('reused'));
    }

    public function test_bounded_chunks_resume_and_do_not_repeat_account_creations(): void
    {
        $tenant = $this->readyTenant('imp-chunks');
        $this->signInPlatformAdmin('imp-chunk-admin');
        $rows = [];
        for ($i = 1; $i <= 101; $i++) {
            $rows[] = [(string) $i, 'CHUNK'.$i, 'Chunk Vendor '.$i, '', 'ACCOUNT PAYABLE', 'VENDORS'];
        }
        $first = $this->upload($tenant, $this->accounts($rows))->assertOk()
            ->assertJsonPath('status', 'processing')->assertJsonPath('total', 101);
        $this->assertGreaterThanOrEqual(1, $first->json('processed'));
        $this->assertLessThanOrEqual(20, $first->json('processed'));
        $previous = $first->json('processed');
        $result = $first;
        for ($attempt = 0; $attempt < 101 && $result->json('status') === 'processing'; $attempt++) {
            $result = $this->upload($tenant, $this->accounts($rows))->assertOk();
            $this->assertGreaterThan($previous, $result->json('processed'));
            $this->assertLessThanOrEqual(20, $result->json('processed') - $previous);
            $previous = $result->json('processed');
        }
        $result->assertJsonPath('status', 'completed')->assertJsonPath('processed', 101)
            ->assertJsonPath('created', 101);
        $this->upload($tenant, $this->accounts($rows))->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(101, Supplier::query()->forTenant($tenant->id)->count());
    }

    public function test_conflicting_product_codes_and_formulas_are_skipped_without_catalog_writes(): void
    {
        $tenant = $this->readyTenant('imp-conflict');
        $this->signInPlatformAdmin('imp-conflict-admin');
        $file = $this->products();
        $temp = tempnam(storage_path('app'), 'import-fixture-');
        copy($file->getRealPath(), $temp);
        $zip = new \ZipArchive;
        $zip->open($temp);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        // Change second source code's shared-string reference to first code.
        preg_match('/<c r="B6"[^>]*><v>(\d+)<\/v><\/c>/', $xml, $m);
        $xml = preg_replace('/(<c r="B7"[^>]*><v>)\d+(<\/v><\/c>)/', '${1}'.$m[1].'${2}', $xml);
        $xml = str_replace('<c r="I10" t="s">', '<c r="I10" t="s"><f>1-2</f>', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
        $file = UploadedFile::fake()->createWithContent('formulas.xlsx', file_get_contents($temp));
        unlink($temp);
        $this->upload($tenant, $file)->assertOk()->assertJsonPath('skipped', 3)->assertJsonPath('created', 0);
        $this->assertSame(0, Product::query()->forTenant($tenant->id)->count());
        $this->assertSame(0, Category::query()->forTenant($tenant->id)->count());
    }


    public function test_account_list_csv_and_xlsx_detect_headers_and_skip_existing_records(): void
    {
        $tenant = $this->readyTenant('imp-account-exports');
        $this->signInPlatformAdmin('imp-account-exports-admin');
        $headers = ['Main Head', 'Account', 'Head', 'Sub Head'];
        $data = [
            ['01-ASSETS', '00064-CSV Customer', '010-CURRENT ASSETS', '0011-ACCOUNT RECEIVABLE'],
            ['02-LIABILITIES', '00016-CSV Supplier', '020-SHORT TERM LIABILITIES', '0020-ACCOUNT PAYABLE'],
            ['03-EXPENSES', '00033-Office Expense', '030-EXPENSES', '0030-EXPENSES'],
            ['03-EXPENSES', '', '030-EXPENSES', '0031-INCOME TAX'],
        ];
        $csv = implode("\n", array_map(fn ($row) => implode(',', $row), array_merge([$headers], $data)))."\n";
        $csvFile = UploadedFile::fake()->createWithContent('accounts.csv', $csv);
        $this->upload($tenant, $csvFile)->assertOk()->assertJsonPath('created', 3)->assertJsonPath('failed', 0);
        $xlsx = UploadedFile::fake()->createWithContent('accounts.xlsx', app(SimpleXlsx::class)->write($headers, $data));
        // Both formats normalize to the same content fingerprint. A replay reports
        // the original batch totals but performs zero additional writes.
        $this->upload($tenant, $xlsx)->assertOk()->assertJsonPath('replayed', true)
            ->assertJsonPath('created', 3)->assertJsonPath('failed', 0);
        $this->assertSame(1, Supplier::query()->forTenant($tenant->id)->count());
        $this->assertSame(1, Customer::query()->forTenant($tenant->id)->count());
        $this->assertSame(3, TenantImportRow::query()->count());
        $this->assertSame(1, Supplier::query()->forTenant($tenant->id)->count());
        $this->assertSame(1, Customer::query()->forTenant($tenant->id)->count());
        $this->assertDatabaseHas('accounts', ['tenant_id' => $tenant->id, 'code' => '00033']);
    }

    public function test_sale_details_xlsx_maps_product_columns_and_skips_repeat_with_new_rows(): void
    {
        $tenant = $this->readyTenant('imp-sale-details');
        $this->signInPlatformAdmin('imp-sale-details-admin');
        $headers = ['ID', 'Disc', 'CODE', 'Description', 'Manufacturer', 'Category', 'U.O.M', 'Cost Price', 'Margin', 'Selling', 'In Stock'];
        $make = fn ($rows) => UploadedFile::fake()->createWithContent('products.xlsx', app(SimpleXlsx::class)->write($headers, $rows));
        $first = [['1', '', 'P-001', 'Milk One', 'BRAND ONE', 'DAIRY', 'EACH', '40', '10', '50', '0']];
        $this->upload($tenant, $make($first))->assertOk()->assertJsonPath('created', 1);
        $second = array_merge($first, [['2', '', 'P-002', 'Milk Two', 'BRAND ONE', 'DAIRY', 'EACH', '60', '10', '75', '0']]);
        $this->upload($tenant, $make($second))->assertOk()
            ->assertJsonPath('created', 1)->assertJsonPath('reused', 1)->assertJsonPath('failed', 0);
        $this->assertSame(2, Product::query()->forTenant($tenant->id)->count());
        $existing = Product::query()->forTenant($tenant->id)->where('sku', 'P-001')->firstOrFail();
        $this->assertSame('Milk One', $existing->name);
        $this->assertSame('BRAND ONE', $existing->brand->name);
        $this->assertSame('DAIRY', $existing->category->name);
        $this->assertSame('PCS', $existing->baseUnit->code);
    }

    public function test_async_upload_stages_once_and_worker_commits_without_reposting_on_replay(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['queue.default' => 'database']);
        $tenant = $this->readyTenant('imp-async-once');
        $this->signInPlatformAdmin('imp-async-once-admin');
        $route = '/api/platform/tenants/'.$tenant->ulid.'/imports/async';

        $response = $this->post($route, ['file' => $this->accounts()])
            ->assertStatus(202)->assertJsonPath('status', 'processing')
            ->assertJsonPath('processed', 0)->assertJsonPath('total', 1);
        $batch = TenantImport::query()->where('tenant_id', $tenant->id)->sole();
        $this->assertNotNull($batch->source_path);
        Storage::disk('local')->assertExists($batch->source_path);
        Queue::assertPushed(ProcessTenantImport::class);

        app(AsyncTenantImportService::class)->process($batch->id, app(TenantImportService::class));
        $this->getJson('/api/platform/tenants/'.$tenant->ulid.'/imports/'.$batch->ulid)
            ->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('processed', 1)->assertJsonPath('created', 1);
        Storage::disk('local')->assertMissing($batch->source_path);
        $this->assertSame(1, Supplier::query()->forTenant($tenant->id)->count());

        $this->post($route, ['file' => $this->accounts()])
            ->assertStatus(202)->assertJsonPath('replayed', true);
        $this->assertSame(1, Supplier::query()->forTenant($tenant->id)->count());
        $this->assertSame(1, TenantImportRow::query()->where('import_id', $batch->id)->count());
    }

    public function test_async_import_status_is_tenant_scoped_and_requires_platform_authorization(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['queue.default' => 'database']);
        $tenant = $this->readyTenant('imp-async-tenant');
        $other = $this->readyTenant('imp-async-other');
        $this->signInPlatformAdmin('imp-async-tenant-admin');
        $batch = $this->post('/api/platform/tenants/'.$tenant->ulid.'/imports/async',
            ['file' => $this->accounts()])->assertStatus(202)->json();

        $this->getJson('/api/platform/tenants/'.$other->ulid.'/imports/'.$batch['ulid'])
            ->assertNotFound();
        $this->getJson('/api/platform/tenants/'.$tenant->ulid.'/imports/active')
            ->assertOk()->assertJsonPath('ulid', $batch['ulid']);

        $this->postJson('/api/platform/auth/logout')->assertOk();
        $this->getJson('/api/platform/tenants/'.$tenant->ulid.'/imports/'.$batch['ulid'])
            ->assertUnauthorized();
    }

    public function test_async_endpoint_refuses_sync_queue_instead_of_blocking_http(): void
    {
        $tenant = $this->readyTenant('imp-async-sync');
        $this->signInPlatformAdmin('imp-async-sync-admin');
        config(['queue.default' => 'sync']);
        $this->post('/api/platform/tenants/'.$tenant->ulid.'/imports/async',
            ['file' => $this->accounts()])->assertStatus(503)
            ->assertJsonPath('error.key', 'IMPORT_QUEUE_UNAVAILABLE');
        $this->assertSame(0, TenantImport::query()->where('tenant_id', $tenant->id)->count());
    }

    private function readyTenant(string $suffix)
    {
        $tenant = $this->provisionOwner($suffix)->tenant;
        app(TenantCatalogProvisioner::class)->provision($tenant);

        return $tenant;
    }

    private function upload($tenant, UploadedFile $file, ?string $warehouse = null)
    {
        return $this->post('/api/platform/tenants/'.$tenant->ulid.'/imports', ['file' => $file, 'warehouse_ulid' => $warehouse]);
    }

    private function accounts(?array $rows = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('accounts.xlsx', app(SimpleXlsx::class)->write(
            ['ID', 'CODE', 'Vendor / Customer / Accounts', 'Contacts', 'Account Type', 'TYPE'],
            $rows ?? [['1', 'V001', 'Vendor One', '', 'ACCOUNT PAYABLE', 'VENDORS']]));
    }

    private function products(string $retail = '390'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('products.xlsx', app(SimpleXlsx::class)->write(['Legacy Mart'], [
            ['Sales + Purchase Price'], [], ['AIR FRESHENER', '', '', '', '', '', '', '', '', '2'],
            ['ID', 'CODE', '', '', '', '', 'Description of Item', 'In Carton', 'In Stock', '', 'Retail', 'Pur.Rate'],
            ['1', '001234', '', '', '', '', 'Room Spray', '0', '25', '', $retail, '295'],
            ['2', '001235', '', '', '', '', 'Empty Spray', '0', '0', '', '350', '250'],
            ['OTHER', '', '', '', '', '', '', '', '', '1'],
            ['ID', 'CODE', '', '', '', '', 'Description of Item', 'In Carton', 'In Stock', '', 'Retail', 'Pur.Rate'],
            ['3', '001236', '', '', '', '', 'Negative Spray', '0', '-1', '', '480', '0'],
        ]));
    }
}
