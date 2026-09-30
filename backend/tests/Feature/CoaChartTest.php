<?php

namespace Tests\Feature;

use App\Models\Account;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CoaChartTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{ap: string, ar: string, cash: string}
     */
    private function seedCoa(): array
    {
        $assets = $this->postJson('/api/coa/main-heads', [
            'name' => 'ASSETS',
            'sort_order' => 1,
        ])->assertCreated()->json('ulid');

        $liab = $this->postJson('/api/coa/main-heads', [
            'name' => 'LIABILITIES',
            'sort_order' => 2,
        ])->assertCreated()->json('ulid');

        $current = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $assets,
            'name' => 'CURRENT ASSETS',
            'sort_order' => 10,
        ])->assertCreated()->json('ulid');

        $short = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $liab,
            'name' => 'SHORT TERM LIABILITIES',
            'sort_order' => 20,
        ])->assertCreated()->json('ulid');

        $ar = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $current,
            'code' => '0011',
            'name' => 'ACCOUNT RECEIVABLE',
            'is_receivable' => true,
            'sort_order' => 11,
        ])->assertCreated()->json('ulid');

        $cash = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $current,
            'code' => '0010',
            'name' => 'CASH',
            'is_cash' => true,
            'sort_order' => 10,
        ])->assertCreated()->json('ulid');

        $ap = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $short,
            'code' => '0020',
            'name' => 'ACCOUNT PAYABLE',
            'is_payable' => true,
            'sort_order' => 20,
        ])->assertCreated()->json('ulid');

        return ['ap' => $ap, 'ar' => $ar, 'cash' => $cash];
    }

    public function test_leaf_hierarchy_supplier_customer_manual_and_no_duplicate_on_resave(): void
    {
        $this->signInOwner('coa-leaf')->assertOk();
        $coa = $this->seedCoa();

        $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => '00010',
            'name' => 'PAK TRADERS',
            'account_type_ulid' => $coa['ap'],
        ])->assertCreated();

        $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => '00020',
            'name' => 'CUSTOMER A',
            'account_type_ulid' => $coa['ar'],
        ])->assertCreated();

        $manual = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => '00001',
            'name' => 'CASH IN HAND',
            'account_type_ulid' => $coa['cash'],
        ])->assertCreated();
        $manualUlid = $manual->json('ulid');

        $this->assertSame(3, Account::query()->count());

        $flat = $this->getJson('/api/coa/chart')->assertOk();
        $flat->assertJsonPath('mode', 'flat');
        $this->assertCount(3, $flat->json('flat'));
        $this->assertNoInternalIds($flat->json());

        $vendorRow = collect($flat->json('flat'))->firstWhere('leaf_source', 'supplier');
        $this->assertSame('02-LIABILITIES', $vendorRow['main_head_label']);
        $this->assertSame('020-SHORT TERM LIABILITIES', $vendorRow['head_label']);
        $this->assertSame('0020-ACCOUNT PAYABLE', $vendorRow['sub_head_label']);
        $this->assertSame('00010-PAK TRADERS', $vendorRow['account_label']);

        $customerRow = collect($flat->json('flat'))->firstWhere('leaf_source', 'customer');
        $this->assertSame('01-ASSETS', $customerRow['main_head_label']);
        $this->assertStringContainsString('ACCOUNT RECEIVABLE', $customerRow['sub_head_label']);

        $manualRow = collect($flat->json('flat'))->firstWhere('account_ulid', $manualUlid);
        $this->assertSame('manual', $manualRow['leaf_source']);
        $this->assertSame('00001-CASH IN HAND', $manualRow['account_label']);

        $account = Account::query()->where('ulid', $manualUlid)->firstOrFail();
        $this->assertNotNull($account->accountType?->subHead?->mainHead);

        $this->patchJson('/api/parties/'.$manual->json('ulid').'?type=account', [
            'party_type' => 'account',
            'name' => 'CASH IN HAND',
            'code' => '00001',
            'account_type_ulid' => $coa['cash'],
        ])->assertOk();

        $this->assertSame(3, Account::query()->count());

        $grouped = $this->getJson('/api/coa/chart?grouped=1')->assertOk();
        $grouped->assertJsonPath('mode', 'grouped');
        $this->assertNoInternalIds($grouped->json());

        $assetsNode = collect($grouped->json('grouped'))->firstWhere('label', '01-ASSETS');
        $this->assertNotNull($assetsNode);
        $cashType = collect($assetsNode['heads'][0]['sub_heads'] ?? [])
            ->firstWhere('label', '0010-CASH');
        $this->assertNotNull($cashType);
        $this->assertSame('00001-CASH IN HAND', $cashType['accounts'][0]['label'] ?? null);

        $liabNode = collect($grouped->json('grouped'))->firstWhere('label', '02-LIABILITIES');
        $apType = collect($liabNode['heads'][0]['sub_heads'] ?? [])
            ->firstWhere('label', '0020-ACCOUNT PAYABLE');
        $this->assertSame('00010-PAK TRADERS', $apType['accounts'][0]['label'] ?? null);
    }

    public function test_coa_chart_tenant_isolation_and_party_linked_accounts_excluded_from_account_list(): void
    {
        $this->signInOwner('coa-chart-a')->assertOk();
        $coa = $this->seedCoa();

        $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'v1',
            'name' => 'Vendor One',
            'account_type_ulid' => $coa['ap'],
        ])->assertCreated();

        $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => 'm1',
            'name' => 'Manual One',
            'account_type_ulid' => $coa['cash'],
        ])->assertCreated();

        $flatA = $this->getJson('/api/coa/chart')->assertOk()->json('flat');
        $this->assertCount(2, $flatA);

        $accountsList = $this->getJson('/api/parties?type=account')->assertOk()->json('data');
        $this->assertCount(1, $accountsList);
        $this->assertSame('Manual One', $accountsList[0]['name']);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('coa-chart-b')->assertOk();

        $this->getJson('/api/coa/chart')->assertOk()->assertJsonPath('flat', []);
    }

    public function test_customer_code_rejects_collision_with_existing_ledger_account(): void
    {
        $this->signInOwner('coa-code-dup')->assertOk();
        $coa = $this->seedCoa();

        $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => '1234',
            'name' => 'Existing Leaf',
            'account_type_ulid' => $coa['cash'],
        ])->assertCreated();

        $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => '1234',
            'name' => 'Customer Clash',
            'account_type_ulid' => $coa['ar'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonPath('error.fields.code.0', 'This code is already used by another party or ledger account.');
    }
}
