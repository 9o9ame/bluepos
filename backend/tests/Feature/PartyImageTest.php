<?php

namespace Tests\Feature;

use App\Models\Supplier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartyImageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_party_image_upload_get_delete_and_tenant_isolation(): void
    {
        Storage::fake('public');

        $this->signInOwner('img-a')->assertOk();
        $main = $this->postJson('/api/coa/main-heads', ['name' => 'LIABILITIES'])->assertCreated()->json('ulid');
        $sub = $this->postJson('/api/coa/sub-heads', [
            'main_head_ulid' => $main,
            'name' => 'SHORT TERM LIABILITIES',
        ])->assertCreated()->json('ulid');
        $ap = $this->postJson('/api/coa/account-types', [
            'sub_head_ulid' => $sub,
            'code' => '0020',
            'name' => 'ACCOUNT PAYABLE',
            'is_payable' => true,
        ])->assertCreated()->json('ulid');

        $party = $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'V-IMG',
            'name' => 'Image Vendor',
            'account_type_ulid' => $ap,
        ])->assertCreated();
        $ulid = $party->json('ulid');
        $this->assertNull($party->json('image_url'));

        $upload = $this->post(
            '/api/parties/'.$ulid.'/image?type=vendor',
            ['image' => UploadedFile::fake()->image('vendor.jpg', 120, 120)],
            ['Accept' => 'application/json'],
        )->assertOk();
        $imageUrl = $upload->json('image_url');
        $this->assertNotNull($imageUrl);
        $this->assertStringContainsString('/api/parties/'.$ulid.'/image', $imageUrl);

        $path = Supplier::query()->where('ulid', $ulid)->value('image_path');
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $this->get($imageUrl)->assertOk();

        $this->deleteJson('/api/parties/'.$ulid.'/image?type=vendor')
            ->assertOk()
            ->assertJsonPath('image_url', null);
        $this->assertNull(Supplier::query()->where('ulid', $ulid)->value('image_path'));

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('img-b')->assertOk();
        $this->post(
            '/api/parties/'.$ulid.'/image?type=vendor',
            ['image' => UploadedFile::fake()->image('leak.jpg', 80, 80)],
            ['Accept' => 'application/json'],
        )->assertStatus(404);
    }
}
