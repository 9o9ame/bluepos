<?php

namespace App\Platform\Imports;

use App\Enums\WarehouseStatus;
use App\Exceptions\ApiException;
use App\Jobs\ProcessTenantImport;
use App\Models\Branch;
use App\Models\Platform\PlatformUser;
use App\Models\Platform\TenantImport;
use App\Models\Platform\TenantImportRow;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Durable one-upload import coordinator. No source data or user IDs in queue payload.
 * A legacy in-progress fingerprint is resumed using its existing row checkpoints.
 */
final class AsyncTenantImportService
{
    public function __construct(private readonly WorkbookParser $parser) {}

    public function start(Tenant $tenant, PlatformUser $actor, UploadedFile $file, ?string $warehouseUlid): array
    {
        if (config('queue.default') === 'sync') {
            throw new ApiException('IMPORT_QUEUE_UNAVAILABLE',
                'Background import requires a running database or Redis queue worker.', 503);
        }

        $parsed = $this->parser->parse($file->getRealPath(), strtolower($file->getClientOriginalExtension()));
        $warehouse = null;
        if ($warehouseUlid) {
            $warehouse = Warehouse::query()->forTenant($tenant->id)->where('ulid', $warehouseUlid)->first();
            if (! $warehouse) {
                throw new ApiException('NOT_FOUND', 'The requested warehouse was not found.', 404);
            }
            if ($warehouse->status !== WarehouseStatus::Active
                || ! Branch::query()->forTenant($tenant->id)->whereKey($warehouse->branch_id)
                    ->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['warehouse_ulid' => 'Select an active warehouse.']);
            }
        }

        $batch = DB::transaction(function () use ($tenant, $actor, $file, $parsed, $warehouse) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = TenantImport::query()->where('tenant_id', $tenant->id)
                ->where('fingerprint', $parsed['fingerprint'])->lockForUpdate()->first();
            if ($existing) return $existing;
            return TenantImport::query()->create([
                'tenant_id' => $tenant->id,
                'platform_user_id' => $actor->id,
                'warehouse_id' => $parsed['format'] === 'products' ? $warehouse?->id : null,
                'source_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'format' => $parsed['format'],
                'fingerprint' => $parsed['fingerprint'],
                'status' => 'processing',
                'total_rows' => count($parsed['rows']),
            ]);
        });

        if ($batch->status !== 'completed') {
            $path = 'platform-imports/'.$tenant->ulid.'/'.$batch->ulid.'.json';
            if (! Storage::disk('local')->put($path, json_encode($parsed, JSON_THROW_ON_ERROR))) {
                throw new ApiException('IMPORT_STORAGE_FAILED', 'Cannot securely stage the source file.', 500);
            }
            $batch->source_path = $path;
            $batch->total_rows = count($parsed['rows']);
            $batch->save();
            ProcessTenantImport::dispatch($batch->id)->onQueue('imports');
        }

        return $this->progress($tenant, $batch->refresh());
    }

    public function process(int $batchId, TenantImportService $importer): void
    {
        $batch = TenantImport::query()->findOrFail($batchId);
        if ($batch->status === 'completed') return;
        if (! $batch->source_path || ! Storage::disk('local')->exists($batch->source_path)) {
            throw new ApiException('IMPORT_SOURCE_MISSING',
                'Private staged source is unavailable; re-upload identical file to resume.', 409);
        }
        $parsed = json_decode(Storage::disk('local')->get($batch->source_path), true, 512, JSON_THROW_ON_ERROR);
        $tenant = Tenant::query()->findOrFail($batch->tenant_id);
        $actor = PlatformUser::query()->findOrFail($batch->platform_user_id);
        $warehouse = $batch->warehouse_id
            ? Warehouse::query()->forTenant($tenant->id)->findOrFail($batch->warehouse_id) : null;

        // Existing tenant lock, per-row checkpointing, duplicate prevention and
        // financial/stock domain actions remain authoritative.
        $file = new UploadedFile(
            Storage::disk('local')->path($batch->source_path),
            $batch->source_name, null, null, true
        );
        $importer->execute($tenant, $actor, $file, $warehouse?->ulid, $parsed, true);

        $batch->refresh();
        if ($batch->status !== 'completed') {
            ProcessTenantImport::dispatch($batch->id)->onQueue('imports')->delay(now()->addSecond());
        } else {
            Storage::disk('local')->delete($batch->source_path);
        }
    }

    public function progress(Tenant $tenant, TenantImport $batch): array
    {
        if ((int) $tenant->id !== (int) $batch->tenant_id) {
            throw new ApiException('NOT_FOUND', 'Import not found.', 404);
        }
        $query = TenantImportRow::query()->where('import_id', $batch->id);
        $counts = (clone $query)->selectRaw('outcome, count(*) as row_count')
            ->groupBy('outcome')->pluck('row_count', 'outcome');
        $warnings = [];
        foreach ((clone $query)->orderByDesc('source_row')->limit(15)->get(['result']) as $row) {
            $result = $row->result ?? [];
            foreach ($result['warnings'] ?? [] as $warning) {
                $warnings[] = [
                    'source_row' => $result['source_row'] ?? 0,
                    'code' => $result['source_code'] ?? '',
                    'name' => $result['source_name'] ?? '',
                    'message' => $warning,
                ];
            }
        }

        return [
            'ulid' => $batch->ulid, 'tenant_ulid' => $tenant->ulid,
            'format' => $batch->format, 'status' => $batch->status,
            'processed' => (int) (clone $query)->count(),
            'total' => (int) ($batch->total_rows ?? 0),
            'replayed' => $batch->status === 'completed',
            'created' => (int) ($counts['created'] ?? 0),
            'updated' => (int) ($counts['updated'] ?? 0),
            'reused' => (int) ($counts['reused'] ?? 0),
            'skipped' => (int) ($counts['skipped'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
            'stock' => ['posted' => 0, 'skipped' => 0, 'zero' => 0],
            'warnings' => $warnings, 'rows' => [],
            'ignored_fields' => [],
            'retry_note' => 'Worker saves row checkpoints; re-uploading the identical source resumes incomplete work.',
        ];
    }
}
