<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SaleSchemeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_packaging_product_flag_and_filter(): void
    {
        $this->signInOwner('pkg-flag')->assertOk();
        $pcs = $this->unitUlid('PCS');

        $bag = $this->postJson('/api/products', [
            'name' => 'Shopping Bag',
            'base_unit_ulid' => $pcs,
            'is_packaging' => true,
            'max_free_qty_per_sale' => '3.000000',
        ])->assertCreated();

        $bag->assertJsonPath('is_packaging', true);
        $bag->assertJsonPath('max_free_qty_per_sale', '3.000000');
        $this->assertNoInternalIds($bag->json());

        $this->postJson('/api/products', [
            'name' => 'Normal Item',
            'base_unit_ulid' => $pcs,
        ])->assertCreated();

        $list = $this->getJson('/api/products?packaging=1')->assertOk();
        $names = collect($list->json('data'))->pluck('name')->all();
        $this->assertContains('Shopping Bag', $names);
        $this->assertNotContains('Normal Item', $names);
    }

    public function test_sale_scheme_crud_with_apply_mode(): void
    {
        $this->signInOwner('scheme-crud')->assertOk();
        $pcs = $this->unitUlid('PCS');

        $reward = $this->postJson('/api/products', [
            'name' => 'Free Product X',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $created = $this->postJson('/api/sale-schemes', [
            'name' => '5000 Sale Free Product',
            'apply_mode' => 'salesman',
            'min_sale_amount' => '5000.0000',
            'reward_product_ulid' => $reward,
            'max_reward_qty' => '1.000000',
        ])->assertCreated();

        $created->assertJsonPath('apply_mode', 'salesman');
        $created->assertJsonPath('reward_product.ulid', $reward);
        $this->assertNoInternalIds($created->json());
        $schemeUlid = $created->json('ulid');

        $this->patchJson('/api/sale-schemes/'.$schemeUlid, [
            'apply_mode' => 'auto',
            'min_sale_amount' => '10000.0000',
        ])->assertOk()
            ->assertJsonPath('apply_mode', 'auto')
            ->assertJsonPath('min_sale_amount', '10000.0000');

        $this->getJson('/api/sale-schemes')->assertOk()->assertJsonCount(1);

        $this->deleteJson('/api/sale-schemes/'.$schemeUlid)->assertOk();
        $this->getJson('/api/sale-schemes/'.$schemeUlid)->assertOk()
            ->assertJsonPath('is_active', false);
    }

    public function test_evaluate_offers_respects_threshold_and_apply_mode(): void
    {
        $this->signInOwner('scheme-eval')->assertOk();
        $pcs = $this->unitUlid('PCS');

        $this->postJson('/api/products', [
            'name' => 'Gift Box',
            'base_unit_ulid' => $pcs,
            'is_packaging' => true,
            'max_free_qty_per_sale' => '2.000000',
        ])->assertCreated();

        $reward = $this->postJson('/api/products', [
            'name' => 'Promo Soap',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/sale-schemes', [
            'name' => 'Spend 5000 Free Soap',
            'apply_mode' => 'salesman',
            'min_sale_amount' => '5000.0000',
            'reward_product_ulid' => $reward,
            'max_reward_qty' => '1.000000',
        ])->assertCreated();

        $this->postJson('/api/sale-schemes', [
            'name' => 'Spend 10000 Auto Free Soap',
            'apply_mode' => 'auto',
            'min_sale_amount' => '10000.0000',
            'reward_product_ulid' => $reward,
            'max_reward_qty' => '1.000000',
            'is_stackable' => true,
        ])->assertCreated();

        $below = $this->postJson('/api/sale-offers/evaluate', [
            'subtotal' => '4999.9999',
        ])->assertOk();
        $this->assertCount(1, $below->json('packaging'));
        $this->assertCount(0, $below->json('schemes'));

        $mid = $this->postJson('/api/sale-offers/evaluate', [
            'subtotal' => '5500.0000',
        ])->assertOk();
        $this->assertCount(1, $mid->json('schemes'));
        $mid->assertJsonPath('schemes.0.apply_mode', 'salesman');
        $mid->assertJsonPath('schemes.0.requires_salesman_decision', true);
        $mid->assertJsonPath('schemes.0.auto_apply', false);

        $high = $this->postJson('/api/sale-offers/evaluate', [
            'subtotal' => '10000.0000',
        ])->assertOk();
        $this->assertCount(2, $high->json('schemes'));
        $modes = collect($high->json('schemes'))->pluck('apply_mode')->sort()->values()->all();
        $this->assertSame(['auto', 'salesman'], $modes);
    }

    public function test_sale_scheme_is_tenant_scoped(): void
    {
        $this->signInOwner('scheme-a')->assertOk();
        $pcs = $this->unitUlid('PCS');
        $reward = $this->postJson('/api/products', [
            'name' => 'Reward A',
            'base_unit_ulid' => $pcs,
        ])->assertCreated()->json('ulid');
        $schemeUlid = $this->postJson('/api/sale-schemes', [
            'name' => 'Tenant A Scheme',
            'apply_mode' => 'salesman',
            'min_sale_amount' => '1000.0000',
            'reward_product_ulid' => $reward,
            'max_reward_qty' => '1.000000',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('scheme-b')->assertOk();
        $this->getJson('/api/sale-schemes/'.$schemeUlid)->assertNotFound();
        $this->patchJson('/api/sale-schemes/'.$schemeUlid, [
            'name' => 'Hack',
        ])->assertNotFound();
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
