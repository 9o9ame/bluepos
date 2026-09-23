<?php

namespace Tests\Feature;

use App\Authz\PermissionCatalogue;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use DatabaseTransactions;

    public function test_tenant_can_view_and_update_own_business_settings(): void
    {
        $this->signInOwner('set-own')->assertOk();

        $shown = $this->getJson('/api/settings/business')->assertOk();
        $this->assertSame('Mart set-own', $shown->json('business_name'));
        $this->assertSame('PKR', $shown->json('currency_code'));
        $this->assertNoInternalIds($shown->json());

        $updated = $this->patchJson('/api/settings/business', [
            'business_name' => 'Updated Mart',
            'default_tax_percent' => '17.50000000',
            'negative_stock_allowed' => true,
        ])->assertOk();

        $this->assertSame('Updated Mart', $updated->json('business_name'));
        $this->assertSame('17.50000000', $updated->json('default_tax_percent'));
        $this->assertTrue($updated->json('negative_stock_allowed'));
    }

    public function test_unauthorized_user_cannot_update_settings_and_tenant_id_is_ignored(): void
    {
        $owner = $this->signInOwner('set-unauth')->assertOk();
        $this->createCashier('set-unauth', $owner->json('branch.ulid'));
        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('set-unauth', 'cashier-set-unauth')->assertOk();

        $this->patchJson('/api/settings/business', [
            'business_name' => 'Hacked',
        ])->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('set-unauth', 'owner')->assertOk();

        $this->patchJson('/api/settings/business', [
            'tenant_id' => 999,
            'business_name' => 'Safe Name',
        ])->assertOk()->assertJsonPath('business_name', 'Safe Name');
        $this->assertSame('Mart set-unauth', Tenant::query()->where('name', 'Mart set-unauth')->value('name'));
    }

    public function test_categories_are_tenant_scoped(): void
    {
        $this->signInOwner('cat-a')->assertOk();
        $created = $this->postJson('/api/categories', [
            'code' => 'BEV',
            'name' => 'Beverages',
        ])->assertCreated();
        $this->assertNoInternalIds($created->json());
        $ulidA = $created->json('ulid');

        $this->postJson('/api/categories', [
            'code' => 'BEV',
            'name' => 'Duplicate',
        ])->assertUnprocessable();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('cat-b')->assertOk();
        $this->postJson('/api/categories', [
            'code' => 'BEV',
            'name' => 'Beverages B',
        ])->assertCreated();

        $this->getJson('/api/categories/'.$ulidA)->assertNotFound();
        $this->patchJson('/api/categories/'.$ulidA, ['name' => 'Hack'])->assertNotFound();
        $this->deleteJson('/api/categories/'.$ulidA)->assertNotFound();
    }

    public function test_subcategory_cannot_use_foreign_category(): void
    {
        $this->signInOwner('sub-a')->assertOk();
        $categoryA = $this->postJson('/api/categories', [
            'code' => 'FOOD',
            'name' => 'Food',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('sub-b')->assertOk();
        $this->postJson('/api/subcategories', [
            'category_ulid' => $categoryA,
            'code' => 'SNACK',
            'name' => 'Snacks',
        ])->assertNotFound();

        $own = $this->postJson('/api/categories', [
            'code' => 'FOOD',
            'name' => 'Food B',
        ])->assertCreated()->json('ulid');

        $sub = $this->postJson('/api/subcategories', [
            'category_ulid' => $own,
            'code' => 'SNACK',
            'name' => 'Snacks',
        ])->assertCreated();
        $this->assertSame($own, $sub->json('category.ulid'));
        $this->assertNoInternalIds($sub->json());
    }

    public function test_brands_and_units_are_isolated(): void
    {
        $this->signInOwner('mst-a')->assertOk();
        $brandA = $this->postJson('/api/brands', [
            'code' => 'LOCAL',
            'name' => 'Local Brand',
        ])->assertCreated()->json('ulid');
        $unitA = $this->postJson('/api/units', [
            'code' => 'BAG',
            'name' => 'Bag',
            'symbol' => 'bag',
            'allows_decimal' => false,
        ])->assertCreated();
        $this->assertFalse($unitA->json('allows_decimal'));
        $this->assertNoInternalIds($unitA->json());

        $this->postJson('/api/units', [
            'code' => 'KGX',
            'name' => 'Custom Kg',
            'symbol' => 'kg',
            'allows_decimal' => true,
        ])->assertCreated()->assertJsonPath('allows_decimal', true);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('mst-b')->assertOk();
        $this->getJson('/api/brands/'.$brandA)->assertNotFound();
        $this->getJson('/api/units/'.$unitA->json('ulid'))->assertNotFound();
        $this->postJson('/api/brands', [
            'code' => 'LOCAL',
            'name' => 'Local B',
        ])->assertCreated();
    }

    public function test_catalog_masters_can_be_created_updated_and_deactivated(): void
    {
        $this->signInOwner('master-crud')->assertOk();

        $category = $this->postJson('/api/categories', [
            'code' => 'FOOD',
            'name' => 'Food',
        ])->assertCreated();
        $categoryUlid = $category->json('ulid');
        $this->patchJson('/api/categories/'.$categoryUlid, [
            'code' => 'FOODS',
            'name' => 'Foods',
        ])->assertOk()->assertJsonPath('name', 'Foods');
        $subcategoryUlid = $this->postJson('/api/subcategories', [
            'category_ulid' => $categoryUlid,
            'code' => 'SNACK',
            'name' => 'Snacks',
        ])->assertCreated()->json('ulid');
        $this->deleteJson('/api/categories/'.$categoryUlid)
            ->assertOk()
            ->assertJsonPath('archived', true);
        $this->getJson('/api/categories/'.$categoryUlid)
            ->assertOk()
            ->assertJsonPath('is_active', false);
        $this->getJson('/api/subcategories/'.$subcategoryUlid)->assertOk();

        $brandUlid = $this->postJson('/api/brands', [
            'code' => 'ACME',
            'name' => 'Acme',
        ])->assertCreated()->json('ulid');
        $this->patchJson('/api/brands/'.$brandUlid, ['name' => 'Acme Updated'])
            ->assertOk()
            ->assertJsonPath('name', 'Acme Updated');
        $this->deleteJson('/api/brands/'.$brandUlid)
            ->assertOk()
            ->assertJsonPath('archived', true);
        $this->getJson('/api/brands/'.$brandUlid)->assertOk()->assertJsonPath('is_active', false);

        $unitUlid = $this->postJson('/api/units', [
            'code' => 'TSTBOX',
            'name' => 'Box',
            'symbol' => 'box',
            'allows_decimal' => false,
        ])->assertCreated()->json('ulid');
        $unit = $this->patchJson('/api/units/'.$unitUlid, [
            'name' => 'Large Box',
            'symbol' => 'lbox',
            'allows_decimal' => true,
        ])->assertOk();
        $unit->assertJsonPath('allows_decimal', true);
        $this->deleteJson('/api/units/'.$unitUlid)
            ->assertOk()
            ->assertJsonPath('archived', true);
        $this->getJson('/api/units/'.$unitUlid)->assertOk()->assertJsonPath('is_active', false);

        $barcodeGroup = $this->postJson('/api/barcode-groups', [
            'code' => ' retail ',
            'name' => 'Retail',
            'description' => 'Retail barcode family',
            'sort_order' => 2,
        ])->assertCreated();
        $barcodeGroup->assertJsonPath('code', 'RETAIL');
        $barcodeGroupUlid = $barcodeGroup->json('ulid');
        $updatedBarcodeGroup = $this->patchJson('/api/barcode-groups/'.$barcodeGroupUlid, [
            'code' => ' retail-main ',
            'name' => 'Retail Main',
        ])->assertOk();
        $updatedBarcodeGroup->assertJsonPath('code', 'RETAIL-MAIN');
        $this->assertNoInternalIds($updatedBarcodeGroup->json());
        $this->deleteJson('/api/barcode-groups/'.$barcodeGroupUlid)
            ->assertOk()
            ->assertJsonPath('archived', true);
        $this->getJson('/api/barcode-groups/'.$barcodeGroupUlid)
            ->assertOk()
            ->assertJsonPath('is_active', false);

        foreach ([
            'CATEGORY_CREATED',
            'CATEGORY_UPDATED',
            'CATEGORY_DEACTIVATED',
            'BRAND_CREATED',
            'BRAND_UPDATED',
            'BRAND_DEACTIVATED',
            'UNIT_CREATED',
            'UNIT_UPDATED',
            'UNIT_DEACTIVATED',
            'BARCODE_GROUP_CREATED',
            'BARCODE_GROUP_UPDATED',
            'BARCODE_GROUP_DEACTIVATED',
        ] as $event) {
            $this->assertTrue(AuditLog::query()->where('event', $event)->exists(), "Missing audit event {$event}");
        }
    }

    public function test_all_catalog_masters_are_tenant_isolated(): void
    {
        $this->signInOwner('master-iso-a')->assertOk();
        $resources = [
            'categories' => $this->postJson('/api/categories', [
                'code' => 'ISOCAT',
                'name' => 'Private Category',
            ])->assertCreated()->json('ulid'),
            'brands' => $this->postJson('/api/brands', [
                'code' => 'ISOBRAND',
                'name' => 'Private Brand',
            ])->assertCreated()->json('ulid'),
            'units' => $this->postJson('/api/units', [
                'code' => 'ISOUNIT',
                'name' => 'Private Unit',
                'symbol' => 'iu',
                'allows_decimal' => false,
            ])->assertCreated()->json('ulid'),
            'barcode-groups' => $this->postJson('/api/barcode-groups', [
                'code' => 'ISOBAR',
                'name' => 'Private Barcode Group',
            ])->assertCreated()->json('ulid'),
        ];

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('master-iso-b')->assertOk();

        foreach ($resources as $endpoint => $ulid) {
            $list = $this->getJson('/api/'.$endpoint)->assertOk()->json();
            $this->assertNotContains($ulid, collect($list)->pluck('ulid')->all());
            $this->getJson('/api/'.$endpoint.'/'.$ulid)->assertNotFound();
            $this->patchJson('/api/'.$endpoint.'/'.$ulid, ['name' => 'Leaked'])->assertNotFound();
            $this->deleteJson('/api/'.$endpoint.'/'.$ulid)->assertNotFound();
        }
    }

    public function test_product_barcode_group_round_trip_and_inactive_relations_remain_readable(): void
    {
        $this->signInOwner('barcode-product')->assertOk();
        $masters = $this->seedMasters();
        $barcodeGroupUlid = $this->postJson('/api/barcode-groups', [
            'code' => 'PACKAGING',
            'name' => 'Packaging',
        ])->assertCreated()->json('ulid');

        $product = $this->postJson('/api/products', [
            'name' => 'Grouped Product',
            'category_ulid' => $masters['category'],
            'brand_ulid' => $masters['brand'],
            'barcode_group_ulid' => $barcodeGroupUlid,
            'base_unit_ulid' => $masters['pcs'],
        ])->assertCreated();
        $productUlid = $product->json('ulid');
        $product->assertJsonPath('barcode_group.ulid', $barcodeGroupUlid);
        $this->assertNoInternalIds($product->json());

        $secondGroupUlid = $this->postJson('/api/barcode-groups', [
            'code' => 'PACKAGING2',
            'name' => 'Packaging Two',
        ])->assertCreated()->json('ulid');
        $this->patchJson('/api/products/'.$productUlid, [
            'barcode_group_ulid' => $secondGroupUlid,
        ])->assertOk()->assertJsonPath('barcode_group.ulid', $secondGroupUlid);

        $this->deleteJson('/api/categories/'.$masters['category'])->assertOk();
        $this->deleteJson('/api/brands/'.$masters['brand'])->assertOk();
        $this->deleteJson('/api/units/'.$masters['pcs'])->assertOk();
        $this->deleteJson('/api/barcode-groups/'.$secondGroupUlid)->assertOk();

        $existing = $this->getJson('/api/products/'.$productUlid)->assertOk();
        $existing->assertJsonPath('category.is_active', false);
        $existing->assertJsonPath('brand.is_active', false);
        $existing->assertJsonPath('base_unit.is_active', false);
        $existing->assertJsonPath('barcode_group.is_active', false);
        $this->assertNoInternalIds($existing->json());
    }

    public function test_foreign_barcode_group_assignment_and_unauthorized_master_mutations_are_blocked(): void
    {
        $owner = $this->signInOwner('master-auth-a')->assertOk();
        $masters = $this->seedMasters();
        $barcodeGroupUlid = $this->postJson('/api/barcode-groups', [
            'code' => 'PRIVATE',
            'name' => 'Private Group',
        ])->assertCreated()->json('ulid');
        $this->createCashier('master-auth-a', $owner->json('branch.ulid'));

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('master-auth-b')->assertOk();
        $ownProduct = $this->postJson('/api/products', [
            'name' => 'Own Product',
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated();
        $this->postJson('/api/products', [
            'name' => 'Foreign Group Product',
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'barcode_group_ulid' => $barcodeGroupUlid,
        ])->assertNotFound();
        $this->patchJson('/api/products/'.$ownProduct->json('ulid'), [
            'barcode_group_ulid' => $barcodeGroupUlid,
        ])->assertNotFound();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('master-auth-a', 'cashier-master-auth-a')->assertOk();

        $payloads = [
            'categories' => ['code' => 'NOPE', 'name' => 'Nope'],
            'brands' => ['code' => 'NOPE', 'name' => 'Nope'],
            'units' => ['code' => 'NOPE', 'name' => 'Nope', 'symbol' => 'n', 'allows_decimal' => false],
            'barcode-groups' => ['code' => 'NOPE', 'name' => 'Nope'],
        ];
        $existing = [
            'categories' => $masters['category'],
            'brands' => $masters['brand'],
            'units' => $masters['pcs'],
            'barcode-groups' => $barcodeGroupUlid,
        ];

        foreach ($payloads as $endpoint => $payload) {
            $this->postJson('/api/'.$endpoint, $payload)->assertForbidden();
            $this->patchJson('/api/'.$endpoint.'/'.$existing[$endpoint], ['name' => 'Forbidden'])
                ->assertForbidden();
            $this->deleteJson('/api/'.$endpoint.'/'.$existing[$endpoint])->assertForbidden();
        }
    }

    public function test_product_lifecycle_barcodes_and_prices(): void
    {
        $this->signInOwner('prd-a')->assertOk();
        $masters = $this->seedMasters();

        $created = $this->postJson('/api/products', [
            'product_number' => 'P-100',
            'sku' => 'SKU-100',
            'name' => 'Cola 330ml',
            'category_ulid' => $masters['category'],
            'brand_ulid' => $masters['brand'],
            'base_unit_ulid' => $masters['pcs'],
            'tax_percent' => '17.50000000',
            'barcodes' => [[
                'barcode' => '1234567890123',
                'unit_ulid' => $masters['pcs'],
                'conversion_factor' => '1.00000000',
                'is_primary' => true,
            ]],
            'prices' => [
                ['price_type' => 'retail', 'amount' => '120.2500'],
                ['price_type' => 'wholesale', 'amount' => '110.0000'],
            ],
        ])->assertCreated();

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $created->json('ulid'));
        $this->assertSame('P-100', $created->json('product_number'));
        $this->assertSame('120.2500', collect($created->json('prices'))->firstWhere('price_type', 'retail')['amount']);
        $this->assertNoInternalIds($created->json());

        $this->postJson('/api/products', [
            'product_number' => 'P-100',
            'name' => 'Dup number',
            'base_unit_ulid' => $masters['pcs'],
        ])->assertUnprocessable();

        $this->postJson('/api/products', [
            'sku' => 'SKU-100',
            'name' => 'Dup sku',
            'base_unit_ulid' => $masters['pcs'],
        ])->assertUnprocessable();

        $second = $this->postJson('/api/products', [
            'name' => 'Water',
            'base_unit_ulid' => $masters['pcs'],
        ])->assertCreated();

        $this->putJson('/api/products/'.$second->json('ulid').'/barcodes', [
            'barcodes' => [
                [
                    'barcode' => '1234567890123',
                    'unit_ulid' => $masters['pcs'],
                    'conversion_factor' => '1.00000000',
                    'is_primary' => true,
                ],
            ],
        ])->assertUnprocessable();

        $this->putJson('/api/products/'.$created->json('ulid').'/barcodes', [
            'barcodes' => [
                [
                    'barcode' => '1234567890123',
                    'unit_ulid' => $masters['pcs'],
                    'conversion_factor' => '1.00000000',
                    'is_primary' => true,
                ],
                [
                    'barcode' => '1234567890999',
                    'unit_ulid' => $masters['carton'],
                    'conversion_factor' => '24.00000000',
                    'is_primary' => false,
                ],
            ],
        ])->assertOk()->assertJsonCount(2, 'barcodes');

        $this->putJson('/api/products/'.$created->json('ulid').'/barcodes', [
            'barcodes' => [
                [
                    'barcode' => 'AAA',
                    'unit_ulid' => $masters['pcs'],
                    'conversion_factor' => '1.00000000',
                    'is_primary' => true,
                ],
                [
                    'barcode' => 'BBB',
                    'unit_ulid' => $masters['carton'],
                    'conversion_factor' => '24.00000000',
                    'is_primary' => true,
                ],
            ],
        ])->assertUnprocessable();

        $this->putJson('/api/products/'.$created->json('ulid').'/prices', [
            'prices' => [
                ['price_type' => 'retail', 'amount' => '-1.0000'],
            ],
        ])->assertUnprocessable();

        $this->putJson('/api/products/'.$created->json('ulid').'/prices', [
            'prices' => [
                ['price_type' => 'minimum_sale', 'amount' => '100.5000'],
            ],
        ])->assertOk();

        $this->assertSame('100.5000', collect($this->getJson('/api/products/'.$created->json('ulid'))->json('prices'))
            ->firstWhere('price_type', 'minimum_sale')['amount']);

        $deactivated = $this->deleteJson('/api/products/'.$created->json('ulid'))->assertOk();
        $this->assertSame('discontinued', $deactivated->json('status'));
        $this->assertFalse($deactivated->json('is_active'));
        $this->getJson('/api/products/'.$created->json('ulid'))
            ->assertOk()
            ->assertJsonPath('status', 'discontinued');

        $this->assertTrue(Product::query()->where('ulid', $created->json('ulid'))->exists());

        $page = $this->getJson('/api/products?q=Cola&per_page=1000000')->assertOk();
        $this->assertSame(100, $page->json('meta.per_page'));
        $this->assertNoInternalIds($page->json());
    }

    public function test_product_rejects_foreign_masters_and_unauthorized_create(): void
    {
        $tenantA = $this->signInOwner('prd-iso-a')->assertOk();
        $mastersA = $this->seedMasters();
        $productA = $this->postJson('/api/products', [
            'name' => 'Secret',
            'base_unit_ulid' => $mastersA['pcs'],
        ])->assertCreated()->json('ulid');
        $this->createCashier('prd-iso-a', $tenantA->json('branch.ulid'));

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('prd-iso-b')->assertOk();
        $pcsB = $this->unitUlid('PCS');

        $this->postJson('/api/products', [
            'name' => 'Steal category',
            'category_ulid' => $mastersA['category'],
            'base_unit_ulid' => $pcsB,
        ])->assertNotFound();

        $this->postJson('/api/products', [
            'name' => 'Steal brand',
            'brand_ulid' => $mastersA['brand'],
            'base_unit_ulid' => $pcsB,
        ])->assertNotFound();

        $this->postJson('/api/products', [
            'name' => 'Steal unit',
            'base_unit_ulid' => $mastersA['pcs'],
        ])->assertNotFound();

        $this->getJson('/api/products/'.$productA)->assertNotFound();

        $own = $this->postJson('/api/products', [
            'product_number' => 'P-100',
            'name' => 'Tenant B cola',
            'base_unit_ulid' => $pcsB,
            'barcodes' => [[
                'barcode' => '1234567890123',
                'unit_ulid' => $pcsB,
                'conversion_factor' => '1.00000000',
                'is_primary' => true,
            ]],
        ])->assertCreated();
        $this->assertSame('P-100', $own->json('product_number'));

        $this->putJson('/api/products/'.$own->json('ulid').'/barcodes', [
            'barcodes' => [[
                'barcode' => 'FOREIGN-UNIT',
                'unit_ulid' => $mastersA['pcs'],
                'conversion_factor' => '1.00000000',
                'is_primary' => true,
            ]],
        ])->assertNotFound();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('prd-iso-a', 'cashier-prd-iso-a')->assertOk();

        $this->postJson('/api/products', [
            'name' => 'Cashier product',
            'base_unit_ulid' => $mastersA['pcs'],
        ])->assertForbidden()->assertJsonPath('error.key', 'FORBIDDEN');

        $this->putJson('/api/products/'.$productA.'/prices', [
            'prices' => [['price_type' => 'retail', 'amount' => '1.0000']],
        ])->assertForbidden();
    }

    /**
     * @return array{category: string, brand: string, pcs: string, carton: string}
     */
    private function seedMasters(): array
    {
        $category = $this->postJson('/api/categories', [
            'code' => 'BEV',
            'name' => 'Beverages',
        ])->assertCreated()->json('ulid');

        $brand = $this->postJson('/api/brands', [
            'code' => 'NOBRAND',
            'name' => 'No Brand',
        ])->assertCreated()->json('ulid');

        return [
            'category' => $category,
            'brand' => $brand,
            'pcs' => $this->unitUlid('PCS'),
            'carton' => $this->unitUlid('CARTON'),
        ];
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

    private function createCashier(string $suffix, string $branchUlid): void
    {
        $roles = $this->getJson('/api/roles')->assertOk()->json();
        $cashier = collect($roles)->firstWhere('code', PermissionCatalogue::CASHIER);

        $this->postJson('/api/memberships', [
            'name' => 'Cashier '.$suffix,
            'username' => $this->staffUsername($suffix),
            'recovery_email' => "cashier-{$suffix}@example.com",
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$cashier['ulid']],
            'branches' => [$branchUlid],
        ])->assertCreated();
    }
}
