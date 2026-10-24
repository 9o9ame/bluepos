<?php

namespace Tests\Feature;

use App\Enums\BranchAccess;
use App\Enums\JournalStatus;
use App\Models\Account;
use App\Models\AccountMainHead;
use App\Models\AccountSubHead;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TrialBalanceReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_trial_balance_uses_posted_current_branch_entries_as_of_date_and_keeps_zero_balance_accounts(): void
    {
        $this->signInOwner('trial-balance')->assertOk();

        $context = app(TenantContext::class);
        [$cash, $revenue, $unused] = $this->accounts($context->tenantId());

        $this->journal(
            tenantId: $context->tenantId(),
            branchId: $context->branchId(),
            date: '2026-10-20',
            status: JournalStatus::Posted,
            lines: [
                [$cash, '150.0000', '0.0000'],
                [$revenue, '0.0000', '150.0000'],
            ],
        );

        $this->journal(
            tenantId: $context->tenantId(),
            branchId: $context->branchId(),
            date: '2026-10-25',
            status: JournalStatus::Posted,
            lines: [
                [$cash, '50.0000', '0.0000'],
                [$revenue, '0.0000', '50.0000'],
            ],
        );

        $this->journal(
            tenantId: $context->tenantId(),
            branchId: $context->branchId(),
            date: '2026-10-20',
            status: JournalStatus::Draft,
            lines: [
                [$cash, '75.0000', '0.0000'],
                [$revenue, '0.0000', '75.0000'],
            ],
        );

        $otherBranch = Branch::factory()->create(['tenant_id' => $context->tenantId()]);
        $this->journal(
            tenantId: $context->tenantId(),
            branchId: (int) $otherBranch->id,
            date: '2026-10-20',
            status: JournalStatus::Posted,
            lines: [
                [$cash, '25.0000', '0.0000'],
                [$revenue, '0.0000', '25.0000'],
            ],
        );

        $response = $this->getJson('/api/reports/accounting/trial-balance?as_of_date=2026-10-24&group_by=level_wise&arrange_by=code_asc')
            ->assertOk()
            ->assertJsonPath('totals.debit', '150.0000')
            ->assertJsonPath('totals.credit', '150.0000')
            ->assertJsonPath('totals.difference', '0.0000')
            ->assertJsonPath('totals.difference_side', 'Dr');

        $rows = collect($response->json('groups'))->flatMap(fn (array $group) => $group['rows']);

        $this->assertSame('150.0000', $rows->firstWhere('account.ulid', $cash->ulid)['debit']);
        $this->assertSame('150.0000', $rows->firstWhere('account.ulid', $revenue->ulid)['credit']);
        $this->assertSame('0.0000', $rows->firstWhere('account.ulid', $unused->ulid)['debit']);
        $this->assertSame('0.0000', $rows->firstWhere('account.ulid', $unused->ulid)['credit']);
        $this->assertNoInternalIds($response->json());
    }

    public function test_trial_balance_requires_accounting_report_permission(): void
    {
        $this->signInOwner('trial-balance-denied')->assertOk();
        $this->restrictCurrentMembershipWithoutPermission('reports.accounting');

        $this->getJson('/api/reports/accounting/trial-balance?as_of_date=2026-10-24')
            ->assertForbidden();
    }

    /**
     * @return array{0:Account,1:Account,2:Account}
     */
    private function accounts(int $tenantId): array
    {
        $assets = AccountMainHead::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'TEST ASSETS',
            'sort_order' => 901,
            'is_active' => true,
        ]);
        $assetSub = AccountSubHead::query()->create([
            'tenant_id' => $tenantId,
            'main_head_id' => $assets->id,
            'name' => 'TEST CURRENT ASSETS',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $assetType = AccountType::query()->create([
            'tenant_id' => $tenantId,
            'sub_head_id' => $assetSub->id,
            'code' => 'TB01',
            'name' => 'TEST CASH',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $revenueHead = AccountMainHead::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'TEST REVENUES',
            'sort_order' => 902,
            'is_active' => true,
        ]);
        $revenueSub = AccountSubHead::query()->create([
            'tenant_id' => $tenantId,
            'main_head_id' => $revenueHead->id,
            'name' => 'TEST REVENUE HEAD',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $revenueType = AccountType::query()->create([
            'tenant_id' => $tenantId,
            'sub_head_id' => $revenueSub->id,
            'code' => 'TB02',
            'name' => 'TEST SALES',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        return [
            Account::query()->create([
                'tenant_id' => $tenantId,
                'code' => 'TB-CASH',
                'name' => 'Trial Cash',
                'account_type_id' => $assetType->id,
                'is_active' => true,
            ]),
            Account::query()->create([
                'tenant_id' => $tenantId,
                'code' => 'TB-SALES',
                'name' => 'Trial Sales',
                'account_type_id' => $revenueType->id,
                'is_active' => true,
            ]),
            Account::query()->create([
                'tenant_id' => $tenantId,
                'code' => 'TB-ZERO',
                'name' => 'Trial Zero',
                'account_type_id' => $assetType->id,
                'is_active' => true,
            ]),
        ];
    }

    /**
     * @param list<array{0:Account,1:string,2:string}> $lines
     */
    private function journal(
        int $tenantId,
        int $branchId,
        string $date,
        JournalStatus $status,
        array $lines,
    ): void {
        $entry = JournalEntry::query()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'document_type' => 'trial_balance_test',
            'entry_date' => $date,
            'description' => 'Trial Balance Test',
            'status' => $status,
            'created_by' => app(TenantContext::class)->userId(),
            'posted_by' => $status === JournalStatus::Posted ? app(TenantContext::class)->userId() : null,
            'posted_at' => $status === JournalStatus::Posted ? now() : null,
        ]);

        foreach ($lines as $index => [$account, $debit, $credit]) {
            JournalLine::query()->create([
                'tenant_id' => $tenantId,
                'journal_entry_id' => $entry->id,
                'account_id' => $account->id,
                'description' => 'Trial Balance Test',
                'debit' => $debit,
                'credit' => $credit,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
