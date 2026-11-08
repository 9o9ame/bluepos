<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BusinessSetting;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class OutstandingBalancesReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_outstanding_balances_use_posted_as_of_journals_and_authorized_party_details(): void
    {
        $this->signInOwner('outstanding-balance')->assertOk();
        $equity = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => 'OUT-EQUITY',
            'name' => 'Outstanding Opening Equity',
            'account_type_ulid' => $this->referenceAccountTypeUlid('0050'),
        ])->assertCreated();
        BusinessSetting::query()->forTenant(app(TenantContext::class)->tenantId())
            ->update(['opening_balance_equity_account_id' => Account::query()
                ->where('ulid', $equity->json('ulid'))->value('id')]);

        $customer = $this->postJson('/api/parties', [
            'party_type' => 'customer', 'code' => 'OUT-C1',
            'name' => 'Outstanding Alpha Customer', 'address' => 'Sample Address',
            'account_type_ulid' => $this->referenceAccountTypeUlid('0011'),
        ])->assertCreated()->json('ulid');
        $open = $this->postJson('/api/parties/'.$customer.'/opening-balances?type=customer', [
            'opening_date' => '2026-10-10',
            'narration' => 'Customer opening debt',
            'debit' => '250.0000',
            'credit' => '0.0000',
        ])->assertCreated()->json('ulid');
        $this->postJson('/api/parties/'.$customer.'/opening-balances/'.$open.'/post?type=customer')
            ->assertOk();

        $this->getJson('/api/reports/accounts/outstanding-balances?as_of=2026-10-09&party_type=customer&q=Outstanding%20Alpha')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.balance', '0.0000');

        $current = $this->getJson('/api/reports/accounts/outstanding-balances?as_of=2026-10-10&party_type=customer&mode=current_future&q=Outstanding%20Alpha')
            ->assertOk()->assertJsonPath('data.0.party_ulid', $customer)
            ->assertJsonPath('data.0.address', 'Sample Address')
            ->assertJsonPath('data.0.balance', '250.0000')
            ->assertJsonPath('data.0.current_due', null)
            ->assertJsonPath('data.0.future_due', null);
        $this->assertNoInternalIds($current->json());

        $this->getJson('/api/reports/accounts/outstanding-balances?as_of=bad&party_type=customer')
            ->assertUnprocessable();
    }

    public function test_outstanding_balance_is_tenant_isolated(): void
    {
        $this->signInOwner('outstanding-tenant-a')->assertOk();
        $this->postJson('/api/parties', [
            'party_type' => 'customer', 'code' => 'OUT-SECRET',
            'name' => 'Secret Outstanding Party',
            'account_type_ulid' => $this->referenceAccountTypeUlid('0011'),
        ])->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('outstanding-tenant-b')->assertOk();
        $this->getJson('/api/reports/accounts/outstanding-balances?as_of=2026-10-10&party_type=customer&q=Secret%20Outstanding')
            ->assertOk()->assertJsonPath('meta.total', 0);
    }
}
