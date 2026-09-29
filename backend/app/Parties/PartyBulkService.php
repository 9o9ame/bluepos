<?php

namespace App\Parties;

use App\Accounting\PartyLeafAccountSync;
use App\Exceptions\ApiException;
use App\Http\Resources\PartyResource;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Customer;
use App\Models\Supplier;
use App\Security\AuditLogger;
use App\Support\SimpleXlsx;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PartyBulkService
{
    /** @var list<string> */
    public const TEMPLATE_HEADERS = [
        'Type',
        'Code',
        'Name',
        'Deals In',
        'Address',
        'Billing Address',
        'Contact Person',
        'Mobile 1',
        'Mobile 2',
        'Phone 1',
        'Phone 2',
        'Email',
        'Area',
        'License Number',
        'License Type',
        'License Issue Date',
        'License Expiry Date',
        'RF ID',
        'Ignore Warranty',
        'Print License',
        'Discontinued',
        'Restricted',
        'Account Type Name',
        'Credit Limit Days',
        'Credit Limit Amount',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PartyLeafAccountSync $leafSync,
        private readonly AuditLogger $audit,
        private readonly SimpleXlsx $xlsx,
    ) {}

    public function templateBinary(): string
    {
        return $this->xlsx->write(self::TEMPLATE_HEADERS);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{updated: int, data: list<array<string, mixed>>}
     */
    public function bulkUpdate(array $rows): array
    {
        $tenantId = $this->tenantContext->tenantId();
        $userId = $this->tenantContext->userId();

        $updated = DB::transaction(function () use ($rows, $tenantId, $userId): array {
            $out = [];
            foreach ($rows as $index => $row) {
                $validator = Validator::make($row, [
                    'ulid' => ['required', 'string', 'size:26'],
                    'party_type' => ['required', 'string', Rule::in(['vendor', 'customer', 'account'])],
                    'is_active' => ['sometimes', 'boolean'],
                    'invoice_restricted' => ['sometimes', 'boolean'],
                    'area' => ['nullable', 'string', 'max:120'],
                    'account_type_ulid' => [
                        'sometimes',
                        'nullable',
                        'string',
                        'size:26',
                        Rule::exists('account_types', 'ulid')
                            ->where('tenant_id', $tenantId)
                            ->where('is_active', true),
                    ],
                    'credit_limit_amount' => ['sometimes', 'regex:/^\d+(\.\d{1,4})?$/'],
                    'credit_limit_days' => ['sometimes', 'integer', 'min:0', 'max:99999'],
                ]);
                if ($validator->fails()) {
                    throw new ApiException('VALIDATION_FAILED', 'Bulk update row '.($index + 1).' is invalid.', 422, [
                        'row' => $index + 1,
                        'errors' => $validator->errors()->toArray(),
                    ]);
                }
                $data = $validator->validated();
                $partyType = $data['party_type'];
                $party = $this->findParty($partyType, $data['ulid'], $tenantId);
                if (! $party) {
                    throw new ApiException('NOT_FOUND', 'Party not found for bulk row '.($index + 1).'.', 404, [
                        'row' => $index + 1,
                    ]);
                }

                $attrs = [];
                if (array_key_exists('is_active', $data)) {
                    $attrs['is_active'] = (bool) $data['is_active'];
                }
                if (array_key_exists('invoice_restricted', $data)) {
                    $attrs['invoice_restricted'] = (bool) $data['invoice_restricted'];
                }
                if (array_key_exists('area', $data)) {
                    $attrs['area'] = $data['area'];
                }
                if (array_key_exists('credit_limit_amount', $data)) {
                    $attrs['credit_limit_amount'] = bcadd((string) $data['credit_limit_amount'], '0', 4);
                }
                if (array_key_exists('credit_limit_days', $data)) {
                    $attrs['credit_limit_days'] = (int) $data['credit_limit_days'];
                }
                if (array_key_exists('account_type_ulid', $data) && $data['account_type_ulid']) {
                    $attrs['account_type_id'] = AccountType::query()
                        ->forTenant($tenantId)
                        ->where('ulid', $data['account_type_ulid'])
                        ->where('is_active', true)
                        ->value('id');
                    if (! $attrs['account_type_id']) {
                        throw new ApiException('VALIDATION_FAILED', 'Invalid Account Type on bulk row '.($index + 1).'.', 422, [
                            'row' => $index + 1,
                        ]);
                    }
                }

                $party->fill($attrs);
                $party->updated_by = $userId;
                $party->save();

                if ($partyType === 'vendor') {
                    $this->leafSync->syncSupplier($party);
                } elseif ($partyType === 'customer') {
                    $this->leafSync->syncCustomer($party);
                }

                $out[] = (new PartyResource($party->fresh()->load('accountType'), $partyType))->resolve();
            }

            return $out;
        });

        $this->audit->record('PARTY_BULK_UPDATED', [
            'resource_type' => 'party_bulk',
            'count' => count($updated),
        ]);

        return ['updated' => count($updated), 'data' => $updated];
    }

    /**
     * @return array{valid: list<array<string, mixed>>, invalid: list<array<string, mixed>>, warnings: list<string>}
     */
    public function previewExcel(UploadedFile $file): array
    {
        $this->assertSpreadsheet($file);
        $parsed = $this->xlsx->read($file->getRealPath() ?: '');
        $this->assertTemplateHeaders($parsed['headers']);

        $warnings = [];
        if ($parsed['formula_rows'] !== []) {
            $warnings[] = 'Formula cells detected on spreadsheet rows: '.implode(', ', $parsed['formula_rows']).'. Those rows are rejected.';
        }

        $valid = [];
        $invalid = [];
        foreach ($parsed['rows'] as $i => $row) {
            $excelRow = $i + 2;
            if (in_array($excelRow, $parsed['formula_rows'], true)) {
                $invalid[] = [
                    'row' => $excelRow,
                    'action' => 'reject',
                    'errors' => ['Formulas are not allowed.'],
                    'data' => $row,
                ];

                continue;
            }

            $normalized = $this->normalizeExcelRow($row);
            $result = $this->validateImportRow($normalized, $excelRow);
            if ($result['ok']) {
                $valid[] = $result;
            } else {
                $invalid[] = $result;
            }
        }

        return compact('valid', 'invalid', 'warnings');
    }

    /**
     * @return array{created: int, updated: int, data: list<array<string, mixed>>}
     */
    public function importExcel(UploadedFile $file): array
    {
        $preview = $this->previewExcel($file);
        if ($preview['invalid'] !== []) {
            throw new ApiException('VALIDATION_FAILED', 'Import contains invalid rows.', 422, [
                'invalid' => $preview['invalid'],
                'warnings' => $preview['warnings'],
            ]);
        }

        $tenantId = $this->tenantContext->tenantId();
        $userId = $this->tenantContext->userId();

        $result = DB::transaction(function () use ($preview, $tenantId, $userId): array {
            $created = 0;
            $updated = 0;
            $data = [];

            foreach ($preview['valid'] as $item) {
                $payload = $item['payload'];
                $partyType = $payload['party_type'];
                $existing = $this->findByTypeCode($partyType, $payload['code'], $tenantId);

                if ($existing) {
                    $existing->fill($payload['attrs']);
                    $existing->updated_by = $userId;
                    $existing->save();
                    $party = $existing;
                    $updated++;
                    $action = 'update';
                } else {
                    $party = match ($partyType) {
                        'vendor' => Supplier::query()->create([
                            'tenant_id' => $tenantId,
                            ...$payload['attrs'],
                            'created_by' => $userId,
                        ]),
                        'customer' => Customer::query()->create([
                            'tenant_id' => $tenantId,
                            ...$payload['attrs'],
                            'created_by' => $userId,
                        ]),
                        default => Account::query()->create([
                            'tenant_id' => $tenantId,
                            ...$payload['attrs'],
                            'created_by' => $userId,
                        ]),
                    };
                    $created++;
                    $action = 'create';
                }

                if ($partyType === 'vendor') {
                    $this->leafSync->syncSupplier($party);
                } elseif ($partyType === 'customer') {
                    $this->leafSync->syncCustomer($party);
                }

                $data[] = [
                    'action' => $action,
                    'party' => (new PartyResource($party->fresh()->load('accountType'), $partyType))->resolve(),
                ];
            }

            return compact('created', 'updated', 'data');
        });

        $this->audit->record('PARTY_EXCEL_IMPORTED', [
            'resource_type' => 'party_bulk',
            'created' => $result['created'],
            'updated' => $result['updated'],
        ]);

        return $result;
    }

    private function assertSpreadsheet(UploadedFile $file): void
    {
        if ($file->getSize() !== null && $file->getSize() > 2_000_000) {
            throw new ApiException('VALIDATION_FAILED', 'Spreadsheet exceeds 2MB limit.', 422);
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'xlsx') {
            throw new ApiException('VALIDATION_FAILED', 'Only .xlsx files are supported.', 422);
        }
        $mime = (string) $file->getMimeType();
        $allowed = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ];
        if (! in_array($mime, $allowed, true)) {
            throw new ApiException('VALIDATION_FAILED', 'Invalid spreadsheet MIME type.', 422);
        }
    }

    /**
     * @param  list<string>  $headers
     */
    private function assertTemplateHeaders(array $headers): void
    {
        if ($headers !== self::TEMPLATE_HEADERS) {
            throw new ApiException('VALIDATION_FAILED', 'Spreadsheet headers do not match the BluePOS parties template.', 422);
        }
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function normalizeExcelRow(array $row): array
    {
        $bool = static function (string $value): bool {
            $v = strtoupper(trim($value));

            return in_array($v, ['1', 'Y', 'YES', 'TRUE', 'T'], true);
        };

        return [
            'party_type' => match (strtoupper(trim($row['Type'] ?? ''))) {
                'VENDOR', 'VENDORS', 'SUPPLIER' => 'vendor',
                'CUSTOMER', 'CUSTOMERS' => 'customer',
                'ACCOUNT', 'ACCOUNTS' => 'account',
                default => strtolower(trim($row['Type'] ?? '')),
            },
            'code' => strtoupper(trim($row['Code'] ?? '')),
            'name' => trim($row['Name'] ?? ''),
            'deals_in' => trim($row['Deals In'] ?? '') ?: null,
            'address' => trim($row['Address'] ?? '') ?: null,
            'billing_address' => trim($row['Billing Address'] ?? '') ?: null,
            'contact_person' => trim($row['Contact Person'] ?? '') ?: null,
            'mobile' => trim($row['Mobile 1'] ?? '') ?: null,
            'mobile_secondary' => trim($row['Mobile 2'] ?? '') ?: null,
            'phone' => trim($row['Phone 1'] ?? '') ?: null,
            'phone_secondary' => trim($row['Phone 2'] ?? '') ?: null,
            'email' => strtolower(trim($row['Email'] ?? '')) ?: null,
            'area' => trim($row['Area'] ?? '') ?: null,
            'license_number' => trim($row['License Number'] ?? '') ?: null,
            'license_type' => strtoupper(trim($row['License Type'] ?? '')) ?: null,
            'license_issued_on' => trim($row['License Issue Date'] ?? '') ?: null,
            'license_expires_on' => trim($row['License Expiry Date'] ?? '') ?: null,
            'rf_id' => trim($row['RF ID'] ?? '') ?: null,
            'ignore_warranty' => $bool((string) ($row['Ignore Warranty'] ?? '')),
            'print_license' => $bool((string) ($row['Print License'] ?? '')),
            'is_active' => ! $bool((string) ($row['Discontinued'] ?? '')),
            'invoice_restricted' => $bool((string) ($row['Restricted'] ?? '')),
            'account_type_name' => trim($row['Account Type Name'] ?? ''),
            'credit_limit_days' => trim($row['Credit Limit Days'] ?? '') === '' ? 0 : (int) $row['Credit Limit Days'],
            'credit_limit_amount' => trim($row['Credit Limit Amount'] ?? '') === '' ? '0.0000' : (string) $row['Credit Limit Amount'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function validateImportRow(array $row, int $excelRow): array
    {
        $tenantId = $this->tenantContext->tenantId();
        $type = (string) $row['party_type'];
        if (! in_array($type, ['vendor', 'customer', 'account'], true)) {
            return [
                'ok' => false,
                'row' => $excelRow,
                'action' => 'reject',
                'errors' => ['Type must be vendor, customer, or account.'],
                'data' => $row,
            ];
        }

        $rules = [
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:180'],
            'account_type_name' => ['required', 'string', 'max:180'],
            'address' => ['nullable', 'string'],
            'area' => ['nullable', 'string', 'max:120'],
            'credit_limit_amount' => ['regex:/^\d+(\.\d{1,4})?$/'],
            'credit_limit_days' => ['integer', 'min:0', 'max:99999'],
        ];
        if ($type !== 'account') {
            $rules['email'] = ['nullable', 'email', 'max:180'];
            $rules['license_issued_on'] = ['nullable', 'date'];
            $rules['license_expires_on'] = ['nullable', 'date'];
        }

        $validator = Validator::make($row, $rules);
        if ($validator->fails()) {
            return [
                'ok' => false,
                'row' => $excelRow,
                'action' => 'reject',
                'errors' => $validator->errors()->all(),
                'data' => $row,
            ];
        }

        $accountType = AccountType::query()
            ->forTenant($tenantId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $row['account_type_name'])])
            ->where('is_active', true)
            ->first();
        if (! $accountType) {
            return [
                'ok' => false,
                'row' => $excelRow,
                'action' => 'reject',
                'errors' => ['Account Type Name not found for this tenant.'],
                'data' => $row,
            ];
        }

        $existing = $this->findByTypeCode($type, (string) $row['code'], $tenantId);
        $attrs = [
            'code' => $row['code'],
            'name' => $row['name'],
            'address' => $row['address'],
            'area' => $row['area'],
            'invoice_restricted' => (bool) $row['invoice_restricted'],
            'credit_limit_amount' => bcadd((string) $row['credit_limit_amount'], '0', 4),
            'credit_limit_days' => (int) $row['credit_limit_days'],
            'account_type_id' => $accountType->id,
            'is_active' => (bool) $row['is_active'],
        ];
        if ($type !== 'account') {
            $attrs = [
                ...$attrs,
                'deals_in' => $row['deals_in'],
                'billing_address' => $row['billing_address'],
                'contact_person' => $row['contact_person'],
                'mobile' => $row['mobile'],
                'mobile_secondary' => $row['mobile_secondary'],
                'phone' => $row['phone'],
                'phone_secondary' => $row['phone_secondary'],
                'email' => $row['email'],
                'license_number' => $row['license_number'],
                'license_type' => $row['license_type'],
                'license_issued_on' => $row['license_issued_on'],
                'license_expires_on' => $row['license_expires_on'],
                'rf_id' => $row['rf_id'],
                'ignore_warranty' => (bool) $row['ignore_warranty'],
                'print_license' => (bool) $row['print_license'],
            ];
        }

        return [
            'ok' => true,
            'row' => $excelRow,
            'action' => $existing ? 'update' : 'create',
            'errors' => [],
            'payload' => [
                'party_type' => $type,
                'code' => $row['code'],
                'attrs' => $attrs,
            ],
            'data' => $row,
        ];
    }

    private function findParty(string $partyType, string $ulid, int $tenantId): Supplier|Customer|Account|null
    {
        return match ($partyType) {
            'vendor' => Supplier::query()->forTenant($tenantId)->where('ulid', $ulid)->first(),
            'customer' => Customer::query()->forTenant($tenantId)->where('ulid', $ulid)->first(),
            default => Account::query()
                ->forTenant($tenantId)
                ->whereNull('supplier_id')
                ->whereNull('customer_id')
                ->where('ulid', $ulid)
                ->first(),
        };
    }

    private function findByTypeCode(string $partyType, string $code, int $tenantId): Supplier|Customer|Account|null
    {
        return match ($partyType) {
            'vendor' => Supplier::query()->forTenant($tenantId)->where('code', $code)->first(),
            'customer' => Customer::query()->forTenant($tenantId)->where('code', $code)->first(),
            default => Account::query()
                ->forTenant($tenantId)
                ->whereNull('supplier_id')
                ->whereNull('customer_id')
                ->where('code', $code)
                ->first(),
        };
    }
}
