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
        $this->assertSame('100.0000', $rows[1]['credit']);
        $this->assertNoInternalIds($report->json());
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
