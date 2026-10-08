<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Models\StockMovement;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchasePaymentTest extends TestCase
{
    use DatabaseTransactions;

    public function test_full_purchase_payment_posts_balanced_journal_and_clears_balance(): void
    {
        $this->signInOwner('pur-pay-full')->assertOk();
        $accounts = $this->configureAccounts();
        $purchase = $this->createPostedPurchase('pur-pay-full', '100.0000');
        $purchaseModel = PurchaseInvoice::query()->where('ulid', $purchase)->firstOrFail();
        $supplierAccount = Account::query()
            ->where('supplier_id', $purchaseModel->supplier_id)
            ->firstOrFail();

        $payment = $this->postJson(
            '/api/purchases/'.$purchase.'/payments',
            [
                'amount' => '100.0000',
                'method' => 'cash',
                'reference' => 'SUP-SETTLE-1',
            ],
            $this->idem('pur-pay-full-1'),
        )->assertCreated();

        $payment->assertJsonPath('amount', '100.0000')
            ->assertJsonPath('method', 'cash')
            ->assertJsonPath('reference', 'SUP-SETTLE-1');
        $this->assertNoInternalIds($payment->json());

        $invoice = $this->getJson('/api/purchases/'.$purchase)->assertOk();
        $invoice->assertJsonPath('paid_amount', '100.0000')
            ->assertJsonPath('balance_payable', '0.0000')
            ->assertJsonCount(1, 'payments');

        $purchaseId = PurchaseInvoice::query()->where('ulid', $purchase)->value('id');
        $journal = JournalEntry::query()
            ->where('document_type', JournalEntry::DOCUMENT_PURCHASE_PAYMENT)
            ->where('document_id', $purchaseId)
            ->with('lines')
            ->sole();

        $this->assertCount(2, $journal->lines);
        $this->assertSame(
            '100.0000',
            (string) $journal->lines->firstWhere('account_id', $supplierAccount->id)?->debit,
        );
        $this->assertSame(
            '100.0000',
            (string) $journal->lines->firstWhere('account_id', $accounts['cash']->id)?->credit,
        );
        $this->assertSame(
            '100.0000',
            $journal->lines->reduce(
                fn (string $total, JournalLine $line): string => bcadd($total, (string) $line->debit, 4),
                '0.0000',
            ),
        );
        $this->assertSame(
            '100.0000',
            $journal->lines->reduce(
                fn (string $total, JournalLine $line): string => bcadd($total, (string) $line->credit, 4),
                '0.0000',
            ),
        );
    }

    public function test_partial_payment_is_idempotent_and_overpayment_is_rejected(): void
    {
        $this->signInOwner('pur-pay-partial')->assertOk();
        $this->configureAccounts();
        $purchase = $this->createPostedPurchase('pur-pay-partial', '100.0000');

        $first = $this->postJson(
            '/api/purchases/'.$purchase.'/payments',
            ['amount' => '40.0000', 'method' => 'bank'],
            $this->idem('pur-pay-partial-1'),
        )->assertCreated();

        $second = $this->postJson(
            '/api/purchases/'.$purchase.'/payments',
            ['amount' => '40.0000', 'method' => 'bank'],
            $this->idem('pur-pay-partial-1'),
        )->assertCreated();

        $this->assertSame($first->json('ulid'), $second->json('ulid'));
        $this->assertSame(1, PurchasePayment::query()->count());

        $this->getJson('/api/purchases/'.$purchase)
            ->assertOk()
            ->assertJsonPath('paid_amount', '40.0000')
            ->assertJsonPath('balance_payable', '60.0000');

        $this->postJson(
            '/api/purchases/'.$purchase.'/payments',
            ['amount' => '61.0000', 'method' => 'cash'],
            $this->idem('pur-pay-partial-over'),
        )->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_ERROR');

        $this->assertSame(1, PurchasePayment::query()->count());
    }

    public function test_purchase_payment_requires_posted_invoice_and_cash_account(): void
    {
        $this->signInOwner('pur-pay-guards')->assertOk();
        $this->configurePurchaseClearingOnly();

        $warehouse = $this->sessionWarehouseUlid();
        $supplier = $this->createSupplier('PP-GUARD', 'Payment Guard Supplier');
        $product = $this->createProduct('Payment Guard Product');

        $purchase = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplier,
            'warehouse_ulid' => $warehouse,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$purchase.'/lines', [
            'product_ulid' => $product,
            'unit_ulid' => $this->unitUlid('PCS'),
            'quantity' => '1.000000',
            'unit_cost' => '100.0000',
        ])->assertCreated();

        $this->postJson(
            '/api/purchases/'.$purchase.'/payments',
            ['amount' => '10.0000', 'method' => 'cash'],
            $this->idem('pur-pay-draft'),
        )->assertStatus(422)
            ->assertJsonPath('error.key', 'PURCHASE_NOT_POSTED');

        $this->postJson('/api/purchases/'.$purchase.'/post')->assertOk();

        $this->postJson(
            '/api/purchases/'.$purchase.'/payments',
            ['amount' => '10.0000', 'method' => 'cash'],
            $this->idem('pur-pay-no-cash'),
        )->assertStatus(422)
            ->assertJsonPath('error.key', 'CASH_ACCOUNT_REQUIRED');

        $this->assertSame(0, PurchasePayment::query()->count());
    }

    public function test_purchase_payment_requires_permission_and_preserves_tenant_isolation(): void
    {
        $this->signInOwner('pur-pay-tenant-a')->assertOk();
        $this->configureAccounts();
        $purchaseA = $this->createPostedPurchase('pur-pay-tenant-a', '50.0000');

        $context = app(TenantContext::class);
        $roleIds = $context->membership()
            ->roles()
            ->where('roles.tenant_id', $context->tenantId())
            ->pluck('roles.id');
        $permissionId = DB::table('permissions')->where('key', 'payments.create')->value('id');

        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->where('permission_id', $permissionId)
            ->delete();
        app()->forgetInstance(\App\Authz\PermissionService::class);

        $this->postJson(
            '/api/purchases/'.$purchaseA.'/payments',
            ['amount' => '10.0000', 'method' => 'cash'],
            $this->idem('pur-pay-no-permission'),
        )->assertForbidden();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('pur-pay-tenant-b')->assertOk();
        $this->configureAccounts();

        $this->getJson('/api/purchases/'.$purchaseA.'/payments')->assertNotFound();
        $this->postJson(
            '/api/purchases/'.$purchaseA.'/payments',
            ['amount' => '10.0000', 'method' => 'cash'],
            $this->idem('pur-pay-cross-tenant'),
        )->assertNotFound();
    }

    public function test_posted_purchase_return_blocks_payment_until_return_accounting_exists(): void
    {
        $this->signInOwner('pur-pay-return')->assertOk();
        $this->configureAccounts();
        $purchaseUlid = $this->createPostedPurchase('pur-pay-return', '75.0000');

        $invoice = PurchaseInvoice::query()->where('ulid', $purchaseUlid)->firstOrFail();

        PurchaseReturn::query()->create([
            'tenant_id' => $invoice->tenant_id,
            'branch_id' => $invoice->branch_id,
            'warehouse_id' => $invoice->warehouse_id,
            'supplier_id' => $invoice->supplier_id,
            'purchase_invoice_id' => $invoice->id,
            'document_number' => 'PR-ACCOUNTING-GUARD',
            'return_date' => now()->toDateString(),
            'status' => 'posted',
            'subtotal' => '10.0000',
            'discount_amount' => '0.0000',
            'tax_amount' => '0.0000',
            'grand_total' => '10.0000',
            'created_by' => app(TenantContext::class)->userId(),
            'posted_by' => app(TenantContext::class)->userId(),
            'posted_at' => now(),
        ]);

        $this->postJson(
            '/api/purchases/'.$purchaseUlid.'/payments',
            ['amount' => '10.0000', 'method' => 'cash'],
            $this->idem('pur-pay-return-guard'),
        )->assertStatus(422)
            ->assertJsonPath('error.key', 'PURCHASE_RETURN_ACCOUNTING_REQUIRED');

        $this->assertSame(0, PurchasePayment::query()->count());
    }

    /**
     * @return array{cash: Account, clearing: Account}
     */
    private function configureAccounts(): array
    {
        $tenant = app(TenantContext::class);
        $cash = $this->leafAccount('PURCHASE TEST CASH', '9201', '0010');
        $clearing = $this->leafAccount('PURCHASE TEST CLEARING', '9202', '0060');

        BusinessSetting::query()
            ->forTenant($tenant->tenantId())
            ->update([
                'default_cash_account_id' => $cash->id,
                'purchase_clearing_account_id' => $clearing->id,
            ]);

        return [
            'cash' => $cash,
            'clearing' => $clearing,
        ];
    }

    private function configurePurchaseClearingOnly(): void
    {
        $tenant = app(TenantContext::class);
        $clearing = $this->leafAccount('PURCHASE ONLY CLEARING', '9203', '0060');

        BusinessSetting::query()
            ->forTenant($tenant->tenantId())
            ->update(['purchase_clearing_account_id' => $clearing->id]);
    }

    private function createPostedPurchase(string $suffix, string $amount): string
    {
        $warehouse = $this->sessionWarehouseUlid();
        $supplier = $this->createSupplier('SUP-'.strtoupper(substr(md5($suffix), 0, 6)), 'Supplier '.$suffix);
        $product = $this->createProduct('Product '.$suffix);

        $purchase = $this->postJson('/api/purchases', [
            'supplier_ulid' => $supplier,
            'warehouse_ulid' => $warehouse,
        ])->assertCreated()->json('ulid');

        $this->postJson('/api/purchases/'.$purchase.'/lines', [
            'product_ulid' => $product,
            'unit_ulid' => $this->unitUlid('PCS'),
            'quantity' => '1.000000',
            'unit_cost' => $amount,
        ])->assertCreated();

        $this->postJson('/api/purchases/'.$purchase.'/post')->assertOk();

        return $purchase;
    }

    private function createSupplier(string $code, string $name): string
    {
        return $this->postJson('/api/suppliers', [
            'code' => $code,
            'name' => $name,
        ])->assertCreated()->json('ulid');
    }

    private function createProduct(string $name): string
    {
        return $this->postJson('/api/products', [
            'name' => $name,
            'base_unit_ulid' => $this->unitUlid('PCS'),
        ])->assertCreated()->json('ulid');
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

    /**
     * @return array<string, string>
     */
    private function idem(string $key): array
    {
        return ['Idempotency-Key' => $key];
    }

    private function sessionWarehouseUlid(): string
    {
        return (string) $this->getJson('/api/auth/me')->assertOk()->json('warehouse.ulid');
    }

    private function unitUlid(string $code): string
    {
        foreach ($this->getJson('/api/units')->assertOk()->json() as $unit) {
            if ($unit['code'] === $code) {
                return $unit['ulid'];
            }
        }

        $this->fail('Missing unit '.$code);
    }
}
