# BluePOS Remaining Reports — Implementation Milestones (2026-11-08 v2)

**Branch:** `feature/reports` ONLY. **Status:** Plan and reference analysis recorded; not all reports implemented. Existing BluePOS Daily Reports and Sales Invoice UI are frozen/user accepted. The plan extends `BLUEPOS_REMAINING_REPORTS_2026_11_08.md` and does not supersede existing implementation.

## Reference inventory and exact layouts

- Prior 4 standalone screenshots: Advance Sales, Vendor/Customer Ledger Options, Inactive Customers, Salesman Commissions.
- Prior 10 ZIP reference packs: Outstanding Balance, Product Ledger, Product GL, Daily/Monthly PL, Stock Status, Stock Activity Between Dates, Price List, Dead Stock, Expiry Stock, Barcode Printing.
- Newly supplied standalone PDFs: `product-gl (Grid).pdf` (4 pages); `Daily  Monthly PL.pdf` (4 pages); `Stock Status.pdf` (115 pages).
- Newly supplied ZIPs, 3 PDFs each:
  - **Stock Activity Between Dates:** `qty-between-dates.pdf` (83 pages), `doc-wise-stock.pdf` (153 pages), `sales-activity-sales&stock.pdf` (119 pages).
  - **Price List:** `price-list-show-sales+purchase-price.pdf` (116 pages), `stock-position price-list lessthen reorder.pdf` (58 pages), `price-list-stock-position price-list less then reorder.pdf` (58 pages).
  - **Outstanding Balances:** `balances-address_plus_phone.pdf` (2 pages), `current_plus_future.pdf` (3 pages), `balance-with-add.pdf` (3 pages).

### Evidence-derived report contracts

1. Product GL PDF: product-level Opening, Debit Qty, Credit Qty, Closing; signed Dr/Cr shown in reference. 4-page footer has opening 3,213.81, debit 169, credit 331, closing 3,051.81. For BluePOS use signed quantity with labelled Dr/Cr presentation, never imply positive negative inventory is invalid. Reconcile opening + inbound - outbound = closing; reversals/amendments must retain their actual signs.
2. Daily/Monthly PL PDF: `DAILY PROFIT LOSS SUMMARY by PRODUCT NAME WISE`, date 08-Nov-2026, `Costing: MovingAverage`, columns DOC, Narration, Sale Qty, Sale AMT, Cost AMT, Sale Rate, Cost Rate, P/L and final margin percent. Reference grand quantity 333, revenue 25,223.26, cost 21,062.24, profit 4,161, ~16.5%. This is product gross profit; **not** ledger net Income Statement. Source PDF is a daily example; monthly selection behavior must be mapped from screenshots, not invented.
3. Stock Status PDF: `Stock Position As on` date with category groups, group counts, product ID/code/name, Carton, PCs, In Stock, MV Rate, Amount, category subtotals/grand totals; includes zero and negative on-hand. BluePOS must use available stock balances and actual conversion factors only; no invented historical moving-average valuation.
4. Stock Activity PDFs are **three distinct views**: category-level opening/purchases/sales/returns/adjustments/closing plus average sales; document-wise PSJ/PRJ/SLJ/SRJ quantity and value plus total; Sales & Stock Statement with opening, purchases, returns, gross/net sales and other movements. Reconcile against append-only posted stock movements; averages depend on stated lookback, avoid assumed denominators.
5. Price List PDFs are **three views**: Sales + Purchase Price with retail and purchase rate; and two `Less than Reorder Level` formats with In Stock, Re Order, signed Less (one packs/carton presentation). Use current price configurations; restrict cost/purchase rate by permissions; reorder difference = stock - reorder with exact decimal arithmetic.
6. Outstanding Balances are **three formats**: account title/address/NTN/mobile/license/license expiry/balance; address/phone/last sale/net balance/received; current/future due, last receipt date, elapsed days, remarks. Only show columns backed by real schema; future due requires actual due dates, never infer them.
7. Other supplied reports: Advance Sales, Vendor/Customer Ledger Options, Inactive Customers, Salesman Commissions, Product Ledger, Dead Stock, Expiry Stock, Barcode Printing remain in `BLUEPOS_REMAINING_REPORTS_2026_11_08.md`.

## Milestones and definition of done

| ID | Milestone | Deliverables | Acceptance / tests | Status |
| --- | --- | --- | --- | --- |
| RR-0 | Reference reconciliation | Inventory PDFs/ZIPs/screenshots, compare existing routes/models/pages and frozen UI | No invented data, code/doc conflicts flagged, row/column matrix | **DONE** |
| RR-1 | Baseline and shared report contracts | Reuse existing ReportViewerToolbar, report RBAC, option/preview/export patterns | Tenant, branch, warehouse, export permissions, ULID only, decimal strings, no mutations; design guard | **INSPECTED; executable regression pending** |
| RR-2 | Stock Status + Price List / Reorder | Existing StockStatus report: signed reorder variance and optional print variants; new retail/purchase price view and category totals only where supported | Quantity, cost permission, category-group subtotal, zero/negative stock, fractions, conversions, 100+ pages pagination/print | **PRICE/REORDER THREE FORMATS + CATEGORY TOTALS IN SOURCE; TESTS/PDF PARITY PENDING** |
| RR-3 | Product GL grid | Product Opening/Debit/Credit/Closing by date, print/CSV, optionally category | Opening + signed movements = closing incl negatives, amendments, tenant/warehouse and dates, page-independent totals | **IMPLEMENTED IN SOURCE; TESTS + BROWSER PENDING** |
| RR-4 | Stock Activity Between Dates | Category stock balance, document-wise stock, sales-and-stock formats with source-linked qty/value | Three separate modes; posting reconciliation, signed returns, document linkage, no stock double count | **QUANTITY VIEW IMPLEMENTED IN SOURCE; TWO REFERENCE FORMATS + TESTING PENDING** |
| RR-5 | Daily/Monthly Product Profit/Loss | Product gross P/L: sale qty, revenue, movement-snapshot COGS, rates, amount/margin; day/month group options | Missing cost -> unavailable, not 0; returns, price discounts, precision; reconcile sales vs posted movement costs; distinguish net ledger statement | **EXISTING GROSS-PROFIT CORE + RATES + CURRENT-MONTH SELECTION; VALIDATION PENDING** |
| RR-6 | Outstanding Balances (3 modes) | Ledger as-of positions, phone/address contact variant, current/future due only when supported | Posted journal balances match ledger, date cutoff, Dr/Cr sign, tenant RBAC, missing due/last sale blanks, no unauthorized PII | **SOURCE IMPLEMENTED; due-age allocation unavailable; tests pending** |
| RR-7 | Vendor/Customer Ledger Options | Account selection, dates, document selection, summary/detail, existing ledger reuse | Open/running/closing, debit credit polarity, opening and posting controls, cross-tenant denial | **EXISTING CORE CONFIRMED; PDF PARITY + TESTS PENDING** |
| RR-8 | Inactive Customers | Last sale date, adjustable inactivity, customer filters supported by schema | No sale vs cancelled/unposted, inclusivity, balance visibility RBAC | **SOURCE IMPLEMENTED; tests/browser pending** |
| RR-9 | Salesman Commissions | Configured commission basis, salesman allocations, sales/returns, totals | Do not invent commission %; nullable rates until verified, discounts/cost RBAC, posted-only calculation | BLOCKED ON COMMISSION RULE/SOURCE VERIFICATION |
| RR-10 | Dead Stock | On-hand positive stock, no outbound posted sale over selected days, preview/export | Tenant/warehouse, cost access, tests/browser validation | **IMPLEMENTED IN SOURCE previously; validation pending** |
| RR-11 | Expiry Stock | Actual lot/batch expiry and remaining qty, aging/filter/print | Block if no reliable persisted expiry-by-lot; no fake dates | **BLOCKED: NO VERIFIED LOT-EXPIRY QUANTITY SOURCE** |
| RR-12 | Barcode Printing | Reuse existing print engine, align reference options and selected quantity/format | Printer/browser preview and label measurements, no frozen Definition changes | **EXISTING FEATURE; REFERENCE BROWSER GAP CHECK PENDING** |
| RR-13 | End-to-end validation and freeze | Focused PHPUnit (bluepos_test), `npm run ui:check`, `npm run typecheck`, `npm run build`, browser acceptance/print | All report totals, filters, 403/IDOR, warehouse/branch/tenant, exact decimals, CSV formula safety | PENDING |

## Implementation rules

- Slices should remain bounded, reversible, and confined to `feature/reports`; **never implement on main**. No merge until user-reviewed and tested.
- Read `PROJECT_UI_DESIGN_SYSTEM.md` before UI edits; use canonical UiButton, UiSelect, PosDataGrid, and ReportViewerToolbar; no rewrites of frozen layouts.
- Tenant/branch/warehouse context always server-resolved; no client-authoritative IDs, prices, cost, balances, commission or totals.
- Financial amounts NUMERIC(20,4), quantity NUMERIC(20,6); BC/SQL NUMERIC, not floats. Read-only posted financial records, immutable stock movement data, no parallel ledgers.
- Existing Product Ledger, Stock Status, Daily Income Statement, four accepted Daily Reports, six staged next reports, Barcode Printing must be inspected before new parallel code.
- If referenced historical cost, stock snapshot, carton conversion, expiry batch or due date unavailable, return null/unsupported rather than fabricated values.
- Focused tests use `bluepos_test` exclusively; no persistent-DB automated tests. The GitHub connector can commit but cannot run local Laravel/Vite, so never mark executable tests passed without user output.

## Pull / verification commands

```powershell
cd C:\laragon\www\bluepos
git status -sb
git switch feature/reports
git pull --ff-only origin feature/reports
git status -sb
```

Backend tests only after change-specific test file is named; use `$env:APP_ENV='testing'; $env:DB_DATABASE='bluepos_test'`, then clear both variables in finally. UI: `npm run ui:check`, `npm run typecheck`, `npm run build`.

## RR-2 first implementation slice — 2026-11-08

- Existing `ProductStockStatusController` now returns `reorder_variance = current on-hand quantity - configured reorder_level`, as `NUMERIC(20,6)` decimal string (BCMath); null when reorder level is missing.
- Existing `ProductStockStatusReportPage` now displays signed `Vs Reorder` column; no changed global styling or frozen sales UI.
- Updated frontend `ProductStockStatusRow` contract; existing focused `ProductStockStatusReportTest` now asserts negative/positive/zero-stock variance, preserving warehouse/tenant/cost checks.
- **Status:** GitHub changes committed on `feature/reports`; PHPUnit, `ui:check`, typecheck, Vite build, print/browser review **NOT RUN** here. No new migration, no `main` changes. This is one subset of RR-2, not complete Price List/Reorder report acceptance.

## RR-3 first implementation slice — 2026-11-08

- Added `ProductGlGridController` and route `/api/reports/products/gl-grid`: immutable signed movements grouped by product with opening/debit/credit/closing as decimal strings, server tenant/branch/warehouse scope, from/to dates, search, SQL summary totals and 100-row pages.
- Added `ProductGlGridPage` with date options, shared preview/print/export toolbar, signed quantity grid and page navigation; registered route and workspace module.
- Added `ProductGlGridReportTest` covering negative movements, amendment reversal, date ordering, tenant scope and another warehouse. **Focused tests not executed yet.**
- Changed files: `backend/app/Http/Controllers/Api/Reports/ProductGlGridController.php`, `backend/routes/api.php`, `frontend/src/pages/ProductGlGridPage.tsx`, `frontend/src/routes/index.tsx`, `frontend/src/features/workspace/modules.ts`, `backend/tests/Feature/ProductGlGridReportTest.php`.
- Product GL source slice is not accepted until `bluepos_test` regression, frontend guards/typecheck/build and comparison with 4-page PDF are run.

## RR-4 first implementation slice — 2026-11-08

- Added `StockActivityBetweenDatesController` and `/api/reports/products/stock-activity` read-only endpoint, grouped by product and category, with signed opening/purchases/purchase returns/sales/sale returns/other movements/closing from immutable stock movements.
- Added `StockActivityBetweenDatesPage` with date-range options, shared viewer, print, page-level CSV/HTML/TXT, search and pagination; registered module and route.
- Added `StockActivityBetweenDatesReportTest.php` for positive/negative movement reconciliation, another warehouse, tenant isolation and invalid dates. **Tests not executed.**
- The reference also contains distinct Document Wise Stock and Sales & Stock Statement with value columns. Those two modes **remain pending** because sale values must be tied to the posted sale snapshot, not incorrectly derived from stock cost.
- No migration or `main` merge. Changed: `backend/app/Http/Controllers/Api/Reports/StockActivityBetweenDatesController.php`, `backend/routes/api.php`, `frontend/src/pages/StockActivityBetweenDatesPage.tsx`, `frontend/src/routes/index.tsx`, `frontend/src/features/workspace/modules.ts`, `backend/tests/Feature/StockActivityBetweenDatesReportTest.php`.

## RR-5 first implementation slice — 2026-11-08

- Existing `ProductDailyReportsController::profit` already computes product gross-profit from posted sale effective snapshots and immutable stock movement cost snapshots, with strict cost/quantity verification and missing-cost nulls.
- Added `sale_rate = net sale amount / signed sale quantity` and `cost_rate = verified cost amount / signed sale quantity` at 4 decimals. Zero quantity and unavailable cost yield null rather than division by zero or fabricated values.
- Updated `ProductDailyReportPage` profit columns to display Sale Rate / Cost Rate, preserving shared viewer, accepted routes, and net/gross distinction. Updated `ProductDailyReportsTest` assertions for 2 units @ sale 100 / cost 10.
- This is a partial PDF parity improvement, **not** a completed monthly grouping implementation. Existing 31-day date-range supports selecting one month; separate day/month controls and long-range performance remain pending. Focused tests/typecheck not run.

## RR-6 + RR-8 implementation slices — 2026-11-08

**RR-6:** Added `OutstandingBalancesReportController`, `OutstandingBalancesReportPage`, route and menu, plus `OutstandingBalancesReportTest`. Supports customer/vendor linked accounts, 3 visible reference modes, as-of posted branch journal balance using `AccountBalanceCalculator` normal side, bounded pages, authorized contact/address/NTN/license details, last posted sale date for customers. No leaf-account creation or posted-record mutations. **Current/future due remains null by design** until actual due-date + payment allocation verified. Page balance total is page-scoped. Test not run.

**RR-8:** Added `InactiveCustomersReportController`, `InactiveCustomersReportPage`, route/menu and `InactiveCustomersReportTest`. Uses server-tenant/branch-scoped posted customer sales, bounded 1–3650 day inactivity ranges, optional never-sold customers, search, page-level preview/CSV/print; no draft or cancelled sale counts. Area filter/selection UI and having balance criteria remain pending for PDF parity; automated test not run.

**RR-7:** Inspected existing `PartyLedgerController`, `AccountLedgerBuilder` and `VendorCustomerLedgerPage` groundwork: document types, opening-balance, group_by, filter_by, and sale mode already exist. Separate PDF acceptance comparison and focused regression pending; do not duplicate ledger.

**RR-9:** No verified commission percent/per-unit configuration found in inspected `PartyProfile`, `Sale`, `SaleItem`, `SaleReturn` models. Payment amounts must not be invented; block payable commission until a configured rate/source is established.

## Additional implementation and blockers — 2026-11-08

- **RR-2:** Added `PriceListReportPage` (three modes: current sales/purchase rate, below-reorder, below-reorder conversion-factor format) using the existing authorized `ProductStockStatusController` API. Backend now returns permission-masked `purchase_rate` and real `secondary_conversion_factor`, and full-filter category subtotals in `meta.category_totals`. Focused `ProductStockStatusReportTest` checks price masking, reorder variance and category subtotal reconciliation. Current stock only; **historical as-of cost/stock NOT implemented**, and actual carton conversions are intentionally not inferred.
- **RR-5:** Added Current Month / Current Day / Custom period selector to existing product gross-profit report, computed bounded one-month dates, verified sale and cost unit rates, and focused tests. Month is a selected date window, not a separate preaggregated monthly ledger.
- **RR-7:** Existing `VendorCustomerLedgerPage` already has document filters, opening balance options, group_by/filter_by, salesman sale-linked groups, preview/export and various print options. Avoided duplicate UI/API. Still needs PDF acceptance comparison.
- **RR-11:** Inspected Product, StockMovement, StockBalance and attempted StockBatch/ProductBatch/InventoryLot model paths. No verified persisted batch-level expiration **quantity balance** found in scoped inspection. `track_expiry` flag alone cannot drive an accurate expiry report. Do not invent per-lot remaining stock or expiry dates; wait for schema/domain support and safe append-only inventory flow.
- **RR-12:** Existing BarcodePrintingPage and existing helpers were identified; avoid duplicating code. Reference comparison and browser print acceptance pending.
- **All changes are untested in this GitHub-only execution environment.** Existing frozen UI untouched. Do not merge or deploy until regression results are supplied and reviewed.

## RR-2 strict reorder verification — 2026-11-08

- Corrected `below_reorder` filtering: products without a warehouse stock balance count as zero stock and are included **only** when the configured reorder threshold is greater than zero. Existing-balance products must have `stock_balances.quantity < products.reorder_level` (strict inequality). An equal quantity is not below the threshold.
- Added fractional/negative stock, missing balance, equal quantity and zero reorder threshold regression in `ProductStockStatusReportTest` using `bluepos_test` in CI.
- Previous GitHub Actions reports validation run #37 passed its backend focused suite, UI design check, TypeScript and build. **This new change requires another CI result; browser PDF parity remains pending.**
- Scope: `ProductStockStatusController.php`, `ProductStockStatusReportTest.php`; no migration, no main merge.

## RR-3 Product GL — pagination and reference totals regression (2026-11-08)

- Existing Product GL reads immutable stock movements with server-selected tenant, branch and warehouse, opening before start date and debit/credit by signed movement quantity within the period.
- New focused regression covers multiple products across separate pages, all-match opening/debit/credit/closing totals, fractional quantities, negative closing, excluding post-end-date movements and public ULID safety. Grand totals remain unchanged when navigating pages.
- Report viewer CSV/TXT/HTML export now includes a labeled **GRAND TOTAL (ALL MATCHING PRODUCTS)** footer alongside current-page rows. Export deliberately does not claim to include every row when paginated.
- Browser/PDF visual acceptance remains the user's final step. This slice needs CI passing before status is marked validated.
- Changed: `backend/tests/Feature/ProductGlGridReportTest.php`, `frontend/src/pages/ProductGlGridPage.tsx`. No migrations or global CSS changes.

## RR-4 category summary reconciliation — 2026-11-08

- Added server-derived category subtotals for Stock Activity Between Dates from the **same filtered posted stock-movement product summaries**, grouped by actual category ULID (so same-name categories stay distinct). Includes opening, purchases, purchase returns, sales, sale returns, other and closing signed quantities; no independent ledger or new schema.
- Quantity view now shows full-filter category subtotal table across all pages; document-wise cost and Sales & Stock source screens remain separate. Existing grand totals remain unchanged and full-filter scoped.
- Focused `StockActivityBetweenDatesReportTest.php` added for category totals, two-page aggregation, fractional quantities, future-movement exclusion, and existing warehouse/tenant isolation.
- Files: `backend/app/Http/Controllers/Api/Reports/StockActivityBetweenDatesController.php`, `frontend/src/pages/StockActivityBetweenDatesPage.tsx`, `backend/tests/Feature/StockActivityBetweenDatesReportTest.php`. No migration. **Validation and PDF/browser acceptance pending until CI reports completion.**

## RR-4 Document-wise immutable movement detail — 2026-11-08

- The Stock Activity API accepts `view=documents` and returns actual, paginated stock movement records with public movement/product/document ULIDs, recorded reference type and line ULID, signed quantity, signed historical movement cost (only with `inventory.view`), and occurred-at timestamp. This is **not** the old pseudo-document aggregate.
- The existing Stock Activity UI's Document Wise mode now uses the detailed endpoint, shows document links/transaction type, and exports only the current page. Existing summary / category totals remain in other two modes.
- Added `StockActivityBetweenDatesReportTest::test_document_view_returns_real_immutable_movements_not_aggregated_document_columns` for pagination, date exclusion, public-ULID safety, signed quantities/cost and invalid mode validation.
- This still does not equate stock cost with sale revenue or present invoice value; sales-value reference parity requires linking posted sale snapshots. No migration or `main` changes.
- Pending: CI focused tests, frontend checks and final browser/pdf parity. Files: `StockActivityBetweenDatesController.php`, `StockActivityBetweenDatesPage.tsx`, `StockActivityBetweenDatesReportTest.php`.

## RR-5 weighted product gross-profit footer — 2026-11-08

- Corrected Product Gross P/L report footer to match all 8 displayed columns, including **server-derived weighted sale rate, verified cost rate and margin percent**. Per-product rates are never summed.
- Zero signed quantity yields unavailable unit rates; any unverified historical cost makes total cost rate and total margin unavailable instead of inventing values.
- Added `ProductDailyReportsTest` assertions for weighted totals and missing-cost null treatment.
- Source: `ProductDailyReportsController.php`, `ProductDailyReportPage.tsx`, `ProductDailyReportsTest.php`. No migration or `main` change. PDF visual acceptance remains pending; CI must pass before validation claim.
