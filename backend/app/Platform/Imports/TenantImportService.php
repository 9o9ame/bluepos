<?php

namespace App\Platform\Imports;

use App\Accounting\PartyLeafAccountSync;
use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Models\Branch;
use App\Models\Platform\PlatformUser;
use App\Models\Platform\TenantImport;
use App\Models\Platform\TenantImportRow;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Platform\PlatformAuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TenantImportService
{
    public function __construct(private readonly WorkbookParser $parser, private readonly PlatformAuditLogger $audit) {}

    public function execute(Tenant $tenant, PlatformUser $actor, UploadedFile $file, ?string $warehouseUlid): array
    {
        $parsed = $this->parser->parse($file->getRealPath());
        $warehouse = null;
        if ($warehouseUlid) {
            $warehouse = Warehouse::query()->forTenant($tenant->id)->where('ulid', $warehouseUlid)->first();
            if (! $warehouse) {
                throw new ApiException('NOT_FOUND', 'The requested warehouse was not found.', 404);
            }
            if ($warehouse->status !== WarehouseStatus::Active) {
                throw ValidationException::withMessages(['warehouse_ulid' => 'Select an active warehouse.']);
            }
            if (! Branch::query()->forTenant($tenant->id)->whereKey($warehouse->branch_id)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['warehouse_ulid' => 'Warehouse must belong to an active branch of the selected tenant.']);
            }
        }
        $batch = DB::transaction(function () use ($tenant, $actor, $file, $parsed, $warehouse) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = TenantImport::query()->where('tenant_id', $tenant->id)->where('fingerprint', $parsed['fingerprint'])->lockForUpdate()->first();
            if ($existing) {
                // The first attempt fixes the warehouse, including catalog-only (NULL).
                if ($parsed['format'] === 'products' && $existing->warehouse_id !== $warehouse?->id) {
                    throw ValidationException::withMessages(['warehouse_ulid' => 'This workbook was already imported with another stock destination (or catalog only). Its stock destination cannot be changed on replay.']);
                }

                return $existing;
            }

            return TenantImport::query()->create(['tenant_id' => $tenant->id, 'platform_user_id' => $actor->id,
                'warehouse_id' => $parsed['format'] === 'products' ? $warehouse?->id : null,
                'source_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'format' => $parsed['format'], 'fingerprint' => $parsed['fingerprint'], 'status' => 'processing']);
        });
        $replay = $batch->status === 'completed';
        if (! $replay) {
            $processed = array_fill_keys(TenantImportRow::query()->where('import_id', $batch->id)->pluck('source_row')->all(), true);
            $pending = array_values(array_filter($parsed['rows'], fn ($row) => ! isset($processed[$row['row']])));
            $chunk = array_slice($pending, 0, 100);
            $importer = $parsed['format'] === 'accounts'
                ? new AccountsImporter($tenant, app(PartyLeafAccountSync::class)) : new ProductsImporter($tenant, $warehouse, $chunk, $actor);
            $conflicts = $this->conflicts($parsed['rows'], $parsed['format']);
            foreach ($chunk as $row) {
                DB::transaction(function () use ($tenant, $batch, $row, $importer, $conflicts) {
                    // Bounded row transaction serializes identity/sequence allocation within tenant.
                    Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                    TenantImport::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                    if (TenantImportRow::query()->where('import_id', $batch->id)->where('source_row', $row['row'])->exists()) {
                        return;
                    }
                    $result = ['outcome' => 'skipped', 'warnings' => []];
                    if ($row['formula']) {
                        $result['warnings'][] = 'Formula cells are unsupported; source row was not imported.';
                    } elseif (isset($conflicts[$row['row']]) && ($row['type'] ?? '') !== 'ALL') {
                        $result['warnings'][] = 'Conflicting duplicate source identity; row requires review.';
                    } else {
                        try {
                            $result = DB::transaction(fn () => $importer->import($row));
                        } catch (ValidationException $e) {
                            $result = ['outcome' => 'failed', 'warnings' => $e->validator->errors()->all()];
                            if ($importer instanceof ProductsImporter) {
                                $importer->reload();
                            }
                        } catch (QueryException $e) {
                            // Do not expose SQL, connection details, or source PII in errors/logs.
                            $result = ['outcome' => 'failed', 'warnings' => ['Row could not be saved because of a database constraint. Review tenant configuration and source identity.']];
                            if ($importer instanceof ProductsImporter) {
                                $importer->reload();
                            }
                        }
                    }
                    $opening = $result['opening_balance_id'] ?? null;
                    unset($result['opening_balance_id']);
                    $result = ['source_row' => $row['row'], 'source_code' => $row['code'], 'source_name' => $row['name'],
                        'source_legacy_id' => $row['legacy_id'], ...$result];
                    TenantImportRow::query()->create(['import_id' => $batch->id, 'source_row' => $row['row'],
                        'source_identity' => mb_substr($row['code'] ?: $row['legacy_id'], 0, 255),
                        'outcome' => $result['outcome'], 'result' => $result, 'opening_balance_id' => $opening]);
                });
            }
            DB::transaction(function () use ($batch, $parsed) {
                $locked = TenantImport::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                $locked->status = TenantImportRow::query()->where('import_id', $batch->id)->count() === count($parsed['rows'])
                    ? 'completed' : 'processing';
                $locked->save();
            });
            $batch->refresh();
        }
        $results = TenantImportRow::query()->where('import_id', $batch->id)->orderBy('source_row')->get()->pluck('result');
        $counts = array_fill_keys(['created', 'updated', 'reused', 'skipped', 'failed'], 0);
        $warnings = [];
        $stock = ['posted' => 0, 'skipped' => 0, 'zero' => 0];
        foreach ($results as $result) {
            $counts[$result['outcome']]++;
            if (isset($result['stock'])) {
                $stock[$result['stock']]++;
            }
            foreach ($result['warnings'] as $message) {
                $warnings[] = ['source_row' => $result['source_row'], 'code' => $result['source_code'], 'name' => $result['source_name'], 'message' => $message];
            }
        }
        $this->audit->record($replay ? 'TENANT_IMPORT_REPLAYED' : ($batch->status === 'completed' ? 'TENANT_IMPORT_COMPLETED' : 'TENANT_IMPORT_PROGRESS'), [
            'resource_type' => 'tenant_import', 'resource_ulid' => $batch->ulid,
            'tenant_ulid' => $tenant->ulid, 'format' => $parsed['format'], 'counts' => $counts], null, $actor);

        return ['ulid' => $batch->ulid, 'tenant_ulid' => $tenant->ulid, 'format' => $batch->format,
            'status' => $batch->status, 'processed' => $results->count(), 'total' => count($parsed['rows']), 'replayed' => $replay, ...$counts, 'stock' => $stock,
            'warnings' => $warnings, 'rows' => $results->all(),
            'ignored_fields' => $batch->format === 'products' ? ['In Carton: unit/conversion semantics are unspecified.'] : [],
            'retry_note' => 'Processed rows are persisted. Re-upload resumes unprocessed rows; completed row outcomes, including skips/failures, are replayed.'];
    }

    private function conflicts(array $rows, string $format): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['code'] !== '' ? 'code:'.($format === 'accounts' ? mb_strtoupper($row['code']) : $row['code'])
                : ($format === 'accounts' ? $row['type'].':'.AccountsImporter::normalize($row['name']) : 'missing:'.$row['row']);
            $copy = $row;
            unset($copy['row'], $copy['legacy_id']);
            $groups[$key][] = ['row' => $row['row'], 'hash' => hash('sha256', json_encode($copy))];
        }
        $conflicts = [];
        foreach ($groups as $group) {
            if (count(array_unique(array_column($group, 'hash'))) > 1) {
                foreach ($group as $item) {
                    $conflicts[$item['row']] = true;
                }
            }
        }

        return $conflicts;
    }
}
