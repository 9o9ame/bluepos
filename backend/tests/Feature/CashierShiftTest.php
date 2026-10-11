<?php

namespace Tests\Feature;

use App\Authz\PermissionService;
use App\Enums\BranchStatus;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Permission;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CashierShiftTest extends TestCase
{
    use DatabaseTransactions;

    public function test_shift_open_is_idempotent_and_only_one_open_shift_is_allowed_per_branch(): void
    {
        $this->signInOwner('cash-shift-open')->assertOk();

        $payload = [
            'opening_cash' => '100.0000',
            'notes' => 'Morning drawer',
        ];

        $first = $this->postJson(
            '/api/cash-sessions/open',
            $payload,
            $this->idem('cash-shift-open-1'),
        )->assertCreated()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('opening_cash', '100.0000')
            ->assertJsonPath('expected_cash', '100.0000');

        $shiftUlid = (string) $first->json('ulid');
        $this->assertNoInternalIds($first->json());

        $second = $this->postJson(
            '/api/cash-sessions/open',
            $payload,
            $this->idem('cash-shift-open-1'),
        )->assertCreated();

        $this->assertSame($shiftUlid, $second->json('ulid'));
        $this->assertSame(1, CashierShift::query()->count());

        $this->postJson(
            '/api/cash-sessions/open',
            ['opening_cash' => '100.0000'],
            $this->idem('cash-shift-open-2'),
        )->assertStatus(409)
            ->assertJsonPath('error.key', 'CASH_SHIFT_ALREADY_OPEN');

        $this->getJson('/api/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('shift.ulid', $shiftUlid)
            ->assertJsonPath('shift.status', 'open');
    }

    public function test_shift_close_recomputes_expected_drawer_cash_and_replays_safely(): void
    {
        $this->signInOwner('cash-shift-close')->assertOk();

        $cash = $this->leafAccount('SHIFT CASH', 'SHIFT-CASH', '0010');
        $revenue = $this->leafAccount('SHIFT REVENUE', 'SHIFT-REV', '0040');
        $expense = $this->leafAccount('SHIFT EXPENSE', 'SHIFT-EXP', '0030');

        $opened = $this->postJson(
            '/api/cash-sessions/open',
            ['opening_cash' => '100.0000'],
            $this->idem('cash-shift-close-open'),
        )->assertCreated();

        $shiftUlid = (string) $opened->json('ulid');
        CashierShift::query()
            ->where('ulid', $shiftUlid)
            ->update(['opened_at' => now()->subMinute()]);

        $this->postedJournal(
            'receiving_voucher',
            [
                [$cash, '50.0000', '0.0000', 'drawer receipt'],
                [$revenue, '0.0000', '50.0000', 'income'],
            ],
        );

        $this->postedJournal(
            'payment_voucher',
            [
                [$expense, '20.0000', '0.0000', 'expense'],
                [$cash, '0.0000', '20.0000', 'drawer payment'],
            ],
        );

        // Current payment accounting routes non-credit methods through the
        // configured cash account. Method-labelled card/bank lines must not
        // inflate physical drawer cash.
        $this->postedJournal(
            'sale_payment',
            [
                [$cash, '30.0000', '0.0000', 'card'],
                [$revenue, '0.0000', '30.0000', 'sale settlement'],
            ],
        );

        $closed = $this->postJson(
            '/api/cash-sessions/'.$shiftUlid.'/close',
            ['actual_cash' => '125.0000', 'notes' => 'Counted drawer'],
            $this->idem('cash-shift-close-1'),
        )->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('opening_cash', '100.0000')
            ->assertJsonPath('expected_cash', '130.0000')
            ->assertJsonPath('actual_cash', '125.0000')
            ->assertJsonPath('difference', '-5.0000')
            ->assertJsonPath('notes', 'Counted drawer');

        $this->assertNoInternalIds($closed->json());

        $replay = $this->postJson(
            '/api/cash-sessions/'.$shiftUlid.'/close',
            ['actual_cash' => '125.0000', 'notes' => 'Counted drawer'],
            $this->idem('cash-shift-close-1'),
        )->assertOk();

        $this->assertSame($shiftUlid, $replay->json('ulid'));

        $this->postJson(
            '/api/cash-sessions/'.$shiftUlid.'/close',
            ['actual_cash' => '125.0000'],
            $this->idem('cash-shift-close-2'),
        )->assertStatus(409)
            ->assertJsonPath('error.key', 'CASH_SHIFT_ALREADY_CLOSED');

        $this->getJson('/api/cash-sessions/current')
            ->assertOk()
            ->assertJsonPath('shift', null);

        $this->postJson(
            '/api/cash-sessions/open',
            ['opening_cash' => '25.0000'],
            $this->idem('cash-shift-open-after-close'),
        )->assertCreated()
            ->assertJsonPath('status', 'open');
    }

    public function test_shift_permissions_and_branch_isolation_are_enforced(): void
    {
        $this->signInOwner('cash-shift-permissions')->assertOk();

        $tenant = app(TenantContext::class);
        $role = $tenant->membership()
            ->roles()
            ->where('roles.tenant_id', $tenant->tenantId())
            ->firstOrFail();

        $openPermission = Permission::query()->where('key', 'cash_sessions.open')->firstOrFail();
        $role->permissions()->detach($openPermission->id);
        app()->forgetInstance(PermissionService::class);

        $this->postJson(
            '/api/cash-sessions/open',
            ['opening_cash' => '10.0000'],
            $this->idem('cash-shift-open-forbidden'),
        )->assertForbidden();

        $role->permissions()->syncWithoutDetaching([$openPermission->id]);
        app()->forgetInstance(PermissionService::class);

        $shiftUlid = $this->postJson(
            '/api/cash-sessions/open',
            ['opening_cash' => '10.0000'],
            $this->idem('cash-shift-permission-open'),
        )->assertCreated()->json('ulid');

        $closePermission = Permission::query()->where('key', 'cash_sessions.close')->firstOrFail();
        $role->permissions()->detach($closePermission->id);
        app()->forgetInstance(PermissionService::class);

        $this->postJson(
            '/api/cash-sessions/'.$shiftUlid.'/close',
            ['actual_cash' => '10.0000'],
            $this->idem('cash-shift-close-forbidden'),
        )->assertForbidden();

        $role->permissions()->syncWithoutDetaching([$closePermission->id]);
        app()->forgetInstance(PermissionService::class);

        $otherBranch = Branch::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'code' => 'SHIFT-B2',
            'name' => 'Shift Other Branch',
            'status' => BranchStatus::Active,
            'is_default' => false,
        ]);

        $foreignShift = CashierShift::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'branch_id' => $otherBranch->id,
            'membership_id' => $tenant->membershipId(),
            'opened_at' => now(),
            'status' => 'open',
            'opening_cash' => '5.0000',
            'expected_cash' => '5.0000',
            'open_idempotency_key' => 'foreign-branch-shift',
        ]);

        $this->postJson(
            '/api/cash-sessions/'.$foreignShift->ulid.'/close',
            ['actual_cash' => '5.0000'],
            $this->idem('cash-shift-cross-branch-close'),
        )->assertNotFound();
    }

    /**
     * @param array<int, array{0: Account, 1: string, 2: string, 3: string}> $lines
     */
    private function postedJournal(string $documentType, array $lines): void
    {
        $tenant = app(TenantContext::class);

        $entry = JournalEntry::query()->create([
            'tenant_id' => $tenant->tenantId(),
            'branch_id' => $tenant->branchId(),
            'document_type' => $documentType,
            'entry_date' => now()->toDateString(),
            'description' => 'Cash shift test journal',
            'status' => 'posted',
            'created_by' => $tenant->userId(),
            'posted_by' => $tenant->userId(),
            'posted_at' => now(),
        ]);

        foreach ($lines as $index => [$account, $debit, $credit, $description]) {
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
}
