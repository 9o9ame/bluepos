# BluePOS — Next Reports Milestones (2026-11-05)

**Branch:** `feature/reports` only. **Status:** Planning recorded; implementation pending.
**Source references:** User-uploaded Advance Sales View JPEG; Day Book, Product Wise Transactions, Detailed Transactions, FBR Sales Register, and FBR Purchase Register ZIPs (screenshots plus 5 PDFs and one XLSX in these packs). Source samples illustrate workflows, not proof of BluePOS schema or current FBR compliance.
**Design authority:** `PROJECT_UI_DESIGN_SYSTEM.md`. Preserve existing frozen and user-accepted Daily Reports, Sales Invoice and Purchase Invoice. Use `UiSelect`, `UiButton`, `PosDataGrid`, and `ReportViewerToolbar` as applicable.

## Scope — six distinct report families

| Code | Reference report | Reference evidence / core contract |
| --- | --- | --- |
| NR-1 | **Advance Sales View** | Supplied JPEG: filter From/To; transaction #, date, product description, sold qty, Stock@, current stock, rate, amount; bottom totals. Confirm meaning of Stock@ and historical vs current stock before implementing; do not copy misleading negative figures. |
| NR-2 | **Day Book (Daily Business Summary)** | Selection screenshot and `daily-business-report(daily book).pdf`: transaction-type checkboxes, date range, grouped payments/expenses, purchases, sales, returns/vouchers and subtotals; `daily-business-report(daily book)-ledger.pdf`: optional account ledger with opening, debit, credit, running and closing balance. |
| NR-3 | **Product Wise Transactions (Grid)** | Grid+filter+preview/export screenshots; PDF `product-wise-transactions.pdf`, XLSX `ProductTransactions.xlsx`: date range, document type(s), transaction/document/date, vendor/customer, category, company/brand, product, batch/vendor (if supported), quantity +/- and amount. |
| NR-4 | **Detailed Transactions Wise Report** | 7 screenshots plus `transaction-detail-summary.pdf`, `transaction-detail-day-by-day.pdf`, `transaction-detail.pdf`: document selection, account/party selection, grouping, summary by party, calendar day-by-day monthly matrix, transaction-level invoice lines, and print preview. These are three formats of a single report family. |
| NR-5 | **FBR Sales Register** | Three screenshots: date range selection, multi-select item picker and Short/Invoice options. Actual statutory column layout and export schema are **not provided**. Determine current applicable FBR jurisdiction, registration and official requirements before advertising compliance. |
| NR-6 | **FBR Purchase Register** | Three screenshots: same date/item selector and Short/Invoice workflow for purchase transactions. Statutory column layout and mandatory identifiers remain to be verified from official requirements. |

## Delivery milestones

| Milestone | Work | Definition of done | Status |
| --- | --- | --- | --- |
| **NR-0** | Reference-to-BluePOS mapping, existing route/model/data inspection, financial meaning, permissions, and UI layout constraints | Six report contracts + reuse matrix documented; ambiguities named without guessing | **IN PROGRESS** |
| **NR-1** | Shared read-only reporting foundation | Server-scoped date/document/party/product filters; stable public ULIDs; zero write actions; shared viewer/exports; focused validation for 403, tenant/branch/warehouse and date ranges | PLANNED |
| **NR-2** | Advance Sales View | Transaction-line grid, posted sales/returns/amendment behavior, audited quantity/rate/amount, stock column semantics reconciled, filters/totals; no mutation of existing product ledger | PLANNED |
| **NR-3** | Day Book + optional ledger | Grouped document sources and exact debit/credit totals; opening/running/closing from existing journal flow; date/status, reversal, amendments and duplicate prevention verified | PLANNED |
| **NR-4** | Product Wise Transactions | Purchase/sale/returns/adjustment movement data, document filters, product/category/brand, base-unit precision and reconciliation with existing stock ledger, preview/CSV/print | PLANNED |
| **NR-5** | Detailed Transactions (three views) | Party summary, calendar day-by-day, detailed invoice/transaction report; posted-document totals reconcile with actual source snapshots and date grouping | PLANNED |
| **NR-6** | FBR Sales + Purchase Registers | Official field/format requirements verified first; tenant registration/tax configuration checked; taxable/non-taxable/returns/credit-note handling; no fabricated NTN/STRN or false compliance claim | PLANNED |
| **NR-7** | End-to-end focused regression + browser acceptance | Tests on `bluepos_test`; frontend typecheck/build, CSV formula injection safety, export+print, pagination/performance, cross-tenant and role tests; user browser signoff on six reports; documentation updated; main unmerged until approval | PLANNED |

## Sequencing and controls

1. NR-0 must finish before changing routes/data calculations. Prefer smallest data-source inspections and existing snapshots; do not create duplicate accounting/stock flows.
2. NR-1 shared contract then NR-2 → NR-3 → NR-4 → NR-5. NR-6 requires official FBR requirements and an explicit distinction between *reference-style register* and *statutory-compliant export*.
3. Changes remain on `feature/reports` with user-approved continuous milestones. Each implementation slice includes tests and a precise file list. If a <=5-file estimate expands beyond 7 files, stop and explain scope.
4. Never use floats for amounts; amount NUMERIC(20,4), quantity NUMERIC(20,6), factors NUMERIC(12,8). All totals recomputed server-side. Do not include BIGINT IDs or trust client tenant/branch/warehouse IDs.
5. Do not modify or repost posted invoices/journals/stock; no destructive DB/Git commands. Restrict PHPUnit to `bluepos_test`. Browser acceptance is the only planned user checkpoint.
6. Current four accepted Daily Reports remain untouched, unless a new bug requires separately reviewed localized changes.

## Initial investigation findings

- `frontend/src/features/workspace/modules.ts` already has disabled menu placeholders for Day Book, Product Wise Transaction, Detailed Transactions, FBR Sales Register and FBR Purchase Register; Advance Sales View is a disabled ribbon command.
- `backend/routes/api.php` has existing reporting endpoints including Cash Book, Daily Activity, Product Stock Status and Product Ledger; no new six-report endpoints established during this milestone planning.
- `PROJECT_UI_DESIGN_SYSTEM.md` explicitly mandates the shared report viewer, theme tokens and preserved frozen UI.
- Existing product/stock reports and accounting journals are reuse candidates, **not** evidence that the new six reports are implemented.
- Need verify FBR reporting obligations against current official guidance at implementation time; screenshots alone are insufficient for authoritative tax register calculations.
