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
use App\Models\Customer;
use App\Models\PurchaseReturn;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\SaleReturn;
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

    public function test_cash_book_post_preview_uses_same_authorization_and_validation(): void
    {
        $this->signInOwner('cash-book-post-preview')->assertOk();
        $this->postJson('/api/reports/accounting/cash-book/preview', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-25',
            'include_bank' => false,
            'show_purchase' => false,
            'non_cash_also' => false,
            'summary_only' => false,
            'group_by' => 'none',
            'account_ulids' => [],
        ])->assertOk()->assertJsonStructure(['rows', 'totals']);
        $this->postJson('/api/reports/accounting/cash-book/preview', [
            'start_date' => '2026-10-26',
            'end_date' => '2026-10-01',
        ])->assertUnprocessable();
        $this->restrictCurrentMembershipWithoutPermission('reports.accounting');
        $this->postJson('/api/reports/accounting/cash-book/preview', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-25',
        ])->assertForbidden();
    }

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

    public function test_reports_cash_book_requires_accounting_report_permission(): void
    {
        $this->signInOwner('reports-cash-denied')->assertOk();
        $this->restrictCurrentMembershipWithoutPermission('reports.accounting');
        $this->getJson('/api/reports/accounting/cash-book/accounts')->assertForbidden();
        $this->getJson('/api/reports/accounting/cash-book?start_date=2026-10-01'
            .'&end_date=2026-10-24')->assertForbidden();
    }

    public function test_total_turnover_subtracts_posted_purchase_returns_and_ignores_drafts(): void
    {
        $this->signInOwner('turnover-purchases')->assertOk();
        $context = app(TenantContext::class);
        $supplier = Supplier::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'TURNOVER-SUP',
            'name' => 'Purchase Turnover Supplier',
            'is_active' => true,
            'created_by' => $context->userId(),
        ]);
        $payable = $this->leafAccount('Purchase Turnover Ledger', 'TURN-PAY', '0020');
        $payable->update(['supplier_id' => $supplier->id]);
        foreach ([['2026-10-04', '125.0000', 'posted'], ['2026-10-05', '900.0000', 'draft']] as $index => [$date, $amount, $status]) {
            PurchaseInvoice::query()->create([
                'tenant_id' => $context->tenantId(),
                'branch_id' => $context->branchId(),
                'warehouse_id' => $context->warehouseId(),
                'supplier_id' => $supplier->id,
                'document_number' => 'TURN-PURCHASE-'.$index,
                'invoice_date' => $date,
                'status' => $status,
                'subtotal' => $amount,
                'grand_total' => $amount,
                'created_by' => $context->userId(),
                'posted_at' => $status === 'posted' ? now() : null,
            ]);
        }
        PurchaseReturn::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $context->warehouseId(),
            'supplier_id' => $supplier->id,
            'document_number' => 'TURN-PURCHASE-RETURN',
            'return_date' => '2026-10-06',
            'status' => 'posted',
            'subtotal' => '25.0000',
            'grand_total' => '25.0000',
            'created_by' => $context->userId(),
            'posted_at' => now(),
        ]);
        $url = '/api/reports/accounting/total-turnover?mode=simple&group=vendors'
            .'&from=2026-10-01&to=2026-10-25';
        $clearing = $this->leafAccount('Purchase Clearing', 'TURN-CLEAR', '0030');
        $this->postedJournal('purchase_invoice', '2026-10-04',
            [[$clearing, '125.0000', '0.0000'], [$payable, '0.0000', '125.0000']], 'Purchase');
        $this->postedJournal('purchase_return', '2026-10-06',
            [[$payable, '25.0000', '0.0000'], [$clearing, '0.0000', '25.0000']], 'Purchase return');
        $response = $this->getJson($url)->assertOk()->assertJsonCount(1, 'rows');
        $this->assertSame('100.0000', $response->json('rows.0.purchases'));
        $this->assertSame('100.0000', $response->json('totals.purchases'));
        $this->getJson($url.'&above=100.0000')->assertJsonCount(0, 'rows');
        $this->assertNoInternalIds($response->json());
        foreach ([
            ['purchase_invoice_reversal', '125.0000', '0.0000'],
            ['purchase_invoice_amendment', '0.0000', '150.0000'],
            ['purchase_return_reversal', '0.0000', '25.0000'],
            ['purchase_return_amendment', '35.0000', '0.0000'],
            // Voiding another invoice retains both posted entries, net zero.
            ['purchase_invoice', '0.0000', '77.0000'],
            ['purchase_invoice_reversal', '77.0000', '0.0000'],
        ] as [$type, $debit, $credit]) {
            $this->postedJournal($type, '2026-10-25',
                [[$payable, $debit, $credit], [$clearing, $credit, $debit]], $type);
        }
        $this->getJson($url)->assertOk()
            ->assertJsonPath('totals.purchases', '115.0000')
            ->assertJsonPath('totals.receivable', '-115.0000');
    }

    public function test_total_turnover_requires_accounting_report_permission(): void
    {
        $this->signInOwner('total-turnover-denied')->assertOk();
        $this->restrictCurrentMembershipWithoutPermission('reports.accounting');
        $this->getJson('/api/reports/accounting/total-turnover/options')->assertForbidden();
        $this->getJson('/api/reports/accounting/total-turnover?group=customers&from=2026-10-01&to=2026-10-25')
            ->assertForbidden();
    }

    public function test_total_turnover_rejects_invalid_dates_and_account_type_filters(): void
    {
        $this->signInOwner('total-turnover-test')->assertOk();
        $this->getJson('/api/reports/accounting/total-turnover/options')
            ->assertOk()->assertJsonStructure(['account_types']);
        $base = '/api/reports/accounting/total-turnover?from=2026-10-01&to=2026-10-25';
        $response = $this->getJson($base)->assertOk()
            ->assertJsonStructure(['rows', 'totals' => ['sales_returns', 'received', 'receivable', 'sales', 'purchases']]);
        $this->assertNoInternalIds($response->json());
        $this->getJson($base.'&account_type_ulids[0]=01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertNotFound();
        $this->getJson('/api/reports/accounting/total-turnover?from=2026-10-26&to=2026-10-01')
            ->assertUnprocessable();
        $this->getJson($base.'&above=-10')->assertUnprocessable();
        $this->getJson($base.'&above=1.00001')->assertUnprocessable();
        $this->getJson($base.'&above=1e10')->assertUnprocessable();
        $this->getJson($base.'&group=salesman')->assertUnprocessable();
        $this->getJson($base.'&salesman_ulid=01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertUnprocessable();
    }

    public function test_total_turnover_nets_posted_returns_and_does_not_duplicate_party_sales(): void
    {
        $this->signInOwner('turnover-reconciliation')->assertOk();
        $context = app(TenantContext::class);
        $customer = Customer::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => 'TURNOVER-PARTY',
            'name' => 'Turnover Test Party',
            'is_active' => true,
            'created_by' => $context->userId(),
        ]);
        $first = $this->leafAccount('Turnover Party A', 'TURN-A', '0010');
        $first->update(['customer_id' => $customer->id]);
        // Persisted schema permits one linked account per customer. Two
        // invoices and a return must still aggregate without join fan-out.
        $second = $first;
        $cash = $this->leafAccount('Turnover Receipt Cash', 'TURN-CASH', '0010');
        $revenue = $this->leafAccount('Turnover Sales', 'TURN-SALES', '0040');

        foreach ([['2026-10-05', '100.0000'], ['2026-10-06', '200.0000']] as $index => [$date, $amount]) {
            Sale::query()->create([
                'tenant_id' => $context->tenantId(),
                'branch_id' => $context->branchId(),
                'warehouse_id' => $context->warehouseId(),
                'customer_id' => $customer->id,
                'document_number' => 'TURNOVER-INVOICE-'.$index,
                'status' => 'posted',
                'sale_date' => $date,
                'subtotal' => $amount,
                'grand_total' => $amount,
                'idempotency_key' => 'turnover-test-'.$index,
                'created_by' => $context->userId(),
                'posted_at' => now(),
            ]);
        }
        SaleReturn::query()->create([
            'tenant_id' => $context->tenantId(),
            'branch_id' => $context->branchId(),
            'warehouse_id' => $context->warehouseId(),
            'customer_id' => $customer->id,
            'document_number' => 'TURNOVER-RETURN',
            'idempotency_key' => 'turnover-return-test',
            'return_date' => '2026-10-07',
            'status' => 'posted',
            'subtotal' => '30.0000',
            'grand_total' => '30.0000',
            'created_by' => $context->userId(),
            'posted_at' => now(),
        ]);
        $this->postedJournal('sale_invoice', '2026-10-05',
            [[$first, '100.0000', '0.0000'], [$revenue, '0.0000', '100.0000']], 'Invoice A');
        $this->postedJournal('sale_invoice', '2026-10-06',
            [[$second, '200.0000', '0.0000'], [$revenue, '0.0000', '200.0000']], 'Invoice B');
        $this->postedJournal('sale_return', '2026-10-07',
            [[$revenue, '30.0000', '0.0000'], [$second, '0.0000', '30.0000']], 'Return');
        $this->postedJournal('receiving_voucher', '2026-10-08',
            [[$cash, '25.0000', '0.0000'], [$second, '0.0000', '25.0000']], 'Recovery');

        $base = '/api/reports/accounting/total-turnover?mode=simple&group=customers'
            .'&from=2026-10-01&to=2026-10-25';
        $result = $this->getJson($base)->assertOk();
        $this->assertCount(1, $result->json('rows'));
        $this->assertSame('245.0000', $result->json('rows.0.sales_returns'));
        $this->assertSame('270.0000', $result->json('rows.0.net_sales'));
        $this->assertSame('25.0000', $result->json('rows.0.received'));
        $this->assertSame('245.0000', $result->json('rows.0.receivable'));
        $this->assertSame('245.0000', $result->json('totals.sales_returns'));
        $this->assertSame('270.0000', $result->json('totals.net_sales'));
        $this->assertNoInternalIds($result->json());
        $this->getJson($base.'&above=270.0000')->assertJsonCount(0, 'rows');
        $this->getJson($base.'&above=269.9999')->assertJsonCount(1, 'rows');
    }

    public function test_turnover_reconciles_amendments_reversals_dates_and_isolation(): void
    {
        $this->signInOwner('turnover-corrections')->assertOk();
        $context = app(TenantContext::class);
        $customer = Customer::query()->create([
            'tenant_id' => $context->tenantId(), 'code' => 'CORR', 'name' => 'Corrections',
            'is_active' => true, 'created_by' => $context->userId(),
        ]);
        $party = $this->leafAccount('Corrections Ledger', 'CORR', '0010');
        $party->update(['customer_id' => $customer->id]);
        $offset = $this->leafAccount('Corrections Offset', 'CORR-OFF', '0040');
        foreach ([
            ['opening_balance', '2026-09-30', '50.0000', '0.0000'],
            ['sale_invoice', '2026-10-01', '100.0000', '0.0000'],
            ['sale_invoice_reversal', '2026-10-15', '0.0000', '100.0000'],
            ['sale_invoice_amendment', '2026-10-15', '140.0000', '0.0000'],
            ['sale_return', '2026-10-16', '0.0000', '20.0000'],
            ['sale_return_reversal', '2026-10-17', '20.0000', '0.0000'],
            ['sale_return_amendment', '2026-10-17', '0.0000', '30.0000'],
            ['receiving_voucher', '2026-10-18', '0.0000', '25.0000'],
            ['receiving_voucher_reversal', '2026-10-19', '25.0000', '0.0000'],
            ['sale_payment', '2026-10-25', '0.0000', '10.1250'],
            ['sale_payment_reversal', '2026-10-25', '2.0000', '0.0000'],
            ['payment_voucher', '2026-10-25', '3.0000', '0.0000'],
            ['sale_payment', '2026-10-26', '0.0000', '999.0000'],
            ['sale_invoice', '2026-10-25', '77.0000', '0.0000'],
            ['sale_invoice_reversal', '2026-10-25', '0.0000', '77.0000'],
        ] as [$type, $date, $debit, $credit]) {
            $this->postedJournal($type, $date,
                [[$party, $debit, $credit], [$offset, $credit, $debit]], $type);
        }
        $otherBranch = Branch::query()->create([
            'tenant_id' => $context->tenantId(), 'code' => 'CORR-OTHER',
            'name' => 'Other Branch', 'status' => BranchStatus::Active, 'is_default' => false,
        ]);
        $entry = JournalEntry::query()->create([
            'tenant_id' => $context->tenantId(), 'branch_id' => $otherBranch->id,
            'document_type' => 'sale_invoice', 'entry_date' => '2026-10-01',
            'status' => 'posted', 'created_by' => $context->userId(),
        ]);
        foreach ([[$party, '999.0000', '0.0000'], [$offset, '0.0000', '999.0000']] as $i => [$account, $debit, $credit]) {
            JournalLine::query()->create([
                'tenant_id' => $context->tenantId(), 'journal_entry_id' => $entry->id,
                'account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'sort_order' => $i,
            ]);
        }
        $base = '/api/reports/accounting/total-turnover?from=2026-10-01&to=2026-10-25';
        $result = $this->getJson($base.'&group=customers')->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('totals.sales', '140.0000')
            ->assertJsonPath('totals.net_sales', '110.0000')
            ->assertJsonPath('totals.received', '8.1250')
            ->assertJsonPath('totals.sales_returns', '104.8750')
            ->assertJsonPath('totals.receivable', '154.8750');
        $this->assertNoInternalIds($result->json());
        $this->getJson($base.'&group=vendors')->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson($base.'&group=customers&above=110')->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson($base.'&group=customers&above=109.9999')->assertOk()->assertJsonCount(1, 'rows');
        $this->getJson($base.'&group=customers&account_type_ulids[0]='.$offset->accountType->ulid)
            ->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson('/api/reports/accounting/total-turnover?group=customers&from=2026-10-01&to=2026-10-14')
            ->assertOk()->assertJsonPath('totals.net_sales', '100.0000');
        $this->getJson('/api/reports/accounting/total-turnover?group=customers&from=2026-10-15&to=2026-10-15')
            ->assertOk()->assertJsonPath('totals.net_sales', '40.0000');
        // Recovery-only periods legitimately have negative signed movement.
        $this->getJson('/api/reports/accounting/total-turnover?group=customers&from=2026-10-18&to=2026-10-18')
            ->assertOk()->assertJsonPath('totals.sales_returns', '-25.0000')
            ->assertJsonPath('totals.received', '25.0000');
        $this->postJson('/api/auth/logout')->assertSuccessful();
        $this->signInOwner('turnover-isolated')->assertOk();
        $this->getJson($base.'&group=all')->assertOk()->assertJsonCount(0, 'rows');
        $this->getJson($base.'&account_type_ulids[0]='.$party->accountType->ulid)->assertNotFound();
    }

    public function test_total_turnover_group_modes_only_include_verified_parties_without_duplicates(): void
    {
        $this->signInOwner('turnover-groups')->assertOk();
        $context = app(TenantContext::class);
        $offset = $this->leafAccount('Group Offset', 'GROUP-OFF', '0040');
        $customer = Customer::query()->create([
            'tenant_id' => $context->tenantId(), 'code' => 'GROUP-C', 'name' => 'Customer',
            'is_active' => true, 'created_by' => $context->userId(),
        ]);
        $supplier = Supplier::query()->create([
            'tenant_id' => $context->tenantId(), 'code' => 'GROUP-V', 'name' => 'Vendor',
            'is_active' => true, 'created_by' => $context->userId(),
        ]);
        $customerAccount = $this->leafAccount('Group Customer', 'GROUP-C', '0011');
        $customerAccount->update(['customer_id' => $customer->id]);
        $vendorAccount = $this->leafAccount('Group Vendor', 'GROUP-V', '0020');
        $vendorAccount->update(['supplier_id' => $supplier->id]);
        $this->postedJournal('sale_invoice', '2026-10-10',
            [[$customerAccount, '100.0000', '0.0000'], [$offset, '0.0000', '100.0000']], 'Customer');
        $this->postedJournal('purchase_invoice', '2026-10-10',
            [[$vendorAccount, '0.0000', '200.0000'], [$offset, '200.0000', '0.0000']], 'Vendor');
        foreach ([
            ['Manual A', '0011', 'account', '10.0000'],
            ['Manual B', '0020', 'account', '20.0000'],
            ['Internal Cash', '0010', 'account', '999.0000'],
            ['Internal Bank', '0012', 'account', '999.0000'],
            ['Internal Revenue', '0040', 'account', '999.0000'],
            ['Internal Expense', '0030', 'account', '999.0000'],
            ['Internal Tax', '0031', 'account', '999.0000'],
            ['Internal Stock', '0060', 'account', '999.0000'],
            ['Unclassified Ledger', '0011', null, '999.0000'],
            ['Salesman Only', '0011', 'salesman', '999.0000'],
            ['Configured Control', '0011', 'account', '999.0000'],
        ] as $i => [$name, $type, $role, $amount]) {
            $account = $this->leafAccount($name, 'GROUP-M-'.$i, $type);
            if ($role !== null) {
                $profile = \App\Models\PartyProfile::query()->create([
                    'tenant_id' => $context->tenantId(), 'code' => 'GROUP-P-'.$i, 'name' => $name,
                    'is_active' => true, 'created_by' => $context->userId(),
                ]);
                $profile->types()->create(['type' => $role]);
                // Extra profile roles must not fan out journal totals.
                if ($name === 'Manual A') {
                    $profile->types()->create(['type' => 'customer']);
                    $profile->types()->create(['type' => 'vendor']);
                    $customer->update(['party_profile_id' => $profile->id]);
                    $supplier->update(['party_profile_id' => $profile->id]);
                }
                $account->update(['party_profile_id' => $profile->id]);
            }
            if ($name === 'Configured Control') {
                \App\Models\BusinessSetting::query()->forTenant($context->tenantId())->firstOrFail()
                    ->update(['inventory_account_id' => $account->id]);
            }
            $this->postedJournal('journal_voucher', '2026-10-10',
                [[$account, $amount, '0.0000'], [$offset, '0.0000', $amount]], $name);
            if ($name === 'Manual A') {
                $branch = Branch::query()->create([
                    'tenant_id' => $context->tenantId(), 'code' => 'MANUAL-OTHER',
                    'name' => 'Other Manual Branch', 'status' => BranchStatus::Active, 'is_default' => false,
                ]);
                $entry = JournalEntry::query()->create([
                    'tenant_id' => $context->tenantId(), 'branch_id' => $branch->id,
                    'document_type' => 'journal_voucher', 'entry_date' => '2026-10-10',
                    'status' => 'posted', 'created_by' => $context->userId(),
                ]);
                foreach ([[$account, '999.0000', '0.0000'], [$offset, '0.0000', '999.0000']] as $j => [$ledger, $debit, $credit]) {
                    JournalLine::query()->create([
                        'tenant_id' => $context->tenantId(), 'journal_entry_id' => $entry->id,
                        'account_id' => $ledger->id, 'debit' => $debit, 'credit' => $credit, 'sort_order' => $j,
                    ]);
                }
            }
        }
        $base = '/api/reports/accounting/total-turnover?from=2026-10-01&to=2026-10-25';
        foreach ([
            'accounts' => [['Manual A', 'Manual B'], '30.0000', '0.0000', '0.0000'],
            'customers' => [['Group Customer'], '100.0000', '100.0000', '0.0000'],
            'vendors' => [['Group Vendor'], '-200.0000', '0.0000', '200.0000'],
            'all' => [['Group Customer', 'Manual B'], '-70.0000', '100.0000', '200.0000'],
        ] as $group => [$names, $balance, $sales, $purchases]) {
            $result = $this->getJson($base.'&group='.$group)->assertOk()
                ->assertJsonCount(count($names), 'rows')
                ->assertJsonPath('totals.receivable', $balance)
                ->assertJsonPath('totals.sales', $sales)
                ->assertJsonPath('totals.purchases', $purchases);
            $this->assertSame($names, array_column($result->json('rows'), 'name'));
            $this->assertNoInternalIds($result->json());
        }
        $this->getJson($base)->assertOk()->assertJsonCount(2, 'rows');
        $this->getJson($base.'&group=accounts&account_type_ulids[0]='.$vendorAccount->accountType->ulid)
            ->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.name', 'Manual B');
        $this->postJson('/api/auth/logout')->assertSuccessful();
        $this->signInOwner('turnover-groups-other')->assertOk();
        foreach (['accounts', 'customers', 'vendors', 'all'] as $group) {
            $this->getJson($base.'&group='.$group)->assertOk()->assertJsonCount(0, 'rows');
        }
    }

    public function test_total_turnover_empty_result_excludes_controls_and_fully_offset_trading(): void
    {
        $this->signInOwner('turnover-empty')->assertOk();
        $context = app(TenantContext::class);
        $offset = $this->leafAccount('Empty Offset', 'EMPTY-OFF', '0040');
        foreach (['trading', 'clearing', 'equity'] as $key) {
            $supplier = Supplier::query()->create([
                'tenant_id' => $context->tenantId(), 'code' => 'EMPTY-'.$key, 'name' => $key,
                'is_active' => true, 'created_by' => $context->userId(),
            ]);
            $account = $this->leafAccount('Empty '.$key, 'EMPTY-'.$key, '0020');
            $account->update(['supplier_id' => $supplier->id]);
            if ($key === 'trading') {
                $this->postedJournal('purchase_invoice', '2026-10-08',
                    [[$account, '0.0000', '902.7000'], [$offset, '902.7000', '0.0000']], 'Purchase');
                $this->postedJournal('purchase_return', '2026-10-08',
                    [[$account, '902.7000', '0.0000'], [$offset, '0.0000', '902.7000']], 'Return');
            } else {
                $field = $key === 'clearing' ? 'sales_clearing_account_id' : 'opening_balance_equity_account_id';
                \App\Models\BusinessSetting::query()->forTenant($context->tenantId())->firstOrFail()
                    ->update([$field => $account->id]);
                $this->postedJournal($key === 'clearing' ? 'sale_payment' : 'opening_balance', '2026-10-10',
                    [[$account, '100.0000', '0.0000'], [$offset, '0.0000', '100.0000']], 'Control');
            }
        }
        foreach (['0010', '0013'] as $type) {
            $profile = \App\Models\PartyProfile::query()->create([
                'tenant_id' => $context->tenantId(), 'code' => 'EMPTY-P-'.$type, 'name' => 'Unmapped manual',
                'is_active' => true, 'created_by' => $context->userId(),
            ]);
            $profile->types()->create(['type' => 'account']);
            $manual = $this->leafAccount('Empty Manual '.$type, 'EMPTY-M-'.$type, $type);
            $manual->update(['party_profile_id' => $profile->id]);
            $this->postedJournal('journal_voucher', '2026-10-10',
                [[$manual, '50.0000', '0.0000'], [$offset, '0.0000', '50.0000']], 'Ineligible manual');
        }
        foreach (['simple', 'detailed'] as $mode) {
            foreach (['accounts', 'customers', 'vendors', 'all'] as $group) {
                $result = $this->getJson('/api/reports/accounting/total-turnover?from=2026-10-01&to=2026-10-31'
                    .'&above=0&mode='.$mode.'&group='.$group)->assertOk()->assertJsonCount(0, 'rows');
                foreach (['received', 'sales_returns', 'net_sales', 'receivable', 'sales', 'purchases'] as $field) {
                    $result->assertJsonPath('totals.'.$field, '0.0000');
                }
                $this->assertNoInternalIds($result->json());
            }
        }
    }

    public function test_simple_turnover_uses_posted_period_ledger_movements(): void
    {
        $this->signInOwner('simple-turnover')->assertOk();
        $cash = $this->leafAccount('Turnover Cash', 'SIMPLE-CASH', '0010');
        $revenue = $this->leafAccount('Turnover Revenue', 'SIMPLE-REV', '0040');
        $this->postReceiving($cash, $revenue, '2026-09-30', '40.0000', true, 'before-period');
        $this->postReceiving($cash, $revenue, '2026-10-08', '15.1250', true, 'in-period');
        $this->postReceiving($cash, $revenue, '2026-10-08', '500.0000', false, 'draft');
        $response = $this->getJson('/api/reports/accounting/total-turnover?mode=simple'
            .'&from=2026-10-01&to=2026-10-25')->assertOk();
        $this->assertNull(collect($response->json('rows'))->firstWhere('name', $cash->name));
        $this->assertNull(collect($response->json('rows'))->firstWhere('name', $revenue->name));
        $this->assertNoInternalIds($response->json());
    }

    public function test_top_special_validates_rankings_and_never_accepts_cross_tenant_filter_ids(): void
    {
        $this->signInOwner('top-special-reports')->assertOk();
        $this->getJson('/api/reports/top-special/options')->assertOk()
            ->assertJsonStructure(['customers', 'suppliers', 'categories', 'brands', 'salespeople', 'areas']);
        $base = '/api/reports/top-special?from=2026-10-01&to=2026-10-25&top=10&kind=customers';
        $response = $this->getJson($base)->assertOk()
            ->assertJsonStructure(['ranking', 'kind', 'from', 'to']);
        $this->assertNoInternalIds($response->json());
        $this->getJson($base.'&party_ulids[0]=01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
        $this->getJson('/api/reports/top-special?from=2026-10-26&to=2026-10-01'
            .'&top=10&kind=customers')->assertUnprocessable();
        $this->getJson($base.'&top=1000')->assertUnprocessable();
        $this->getJson($base.'&kind=vendors')->assertStatus(422);
    }

    public function test_daily_business_activity_uses_posted_ledgers_and_preserves_opening_balance(): void
    {
        $this->signInOwner('daily-business-activity')->assertOk();
        $cash = $this->leafAccount('Activity Cash', 'ACT-CASH', '0010');
        $revenue = $this->leafAccount('Activity Revenue', 'ACT-REV', '0040');
        $this->postReceiving($cash, $revenue, '2026-09-30', '40.0000', true, 'act-opening');
        $this->postReceiving($cash, $revenue, '2026-10-08', '15.1250', true, 'act-posted');
        $this->postReceiving($cash, $revenue, '2026-10-08', '500.0000', false, 'act-draft');
        $type = $cash->accountType()->firstOrFail();
        $this->getJson('/api/reports/accounting/daily-business-activity/types')
            ->assertOk()->assertJsonFragment(['ulid' => $type->ulid]);
        $url = '/api/reports/accounting/daily-business-activity?start_date=2026-10-01'
            .'&end_date=2026-10-24&account_type_ulids[0]='.$type->ulid;
        $response = $this->getJson($url)->assertOk();
        $sections = collect($response->json('sections'));
        $section = $sections->firstWhere('account.ulid', $cash->ulid);
        $this->assertNotNull($section);
        $this->assertSame('40.0000', $section['opening']);
        $this->assertSame('15.1250', $section['debit']);
        $this->assertSame('55.1250', $section['closing']);
        $this->assertCount(1, $section['rows']);
        $this->assertNoInternalIds($response->json());
        $all = $this->getJson('/api/reports/accounting/daily-business-activity?start_date=2026-10-01'
            .'&end_date=2026-10-24')->assertOk();
        $allSections = collect($all->json('sections'));
        $this->assertNotNull($allSections->firstWhere('account.ulid', $cash->ulid));
        $this->assertNotNull($allSections->firstWhere('account.ulid', $revenue->ulid));
        $this->assertNoInternalIds($all->json());
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

    public function test_cash_book_area_and_salesperson_grouping_preserves_totals_and_public_ids(): void
    {
        $this->signInOwner('cashbook-grouping')->assertOk();
        $cash = $this->leafAccount('Cash Grouping', 'CB-GROUP-CASH', '0010');
        $revenue = $this->leafAccount('Revenue Grouping', 'CB-GROUP-REV', '0040');
        $this->postReceiving($cash, $revenue, '2026-10-08', '42.1250', true, 'grouping-test');
        $base = '/api/reports/accounting/cash-book?start_date=2026-10-01'
            .'&end_date=2026-10-24&account_ulids[0]='.$cash->ulid;
        $normal = $this->getJson($base)->assertOk();
        foreach (['area', 'salesperson'] as $groupBy) {
            $response = $this->getJson($base.'&group_by='.$groupBy)->assertOk()
                ->assertJsonCount(1, 'rows')
                ->assertJsonStructure(['rows' => [['area_name', 'salesperson_name']]]);
            $this->assertSame($normal->json('totals'), $response->json('totals'));
            $this->assertNoInternalIds($response->json());
        }
    }

    public function test_cash_book_optional_purchase_and_non_cash_documents_do_not_change_cash_totals(): void
    {
        $this->signInOwner('cashbook-optional-types')->assertOk();
        $cash = $this->leafAccount('Cash Optional', 'CB-OPT-CASH', '0010');
        $expense = $this->leafAccount('Expense Optional', 'CB-OPT-EXP', '0030');
        $payable = $this->leafAccount('Payable Optional', 'CB-OPT-PAY', '0020');

        $purchase = $this->postedJournal('purchase_invoice', '2026-10-10',
            [[$expense, '75.0000', '0.0000'], [$payable, '0.0000', '75.0000']], 'Credit purchase');
        $other = $this->postedJournal('journal_voucher', '2026-10-10',
            [[$expense, '20.0000', '0.0000'], [$payable, '0.0000', '20.0000']], 'Noncash adjustment');
        $this->postedJournal('payment_voucher', '2026-10-10',
            [[$expense, '5.0000', '0.0000'], [$cash, '0.0000', '5.0000']], 'Cash payment');

        $base = '/api/reports/accounting/cash-book?start_date=2026-10-01&end_date=2026-10-24'
            .'&account_ulids[0]='.$cash->ulid;
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'supplementary')
            ->assertJsonPath('totals.paid', '5.0000');
        $purchaseResponse = $this->getJson($base.'&show_purchase=1')->assertOk()
            ->assertJsonCount(1, 'supplementary')
            ->assertJsonPath('totals.paid', '5.0000');
        $this->assertSame($purchase->ulid, $purchaseResponse->json('supplementary.0.entry_ulid'));

        $all = $this->getJson($base.'&non_cash_also=1')->assertOk()
            ->assertJsonCount(2, 'supplementary')->assertJsonPath('totals.paid', '5.0000');
        $this->assertContains($other->ulid, array_column($all->json('supplementary'), 'entry_ulid'));
        $this->assertNoInternalIds($all->json());
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
