<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CoaHierarchyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_main_sub_and_account_type_crud_tree_and_ulids(): void
    {
        $this->signInOwner('coa-crud')->assertOk();

        $main = $this->postJson('/api/coa/main-heads', [
            'name' => 'ASSETS',
            'sort_order' => 1,
        ])->assertCreated();
        $main->assertJsonPath('name', 'ASSETS');
        $this->assertNoInternalIds($main->json());
        $mainUlid = $main->json('ulid');

        $sub = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $mainUlid,
            'name' => 'CURRENT ASSETS',
            'sort_order' => 1,
        ])->assertCreated();
        $sub->assertJsonPath('name', 'CURRENT ASSETS');
        $sub->assertJsonPath('main_head.ulid', $mainUlid);
        $this->assertNoInternalIds($sub->json());
        $subUlid = $sub->json('ulid');

        $type = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $subUlid,
            'code' => '0010',
            'name' => 'CASH',
            'is_cash' => true,
            'is_bank' => false,
            'pnl_grouping_label' => 'Cash & Equivalents',
            'hint' => 'Petty and till cash',
            'sort_order' => 10,
        ])->assertCreated();
        $type->assertJsonPath('code', '0010');
        $type->assertJsonPath('name', 'CASH');
        $type->assertJsonPath('is_cash', true);
        $type->assertJsonPath('sub_head.ulid', $subUlid);
        $this->assertNoInternalIds($type->json());
        $typeUlid = $type->json('ulid');

        $this->patchJson('/api/coa/account-types/'.$typeUlid, [
            'name' => 'CASH IN HAND',
            'is_bank' => false,
        ])->assertOk()->assertJsonPath('name', 'CASH IN HAND');

        $tree = $this->getJson('/api/coa/tree')->assertOk()->json('data');
        $this->assertCount(1, $tree);
        $this->assertSame($mainUlid, $tree[0]['ulid']);
        $this->assertSame($subUlid, $tree[0]['sub_heads'][0]['ulid']);
        $this->assertSame($typeUlid, $tree[0]['sub_heads'][0]['account_types'][0]['ulid']);
        $this->assertSame('CASH IN HAND', $tree[0]['sub_heads'][0]['account_types'][0]['name']);

        $this->deleteJson('/api/coa/main-heads/'.$mainUlid)->assertStatus(409);
        $this->deleteJson('/api/coa/sub-heads/'.$subUlid)->assertStatus(409);

        $this->deleteJson('/api/coa/account-types/'.$typeUlid)
            ->assertOk()
            ->assertJsonPath('archived', true);
        $this->getJson('/api/coa/account-types/'.$typeUlid)
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->deleteJson('/api/coa/sub-heads/'.$subUlid)
            ->assertOk()
            ->assertJsonPath('archived', true);
        $this->deleteJson('/api/coa/main-heads/'.$mainUlid)
            ->assertOk()
            ->assertJsonPath('archived', true);
    }

    public function test_coa_tenant_isolation_and_validation(): void
    {
        $this->signInOwner('coa-iso-a')->assertOk();
        $mainUlid = $this->postJson('/api/coa/main-heads', [
            'name' => 'LIABILITIES',
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/coa/main-heads', [
            'name' => 'LIABILITIES',
        ])->assertStatus(422);

        $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $mainUlid,
            'name' => '',
        ])->assertStatus(422);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('coa-iso-b')->assertOk();

        $list = $this->getJson('/api/coa/main-heads')->assertOk()->json('data');
        $this->assertNotContains($mainUlid, collect($list)->pluck('ulid')->all());
        $this->getJson('/api/coa/main-heads/'.$mainUlid)->assertNotFound();
        $this->patchJson('/api/coa/main-heads/'.$mainUlid, [
            'name' => 'Leaked',
        ])->assertNotFound();
        $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $mainUlid,
            'name' => 'FOREIGN',
        ])->assertStatus(422);
    }
}
