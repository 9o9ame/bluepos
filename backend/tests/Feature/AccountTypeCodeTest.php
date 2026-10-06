<?php

namespace Tests\Feature;

use App\Models\AccountType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountTypeCodeTest extends TestCase
{
    use DatabaseTransactions;

    private function seedSubHead(): string
    {
        $main = $this->postJson('/api/coa/main-heads', ['name' => 'TEST ASSETS'])->assertCreated()->json('ulid');

        return $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $main,
            'name' => 'TEST CURRENT',
        ])->assertCreated()->json('ulid');
    }

    public function test_account_type_code_unique_per_tenant_and_reusable_across_tenants(): void
    {
        $this->signInOwner('atc-a')->assertOk();
        $subA = $this->seedSubHead();

        $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $subA,
            'code' => '9010-cash',
            'name' => 'CASH',
            'is_cash' => true,
        ])->assertCreated()->assertJsonPath('code', '9010-CASH');

        $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $subA,
            'code' => '9010-CASH',
            'name' => 'CASH DUP',
        ])->assertStatus(422);

        $countA = AccountType::query()->where('code', '9010-CASH')->count();
        $this->assertSame(1, $countA);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('atc-b')->assertOk();
        $subB = $this->seedSubHead();

        $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $subB,
            'code' => '9010-CASH',
            'name' => 'CASH',
            'is_cash' => true,
        ])->assertCreated()->assertJsonPath('code', '9010-CASH');

        $this->assertSame(2, AccountType::query()->where('code', '9010-CASH')->count());
    }

    public function test_create_requires_code_legacy_null_allowed_on_update_omit(): void
    {
        $this->signInOwner('atc-req')->assertOk();
        $sub = $this->seedSubHead();

        $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $sub,
            'name' => 'NO CODE',
        ])->assertStatus(422);

        $created = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $sub,
            'code' => '  9020  ',
            'name' => 'AP',
            'is_payable' => true,
        ])->assertCreated();
        $created->assertJsonPath('code', '9020');
        $ulid = $created->json('ulid');

        $this->patchJson('/api/coa/account-types/'.$ulid, [
            'name' => 'ACCOUNT PAYABLE',
        ])->assertOk()->assertJsonPath('code', '9020');
    }
}
