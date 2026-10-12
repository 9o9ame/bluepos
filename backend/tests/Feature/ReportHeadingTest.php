<?php

namespace Tests\Feature;

use App\Models\ReportHeading;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReportHeadingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_owner_gets_reference_defaults_and_can_create_update_copy_and_delete_headings(): void
    {
        $this->signInOwner('report-heading-admin')->assertOk();

        $index = $this->getJson('/api/report-headings')
            ->assertOk();

        $this->assertCount(6, $index->json());
        $this->assertSame(
            ['Head', 'HeadGrid', 'HeadLand', 'POS', 'ProdTemplate', 'ZapSaleFooter'],
            collect($index->json())->pluck('head_id')->all(),
        );
        $this->assertNoInternalIds($index->json());

        $created = $this->postJson('/api/report-headings', [
            'head_id' => 'CustomHead',
            'used_for' => 'Custom Report',
            'content_html' => '<p onclick="bad()"><strong>Safe</strong><script>alert(1)</script></p>',
        ])->assertCreated()
            ->assertJsonPath('head_id', 'CustomHead')
            ->assertJsonPath('used_for', 'Custom Report');

        $createdUlid = (string) $created->json('ulid');
        $this->assertSame('<p><strong>Safe</strong>alert(1)</p>', $created->json('content_html'));
        $this->assertNoInternalIds($created->json());

        $updated = $this->patchJson('/api/report-headings/'.$createdUlid, [
            'used_for' => 'Updated Report',
            'content_html' => '<p><u>Updated</u></p>',
        ])->assertOk()
            ->assertJsonPath('used_for', 'Updated Report')
            ->assertJsonPath('content_html', '<p><u>Updated</u></p>');

        $head = collect($this->getJson('/api/report-headings')->assertOk()->json())
            ->firstWhere('head_id', 'Head');

        $this->patchJson('/api/report-headings/'.$head['ulid'], [
            'content_html' => '<p><strong>Copied Heading</strong></p>',
        ])->assertOk();

        $copy = $this->postJson('/api/report-headings/'.$head['ulid'].'/copy-to-others')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(6, $copy->json('updated'));
        $this->assertSame(
            '<p><strong>Copied Heading</strong></p>',
            ReportHeading::query()->where('ulid', $createdUlid)->value('content_html'),
        );

        $this->deleteJson('/api/report-headings/'.$createdUlid)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('report_headings', ['ulid' => $createdUlid]);
    }


    public function test_existing_heading_does_not_block_missing_reference_defaults(): void
    {
        $this->signInOwner('report-heading-partial')->assertOk();

        $tenantId = app(\App\Tenancy\TenantContext::class)->tenantId();

        ReportHeading::query()->create([
            'tenant_id' => $tenantId,
            'head_id' => 'Head',
            'used_for' => 'Customized Default Heading',
            'content_html' => '<p><strong>Keep Me</strong></p>',
        ]);

        $index = $this->getJson('/api/report-headings')
            ->assertOk();

        $this->assertCount(6, $index->json());
        $this->assertSame(
            ['Head', 'HeadGrid', 'HeadLand', 'POS', 'ProdTemplate', 'ZapSaleFooter'],
            collect($index->json())->pluck('head_id')->all(),
        );

        $head = collect($index->json())->firstWhere('head_id', 'Head');
        $this->assertSame('Customized Default Heading', $head['used_for']);
        $this->assertSame('<p><strong>Keep Me</strong></p>', $head['content_html']);
    }

    public function test_report_headings_are_tenant_scoped_and_require_settings_permissions(): void
    {
        $this->signInOwner('report-heading-a')->assertOk();
        $foreign = $this->postJson('/api/report-headings', [
            'head_id' => 'PrivateHead',
            'used_for' => 'Tenant A only',
            'content_html' => '<p>Private</p>',
        ])->assertCreated();
        $foreignUlid = (string) $foreign->json('ulid');

        $this->postJson('/api/auth/logout')->assertOk();
        $ownerB = $this->signInOwner('report-heading-b')->assertOk();

        $this->patchJson('/api/report-headings/'.$foreignUlid, [
            'used_for' => 'Leak',
        ])->assertNotFound();

        $this->deleteJson('/api/report-headings/'.$foreignUlid)
            ->assertNotFound();

        $roles = $this->getJson('/api/roles')->assertOk()->json();
        $cashier = collect($roles)->firstWhere('code', 'cashier');

        $this->postJson('/api/memberships', [
            'name' => 'Heading Cashier',
            'username' => 'heading-cashier',
            'recovery_email' => 'heading-cashier@example.com',
            'password' => 'password123',
            'must_change_password' => false,
            'roles' => [$cashier['ulid']],
            'branches' => [$ownerB->json('branch.ulid')],
        ])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->loginAs('report-heading-b', 'heading-cashier')->assertOk();

        $this->getJson('/api/report-headings')
            ->assertForbidden()
            ->assertJsonPath('error.key', 'FORBIDDEN');

        $this->postJson('/api/report-headings', [
            'head_id' => 'Denied',
            'used_for' => 'Denied',
        ])->assertForbidden();
    }

    public function test_report_heading_preserves_only_allowlisted_editor_styles(): void
    {
        $this->signInOwner('report-heading-styles')->assertOk();

        $created = $this->postJson('/api/report-headings', [
            'head_id' => 'StyledHead',
            'used_for' => 'Styled Report',
            'content_html' => '<p style="text-align: center; position: fixed"><span style="font-family: Arial; font-size: 16px; color: #FF0000; background-color: #FFFF00; background-image: url(javascript:alert(1))">Styled</span></p>',
        ])->assertCreated();

        $this->assertSame(
            '<p style="text-align: center"><span style="font-family: Arial; font-size: 16px; color: #ff0000; background-color: #ffff00">Styled</span></p>',
            $created->json('content_html'),
        );

        $updated = $this->patchJson('/api/report-headings/'.$created->json('ulid'), [
            'content_html' => '<h2 style="text-align: right"><span style="font-family: BadFont; font-size: 99px; color: expression(alert(1))">Unsafe styles removed</span></h2>',
        ])->assertOk();

        $this->assertSame(
            '<h2 style="text-align: right"><span>Unsafe styles removed</span></h2>',
            $updated->json('content_html'),
        );
    }

    public function test_report_heading_validation_prevents_duplicate_ids_and_unsafe_identifiers(): void
    {
        $this->signInOwner('report-heading-validation')->assertOk();
        $this->getJson('/api/report-headings')->assertOk();

        $this->postJson('/api/report-headings', [
            'head_id' => 'Head',
            'used_for' => 'Duplicate',
        ])->assertUnprocessable()
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['head_id']]]);

        $this->postJson('/api/report-headings', [
            'head_id' => 'Bad Head!',
            'used_for' => 'Invalid',
        ])->assertUnprocessable()
            ->assertJsonPath('error.key', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['fields' => ['head_id']]]);
    }
}
