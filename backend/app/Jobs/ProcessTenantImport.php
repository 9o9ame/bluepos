<?php

namespace App\Jobs;

use App\Models\Platform\TenantImport;
use App\Platform\Imports\AsyncTenantImportService;
use App\Platform\Imports\TenantImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

final class ProcessTenantImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 75;

    public function __construct(public readonly int $batchId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('tenant-import-'.$this->batchId))
            ->releaseAfter(10)->expireAfter(120)];
    }

    public function handle(AsyncTenantImportService $coordinator, TenantImportService $importer): void
    {
        $coordinator->process($this->batchId, $importer);
    }

    public function failed(?\Throwable $e): void
    {
        // Do not persist exception messages: they may contain PII, file values or SQL.
        TenantImport::query()->whereKey($this->batchId)->where('status', 'processing')
            ->update(['last_error' => 'Worker exhausted retries. Re-upload the identical source to resume.']);
    }
}
