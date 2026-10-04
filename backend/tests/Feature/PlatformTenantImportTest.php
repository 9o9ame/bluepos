<?php

namespace Tests\Feature;

use App\Accounting\PartyLeafAccountSync;
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

    public function test_edited_workbook_updates_catalog_without_reopening_stock_or_units(): void
    {
        $tenant = $this->readyTenant('imp-edited');
        $warehouse = Warehouse::query()->forTenant($tenant->id)->firstOrFail();
        $this->signInPlatformAdmin('imp-edit-admin');
        $this->upload($tenant, $this->products(), $warehouse->ulid)->assertOk();
        $product = Product::query()->forTenant($tenant->id)->where('sku', '001234')->firstOrFail();
        $kg = Unit::query()->forTenant($tenant->id)->where('code', 'KG')->firstOrFail();
        $product->base_unit_id = $kg->id;
        $product->save();
        $response = $this->upload($tenant, $this->products('400'), $warehouse->ulid)->assertOk()->assertJsonPath('updated', 3)->assertJsonPath('stock.posted', 0);
        $this->assertSame($kg->id, $product->fresh()->base_unit_id);
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame('400.0000', ProductPrice::query()->where('product_id', $product->id)->where('price_type', 'retail')->sole()->amount);
        $this->assertSame(3, ProductBarcode::query()->count());
        $this->assertTrue(collect($response->json('warnings'))->contains(fn ($w) => str_contains($w['message'], 'movement history')));
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
        $this->upload($a, $this->products(), $wa->ulid)->assertStatus(422);
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
        $this->upload($tenant, $this->products())->assertOk()->assertJsonPath('created', 2)->assertJsonPath('updated', 1);
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
        $this->assertTrue(collect($result->json('warnings'))->contains(fn ($w) => str_contains($w['message'], 'unit/barcode conversion')));
    }

    public function test_bounded_chunks_resume_and_do_not_repeat_account_creations(): void
    {
        $tenant = $this->readyTenant('imp-chunks');
        $this->signInPlatformAdmin('imp-chunk-admin');
        $rows = [];
        for ($i = 1; $i <= 101; $i++) {
            $rows[] = [(string) $i, 'CHUNK'.$i, 'Chunk Vendor '.$i, '', 'ACCOUNT PAYABLE', 'VENDORS'];
        }
        $this->upload($tenant, $this->accounts($rows))->assertOk()->assertJsonPath('status', 'processing')
            ->assertJsonPath('processed', 100)->assertJsonPath('total', 101)->assertJsonPath('created', 100);
        $this->upload($tenant, $this->accounts($rows))->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('processed', 101)->assertJsonPath('created', 101);
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
