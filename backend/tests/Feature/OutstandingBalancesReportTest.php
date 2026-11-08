<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Enums\JournalStatus;
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
            ->assertJsonPath('data.0.future_due', null)
            ->assertJsonPath('data.0.last_receipt_date', null)
            ->assertJsonPath('data.0.received_amount', null);
        $this->assertNoInternalIds($current->json());

        $this->getJson('/api/reports/accounts/outstanding-balances?as_of=bad&party_type=customer')
            ->assertUnprocessable();
    }

    public function test_receipt_reversal_after_as_of_does_not_erase_historical_receipt(): void
    {
        $this->signInOwner('outstanding-reversal-cutoff')->assertOk();
        $context = app(TenantContext::class);
        $customer = $this->postJson('/api/parties', [
            'party_type' => 'customer', 'code' => 'OUT-REV-1',
            'name' => 'Receipt Reversal Cutoff Customer',
            'account_type_ulid' => $this->referenceAccountTypeUlid('0011'),
        ])->assertCreated()->json('ulid');
        $leaf = Account::query()->forTenant($context->tenantId())
            ->whereHas('customer', fn ($q) => $q->where('ulid', $customer))
            ->firstOrFail();

        $receipt = JournalEntry::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'document_type' => JournalEntry::DOCUMENT_RECEIVING_VOUCHER,
            'entry_date' => '2026-10-10',
            'voucher_number' => 'REV-CUTOFF-RECEIPT',
            'status' => JournalStatus::Posted,
            'created_by' => $context->userId(),
            'posted_by' => $context->userId(),
            'posted_at' => now(),
        ]);
        JournalLine::query()->create([
            'tenant_id' => $context->tenantId(),
            'journal_entry_id' => $receipt->id,
            'account_id' => $leaf->id,
            'debit' => '0.0000',
            'credit' => '40.0000',
            'sort_order' => 1,
        ]);

        $reversal = JournalEntry::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'document_type' => JournalEntry::DOCUMENT_RECEIVING_VOUCHER,
            'entry_date' => '2026-10-20',
            'voucher_number' => 'REV-CUTOFF-REVERSAL',
            'status' => JournalStatus::Posted,
            'reverses_journal_entry_id' => $receipt->id,
            'created_by' => $context->userId(),
            'posted_by' => $context->userId(),
            'posted_at' => now(),
        ]);
        JournalLine::query()->create([
            'tenant_id' => $context->tenantId(),
            'journal_entry_id' => $reversal->id,
            'account_id' => $leaf->id,
            'debit' => '40.0000',
            'credit' => '0.0000',
            'sort_order' => 1,
        ]);

        $base = '/api/reports/accounts/outstanding-balances?party_type=customer&q=Receipt%20Reversal%20Cutoff';
        $before = $this->getJson($base.'&as_of=2026-10-15')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.last_receipt_date', '2026-10-10')
            ->assertJsonPath('data.0.received_amount', '40.0000')
            ->assertJsonPath('data.0.days_since_receipt', 5);
        $this->assertNoInternalIds($before->json());

        $after = $this->getJson($base.'&as_of=2026-10-25')
            ->assertOk()->assertJsonPath('data.0.last_receipt_date', null)
            ->assertJsonPath('data.0.received_amount', null)
            ->assertJsonPath('data.0.balance', '0.0000');
        $this->assertNoInternalIds($after->json());
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
