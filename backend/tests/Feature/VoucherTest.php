<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class VoucherTest extends TestCase
{
    use DatabaseTransactions;

    public function test_payment_voucher_draft_posts_balanced_journal_and_is_immutable_after_posting(): void
    {
        $this->signInOwner('voucher-payment')->assertOk();

        $cash = $this->leafAccount('Voucher Cash', 'V-CASH-01', '0010');
        $supplierUlid = $this->postJson('/api/suppliers', [
            'code' => 'SUP-VOUCH-1',
            'name' => 'Voucher Supplier',
        ])->assertCreated()->json('ulid');

        $supplier = Supplier::query()->where('ulid', $supplierUlid)->firstOrFail();
        $supplierAccount = Account::query()->where('supplier_id', $supplier->id)->firstOrFail();
        $date = now()->toDateString();

        $draft = $this->postJson('/api/vouchers', [
            'type' => 'payment',
            'entry_date' => $date,
            'description' => 'Supplier settlement',
            'header_account_ulid' => $cash->ulid,
            'lines' => [[
                'account_ulid' => $supplierAccount->ulid,
                'narration' => 'Pay supplier',
                'amount' => '30.0000',
            ]],
        ], $this->idem('voucher-payment-1'))->assertCreated();

        $draft->assertJsonPath('type', 'payment')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('total_debit', '30.0000')
            ->assertJsonPath('total_credit', '30.0000')
            ->assertJsonPath('header_account.ulid', $cash->ulid)
            ->assertJsonPath('lines.0.credit', '30.0000')
            ->assertJsonPath('lines.1.debit', '30.0000')
            ->assertJsonPath('lines.1.party_type', 'vendor');

        $voucherUlid = $draft->json('ulid');
        $this->assertStringStartsWith('PV-', (string) $draft->json('voucher_number'));
        $this->assertNoInternalIds($draft->json());

        $this->postJson('/api/vouchers/'.$voucherUlid.'/post')
            ->assertOk()
            ->assertJsonPath('status', 'posted');

        $journal = JournalEntry::query()
            ->where('ulid', $voucherUlid)
            ->with('lines')
            ->firstOrFail();

        $this->assertTrue($journal->isPosted());
        $this->assertSame('30.0000', $journal->lines->reduce(
            fn (string $total, $line): string => bcadd($total, (string) $line->debit, 4),
            '0.0000',
        ));
        $this->assertSame('30.0000', $journal->lines->reduce(
            fn (string $total, $line): string => bcadd($total, (string) $line->credit, 4),
            '0.0000',
        ));

        $this->putJson('/api/vouchers/'.$voucherUlid, [
            'entry_date' => $date,
            'description' => 'Changed',
            'header_account_ulid' => $cash->ulid,
            'lines' => [[
                'account_ulid' => $supplierAccount->ulid,
                'amount' => '31.0000',
            ]],
        ])->assertStatus(422)->assertJsonPath('error.key', 'DOCUMENT_POSTED');

        $this->deleteJson('/api/vouchers/'.$voucherUlid)
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'DOCUMENT_POSTED');
    }

    public function test_receiving_and_journal_vouchers_follow_server_side_debit_credit_rules(): void
    {
        $this->signInOwner('voucher-directions')->assertOk();

        $cash = $this->leafAccount('Voucher Cash 2', 'V-CASH-02', '0010');
        $revenue = $this->leafAccount('Voucher Revenue', 'V-REV-01', '0040');
        $expense = $this->leafAccount('Voucher Expense', 'V-EXP-01', '0030');
        $date = now()->toDateString();

        $receiving = $this->postJson('/api/vouchers', [
            'type' => 'receiving',
            'entry_date' => $date,
            'description' => 'Other income received',
            'header_account_ulid' => $cash->ulid,
            'lines' => [[
                'account_ulid' => $revenue->ulid,
                'amount' => '25.0000',
            ]],
        ], $this->idem('voucher-receiving-1'))->assertCreated();

        $receiving->assertJsonPath('lines.0.debit', '25.0000')
            ->assertJsonPath('lines.0.credit', '0.0000')
            ->assertJsonPath('lines.1.debit', '0.0000')
            ->assertJsonPath('lines.1.credit', '25.0000');

        $this->postJson('/api/vouchers/'.$receiving->json('ulid').'/post')->assertOk();

        $journal = $this->postJson('/api/vouchers', [
            'type' => 'journal',
            'entry_date' => $date,
            'description' => 'Manual accrual',
            'lines' => [
                [
                    'account_ulid' => $expense->ulid,
                    'debit' => '10.0000',
                    'credit' => '0.0000',
                ],
                [
                    'account_ulid' => $revenue->ulid,
                    'debit' => '0.0000',
                    'credit' => '9.0000',
                ],
            ],
        ], $this->idem('voucher-journal-1'))->assertCreated();

        $journal->assertJsonPath('status', 'draft')
            ->assertJsonPath('total_debit', '10.0000')
            ->assertJsonPath('total_credit', '9.0000');

        $journalUlid = $journal->json('ulid');

        $this->postJson('/api/vouchers/'.$journalUlid.'/post')
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'UNBALANCED_JOURNAL');

        $this->putJson('/api/vouchers/'.$journalUlid, [
            'entry_date' => $date,
            'description' => 'Manual accrual corrected',
            'lines' => [
                [
                    'account_ulid' => $expense->ulid,
                    'debit' => '10.0000',
                    'credit' => '0.0000',
                ],
                [
                    'account_ulid' => $revenue->ulid,
                    'debit' => '0.0000',
                    'credit' => '10.0000',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('total_debit', '10.0000')
            ->assertJsonPath('total_credit', '10.0000');

        $this->postJson('/api/vouchers/'.$journalUlid.'/post')
            ->assertOk()
            ->assertJsonPath('status', 'posted');
    }

    public function test_voucher_idempotency_search_summary_and_tenant_isolation(): void
    {
        $this->signInOwner('voucher-security-a')->assertOk();

        $cash = $this->leafAccount('Voucher Cash A', 'V-CASH-A', '0010');
        $expense = $this->leafAccount('Voucher Expense A', 'V-EXP-A', '0030');
        $date = now()->toDateString();

        $payload = [
            'type' => 'payment',
            'entry_date' => $date,
            'description' => 'Branch expense payment',
            'header_account_ulid' => $cash->ulid,
            'lines' => [[
                'account_ulid' => $expense->ulid,
                'amount' => '12.5000',
            ]],
        ];

        $first = $this->postJson('/api/vouchers', $payload, $this->idem('voucher-idem-1'))->assertCreated();
        $second = $this->postJson('/api/vouchers', $payload, $this->idem('voucher-idem-1'))->assertCreated();

        $this->assertSame($first->json('ulid'), $second->json('ulid'));
        $this->assertSame(
            1,
            JournalEntry::query()
                ->whereIn('document_type', [
                    JournalEntry::DOCUMENT_PAYMENT_VOUCHER,
                    JournalEntry::DOCUMENT_RECEIVING_VOUCHER,
                    JournalEntry::DOCUMENT_JOURNAL_VOUCHER,
                ])
                ->count(),
        );

        $this->postJson('/api/vouchers', [
            ...$payload,
            'type' => 'receiving',
        ], $this->idem('voucher-idem-1'))
            ->assertStatus(409)
            ->assertJsonPath('error.key', 'IDEMPOTENCY_KEY_CONFLICT');

        $voucherUlid = $first->json('ulid');
        $voucherNumber = $first->json('voucher_number');
        $this->postJson('/api/vouchers/'.$voucherUlid.'/post')->assertOk();

        $this->getJson('/api/vouchers?q='.urlencode((string) $voucherNumber))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.ulid', $voucherUlid);

        $this->getJson('/api/vouchers/summary?date_from='.$date.'&date_to='.$date)
            ->assertOk()
            ->assertJsonPath('data.0.debit', '12.5000')
            ->assertJsonPath('data.0.credit', '12.5000');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/vouchers', $payload, $this->idem('voucher-no-auth'))
            ->assertUnauthorized();

        $this->signInOwner('voucher-security-b')->assertOk();
        $this->getJson('/api/vouchers/'.$voucherUlid)->assertNotFound();

        $otherCash = $this->leafAccount('Voucher Cash B', 'V-CASH-B', '0010');
        $this->postJson('/api/vouchers', [
            'type' => 'payment',
            'entry_date' => $date,
            'header_account_ulid' => $otherCash->ulid,
            'lines' => [[
                'account_ulid' => $expense->ulid,
                'amount' => '1.0000',
            ]],
        ], $this->idem('voucher-cross-tenant-account'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');
    }

    /**
     * @return array<string, string>
     */
    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
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
}
