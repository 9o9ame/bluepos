<?php

namespace Tests\Feature;

use App\Authz\PermissionService;
use App\Enums\BranchStatus;
use App\Enums\CashierShiftStatus;
use App\Enums\ProductStatus;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Unit;
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

    public function test_reports_cash_book_is_posted_branch_scoped_and_validates_selected_accounts(): void
    {
        $this->signInOwner('reports-cash-book')->assertOk();
        $cash = $this->leafAccount('Reports Cash', 'REP-CASH', '0010');
        $revenue = $this->leafAccount('Reports Revenue', 'REP-REV', '0040');

        $this->postReceiving($cash, $revenue, '2026-09-30', '100.0000', true, 'rep-opening');
        $this->postReceiving($cash, $revenue, '2026-10-08', '25.2500', true, 'rep-in');
        $this->postReceiving($cash, $revenue, '2026-10-08', '500.0000', false, 'rep-draft');

        $this->getJson('/api/reports/accounting/cash-book/accounts')->assertOk()
            ->assertJsonFragment(['ulid' => $cash->ulid]);

        $url = '/api/reports/accounting/cash-book?start_date=2026-10-01&end_date=2026-10-24'
            .'&account_ulids[0]='.$cash->ulid;
        $response = $this->getJson($url)->assertOk()
            ->assertJsonPath('totals.opening', '100.0000')
            ->assertJsonPath('totals.received', '25.2500')
            ->assertJsonPath('totals.paid', '0.0000')
            ->assertJsonPath('totals.closing', '125.2500')
            ->assertJsonCount(1, 'rows');
        $this->assertNoInternalIds($response->json());

        $this->getJson('/api/reports/accounting/cash-book?start_date=2026-10-24'
            .'&end_date=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/reports/accounting/cash-book?start_date=2026-10-01'
            .'&end_date=2026-10-24&account_ulids[0]='.$revenue->ulid)->assertNotFound();
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
        $this->assertSame(
            'receiving_voucher',
            collect($response->json('filter_options.document'))->firstWhere('value', 'receiving_voucher')['value'] ?? null,
        );
        $this->assertNotEmpty($response->json('filter_options.user_name'));
        $this->assertNoInternalIds($response->json());

        $filtered = $this->getJson(
            '/api/cash-position/summary?date=2026-10-11&filter_by=document&only_for=receiving_voucher'
        )->assertOk()
            ->assertJsonPath('filter_by', 'document')
            ->assertJsonPath('only_for', 'receiving_voucher')
            ->assertJsonPath('metrics.receivings.cash', '10.0000')
            ->assertJsonPath('metrics.payment.cash', '0.0000')
            ->assertJsonPath('metrics.purchase.credit', '0.0000');

        $filteredPosition = collect($filtered->json('positions'))
            ->firstWhere('account.ulid', $cash->ulid);

        $this->assertSame('100.0000', $filteredPosition['opening'] ?? null);
        $this->assertSame('10.0000', $filteredPosition['debit'] ?? null);
        $this->assertSame('0.0000', $filteredPosition['credit'] ?? null);
        $this->assertSame('110.0000', $filteredPosition['closing'] ?? null);

        $this->getJson(
            '/api/cash-position/summary?date=2026-10-11&filter_by=area_name&only_for=RYK'
        )->assertUnprocessable();
    }

    public function test_dashboard_returns_branch_scoped_cash_series_and_trial_balance_accounts(): void
    {
        $this->signInOwner('daily-cash-dashboard')->assertOk();

        $cash = $this->leafAccount('Dashboard Cash', 'DASH-CASH', '0010');
        $bank = $this->leafAccount('Dashboard Bank', 'DASH-BANK', '0012');
        $expense = $this->leafAccount('Dashboard Expense', 'DASH-EXP', '0030');
        $revenue = $this->leafAccount('Dashboard Revenue', 'DASH-REV', '0040');

        $this->postedJournal(
            'opening_balance',
            '2026-10-04',
            [
                [$cash, '100.0000', '0.0000'],
                [$revenue, '0.0000', '100.0000'],
            ],
            'Dashboard opening',
        );

        $this->postedJournal(
            'receiving_voucher',
            '2026-10-08',
            [
                [$bank, '40.0000', '0.0000'],
                [$revenue, '0.0000', '40.0000'],
            ],
            'Dashboard bank receipt',
        );

        $this->postedJournal(
            'payment_voucher',
            '2026-10-10',
            [
                [$expense, '25.0000', '0.0000'],
                [$cash, '0.0000', '25.0000'],
            ],
            'Dashboard cash payment',
        );

        $tenant = app(TenantContext::class);
        $draft = JournalEntry::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'branch_id' => $tenant->branchId(),
            'document_type' => 'receiving_voucher',
            'entry_date' => '2026-10-11',
            'description' => 'Dashboard draft',
            'status' => 'draft',
            'created_by' => $tenant->userId(),
        ]);
        JournalLine::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'journal_entry_id' => $draft->id,
            'account_id' => $cash->id,
            'debit' => '999.0000',
            'credit' => '0.0000',
            'sort_order' => 0,
        ]);

        $otherBranch = Branch::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'code' => 'DASH-OTHER',
            'name' => 'Dashboard Other Branch',
            'status' => BranchStatus::Active,
            'is_default' => false,
        ]);
        $otherEntry = JournalEntry::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'branch_id' => $otherBranch->id,
            'document_type' => 'receiving_voucher',
            'entry_date' => '2026-10-11',
            'description' => 'Other branch dashboard receipt',
            'status' => 'posted',
            'created_by' => $tenant->userId(),
            'posted_by' => $tenant->userId(),
            'posted_at' => now(),
        ]);
        JournalLine::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'journal_entry_id' => $otherEntry->id,
            'account_id' => $cash->id,
            'debit' => '500.0000',
            'credit' => '0.0000',
            'sort_order' => 0,
        ]);
        JournalLine::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'journal_entry_id' => $otherEntry->id,
            'account_id' => $revenue->id,
            'debit' => '0.0000',
            'credit' => '500.0000',
            'sort_order' => 1,
        ]);

        $response = $this->getJson('/api/cash-position/dashboard?date=2026-10-11&days=7')
            ->assertOk()
            ->assertJsonPath('date', '2026-10-11')
            ->assertJsonPath('days', 7)
            ->assertJsonCount(7, 'chart.dates');

        $cashSeries = collect($response->json('chart.series'))
            ->firstWhere('account.ulid', $cash->ulid);
        $bankSeries = collect($response->json('chart.series'))
            ->firstWhere('account.ulid', $bank->ulid);

        $this->assertSame('100.0000', $cashSeries['values'][0] ?? null);
        $this->assertSame('75.0000', $cashSeries['values'][5] ?? null);
        $this->assertSame('75.0000', $cashSeries['values'][6] ?? null);
        $this->assertSame('0.0000', $bankSeries['values'][0] ?? null);
        $this->assertSame('40.0000', $bankSeries['values'][3] ?? null);
        $this->assertSame('40.0000', $bankSeries['values'][6] ?? null);

        $debitRows = collect($response->json('debit_accounts'));
        $creditRows = collect($response->json('credit_accounts'));

        $this->assertSame('75.0000', $debitRows->firstWhere('account.ulid', $cash->ulid)['amount'] ?? null);
        $this->assertSame('40.0000', $debitRows->firstWhere('account.ulid', $bank->ulid)['amount'] ?? null);
        $this->assertSame('25.0000', $debitRows->firstWhere('account.ulid', $expense->ulid)['amount'] ?? null);
        $this->assertSame('140.0000', $creditRows->firstWhere('account.ulid', $revenue->ulid)['amount'] ?? null);
        $response->assertJsonPath('totals.debit', '140.0000')
            ->assertJsonPath('totals.credit', '140.0000');

        $this->assertNoInternalIds($response->json());
    }

    public function test_day_close_overview_returns_category_sales_and_open_shift_snapshot(): void
    {
        $this->signInOwner('daily-cash-day-close')->assertOk();

        $tenant = app(TenantContext::class);
        $unit = Unit::query()
            ->forTenant($tenant->tenantId())
            ->where('code', 'PCS')
            ->firstOrFail();

        $category = Category::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'code' => 'DAY-CAT',
            'name' => 'Day Close Category',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'product_number' => 'DAY-PROD-1',
            'name' => 'Day Close Product',
            'category_id' => $category->id,
            'base_unit_id' => $unit->id,
            'tax_percent' => '0.00000000',
            'status' => ProductStatus::Active->value,
            'is_active' => true,
            'created_by' => $tenant->userId(),
        ]);

        $sale = Sale::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'branch_id' => $tenant->branchId(),
            'warehouse_id' => $tenant->warehouseId(),
            'document_number' => 'DAY-SALE-1',
            'status' => 'posted',
            'sale_date' => '2026-10-11',
            'subtotal' => '150.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'grand_total' => '150.0000',
            'idempotency_key' => 'day-close-sale-1',
            'created_by' => $tenant->userId(),
            'posted_at' => now(),
        ]);

        SaleItem::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'line_kind' => 'sale',
            'quantity' => '3.000000',
            'stock_quantity' => '3.000000',
            'conversion_factor' => '1.00000000',
            'price_type' => 'retail',
            'unit_price' => '50.0000',
            'gross_amount' => '150.0000',
            'discount_percent' => '0.00000000',
            'discount_amount' => '0.0000',
            'tax_percent' => '0.00000000',
            'tax_amount' => '0.0000',
            'line_total' => '150.0000',
        ]);

        $shift = CashierShift::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'branch_id' => $tenant->branchId(),
            'membership_id' => $tenant->membershipId(),
            'opened_at' => now()->subHour(),
            'status' => CashierShiftStatus::Open->value,
            'opening_cash' => '100.0000',
            'expected_cash' => '250.0000',
        ]);

        $response = $this->getJson('/api/cash-position/day-close?date=2026-10-11')
            ->assertOk()
            ->assertJsonPath('date', '2026-10-11')
            ->assertJsonPath('category_sales.0.category.ulid', $category->ulid)
            ->assertJsonPath('category_sales.0.category.name', 'Day Close Category')
            ->assertJsonPath('category_sales.0.orders', 1)
            ->assertJsonPath('category_sales.0.amount', '150.0000')
            ->assertJsonPath('category_totals.orders', 1)
            ->assertJsonPath('category_totals.amount', '150.0000')
            ->assertJsonPath('current_shift.ulid', $shift->ulid)
            ->assertJsonPath('current_shift.opening_cash', '100.0000')
            ->assertJsonPath('current_shift.expected_cash', '100.0000')
            ->assertJsonPath('close_action_available', true)
            ->assertJsonPath('close_action_reason', null);

        $this->assertCount(1, $response->json('open_shift_positions'));
        $this->assertNoInternalIds($response->json());
    }

    public function test_day_close_overview_requires_cash_session_view_permission(): void
    {
        $this->signInOwner('daily-cash-day-close-permission')->assertOk();

        $tenant = app(TenantContext::class);
        $role = $tenant->membership()
            ->roles()
            ->where('roles.tenant_id', $tenant->tenantId())
            ->firstOrFail();
        $permission = Permission::query()
            ->where('key', 'cash_sessions.view')
            ->firstOrFail();

        $role->permissions()->detach($permission->id);
        app()->forgetInstance(PermissionService::class);

        $this->getJson('/api/cash-position/day-close')->assertForbidden();
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
        $this->getJson('/api/cash-position/dashboard')->assertForbidden();
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
