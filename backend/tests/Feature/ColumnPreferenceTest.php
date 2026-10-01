<?php

namespace Tests\Feature;

use App\Models\ColumnPreference;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ColumnPreferenceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_user_can_save_and_reload_column_layout(): void
    {
        $this->signInOwner('colpref-a')->assertOk();

        $columns = [
            ['key' => 'product', 'visible' => true, 'position' => 0, 'width' => 260, 'locked' => true],
            ['key' => 'quantity', 'visible' => true, 'position' => 1, 'width' => 72, 'locked' => false],
            ['key' => 'barcode', 'visible' => false, 'position' => 2, 'width' => 110, 'locked' => false],
        ];

        $save = $this->putJson('/api/column-preferences/purchase.invoice.lines', [
            'scope' => 'user',
            'columns' => $columns,
        ]);

        $save->assertOk()
            ->assertJsonPath('data.scope', 'user')
            ->assertJsonPath('data.screen_key', 'purchase.invoice.lines')
            ->assertJsonPath('data.columns.0.key', 'product')
            ->assertJsonPath('data.columns.2.visible', false);

        $this->assertSame(1, ColumnPreference::query()->count());

        $load = $this->getJson('/api/column-preferences/purchase.invoice.lines');
        $load->assertOk()
            ->assertJsonPath('data.scope', 'user')
            ->assertJsonPath('data.columns.1.key', 'quantity');

        $this->assertNoInternalIds($load->json());
    }

    public function test_role_layout_is_fallback_when_user_has_none(): void
    {
        $session = $this->provisionOwner('colpref-b');
        $this->loginAs('colpref-b', 'owner')->assertOk();

        $role = Role::query()
            ->where('tenant_id', $session->tenant->id)
            ->where('code', 'owner')
            ->firstOrFail();

        ColumnPreference::query()->create([
            'tenant_id' => $session->tenant->id,
            'scope' => ColumnPreference::SCOPE_ROLE,
            'scope_id' => $role->id,
            'screen_key' => 'sales.invoice.lines',
            'layout' => [
                ['key' => 'product', 'visible' => true, 'position' => 0, 'width' => 240, 'locked' => true],
                ['key' => 'net_amt', 'visible' => false, 'position' => 1, 'width' => 90, 'locked' => false],
            ],
        ]);

        $load = $this->getJson('/api/column-preferences/sales.invoice.lines');
        $load->assertOk()
            ->assertJsonPath('data.scope', 'role')
            ->assertJsonPath('data.columns.1.key', 'net_amt')
            ->assertJsonPath('data.columns.1.visible', false);
    }

    public function test_invalid_screen_key_is_rejected(): void
    {
        $this->signInOwner('colpref-c')->assertOk();

        $this->getJson('/api/column-preferences/Bad Key!')
            ->assertStatus(422)
            ->assertJsonPath('error.key', 'VALIDATION_FAILED');
    }
}
