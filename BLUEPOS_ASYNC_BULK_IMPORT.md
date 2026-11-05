# BluePOS Async Bulk Import — Implementation and Deployment

**Branch:** `feature/administration`. **Status:** Source implementation in GitHub; local tests, migration and queue deployment NOT yet run. **Never deploy directly to production without focused test DB validation.**

## Implemented

- `POST /api/platform/tenants/{tenantUlid}/imports/async`: platform authorization + recent MFA; validated XLSX/CSV/PDF source is parsed once, canonical fingerprint matched against earlier import, stored in private local disk path, and queued. Re-uploading the same source resumes its checkpointed import rather than reposting opening stock.
- `GET /api/platform/tenants/{tenantUlid}/imports/active`: last ongoing batch for the authenticated authorized platform user.
- `GET /api/platform/tenants/{tenantUlid}/imports/{importUlid}`: tenant-scoped read-only aggregated progress. Exposes only ULID, counters, limited recent warnings; does not return all rows or SQL/internal IDs.
- Background queue `ProcessTenantImport` on dedicated `imports` queue, serialized by overlapping lock; 120 rows/40s bounded service execution, then schedules another job if incomplete.
- Existing `TenantImportService` retains row-level locks, canonical product/party matching, append-only stock movement posting, double-entry protections, validation and source-row checkpoint idempotency. Background path skips expensive all-history response and resumes from last committed source row; per-file duplicate/conflict checks staged once.
- New React UI uploads one time and polls status. Closing the modal no longer stops background work; reopen to reconnect to active batch.
- Non-destructive additive migration: `platform_tenant_imports.source_path` (nullable string), `total_rows` (nullable unsigned integer). `down()` drops only those two columns.
- Focused tests added to `PlatformTenantImportTest.php` for queue dispatch, worker completion, same-file replay, warehouse stock exactly once, tenant isolation and refusal to run with a synchronous queue.

## Production prerequisites

1. Apply additive migration after deployment and backup review, without refreshing/truncating production DB.
2. `QUEUE_CONNECTION=database` (or a tested supported asynchronous driver). `QUEUE_CONNECTION=sync` is deliberately rejected on the new route.
3. Ensure shared private storage path is visible to both PHP web process and queue worker. A multi-server deployment must use shared storage or a controlled shared filesystem; default `local` is single-server only.
4. Configure a durable worker under Supervisor/systemd/hosting process manager:
   ```bash
   php artisan queue:work database --queue=imports --sleep=1 --tries=5 --timeout=75
   ```
   Queue `retry_after` must exceed timeout (default database retry_after=90). Do not run multiple uncontrolled copies without validating shared cache locks.
5. Cache driver backing `WithoutOverlapping` must support atomic locks across workers.
6. Keep sensitive staged files out of public webroot and log PII-safe messages. Do not change already posted financial/stock rows.
7. If production uses cron-only hosting, review provider-specific worker availability and permissions first. Do not silently fall back to `sync`.
8. Previous 4,477-product import may have partial committed progress; reuse the **identical original file** and chosen tenant/warehouse. Do not run multiple independent versions simultaneously.

## Focused validation

From `backend`, only on `bluepos_test`:

```powershell
$env:APP_ENV = 'testing'
$env:DB_DATABASE = 'bluepos_test'
try {
    php artisan migrate --env=testing --force
    php artisan test tests/Feature/PlatformTenantImportTest.php
} finally {
    Remove-Item Env:APP_ENV -ErrorAction SilentlyContinue
    Remove-Item Env:DB_DATABASE -ErrorAction SilentlyContinue
}
```

The migrate command is additive and test-DB only, **never run it against `bluepos_dev` by mistake**. For production use an approved migration/release procedure instead. Frontend: `npm run typecheck`, `npx vite build`. Browser/staging: stage, poll, close/reopen, reload same source, compare warehouse stock and product counts.

## Remaining scalability work — NOT IMPLEMENTED

- Existing `SimpleXlsx::readMatrix` enforces ~10,000 source rows and an expanded-size cap; **100,000-row XLSX is not yet supported**.
- XLSX parse is still synchronous within first upload request; this is expected to be manageable for a few thousand rows but needs streaming worker-side validation for huge workbooks.
- Domain-ledger opening-stock posting remains per product, potentially the dominant processing cost. Do not bypass accounting or manufacture product balances to speed it up.
- Profile before selecting batch-aggregation strategy. 100,000 product rows require streaming parse and staged chunk files/DB rows rather than one in-memory JSON array. No throughput/ETA guarantees until benchmark.
- Permanent worker failure currently leaves a processing batch requiring admin inspection/re-upload. A dedicated failed-status/recovery interface can be added after queue operations are benchmarked.
- This is a staged throughput improvement (request/parse overhead removed and progress detached from the browser), **not** a verified 100k-row bulk import engine.

## Source changes

Added: `AsyncTenantImportService.php`, `ProcessTenantImport.php`, `2026_11_05_180000_add_async_fields_to_platform_tenant_imports.php`.

Changed: `TenantImportService.php`, `TenantImportController.php`, `routes/platform.php`, `frontend/src/api/platform.ts`, `frontend/src/components/platform/TenantImportModal.tsx`, `PlatformTenantImportTest.php`.
