<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use DatabaseTransactions;

    public function test_expense_posts_balanced_journal_and_replays_idempotently(): void
    {
        $this->signInOwner('expense-post')->assertOk();

        $expenseAccount = $this->leafAccount('OFFICE EXPENSE', 'EXP-1001');
        $paymentAccount = $this->leafAccount('PETTY CASH', 'CASH-1001');

        $payload = [
            'expense_date' => '2026-10-07',
            'expense_account_ulid' => $expenseAccount->ulid,
            'payment_account_ulid' => $paymentAccount->ulid,
            'amount' => '125.5000',
            'reference' => 'EXP-REF-1',
            'description' => 'Office stationery',
        ];

        $first = $this->postJson(
            '/api/sales/expenses',
            $payload,
            $this->idem('expense-post-1'),
        )
            ->assertCreated()
            ->assertJsonPath('amount', '125.5000')
            ->assertJsonPath('expense_account.ulid', $expenseAccount->ulid)
            ->assertJsonPath('payment_account.ulid', $paymentAccount->ulid)
            ->assertJsonPath('reference', 'EXP-REF-1');

        $this->assertNoInternalIds($first->json());

        $expenseUlid = (string) $first->json('ulid');

        $expense = Expense::query()
            ->where('ulid', $expenseUlid)
            ->firstOrFail();

        $journal = JournalEntry::query()
            ->where('ulid', $expense->journal_entry_ulid)
            ->with('lines')
            ->firstOrFail();

        $this->assertSame('posted', $journal->status->value);
        $this->assertSame('expense', $journal->document_type);
        $this->assertCount(2, $journal->lines);

        $debit = '0.0000';
        $credit = '0.0000';

        foreach ($journal->lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 4);
            $credit = bcadd($credit, (string) $line->credit, 4);
        }

        $this->assertSame(0, bccomp($debit, '125.5000', 4));
        $this->assertSame(0, bccomp($credit, '125.5000', 4));
        $this->assertSame(0, bccomp($debit, $credit, 4));

        $second = $this->postJson(
            '/api/sales/expenses',
            $payload,
            $this->idem('expense-post-1'),
        )->assertCreated();

        $this->assertSame($expenseUlid, $second->json('ulid'));
        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('document_type', 'expense')->count());

        $this->getJson('/api/sales/expenses?date_from=2026-10-01&date_to=2026-10-31&q=stationery')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ulid', $expenseUlid);
    }

    public function test_expense_rejects_foreign_or_same_accounts(): void
    {
        $this->signInOwner('expense-tenant-a')->assertOk();

        $foreignExpense = $this->leafAccount('FOREIGN EXPENSE', 'EXP-F-1');
        $foreignCash = $this->leafAccount('FOREIGN CASH', 'CASH-F-1');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('expense-tenant-b')->assertOk();

        $this->postJson('/api/sales/expenses', [
            'expense_date' => '2026-10-07',
            'expense_account_ulid' => $foreignExpense->ulid,
            'payment_account_ulid' => $foreignCash->ulid,
            'amount' => '10.0000',
        ], $this->idem('expense-foreign'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $local = $this->leafAccount('LOCAL ACCOUNT', 'LOCAL-1');

        $this->postJson('/api/sales/expenses', [
            'expense_date' => '2026-10-07',
            'expense_account_ulid' => $local->ulid,
            'payment_account_ulid' => $local->ulid,
            'amount' => '10.0000',
        ], $this->idem('expense-same-account'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertSame(0, Expense::query()->count());
    }

    public function test_expense_requires_idempotency_key_and_positive_amount(): void
    {
        $this->signInOwner('expense-validation')->assertOk();

        $expenseAccount = $this->leafAccount('VALIDATION EXPENSE', 'EXP-V-1');
        $paymentAccount = $this->leafAccount('VALIDATION CASH', 'CASH-V-1');

        $payload = [
            'expense_date' => '2026-10-07',
            'expense_account_ulid' => $expenseAccount->ulid,
            'payment_account_ulid' => $paymentAccount->ulid,
            'amount' => '25.0000',
        ];

        $this->postJson('/api/sales/expenses', $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'IDEMPOTENCY_KEY_REQUIRED');

        $payload['amount'] = '0.0000';

        $this->postJson('/api/sales/expenses', $payload, $this->idem('expense-zero'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertSame(0, Expense::query()->count());
    }

    private function leafAccount(string $name, string $code): Account
    {
        $context = app(TenantContext::class);

        $accountType = AccountType::query()
            ->forTenant($context->tenantId())
            ->orderBy('id')
            ->firstOrFail();

        return Account::query()->create([
            'tenant_id' => $context->tenantId(),
            'code' => $code,
            'name' => $name,
            'account_type_id' => $accountType->id,
            'is_active' => true,
            'created_by' => $context->userId(),
            'updated_by' => $context->userId(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }
}
