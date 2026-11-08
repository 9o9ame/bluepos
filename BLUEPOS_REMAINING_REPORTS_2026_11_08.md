# BluePOS Reports — Remaining Reference Packs (2026-11-08)

**Development branch:** `feature/reports`. **Status:** Reference inventory completed. Dead Stock implementation added in source on this branch but execution/browser validation pending; other gaps are not claimed complete. The November 8 source packs comprise ten ZIPs and four standalone JPEGs. Do not edit `main` directly. Read `PROJECT_UI_DESIGN_SYSTEM.md` before frontend work.

## Reuse inventory and required gap check

| Reference | Existing BluePOS starting point | Remaining verification / implementation |
| --- | --- | --- |
| Advance Sales | `NextReportsPage` advance + `NextReportsController` | Already coded; browser acceptance, stock history semantics and focused tests still pending |
| Vendor / Customer Ledger Options | `VendorCustomerLedgerPage`, `PartyLedgerController`; existing Day Book ledger | Report option filters, document selection, print modes, scoped balances and posted-ledger consistency |
| Inactive Customers | `PartyController`, posted sales reader | New last-sale activity report; inclusive inactivity window, type/area filters only if supported, balances only with accounting permission |
| Salesman Commissions | Existing sales documents and membership/salesman data | New read-only commission report; do not guess commission rule, percent or per-unit rate; require explicit configured rate/source before displaying payable amounts |
| Outstanding Balances | Party ledger + posted journal | New outstanding options and report variants; opening, as-of date, debit/credit sign, no duplicated ledger totals; different PDF variants require explicit mode mapping |
| Product Ledger | `ProductLedgerController`, `ProductLedgerReportPage` | Existing implementation; reconcile opening, movements, running and closing, current warehouse isolation and reference export styles |
| Product GL (grid) | Product ledger + product transactions | New grid/filter format as a view of existing immutable movement data rather than a parallel stock ledger |
| Daily / Monthly P&L | `DailyIncomeStatementReportPage` and product daily gross-profit report | Distinguish ledger net P&L from operational product gross profit; implement requested grouping/filter behavior without relabeling gross as net |
| Stock Status | `ProductStockStatusController`, `ProductStockStatusReportPage` | Existing implementation; adapt filter/print variants only, preserve average-cost permission and warehouse context |
| Stock Activity Between Dates | Product ledger and `StockMovement` | New multi-product date-window movement report, signed quantities, opening/closing and immutable movement links |
| Price List / Stock Position / Reorder | Existing stock-status report + product prices | New report-format options (sales/purchase price), brand/category/location filters, reorder variants; cost gated by `inventory.view` |
| Dead Stock | Existing `StockBalance` / `StockMovement` | **IMPLEMENTED IN SOURCE (tests pending):** scoped report at `/reports/products/dead-stock`, elapsed-day no-sale filter, positive stock, cost RBAC, grid/preview/export. Must validate PHP/TS and browser before accepting |
| Expiry Stock | Product `track_expiry` + batch/expiry source (verify real schema) | New report only if persisted lot/expiry quantities support accurate results. Otherwise show unsupported/unavailable; do not fabricate expiry |
| Barcode Printing | `BarcodePrintingPage`, existing label/print helpers | Existing feature; verify reference options, formats, selected product/quantity, tenant data and browser print behavior |

## Existing implementation safeguards

- `BLUEPOS_DAILY_REPORTS_MILESTONES.md` records four Daily Reports user-accepted; leave frozen/accepted behavior unchanged.
- `BLUEPOS_NEXT_REPORTS_MILESTONES.md` records six next reports implemented in source; NR-7 local regression/browser validation remains unverified.
- GitHub comparison on November 8 reported `main` and remote `feature/reports` identical. This contradicts older milestone text saying next reports unmerged. Current Git history wins.
- Do not duplicate existing report APIs, create duplicate report viewers, or recreate accounting/stock posting flows.
- Use `UiSelect`, `UiButton`, `PosDataGrid`, `ReportViewerToolbar`, design tokens, existing workspace tabs and ribbon registry.
- Dates and filters validated server-side; tenant/branch/warehouse only from `TenantContext`; ULIDs only in public APIs.
- Read-only posted documents, immutable journal postings and append-only stock movements; decimal strings with BCMath. Never use floats to calculate financial totals.
- Permissions: `reports.accounting`, `reports.inventory`, `inventory.view` where applicable; no forbidden totals or cost leak on exports.
- Provide safe CSV/print/PDF outputs through existing report toolbar; escape CSV formulas; do not invent cost, aging, expiry, balance or commission values.

## Delivery sequence

1. **Inventory and revalidation:** verify existing Advance Sales, Product Ledger, Stock Status, Daily/Monthly P&L, Barcode Printing against references; run existing focused tests before claiming complete.
2. **Stock report family:** Price List/Reorder, Stock Activity, Product GL, Dead Stock, Expiry Stock (after batch provenance validation). Reuse immutable stock movements. Each focused slice requires both backend isolation tests and frontend checks.
3. **Accounting and party family:** Outstanding Balances; Vendor/Customer Ledger Options; Inactive Customers, with posted-ledger and activity semantics.
4. **Salesman Commissions:** implement only against existing configured commission terms; if absent, report missing configuration and request business rule, do not invent percentages or payable liability.
5. **Acceptance:** compare option/preview/print/export against supplied references and previous accepted reports; run focused tests only on `bluepos_test`, UI guard/typecheck/build, and verify authorization, precision, tenant and warehouse isolation.

**No runtime implementation, migration, or production deployment is represented by this inventory document.** Record each completion separately with changed files, passing test evidence and pending limitations.

## 2026-11-08 First implementation slice — Dead Stock (UNTESTED)

Added `backend/app/Http/Controllers/Api/Reports/DeadStockReportController.php`, `frontend/src/pages/DeadStockReportPage.tsx`, `backend/tests/Feature/DeadStockReportTest.php`; updated `backend/routes/api.php`, `frontend/src/routes/index.tsx`, `frontend/src/features/workspace/modules.ts`. No migration.

Definition: positive current stock with no outbound sale / negative sale-amendment movements in the preceding 1–3650 days. Uses active server-selected tenant/branch/warehouse, read-only stock history, current stock balance, and hides stock value without `inventory.view`. Download is page-scoped and labeled. Does not count purchases, transfers or returns as sales. Requires focused PHP, UI guard, TS typecheck, Vite build and browser validation; no passing test claim.
