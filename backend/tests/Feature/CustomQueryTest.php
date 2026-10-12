<?php

namespace Tests\Feature;

use App\Authz\PermissionService;
use App\Models\PartyProfile;
use App\Models\Permission;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class CustomQueryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_safe_query_is_tenant_scoped_and_exposes_no_internal_ids(): void
    {
        $this->signInOwner('custom-query-scope')->assertOk();

        $tenantId = app(TenantContext::class)->tenantId();

        $local = PartyProfile::query()->create([
            'tenant_id' => $tenantId,
            'code' => 'LOCAL',
            'name' => 'Local Party',
            'is_active' => true,
        ]);

        $foreignTenant = Tenant::factory()->create();
        $foreign = PartyProfile::query()->create([
            'tenant_id' => $foreignTenant->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign Party',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/custom-query/run', [
            'query' => 'SELECT ulid, code, name FROM party_profiles ORDER BY code LIMIT 100',
        ])->assertOk();

        $response
            ->assertJsonPath('dataset', 'party_profiles')
            ->assertJsonPath('columns.0', 'ulid')
            ->assertJsonPath('row_count', 1)
            ->assertJsonPath('rows.0.ulid', $local->ulid)
            ->assertJsonPath('rows.0.code', 'LOCAL');

        $this->assertFalse(
            collect($response->json('rows'))->contains(
                fn (array $row): bool => ($row['ulid'] ?? null) === $foreign->ulid,
            )
        );

        $this->assertNoInternalIds($response->json());
    }

    public function test_write_and_multi_statement_queries_are_rejected(): void
    {
        $this->signInOwner('custom-query-write')->assertOk();

        foreach ([
            'DELETE FROM party_profiles',
            'SELECT ulid FROM party_profiles; SELECT ulid FROM party_profiles',
            'SELECT tenant_id FROM party_profiles',
        ] as $query) {
            $this->postJson('/api/custom-query/run', ['query' => $query])
                ->assertUnprocessable()
                ->assertJsonPath('error.key', 'CUSTOM_QUERY_INVALID');
        }
    }

    public function test_custom_query_requires_explicit_permission(): void
    {
        $this->signInOwner('custom-query-permission')->assertOk();

        $saved = $this->postJson('/api/custom-query/saved', [
            'title' => 'Permission check',
            'query' => 'SELECT ulid FROM party_profiles LIMIT 10',
        ])->assertCreated();

        $savedUlid = (string) $saved->json('ulid');

        $context = app(TenantContext::class);
        $permission = Permission::query()->where('key', 'database.query.read')->firstOrFail();

        foreach ($context->membership()->roles as $role) {
            $role->permissions()->detach($permission->id);
        }

        app(PermissionService::class)->clearCache();

        $this->getJson('/api/custom-query/catalog')->assertForbidden();
        $this->postJson('/api/custom-query/run', [
            'query' => 'SELECT ulid FROM party_profiles',
        ])->assertForbidden();
        $this->getJson('/api/custom-query/saved')->assertForbidden();
        $this->postJson('/api/custom-query/saved', [
            'title' => 'Denied',
            'query' => 'SELECT ulid FROM party_profiles LIMIT 10',
        ])->assertForbidden();
        $this->patchJson('/api/custom-query/saved/'.$savedUlid, [
            'title' => 'Denied update',
            'query' => 'SELECT ulid FROM party_profiles LIMIT 10',
        ])->assertForbidden();
        $this->deleteJson('/api/custom-query/saved/'.$savedUlid)->assertForbidden();
    }

    public function test_saved_queries_are_tenant_scoped(): void
    {
        $this->signInOwner('custom-query-save-a')->assertOk();

        $saved = $this->postJson('/api/custom-query/saved', [
            'title' => 'Active parties',
            'query' => "SELECT ulid, code, name FROM party_profiles WHERE is_active = TRUE ORDER BY name LIMIT 50",
        ])
            ->assertCreated()
            ->assertJsonPath('title', 'Active parties');

        $savedUlid = $saved->json('ulid');
        $this->assertIsString($savedUlid);

        $this->getJson('/api/custom-query/saved')
            ->assertOk()
            ->assertJsonFragment(['ulid' => $savedUlid]);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('custom-query-save-b')->assertOk();

        $otherTenant = $this->getJson('/api/custom-query/saved')->assertOk();

        $this->assertFalse(
            collect($otherTenant->json())->contains(
                fn (array $row): bool => ($row['ulid'] ?? null) === $savedUlid,
            )
        );
        $this->assertNoInternalIds($otherTenant->json());

        $this->patchJson('/api/custom-query/saved/'.$savedUlid, [
            'title' => 'Cross tenant update',
            'query' => 'SELECT ulid FROM party_profiles LIMIT 10',
        ])->assertNotFound();

        $this->deleteJson('/api/custom-query/saved/'.$savedUlid)
            ->assertNotFound();
    }

    public function test_saved_queries_can_be_updated_and_deleted(): void
    {
        $this->signInOwner('custom-query-manage')->assertOk();

        $saved = $this->postJson('/api/custom-query/saved', [
            'title' => 'Original title',
            'query' => 'SELECT ulid, code FROM party_profiles ORDER BY code LIMIT 25',
        ])->assertCreated();

        $savedUlid = (string) $saved->json('ulid');

        $updated = $this->patchJson('/api/custom-query/saved/'.$savedUlid, [
            'title' => 'Updated title',
            'query' => 'SELECT ulid, code, name FROM party_profiles ORDER BY name DESC LIMIT 20',
        ])->assertOk()
            ->assertJsonPath('ulid', $savedUlid)
            ->assertJsonPath('title', 'Updated title')
            ->assertJsonPath('query', 'SELECT ulid, code, name FROM party_profiles ORDER BY name DESC LIMIT 20');

        $this->assertNoInternalIds($updated->json());

        $this->patchJson('/api/custom-query/saved/'.$savedUlid, [
            'title' => 'Unsafe update',
            'query' => 'DELETE FROM party_profiles',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.key', 'CUSTOM_QUERY_INVALID');

        $this->deleteJson('/api/custom-query/saved/'.$savedUlid)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('custom_queries', ['ulid' => $savedUlid]);
    }
}
