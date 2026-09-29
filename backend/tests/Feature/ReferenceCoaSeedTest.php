<?php

namespace Tests\Feature;

use App\Accounting\ReferenceCoaSeeder;
use App\Models\Account;
use App\Models\AccountMainHead;
use App\Models\AccountSubHead;
use App\Models\AccountType;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReferenceCoaSeedTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reference_coa_seed_hierarchy_idempotent_and_tenant_isolated(): void
    {
        $sessionA = $this->provisionOwner('ref-coa-a');
        $tenantA = $sessionA->tenant;
        $sessionB = $this->provisionOwner('ref-coa-b');
        $tenantB = $sessionB->tenant;

        $accountsBeforeA = Account::query()->forTenant((int) $tenantA->id)->count();
        $accountsBeforeB = Account::query()->forTenant((int) $tenantB->id)->count();

        $seeder = app(ReferenceCoaSeeder::class);
        $first = $seeder->seed($tenantA);
        $this->assertSame(6, $first['main_heads']);
        $this->assertSame(9, $first['sub_heads']);
        $this->assertSame(25, $first['account_types']);
        $this->assertSame(0, $first['accounts']);

        $second = $seeder->seed($tenantA);
        $this->assertSame(6, $second['main_heads']);
        $this->assertSame(9, $second['sub_heads']);
        $this->assertSame(25, $second['account_types']);
        $this->assertSame(0, $second['accounts']);

        $this->assertSame(6, AccountMainHead::query()->forTenant((int) $tenantA->id)->count());
        $this->assertSame(9, AccountSubHead::query()->forTenant((int) $tenantA->id)->count());
        $this->assertSame(25, AccountType::query()->forTenant((int) $tenantA->id)->whereNotNull('code')->count());
        $this->assertSame($accountsBeforeA, Account::query()->forTenant((int) $tenantA->id)->count());

        $this->assertSame(0, AccountMainHead::query()->forTenant((int) $tenantB->id)->count());
        $this->assertSame(0, AccountSubHead::query()->forTenant((int) $tenantB->id)->count());
        $this->assertSame(0, AccountType::query()->forTenant((int) $tenantB->id)->count());
        $this->assertSame($accountsBeforeB, Account::query()->forTenant((int) $tenantB->id)->count());

        $this->assertHierarchy($tenantA, '0011', 'CURRENT ASSETS', 'ASSETS');
        $this->assertHierarchy($tenantA, '0014', 'FIXED ASSETS', 'ASSETS');
        $this->assertHierarchy($tenantA, '0020', 'SHORT TERM LIABILITIES', 'LIABILITIES');
        $this->assertHierarchy($tenantA, '0022', 'LONG TERM LIABILITIES', 'LIABILITIES');
        $this->assertHierarchy($tenantA, '0030', 'EXPENSES', 'EXPENSES');
        $this->assertHierarchy($tenantA, '0040', 'REVENUES', 'REVENUES');
        $this->assertHierarchy($tenantA, '0050', 'CAPITAL', 'CAPITAL');
        $this->assertHierarchy($tenantA, '0060', 'INVENTORY', 'INVENTORY');

        $ar = AccountType::query()->forTenant((int) $tenantA->id)->where('code', '0011')->firstOrFail();
        $this->assertTrue($ar->is_receivable);
        $bank = AccountType::query()->forTenant((int) $tenantA->id)->where('code', '0012')->firstOrFail();
        $this->assertTrue($bank->is_bank);
        $ap = AccountType::query()->forTenant((int) $tenantA->id)->where('code', '0020')->firstOrFail();
        $this->assertTrue($ap->is_payable);

        $this->artisan('bluepos:seed-reference-coa', ['tenantUlid' => $tenantA->ulid])
            ->assertSuccessful();
        $this->assertSame(6, AccountMainHead::query()->forTenant((int) $tenantA->id)->count());
        $this->assertSame(25, AccountType::query()->forTenant((int) $tenantA->id)->count());
        $this->assertSame($accountsBeforeA, Account::query()->forTenant((int) $tenantA->id)->count());
    }

    private function assertHierarchy(Tenant $tenant, string $code, string $subName, string $mainName): void
    {
        $type = AccountType::query()
            ->forTenant((int) $tenant->id)
            ->where('code', $code)
            ->with('subHead.mainHead')
            ->firstOrFail();

        $this->assertSame($subName, $type->subHead?->name);
        $this->assertSame($mainName, $type->subHead?->mainHead?->name);
    }
}
