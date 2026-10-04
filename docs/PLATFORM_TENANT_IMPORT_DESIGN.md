# Platform tenant import — implementation report

Branch: `feature/platform-tenant-import`, based on `origin/main` at `840af93`.

## Implemented behavior

Initial platform login uses email/password and requests Laravel remember authentication
by default. The existing email MFA challenge, verification, recent-MFA protection,
remember-token revocation and logout remain in place. The MFA code input appears only
after a challenge. No password is persisted by BluePOS frontend storage. An explicit
API `remember: false` still requests a session-only login. Tenant login is unchanged.

Tenant list now offers Import Data to users with `platform.tenants.import`. The modal
shows the target tenant, XLSX picker, optional tenant warehouse, pending/processed-row
progress, counts and row warnings. Imports require recent platform MFA. Tenant and
warehouse routes accept public ULIDs; a foreign warehouse returns 404. A warehouse
must belong to an active branch of the selected tenant.

## Persistence and retry

`platform_tenant_imports` records tenant, real platform actor, source filename, format,
SHA-256 canonical parsed-row fingerprint, initial warehouse and status.
`platform_tenant_import_rows` records source identity/row, outcome, public result JSON
and optional opening-document reference. Both have internal PostgreSQL generated
identity primary keys and public unique CHAR(26) ULIDs. Unique tenant/fingerprint and
import/source-row keys prevent duplicate processing.

Each request processes at most 100 unprocessed rows. The modal automatically submits
successive bounded requests. A brief tenant/import lock serializes each row's domain
writes and saved outcome in the same transaction. An interrupted upload resumes on
re-upload. Completed outcomes, including skipped/failed rows, are replayed. Correct
failed source data in a revised workbook; identical replay does not retry completed
failures. Warehouse selection is fixed on the first upload, including catalog-only.

A changed workbook can update catalog data, but opening stock is skipped when the
product already has any movement history in that warehouse. No import updates or
removes posted movements. Concurrent uploads are serialized by PostgreSQL locks and
unique keys; simultaneous browser-upload stress testing remains a manual check.

## Source mappings

Accounts workbook: six actual headers, 212 source rows.

- VENDORS: Supplier plus existing PartyLeafAccountSync supplier leaf account.
- CUSTOMERS: Customer plus existing PartyLeafAccountSync customer leaf account.
- ACCOUNTS: standalone Account linked to a uniquely matched active tenant AccountType,
  whose tenant head/main-head hierarchy is active. Missing/ambiguous types skip that row.
- ALL: always skipped with identifying row/code/name warning. Sample source rows:
  3, 4, 26, 27, 71, 87, 176, 177, 194 (nine rows).
- Contacts: party phone; blank allowed. Standalone account contact values are warned
  as unsupported. No opening balances or transactions are fabricated.
- CODE: existing tenant-scoped code matching with identity-conflict checks. Missing
  codes use `LEG-` plus a deterministic hash of kind/name/contact/account-type ULID.
  Existing names with different codes are flagged instead of automatically merged.
  Conflicting duplicate source identities are skipped; equivalent duplicates reuse.
- Legacy ID is source metadata only; never assigned as a BluePOS identifier.

Products workbook: 4,312 product rows across 112 category sections; 124 negative-stock
rows and four missing-code rows. Repeated ID/CODE/Description/In Carton/In Stock/Retail/
Pur.Rate headers are detected from structure, independent of filename.

- Section headings create/reuse tenant Category; following products receive that category.
  New category codes use a stable `LEG-` hash; active/sort defaults follow the master model.
- CODE remains a string, including leading zeros. Match barcode or SKU; conflicting
  matches skip. New products receive CODE as primary base-unit barcode (factor one),
  and SKU when within the existing field limit. Product number uses existing sequence.
- Description supplies the new product name. Existing matched product names are preserved;
  category and supported retail price are updated. Existing unit configuration is preserved.
- New products require an existing unique active PCS unit and existing tenant catalog
  settings. The importer creates neither PCS nor arbitrary catalog/accounting defaults.
- Retail uses existing SyncProductPricesAction. Blank/invalid values preserve existing
  prices/default behavior with warnings.
- In Carton is intentionally ignored; no carton conversion is inferred.
- Positive stock uses CreateOpeningBalanceAction, UpsertOpeningBalanceLineAction and
  PostOpeningBalanceAction, with Pur.Rate as exact opening unit cost. Missing/invalid
  cost skips stock without preventing catalog creation.
- No warehouse: catalog still imports, stock is skipped with a warning.
- Zero: no movement, no stock failure. Negative: unchanged value is warned and skipped.
- Existing non-PCS units or incompatible barcode conversions also skip unitless stock.
- Pur.Rate is never written directly as average cost or a new price type. Without a
  legitimate positive posting it is reported unused. No batch/expiry facts are invented.

Counts report created, updated, reused, skipped, failed, warnings and stock outcomes.
Warnings include source row, code/name and reason. Public responses expose ULIDs only
for BluePOS entities; source legacy references are explicitly labelled.

## Migrations and dependencies

- `2026_10_04_200000_create_platform_tenant_imports.php`: two tracking tables; nullable
  products.created_by and inventory_opening_balances.created_by. posted_by was already
  nullable. Rollback refuses to discard import tracking or orphan platform attribution.
- `2026_10_04_200100_use_identity_for_platform_import_ids.php`: convert new tracking
  primary keys from Laravel serial defaults to GENERATED BY DEFAULT AS IDENTITY.
- ImportTenantContext supplies an explicit tenant and null tenant-user actor to reused
  domain actions. Inventory action audit events go through ImportAuditLogger with the
  actual PlatformUser and target tenant ULID. No tenant-user impersonation.
- Existing SimpleXlsx is extended with bounded raw-coordinate reading for section rows,
  formula detection and rejection of DTD/entity XML. No Composer/npm dependency changes.

Forward migrations were applied only to `bluepos_test`. Two previously pending existing
Sales snapshot migrations also ran there, without modifying their files. Application
DB migrations were not run. No destructive database commands were used.

## Exact files added

- backend/app/Http/Controllers/Platform/TenantImportController.php
- backend/app/Http/Requests/Platform/TenantImportRequest.php
- backend/app/Models/Platform/TenantImport.php
- backend/app/Models/Platform/TenantImportRow.php
- backend/app/Platform/Imports/AccountsImporter.php
- backend/app/Platform/Imports/ImportAuditLogger.php
- backend/app/Platform/Imports/ImportTenantContext.php
- backend/app/Platform/Imports/ProductsImporter.php
- backend/app/Platform/Imports/TenantImportService.php
- backend/app/Platform/Imports/WorkbookParser.php
- backend/app/Policies/TenantPolicy.php
- backend/database/migrations/2026_10_04_200000_create_platform_tenant_imports.php
- backend/database/migrations/2026_10_04_200100_use_identity_for_platform_import_ids.php
- backend/tests/Feature/PlatformPersistentLoginTest.php
- backend/tests/Feature/PlatformTenantImportTest.php
- frontend/src/components/platform/TenantImportModal.tsx
- docs/PLATFORM_TENANT_IMPORT_DESIGN.md (approved proposal replaced by implementation report)

## Exact files modified

- backend/app/Http/Controllers/Platform/PlatformAuthController.php
- backend/app/Platform/PlatformPermissionCatalogue.php
- backend/app/Support/SimpleXlsx.php
- backend/app/Tenancy/TenantContext.php
- backend/routes/platform.php
- frontend/src/api/platform.ts
- frontend/src/pages/platform/PlatformLoginPage.tsx
- frontend/src/pages/platform/PlatformTenantsPage.tsx

## Validation commands and source-file checks

PowerShell process environment for every DB validation:

```powershell
$env:APP_ENV='testing'
$env:DB_DATABASE='bluepos_test'
$env:DB_CONNECTION='pgsql'
$env:DB_URL=''
```

```text
cd backend
php artisan migrate --env=testing --force
php artisan test --env=testing --filter='PlatformTenantImportTest|PlatformPersistentLoginTest|PlatformRememberMeTest|InventoryOpeningBalanceTest|AuthenticationTest'
php vendor/bin/pint --test <all changed PHP files>
cd frontend
npm.cmd run typecheck
npx.cmd vite build
npx.cmd eslint src/api/platform.ts src/pages/platform/PlatformLoginPage.tsx src/pages/platform/PlatformTenantsPage.tsx src/components/platform/TenantImportModal.tsx
```

Read-only Git status/log/branch checks and workbook ZIP/XML inspection were also run.
Temporary guarded PHP verification scripts used bluepos_test with rollback-only data.
The full accounts workbook produced 199 created, four reused, nine skipped, zero failed;
identical replay passed. A representative product subset retained original workbook
coordinates and contained 33 actual rows: 29 created, four missing-code skips, zero
failed; 20 stock postings, three zero-stock rows and six stock skips. Replay added no
stock. The validation transaction rolled back.

The initial full 4,312-row rollback-only product run was stopped because its single
outer transaction accumulated thousands of nested transactions. Its uncommitted data
rolled back. It is not claimed as a completed bulk-import validation. Full source
parsing and representative real-data import were validated; end-to-end 4,312-row
browser upload remains a manual check.

Frontend typecheck, Vite build and focused ESLint passed. Build retained existing
bundle-size/mixed-import warnings. PHP Pint passed. Backend final suite passed:
33 tests, 333 assertions (104.58 seconds).

## Manual checks and scope

Apply forward migrations to the intended application database before browser testing.
Test platform MFA/login, closing/reopening the browser, logout, tenant upload progress,
warehouse choices, interrupted resume, simultaneous uploads and full source workbook
processing. Review skipped/failed row warnings before making financial corrections.

Main remains at 840af93; sales-ui-fixes remains at 864b4fa. No Sales Invoice, Sales
API/feature files, product UI, shell/ribbon, or unrelated modules were modified. No
merge, push or PR was performed. Commit hash is supplied in the completion message.
