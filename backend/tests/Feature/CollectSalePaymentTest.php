<?php

namespace Tests\Feature;

use App\Accounting\PartyLeafAccountSync;
use App\Models\Account;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\StockBalance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4 — collecting payment against a posted sale.
 *
 * Partial payments are allowed and each one must write a balanced journal.
 * Sales revenue and COGS are not posted here; settlement lands in the
 * configured clearing account until that journal exists.
 */
class CollectSalePaymentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_initial_payment_is_posted_atomically_with_sale(): void
    {
        $this->signInOwner('pay-initial-atomic')->assertOk();
        $this->configureAccounts();

        $product = $this->createProduct('Atomic Initial Payment', '100.0000');
        $this->giveStock($product, '10');

        $payload = [
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
            'initial_payment' => [
                'amount' => '40.0000',
                'method' => 'cash',
                'reference' => 'COUNTER-1',
            ],
        ];

        $first = $this->postJson(
            '/api/sales',
            $payload,
            $this->idem('sale-initial-atomic'),
        )
            ->assertCreated()
            ->assertJsonPath('grand_total', '100.0000')
            ->assertJsonPath('paid_amount', '40.0000')
            ->assertJsonPath('balance_due', '60.0000')
            ->assertJsonPath('payments.0.amount', '40.0000')
            ->assertJsonPath('payments.0.method', 'cash')
            ->assertJsonPath('payments.0.reference', 'COUNTER-1');

        $saleUlid = (string) $first->json('ulid');

        $second = $this->postJson(
            '/api/sales',
            $payload,
            $this->idem('sale-initial-atomic'),
        )->assertCreated();

        $this->assertSame($saleUlid, $second->json('ulid'));
        $this->assertSame(
            1,
            SalePayment::query()
                ->where('sale_id', $this->saleId($saleUlid))
                ->count(),
        );
        $this->assertSame('9.000000', $this->stock($product));
    }

    public function test_initial_overpayment_rolls_back_sale_and_stock(): void
    {
        $this->signInOwner('pay-initial-overpayment')->assertOk();
        $this->configureAccounts();

        $product = $this->createProduct('Atomic Overpayment Item', '100.0000');
        $this->giveStock($product, '10');

        $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
            'initial_payment' => [
                'amount' => '150.0000',
                'method' => 'cash',
            ],
        ], $this->idem('sale-initial-overpayment'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, SalePayment::query()->count());
        $this->assertSame('10.000000', $this->stock($product));
    }

    public function test_initial_payment_failure_rolls_back_sale_and_stock(): void
    {
        $this->signInOwner('pay-initial-rollback')->assertOk();

        $product = $this->createProduct('Atomic Rollback Item', '100.0000');
        $this->giveStock($product, '10');

        $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
            'initial_payment' => [
                'amount' => '100.0000',
                'method' => 'cash',
            ],
        ], $this->idem('sale-initial-rollback'))
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'CASH_ACCOUNT_REQUIRED');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, SalePayment::query()->count());
        $this->assertSame('10.000000', $this->stock($product));
    }

    public function test_initial_payment_requires_payment_permission(): void
    {
        $this->signInOwner('pay-initial-authz')->assertOk();

        $context = app(\App\Tenancy\TenantContext::class);
        $roleIds = $context->membership()
            ->roles()
            ->where('roles.tenant_id', $context->tenantId())
            ->pluck('roles.id');

        $paymentPermissionId = DB::table('permissions')
            ->where('key', 'payments.create')
            ->value('id');

        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $paymentPermissionId)
            ->delete();

        $product = $this->createProduct('Initial Payment Authz', '100.0000');
        $this->giveStock($product, '10');

        $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
            'initial_payment' => [
                'amount' => '100.0000',
                'method' => 'cash',
            ],
        ], $this->idem('sale-initial-authz'))
            ->assertForbidden();

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, SalePayment::query()->count());
        $this->assertSame('10.000000', $this->stock($product));
    }

    public function test_full_cash_payment_writes_a_balanced_journal(): void
    {
        $this->signInOwner('pay-1')->assertOk();
        $accounts = $this->configureAccounts();
        $sale = $this->createSale('pay-1', '100.0000');

        $payment = $this->postJson('/api/sales/'.$sale.'/payments', [
            'amount' => '100.0000',
            'method' => 'cash',
        ], $this->idem('pay-1-cash'))->assertCreated();

        $payment->assertJsonPath('amount', '100.0000');
        $payment->assertJsonPath('method', 'cash');
        $this->assertNoInternalIds($payment->json());

        $this->assertBalancedJournalFor($sale, '100.0000');
        $this->assertSame('0.0000', $this->outstanding($sale));
    }

    public function test_partial_payments_are_allowed_and_reduce_the_due(): void
    {
        $this->signInOwner('pay-2')->assertOk();
        $this->configureAccounts();
        $sale = $this->createSale('pay-2', '100.0000');

        $this->postJson('/api/sales/'.$sale.'/payments', [
            'amount' => '40.0000',
            'method' => 'cash',
        ], $this->idem('pay-2-a'))->assertCreated();

        $this->assertSame('60.0000', $this->outstanding($sale));

        $this->postJson('/api/sales/'.$sale.'/payments', [
            'amount' => '60.0000',
            'method' => 'card',
        ], $this->idem('pay-2-b'))->assertCreated();

        $this->assertSame('0.0000', $this->outstanding($sale));
        $this->assertSame(2, SalePayment::query()->where('sale_id', $this->saleId($sale))->count());
    }

    public function test_due_only_sales_list_excludes_fully_paid_sales_and_keeps_partial_balances(): void
    {
        $this->signInOwner('pay-due-list')->assertOk();
        $this->configureAccounts();

        $unpaid = $this->createSale('pay-due-unpaid', '100.0000');
        $partial = $this->createSale('pay-due-partial', '100.0000');
        $paid = $this->createSale('pay-due-paid', '100.0000');

        $this->postJson('/api/sales/'.$partial.'/payments', [
            'amount' => '40.0000',
            'method' => 'cash',
        ], $this->idem('pay-due-partial-payment'))->assertCreated();

        $this->postJson('/api/sales/'.$paid.'/payments', [
            'amount' => '100.0000',
            'method' => 'cash',
        ], $this->idem('pay-due-full-payment'))->assertCreated();

        $response = $this->getJson('/api/sales?due_only=1&status=posted')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $ulids = collect($response->json('data'))->pluck('ulid')->all();

        $this->assertContains($unpaid, $ulids);
        $this->assertContains($partial, $ulids);
        $this->assertNotContains($paid, $ulids);

        $partialRow = collect($response->json('data'))->firstWhere('ulid', $partial);
        $this->assertSame('40.0000', $partialRow['paid_amount']);
        $this->assertSame('60.0000', $partialRow['balance_due']);
    }

    public function test_payment_cannot_exceed_the_outstanding_balance(): void
    {
        $this->signInOwner('pay-3')->assertOk();
        $this->configureAccounts();
        $sale = $this->createSale('pay-3', '100.0000');

        $this->postJson('/api/sales/'.$sale.'/payments', [
            'amount' => '150.0000',
            'method' => 'cash',
        ], $this->idem('pay-3-a'))->assertStatus(422);

        $this->assertSame(0, SalePayment::query()->count());
        $this->assertSame('100.0000', $this->outstanding($sale));
    }

    public function test_replayed_payment_key_does_not_collect_twice(): void
    {
        $this->signInOwner('pay-4')->assertOk();
        $this->configureAccounts();
        $sale = $this->createSale('pay-4', '100.0000');

        $payload = ['amount' => '100.0000', 'method' => 'cash'];
        $first = $this->postJson('/api/sales/'.$sale.'/payments', $payload, $this->idem('pay-4-replay'))->assertCreated();
        $second = $this->postJson('/api/sales/'.$sale.'/payments', $payload, $this->idem('pay-4-replay'))->assertCreated();

        $this->assertSame($first->json('ulid'), $second->json('ulid'));
        $this->assertSame(1, SalePayment::query()->count());
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        $this->signInOwner('pay-5')->assertOk();
        $this->configureAccounts();
        $sale = $this->createSale('pay-5', '100.0000');

        $this->postJson('/api/sales/'.$sale.'/payments', [
            'amount' => '100.0000',
            'method' => 'cash',
        ])->assertStatus(422)
            ->assertJsonPath('error.key', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_zero_and_negative_amounts_are_rejected(): void
    {
        $this->signInOwner('pay-6')->assertOk();
        $this->configureAccounts();
        $sale = $this->createSale('pay-6', '100.0000');

        $this->postJson('/api/sales/'.$sale.'/payments', [
            'amount' => '0.0000',
            'method' => 'cash',
        ], $this->idem('pay-6-zero'))->assertStatus(422);

        $this->postJson('/api/sales/'.$sale.'/payments', [
            'amount' => '-5.0000',
            'method' => 'cash',
        ], $this->idem('pay-6-neg'))->assertStatus(422);

        $this->assertSame(0, SalePayment::query()->count());
    }

    public function test_business_settings_can_configure_payment_accounts_by_ulid(): void
    {
        $this->signInOwner('pay-settings')->assertOk();

        $cash = $this->leafAccount('SETTINGS CASH', '9201');
        $clearing = $this->leafAccount('SETTINGS CLEARING', '9202');

        $response = $this->patchJson('/api/settings/business', [
            'default_cash_account_ulid' => $cash->ulid,
            'sales_clearing_account_ulid' => $clearing->ulid,
        ])->assertOk();

        $response
            ->assertJsonPath('default_cash_account_ulid', $cash->ulid)
            ->assertJsonPath('default_cash_account.ulid', $cash->ulid)
            ->assertJsonPath('sales_clearing_account_ulid', $clearing->ulid)
            ->assertJsonPath('sales_clearing_account.ulid', $clearing->ulid);

        $settings = BusinessSetting::query()
            ->forTenant(app(\App\Tenancy\TenantContext::class)->tenantId())
            ->firstOrFail();

        $this->assertSame($cash->id, (int) $settings->default_cash_account_id);
        $this->assertSame($clearing->id, (int) $settings->sales_clearing_account_id);

        $sale = $this->createSale('pay-settings', '100.0000');

        $this->postJson('/api/sales/'.$sale.'/payments', [
            'amount' => '100.0000',
            'method' => 'cash',
        ], $this->idem('pay-settings-collect'))
            ->assertCreated()
            ->assertJsonPath('amount', '100.0000');

        $this->assertSame('0.0000', $this->outstanding($sale));
    }

    public function test_business_settings_reject_payment_accounts_from_another_tenant(): void
    {
        $this->signInOwner('pay-settings-a')->assertOk();
        $foreignCash = $this->leafAccount('FOREIGN CASH', '9301');
        $foreignClearing = $this->leafAccount('FOREIGN CLEARING', '9302');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('pay-settings-b')->assertOk();

        $this->patchJson('/api/settings/business', [
            'default_cash_account_ulid' => $foreignCash->ulid,
            'sales_clearing_account_ulid' => $foreignClearing->ulid,
        ])->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');
    }

    public function test_payment_fails_clearly_when_accounts_are_not_configured(): void
    {
        $this->signInOwner('pay-7')->assertOk();
        $sale = $this->createSale('pay-7', '100.0000');

        $this->postJson('/api/sales/'.$sale.'/payments', [
            'amount' => '100.0000',
            'method' => 'cash',
        ], $this->idem('pay-7-unconfigured'))->assertStatus(422)
            ->assertJsonPath('error.key', 'CASH_ACCOUNT_REQUIRED');

        $this->assertSame(0, SalePayment::query()->count());
    }

    public function test_credit_payment_requires_a_customer_on_the_sale(): void
    {
        $this->signInOwner('pay-8')->assertOk();
        $this->configureAccounts();
        $sale = $this->createSale('pay-8', '100.0000');

        $this->postJson('/api/sales/'.$sale.'/payments', [
            'amount' => '100.0000',
            'method' => 'credit',
        ], $this->idem('pay-8-credit'))->assertStatus(422)
            ->assertJsonPath('error.key', 'CREDIT_REQUIRES_CUSTOMER');

        $this->assertSame(0, SalePayment::query()->count());
    }

    public function test_payment_against_another_tenant_sale_is_not_found(): void
    {
        $this->signInOwner('pay-9a')->assertOk();
        $this->configureAccounts();
        $sale = $this->createSale('pay-9a', '100.0000');

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('pay-9b')->assertOk();

        $this->postJson('/api/sales/'.$sale.'/payments', [
            'amount' => '100.0000',
            'method' => 'cash',
        ], $this->idem('pay-9b-foreign'))->assertNotFound();
    }

    private function assertBalancedJournalFor(string $saleUlid, string $expectedAmount): void
    {
        $sale = Sale::query()->where('ulid', $saleUlid)->firstOrFail();
        $payment = SalePayment::query()->where('sale_id', $sale->id)->latest('id')->firstOrFail();

        $entry = JournalEntry::query()
            ->where('ulid', $payment->journal_entry_ulid)
            ->with('lines')
            ->firstOrFail();

        $debit = '0.0000';
        $credit = '0.0000';
        foreach ($entry->lines as $line) {
            $debit = bcadd($debit, (string) $line->debit, 4);
            $credit = bcadd($credit, (string) $line->credit, 4);
        }

        $this->assertSame(0, bccomp($debit, $credit, 4), 'Journal must balance.');
        $this->assertSame(0, bccomp($debit, $expectedAmount, 4));
        $this->assertSame('posted', $entry->status->value);
    }

    private function outstanding(string $saleUlid): string
    {
        $sale = Sale::query()->where('ulid', $saleUlid)->firstOrFail();
        $paid = (string) SalePayment::query()->where('sale_id', $sale->id)->sum('amount');

        return bcsub((string) $sale->grand_total, $paid, 4);
    }

    private function saleId(string $saleUlid): int
    {
        return (int) Sale::query()->where('ulid', $saleUlid)->firstOrFail()->id;
    }

    /** Create a sale of the given unit price x 1 and return its ULID. */
    private function createSale(string $suffix, string $unitPrice): string
    {
        $product = $this->createProduct('Pay Item '.$suffix, $unitPrice);
        $this->giveStock($product, '50');

        return $this->postJson('/api/sales', [
            'items' => [['product_ulid' => $product, 'quantity' => '1']],
        ], $this->idem('sale-'.$suffix))->assertCreated()->json('ulid');
    }

    /**
     * Point business settings at a cash account and a clearing account.
     *
     * @return array{cash: Account, clearing: Account}
     */
    private function configureAccounts(): array
    {
        $cash = $this->leafAccount('TEST CASH', '9101');
        $clearing = $this->leafAccount('TEST CLEARING', '9102');

        BusinessSetting::query()
            ->forTenant(app(\App\Tenancy\TenantContext::class)->tenantId())
            ->update([
                'default_cash_account_id' => $cash->id,
                'sales_clearing_account_id' => $clearing->id,
            ]);

        return ['cash' => $cash, 'clearing' => $clearing];
    }

    /**
     * `accounts` is the ledger leaf table. It has no create endpoint yet
     * (accounts are provisioned by PartyLeafAccountSync), so seed it the same
     * way production does.
     */
    private function leafAccount(string $name, string $code): Account
    {
        $tenantContext = app(\App\Tenancy\TenantContext::class);

        $accountType = \App\Models\AccountType::query()
            ->forTenant($tenantContext->tenantId())
            ->orderBy('id')
            ->firstOrFail();

        return Account::query()->create([
            'tenant_id' => $tenantContext->tenantId(),
            'code' => $code,
            'name' => $name,
            'account_type_id' => $accountType->id,
            'is_active' => true,
            'created_by' => $tenantContext->userId(),
        ]);
    }

    private function createProduct(string $name, string $retail): string
    {
        return $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
            'prices' => [['price_type' => 'retail', 'amount' => $retail]],
        ])->assertCreated()->json('ulid');
    }

    private function giveStock(string $productUlid, string $quantity): void
    {
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        $balance = StockBalance::query()->firstOrCreate(
            [
                'tenant_id' => $product->tenant_id,
                'warehouse_id' => $tenantContext->warehouseId(),
                'product_id' => $product->id,
            ],
            ['branch_id' => $tenantContext->branchId(), 'quantity' => '0.000000'],
        );

        $balance->quantity = bcadd((string) $balance->quantity, $quantity, 6);
        $balance->average_cost = '10.0000';
        $balance->stock_value = bcmul((string) $balance->quantity, '10.0000', 4);
        $balance->save();
    }

    private function stock(string $productUlid): string
    {
        $tenantContext = app(\App\Tenancy\TenantContext::class);
        $product = Product::query()->where('ulid', $productUlid)->firstOrFail();

        return (string) StockBalance::query()
            ->where('tenant_id', $tenantContext->tenantId())
            ->where('branch_id', $tenantContext->branchId())
            ->where('warehouse_id', $tenantContext->warehouseId())
            ->where('product_id', $product->id)
            ->value('quantity');
    }

    /**
     * @return array<string, string>
     */
    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    private function unitUlid(string $code): string
    {
        $units = $this->getJson('/api/units')->assertOk()->json();
        foreach ($units as $unit) {
            if ($unit['code'] === $code) {
                return $unit['ulid'];
            }
        }

        $this->fail('Missing unit '.$code);
    }
}
