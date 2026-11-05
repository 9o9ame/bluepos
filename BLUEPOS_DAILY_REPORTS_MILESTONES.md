# BluePOS Daily Reports — Implementation / Verification Milestones

**Branch:** `feature/reports` (user explicitly approved mixed-module work on this branch).
**Status:** DR-0 through DR-6 **COMPLETED — user accepted on 2026-11-05**. User confirms all four reports working. This is functional acceptance, not proof that every automated test and export scenario passed. Do not merge to `main` until separately approved.
**Reference:** Four uploaded screenshot packs and two reference PDFs (2026-11-05).
**Authority:** `PROJECT_UI_DESIGN_SYSTEM.md`, current repository code and server-resolved context.

## What was implemented

| Milestone | Report | UI route | API route | Status |
| --- | --- | --- | --- | --- |
| DR-0 | Source/reference inventory | — | — | **COMPLETED** |
| DR-1 | Shared context and report viewer | Shared `ReportViewerToolbar` | Authenticated + scoped report controllers | **COMPLETED — user accepted** |
| DR-2 | Daily Activity Report | `/reports/daily/activity-summary` | `GET /api/reports/daily/activity-summary` | **COMPLETED — user accepted** |
| DR-3 | Detail Sale + Stock + Profit Loss | `/reports/daily/detail-sale-stock-profit` | `GET /api/reports/daily/detail-sale-stock-profit` | **COMPLETED — user accepted** |
| DR-4 | Business by Groups | `/reports/daily/business-by-groups` | `GET /api/reports/daily/business-by-groups` | **COMPLETED — user accepted** |
| DR-5 | Daily Product Profit/Loss | `/reports/daily/profit-loss` | `GET /api/reports/daily/profit-loss` | **COMPLETED — user accepted** |
| DR-6 | Regression and freeze | All above | Tests below | **COMPLETED — functional acceptance; automated rerun not evidenced** |

**Existing** `/reports/daily/business-activity` remains the old **Daily Ledger Wise Activity**. It was deliberately preserved. The new **Daily Activity Report** is a separate item in the Daily Reports ribbon.

## API contract

- All report dates: `from=YYYY-MM-DD&to=YYYY-MM-DD` with end >= start; maximum 31-day span.
- DR-3, DR-4, DR-5 optional filters: `category_ulid`, `brand_ulid` (validated active tenant scope). DR-4 requires `group_by=category|brand`.
- Dropdown choices: `GET /api/reports/daily/product-options`. Product dimensions are actual category and brand, not guessed reference "company" strings.
- Tenant, branch and warehouse come only from `TenantContext`, never from client IDs.
- DR-2 requires `reports.accounting` and `reports.inventory`; DR-3 requires `reports.inventory`, hides sale amounts without `reports.accounting`, and hides cost without `inventory.view`; DR-4 requires `reports.inventory` + `reports.accounting`; DR-5 additionally requires `inventory.view`.
- Public JSON exposes ULIDs, not internal BIGINT IDs.
- Money/quantities use strings with BCMath scale 4/6; no JS financial floating-point authority.
- Stock opening + signed period movements = closing. Stock movements are append-only and their snapshotted `total_cost` is the only cost basis for product gross profit.
- DR-4 purchase values are sums of posted line totals excluding line taxes; they are not claimed to equal invoice grand total when freight/other header charges exist.
- Profit is operational product *gross* profit. It is not a posted-ledger Income Statement, and it is not net profit.
- Missing historical cost / stock mismatch / unreconciled document lines yield `null` profit instead of fabricated cost.
- Existing posted sales, purchase documents, journal records and stock movements have not been mutated.

## Validation evidence and optional final regression

User-reported final result: **all four reports working**. Previously supplied focused test run: **10 passed / 1 failed** (invalid 28-character ULID in test fixture). The fixture was subsequently corrected in commit `66a23b83`; no post-fix rerun output is recorded in this conversation. Frontend typecheck/build output for the final report commit is likewise not recorded. For independent verification before a future main merge, run only on `bluepos_test`:
```powershell
cd C:\laragon\www\bluepos\backend
$env:APP_ENV = "testing"
$env:DB_DATABASE = "bluepos_test"
php artisan test tests/Feature/DailyActivitySummaryReportTest.php tests/Feature/ProductDailyReportsTest.php
Remove-Item Env:APP_ENV -ErrorAction SilentlyContinue
Remove-Item Env:DB_DATABASE -ErrorAction SilentlyContinue
```
Then `frontend/npm run typecheck`, `frontend/npx vite build`; manually verify all four option screens, previews, dates, empty states, quantity/value reconciliation, print/export and cross-account visibility.

## Known limitations retained after functional acceptance

- Report browser layout is BluePOS-styled and functionally analogous to the reference screenshots, not a pixel clone of the third-party UI.
- Historical product category/brand grouping reflects the currently linked product hierarchy; no historical dimension snapshot is invented.
- Operational sale cost may be unavailable when stock cost snapshots are null or postings and effective document quantities do not align.
- Older Income Statement accounting-control configuration and historical posting remain a separate project issue; new read-only reports do not repair posted journals.
- GitHub connector cannot execute local PHP or Vite checks. User confirms the reports work, but no post-fix automated suite or final frontend build evidence was provided; do not label these checks PASS without output.
- No database schema migration, destructive command, branch switch, or main merge in this implementation.
