<?php

namespace Tests\Feature;

use App\Authz\PermissionService;
use App\Enums\BranchStatus;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Permission;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DailyCashPositionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cash_book_uses_posted_branch_scoped_journals_and_authoritative_totals(): void
    {
        $this->signInOwner('daily-cash-book')->assertOk();

        $cash = $this->leafAccount('Cash In Hand', 'CASH-BOOK-1', '0010');
        $revenue = $this->leafAccount('Cash Book Revenue', 'CASH-REV-1', '0040');
        $expense = $this->leafAccount('Cash Book Expense', 'CASH-EXP-1', '0030');

        $this->postReceiving($cash, $revenue, '2026-10-01', '100.0000', true, 'cash-opening');
        $this->postReceiving($cash, $revenue, '2026-10-08', '50.0000', true, 'cash-receive');
        $this->postPayment($cash, $expense, '2026-10-08', '30.0000', true, 'cash-pay');
        $this->postReceiving($cash, $revenue, '2026-10-08', '999.0000', false, 'cash-draft');

        $tenant = app(TenantContext::class);
        $otherBranch = Branch::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'code' => 'CASH-OTHER',
            'name' => 'Cash Other Branch',
            'status' => BranchStatus::Active,
            'is_default' => false,
        ]);

        $foreignBranchEntry = JournalEntry::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'branch_id' => $otherBranch->id,
            'document_type' => 'receiving_voucher',
            'voucher_number' => 'CRV-OTHER',
            'entry_date' => '2026-10-08',
            'description' => 'Other branch cash',
            'status' => 'posted',
            'created_by' => $tenant->userId(),
            'posted_by' => $tenant->userId(),
            'posted_at' => now(),
        ]);
        JournalLine::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'journal_entry_id' => $foreignBranchEntry->id,
            'account_id' => $cash->id,
            'description' => 'Other branch debit',
            'debit' => '777.0000',
            'credit' => '0.0000',
            'sort_order' => 0,
        ]);
        JournalLine::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'journal_entry_id' => $foreignBranchEntry->id,
            'account_id' => $revenue->id,
            'description' => 'Other branch offset',
            'debit' => '0.0000',
            'credit' => '777.0000',
            'sort_order' => 1,
        ]);

        $accounts = $this->getJson('/api/cash-position/accounts')->assertOk();
        $cashRow = collect($accounts->json())->firstWhere('ulid', $cash->ulid);
        $expenseRow = collect($accounts->json())->firstWhere('ulid', $expense->ulid);

        $this->assertSame('Cash In Hand', $cashRow['name'] ?? null);
        $this->assertTrue((bool) ($cashRow['is_cash'] ?? false));
        $this->assertNull($expenseRow);
        $this->assertNoInternalIds($accounts->json());

        $report = $this->getJson(
            '/api/cash-position/'.$cash->ulid.'/book?date_from=2026-10-08&date_to=2026-10-08'
        )->assertOk();

        $report->assertJsonPath('account.ulid', $cash->ulid)
            ->assertJsonPath('date_from', '2026-10-08')
            ->assertJsonPath('date_to', '2026-10-08')
            ->assertJsonPath('summary.opening', '100.0000')
            ->assertJsonPath('summary.received', '50.0000')
            ->assertJsonPath('summary.paid', '30.0000')
            ->assertJsonPath('summary.balance', '20.0000')
            ->assertJsonPath('summary.closing', '120.0000')
            ->assertJsonCount(2, 'rows');

        $rows = $report->json('rows');
        $this->assertSame('50.0000', $rows[0]['debit']);
        $this->assertSame('0.0000', $rows[0]['credit']);
        $this->assertSame('30.0000', $rows[1]['credit']);
        $this->assertNoInternalIds($report->json());
    }

    public function test_cash_credit_summary_uses_supported_posted_journals_and_marks_missing_sales_accounting(): void
    {
        $this->signInOwner('daily-cash-summary')->assertOk();

        $cash = $this->leafAccount('Summary Cash', 'SUM-CASH', '0010');
        $revenue = $this->leafAccount('Summary Revenue', 'SUM-REV', '0040');
        $expense = $this->leafAccount('Summary Expense', 'SUM-EXP', '0030');
        $payable = $this->leafAccount('Summary Payable', 'SUM-PAY', '0020');

        $this->postedJournal(
            'receiving_voucher',
            '2026-10-01',
            [
                [$cash, '100.0000', '0.0000'],
                [$revenue, '0.0000', '100.0000'],
            ],
            'Opening cash history',
        );

        $this->postedJournal(
            'purchase_invoice',
            '2026-10-11',
            [
                [$expense, '80.0000', '0.0000'],
                [$payable, '0.0000', '80.0000'],
            ],
            'Credit purchase',
        );

        $this->postedJournal(
            'purchase_return',
            '2026-10-11',
            [
                [$payable, '20.0000', '0.0000'],
                [$expense, '0.0000', '20.0000'],
            ],
            'Credit purchase return',
        );

        $this->postedJournal(
            'sale_payment',
            '2026-10-11',
            [
                [$cash, '50.0000', '0.0000'],
                [$revenue, '0.0000', '50.0000'],
            ],
            'Sale recovery',
        );

        $this->postedJournal(
            'payment_voucher',
            '2026-10-11',
            [
                [$expense, '30.0000', '0.0000'],
                [$cash, '0.0000', '30.0000'],
            ],
            'Cash payment',
        );

        $this->postedJournal(
            'purchase_payment',
            '2026-10-11',
            [
                [$payable, '15.0000', '0.0000'],
                [$cash, '0.0000', '15.0000'],
            ],
            'Purchase cash payment',
        );

        $this->postedJournal(
            'receiving_voucher',
            '2026-10-11',
            [
                [$cash, '10.0000', '0.0000'],
                [$revenue, '0.0000', '10.0000'],
            ],
            'Cash receiving',
        );

        $draft = JournalEntry::query()->create([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'branch_id' => app(TenantContext::class)->branchId(),
            'document_type' => 'receiving_voucher',
            'entry_date' => '2026-10-11',
            'description' => 'Draft must not count',
            'status' => 'draft',
            'created_by' => app(TenantContext::class)->userId(),
        ]);
        JournalLine::query()->create([
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'journal_entry_id' => $draft->id,
            'account_id' => $cash->id,
            'debit' => '999.0000',
            'credit' => '0.0000',
            'sort_order' => 0,
        ]);

        $response = $this->getJson('/api/cash-position/summary?date=2026-10-11')
            ->assertOk()
            ->assertJsonPath('date', '2026-10-11')
            ->assertJsonPath('metrics.sales.supported', false)
            ->assertJsonPath('metrics.sales.cash', null)
            ->assertJsonPath('metrics.retail_sale.supported', false)
            ->assertJsonPath('metrics.sale_return.supported', false)
            ->assertJsonPath('metrics.purchase.supported', true)
            ->assertJsonPath('metrics.purchase.cash', '0.0000')
            ->assertJsonPath('metrics.purchase.credit', '80.0000')
            ->assertJsonPath('metrics.purchase_return.credit', '20.0000')
            ->assertJsonPath('metrics.recovery.cash', '50.0000')
            ->assertJsonPath('metrics.recovery.credit', '0.0000')
            ->assertJsonPath('metrics.payment.cash', '45.0000')
            ->assertJsonPath('metrics.receivings.cash', '10.0000');

        $position = collect($response->json('positions'))
            ->firstWhere('account.ulid', $cash->ulid);

        $this->assertSame('100.0000', $position['opening'] ?? null);
        $this->assertSame('60.0000', $position['debit'] ?? null);
        $this->assertSame('45.0000', $position['credit'] ?? null);
        $this->assertSame('115.0000', $position['closing'] ?? null);
        $this->assertNotEmpty($response->json('unsupported_reasons.sales'));
        $this->assertNoInternalIds($response->json());
    }

    public function test_cash_book_rejects_non_cash_and_cross_tenant_accounts(): void
    {
        $this->signInOwner('daily-cash-security-a')->assertOk();
        $cash = $this->leafAccount('Tenant A Cash', 'CASH-A', '0010');
        $expense = $this->leafAccount('Tenant A Expense', 'EXP-A', '0030');

        $this->getJson('/api/cash-position/'.$expense->ulid.'/book')
            ->assertNotFound();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('daily-cash-security-b')->assertOk();

        $this->getJson('/api/cash-position/'.$cash->ulid.'/book')
            ->assertNotFound();
    }

    public function test_cash_position_requires_accounting_journal_view_permission(): void
    {
        $this->signInOwner('daily-cash-permission')->assertOk();

        $tenant = app(TenantContext::class);
        $role = $tenant->membership()
            ->roles()
            ->where('roles.tenant_id', $tenant->tenantId())
            ->firstOrFail();
        $permission = Permission::query()
            ->where('key', 'accounting.journal.view')
            ->firstOrFail();

        $role->permissions()->detach($permission->id);
        app()->forgetInstance(PermissionService::class);

        $this->getJson('/api/cash-position/accounts')->assertForbidden();
        $this->getJson('/api/cash-position/summary')->assertForbidden();
    }

    /**
     * @param array<int, array{0: Account, 1: string, 2: string}> $lines
     */
    private function postedJournal(string $documentType, string $date, array $lines, string $description): JournalEntry
    {
        $tenant = app(TenantContext::class);

        $entry = JournalEntry::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'branch_id' => $tenant->branchId(),
            'document_type' => $documentType,
            'entry_date' => $date,
            'description' => $description,
            'status' => 'posted',
            'created_by' => $tenant->userId(),
            'posted_by' => $tenant->userId(),
            'posted_at' => now(),
        ]);

        foreach ($lines as $index => [$account, $debit, $credit]) {
            JournalLine::query()->create([
                'tenant_id' => $tenant->tenantId(),
                'journal_entry_id' => $entry->id,
                'account_id' => $account->id,
                'description' => $description,
                'debit' => $debit,
                'credit' => $credit,
                'sort_order' => $index,
            ]);
        }

        return $entry;
    }

    private function leafAccount(string $name, string $code, string $typeCode): Account
    {
        $tenant = app(TenantContext::class);
        $type = AccountType::query()
            ->forTenant($tenant->tenantId())
            ->where('code', $typeCode)
            ->firstOrFail();

        return Account::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'code' => $code,
            'name' => $name,
            'account_type_id' => $type->id,
            'is_active' => true,
            'created_by' => $tenant->userId(),
        ]);
    }

    private function postReceiving(
        Account $cash,
        Account $other,
        string $date,
        string $amount,
        bool $post,
        string $key,
    ): void {
        $response = $this->postJson('/api/vouchers', [
            'type' => 'receiving',
            'entry_date' => $date,
            'header_account_ulid' => $cash->ulid,
            'lines' => [[
                'account_ulid' => $other->ulid,
                'narration' => 'Cash received',
                'amount' => $amount,
            ]],
        ], ['Idempotency-Key' => $key])->assertCreated();

        if ($post) {
            $this->postJson('/api/vouchers/'.$response->json('ulid').'/post')->assertOk();
        }
    }

    private function postPayment(
        Account $cash,
        Account $other,
        string $date,
        string $amount,
        bool $post,
        string $key,
    ): void {
        $response = $this->postJson('/api/vouchers', [
            'type' => 'payment',
            'entry_date' => $date,
            'header_account_ulid' => $cash->ulid,
            'lines' => [[
                'account_ulid' => $other->ulid,
                'narration' => 'Cash paid',
                'amount' => $amount,
            ]],
        ], ['Idempotency-Key' => $key])->assertCreated();

        if ($post) {
            $this->postJson('/api/vouchers/'.$response->json('ulid').'/post')->assertOk();
        }
    }
}
