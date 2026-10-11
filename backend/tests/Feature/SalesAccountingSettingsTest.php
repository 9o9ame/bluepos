<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SalesAccountingSettingsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sales_accounting_control_accounts_round_trip_by_public_ulid(): void
    {
        $this->signInOwner('sales-accounting-settings')->assertOk();

        $tenant = app(TenantContext::class);
        $accountType = AccountType::query()
            ->forTenant($tenant->tenantId())
            ->orderBy('id')
            ->firstOrFail();

        $revenue = $this->account($tenant->tenantId(), $tenant->userId(), $accountType->id, 'SALES-REV', 'Sales Revenue');
        $inventory = $this->account($tenant->tenantId(), $tenant->userId(), $accountType->id, 'INV-CTRL', 'Inventory Control');
        $cogs = $this->account($tenant->tenantId(), $tenant->userId(), $accountType->id, 'COGS-CTRL', 'Cost of Goods Sold');

        $response = $this->patchJson('/api/settings/business', [
            'sales_revenue_account_ulid' => $revenue->ulid,
            'inventory_account_ulid' => $inventory->ulid,
            'cogs_account_ulid' => $cogs->ulid,
        ])->assertOk()
            ->assertJsonPath('sales_revenue_account_ulid', $revenue->ulid)
            ->assertJsonPath('inventory_account_ulid', $inventory->ulid)
            ->assertJsonPath('cogs_account_ulid', $cogs->ulid)
            ->assertJsonPath('sales_revenue_account.ulid', $revenue->ulid)
            ->assertJsonPath('inventory_account.ulid', $inventory->ulid)
            ->assertJsonPath('cogs_account.ulid', $cogs->ulid);

        $this->assertNoInternalIds($response->json());

        $this->getJson('/api/settings/business')
            ->assertOk()
            ->assertJsonPath('sales_revenue_account_ulid', $revenue->ulid)
            ->assertJsonPath('inventory_account_ulid', $inventory->ulid)
            ->assertJsonPath('cogs_account_ulid', $cogs->ulid);
    }

    private function account(
        int $tenantId,
        int $userId,
        int $accountTypeId,
        string $code,
        string $name,
    ): Account {
        return Account::query()->create([
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'account_type_id' => $accountTypeId,
            'is_active' => true,
            'created_by' => $userId,
        ]);
    }
}
