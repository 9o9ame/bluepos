<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TenantContextOverrideTest extends TestCase
{
    use DatabaseTransactions;

    public function test_client_supplied_tenant_ids_do_not_change_context(): void
    {
        $first = $this->signInOwner('romeo')->assertOk();
        $this->postJson('/api/auth/logout')->assertOk();
        $second = $this->signInOwner('sierra')->assertOk();

        $me = $this->getJson('/api/auth/me?tenant_id=1&branch_id=1&warehouse_id=1')->assertOk();
        $this->assertSame($second->json('tenant.ulid'), $me->json('tenant.ulid'));
        $this->assertNotSame($first->json('tenant.ulid'), $me->json('tenant.ulid'));

        $posted = $this->postJson('/api/branches/'.$second->json('branch.ulid').'/switch', [
            'tenant_id' => 1,
            'branch_id' => 1,
            'warehouse_id' => 1,
        ])->assertOk();

        $this->assertSame($second->json('tenant.ulid'), $posted->json('tenant.ulid'));
        $this->assertNoInternalIds($posted->json());
    }
}
