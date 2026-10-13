<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

class ProductPurchaseRateTest extends TestCase
{
    public function test_purchase_rate_is_stored_with_money_precision_and_can_be_updated(): void
    {
        $suffix = 'ppr'.Str::lower(substr((string) Str::ulid(), -20));
        $this->signInOwner($suffix)->assertOk();

        $unit = collect($this->getJson('/api/units')->assertOk()->json())
            ->firstWhere('code', 'PCS');

        $this->assertNotNull($unit);

        $created = $this->postJson('/api/products', [
            'name' => 'Purchase Rate Product',
            'base_unit_ulid' => $unit['ulid'],
            'purchase_rate' => '12.3456',
        ])->assertCreated()
            ->assertJsonPath('purchase_rate', '12.3456');

        $this->patchJson('/api/products/'.$created->json('ulid'), [
            'purchase_rate' => '15.5000',
        ])->assertOk()
            ->assertJsonPath('purchase_rate', '15.5000');

        $this->patchJson('/api/products/'.$created->json('ulid'), [
            'purchase_rate' => '15.50001',
        ])->assertUnprocessable();
    }
}
