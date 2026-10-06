<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountType;
use App\Models\Customer;
use App\Models\Supplier;
use App\Support\SimpleXlsx;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PartyBulkTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return array{ap: string, ar: string}
     */
    private function seedTypes(): array
    {
        return [
            'ar' => $this->referenceAccountTypeUlid('0011'),
            'ap' => $this->referenceAccountTypeUlid('0020'),
        ];
    }

    public function test_bulk_update_excel_import_and_tenant_isolation(): void
    {
        $this->signInOwner('bulk-a')->assertOk();
        $coa = $this->seedTypes();

        $vendor = $this->postJson('/api/parties', [
            'party_type' => 'vendor',
            'code' => 'V-BULK',
            'name' => 'Bulk Vendor',
            'account_type_ulid' => $coa['ap'],
            'license_expires_on' => '2020-01-01',
        ])->assertCreated();
        $vendorUlid = $vendor->json('ulid');

        $customer = $this->postJson('/api/parties', [
            'party_type' => 'customer',
            'code' => 'C-BULK',
            'name' => 'Bulk Customer',
            'account_type_ulid' => $coa['ar'],
        ])->assertCreated()->json('ulid');

        $account = $this->postJson('/api/parties', [
            'party_type' => 'account',
            'code' => 'A-BULK',
            'name' => 'Bulk Account',
            'account_type_ulid' => $coa['ar'],
        ])->assertCreated()->json('ulid');

        $bulk = $this->patchJson('/api/parties/bulk', [
            'rows' => [
                [
                    'ulid' => $vendorUlid,
                    'party_type' => 'vendor',
                    'is_active' => false,
                    'invoice_restricted' => true,
                    'area' => 'Clifton',
                    'credit_limit_amount' => '1500.5000',
                    'account_type_ulid' => $coa['ap'],
                ],
                [
                    'ulid' => $customer,
                    'party_type' => 'customer',
                    'area' => 'Gulshan',
                    'credit_limit_days' => 30,
                ],
                [
                    'ulid' => $account,
                    'party_type' => 'account',
                    'invoice_restricted' => true,
                ],
            ],
        ])->assertOk();
        $bulk->assertJsonPath('updated', 3);
        $this->assertNoInternalIds($bulk->json());

        $this->assertSame('Clifton', Supplier::query()->where('ulid', $vendorUlid)->value('area'));
        $this->assertFalse((bool) Supplier::query()->where('ulid', $vendorUlid)->value('is_active'));
        $this->assertSame(1, Account::query()->where('supplier_id', '!=', null)->count());

        $expired = $this->getJson('/api/parties?type=all&expired_license=1')->assertOk()->json('data');
        $this->assertCount(1, $expired);
        $this->assertSame($vendorUlid, $expired[0]['ulid']);

        $this->patchJson('/api/parties/bulk', [
            'rows' => [[
                'ulid' => $vendorUlid,
                'party_type' => 'vendor',
                'account_type_ulid' => '01INVALIDACCOUNTTYPEULIDXX',
            ]],
        ])->assertStatus(422);

        $template = $this->get('/api/parties/excel-template')->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $template->headers->get('content-type'),
        );

        $xlsx = app(SimpleXlsx::class);
        $headers = \App\Parties\PartyBulkService::TEMPLATE_HEADERS;
        $tmpPath = tempnam(sys_get_temp_dir(), 'partyxlsx').'.xlsx';
        file_put_contents($tmpPath, $xlsx->write($headers, [[
            'VENDOR',
            'V-NEW',
            'Imported Vendor',
            'Parts',
            'Addr',
            'Bill',
            'Ali',
            '0300',
            '',
            '',
            '',
            '',
            'Saddar',
            '',
            '',
            '',
            '',
            '',
            '0',
            '0',
            '0',
            '0',
            '0020',
            'ACCOUNT PAYABLE',
            '15',
            '200.0000',
        ], [
            'VENDOR',
            'V-BULK',
            'Bulk Vendor Updated',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            'Updated Area',
            '',
            '',
            '',
            '',
            '',
            '0',
            '0',
            '0',
            '0',
            '0020',
            'ACCOUNT PAYABLE',
            '0',
            '0.0000',
        ]]));

        $preview = $this->post('/api/parties/excel/preview', [
            'file' => new UploadedFile($tmpPath, 'parties.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(2, $preview->json('valid'));
        $this->assertCount(0, $preview->json('invalid'));

        $import = $this->post('/api/parties/excel/import', [
            'file' => new UploadedFile($tmpPath, 'parties.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'confirm' => '1',
        ], ['Accept' => 'application/json'])->assertOk();
        $import->assertJsonPath('created', 1);
        $import->assertJsonPath('updated', 1);
        $this->assertTrue(Supplier::query()->where('code', 'V-NEW')->exists());
        $this->assertSame('Updated Area', Supplier::query()->where('code', 'V-BULK')->value('area'));
        $this->assertSame(2, Account::query()->whereNotNull('supplier_id')->count());

        @unlink($tmpPath);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('bulk-b')->assertOk();
        $this->patchJson('/api/parties/bulk', [
            'rows' => [[
                'ulid' => $vendorUlid,
                'party_type' => 'vendor',
                'area' => 'Leak',
            ]],
        ])->assertStatus(404);
    }

    public function test_excel_resolves_account_type_by_code_rejects_unknown_and_cross_tenant(): void
    {
        $this->signInOwner('bulk-code-a')->assertOk();
        $coa = $this->seedTypes();
        $typeCountBefore = AccountType::query()->count();

        $xlsx = app(SimpleXlsx::class);
        $headers = \App\Parties\PartyBulkService::TEMPLATE_HEADERS;

        $unknownPath = tempnam(sys_get_temp_dir(), 'partyxlsx').'.xlsx';
        file_put_contents($unknownPath, $xlsx->write($headers, [[
            'VENDOR', 'V-UNK', 'Unknown Type Vendor', '', '', '', '', '', '', '', '', '', '',
            '', '', '', '', '', '0', '0', '0', '0', '9999', 'DOES NOT EXIST', '0', '0.0000',
        ]]));
        $previewUnknown = $this->post('/api/parties/excel/preview', [
            'file' => new UploadedFile($unknownPath, 'parties.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(0, $previewUnknown->json('valid'));
        $this->assertCount(1, $previewUnknown->json('invalid'));
        $this->assertStringContainsString(
            'Account Type Code not found',
            implode(' ', $previewUnknown->json('invalid.0.errors')),
        );
        $this->assertSame($typeCountBefore, AccountType::query()->count());
        @unlink($unknownPath);

        $okPath = tempnam(sys_get_temp_dir(), 'partyxlsx').'.xlsx';
        file_put_contents($okPath, $xlsx->write($headers, [[
            'CUSTOMER', 'C-CODE', 'Code Customer', '', '', '', '', '', '', '', '', '', '',
            '', '', '', '', '', '0', '0', '0', '0', '0011', 'ACCOUNT RECEIVABLE', '0', '0.0000',
        ]]));
        $import = $this->post('/api/parties/excel/import', [
            'file' => new UploadedFile($okPath, 'parties.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            'confirm' => '1',
        ], ['Accept' => 'application/json'])->assertOk();
        $import->assertJsonPath('created', 1);
        $import->assertJsonPath('updated', 0);
        $this->assertSame($typeCountBefore, AccountType::query()->count());

        $customer = Customer::query()->where('code', 'C-CODE')->first();
        $this->assertNotNull($customer);
        $this->assertSame(
            AccountType::query()->where('ulid', $coa['ar'])->value('id'),
            $customer->account_type_id,
        );
        $this->assertTrue(Account::query()->where('customer_id', $customer->id)->exists());
        @unlink($okPath);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->signInOwner('bulk-code-b')->assertOk();
        // Tenant B has no 0011 — foreign tenant code must not resolve.
        $crossPath = tempnam(sys_get_temp_dir(), 'partyxlsx').'.xlsx';
        file_put_contents($crossPath, $xlsx->write($headers, [[
            'VENDOR', 'V-X', 'Cross Tenant', '', '', '', '', '', '', '', '', '', '',
            '', '', '', '', '', '0', '0', '0', '0', '0011', 'ACCOUNT RECEIVABLE', '0', '0.0000',
        ]]));
        $previewB = $this->post('/api/parties/excel/preview', [
            'file' => new UploadedFile($crossPath, 'parties.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(0, $previewB->json('valid'));
        $this->assertCount(1, $previewB->json('invalid'));
        $this->assertStringContainsString(
            'Account Type Code not found',
            implode(' ', $previewB->json('invalid.0.errors')),
        );
        $this->assertFalse(Supplier::query()->where('code', 'V-X')->exists());
        @unlink($crossPath);
    }
}
