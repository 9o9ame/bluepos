# BluePOS — Next Reports Milestones (2026-11-05)

**Branch:** `feature/reports` only. **Status:** Six report families implemented in source; focused PHPUnit, typecheck/build and browser acceptance remain pending. FBR outputs are **internal review registers** and are NOT filing-ready.
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
| **NR-0** | Reference-to-BluePOS mapping, existing route/model/data inspection, financial meaning, permissions, and UI layout constraints | Six report contracts + reuse matrix documented; ambiguities named without guessing | **COMPLETED — scoped inspection** |
| **NR-1** | Shared read-only reporting foundation | Server-scoped date/document/party/product filters; stable public ULIDs; zero write actions; shared viewer/exports; focused validation for 403, tenant/branch/warehouse and date ranges | **IMPLEMENTED — tests pending** |
| **NR-2** | Advance Sales View | Transaction-line grid, posted sales, movement-linked Stock@, current warehouse balance, rates/amounts, filters/totals; amended lines without matching movement are explicitly unavailable | **IMPLEMENTED — browser pending** |
| **NR-3** | Day Book + optional ledger | Grouped posted documents, separate posted voucher groups; optional account journal opening/running/closing (no double counted invoice totals) | **IMPLEMENTED — financial tests pending** |
| **NR-4** | Product Wise Transactions | Append-only movements, transaction-type/product/date filters, matching document references/party where authorized, cost-column guard, preview/CSV/print | **IMPLEMENTED — coverage/filters pending** |
| **NR-5** | Detailed Transactions (three views) | Party summary, date-column day-by-day matrix, detailed posted document lines, effective snapshots and signed returns | **IMPLEMENTED — browser pending** |
| **NR-6** | FBR Sales + Purchase Registers | Internal review registers with short/invoice mode, signed returns, line taxes and party registration if present. **Official filing/digital invoicing compliance NOT implemented**; needs statutory mapping/integration review | **INTERNAL VIEW IMPLEMENTED — compliance pending** |
| **NR-7** | End-to-end focused regression + browser acceptance | Focused tests added at `backend/tests/Feature/NextReportsTest.php`; local PHPUnit on `bluepos_test`, frontend typecheck/build and browser signoff still required | **PENDING LOCAL VERIFICATION** |

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

## 2026-11-05 — Implementation update (GitHub only)

- **Added:** `backend/app/Reports/NextReportDocumentReader.php` (effective posted document snapshots) and `backend/app/Http/Controllers/Api/Reports/NextReportsController.php` (six read-only report APIs).
- **Added:** `frontend/src/pages/NextReportsPage.tsx` (shared selection, table previews, exports, Day Book ledger, Detailed Transactions date matrix).
- **Added:** `backend/tests/Feature/NextReportsTest.php` (auth, validation, posted sale, FBR internal mode, ledger guard and tenant filter cases).
- **Updated:** `backend/routes/api.php`, `frontend/src/routes/index.tsx`, `frontend/src/features/workspace/modules.ts`, `frontend/src/pages/DailyReportSheets.css`.
- **Static review:** All new files fetched back from branch; routes, menu paths and paired bracket counts checked. This is **not** execution of PHP/Laravel or TypeScript/Vite.
- **Important limitations:** Advance Sales may show unavailable historical Stock@ for amendments with no matching immutable movement line. Product transaction cost is stock movement cost, never fabricated sale revenue. Detailed report day-by-day uses actual document dates; category/brand historical snapshots are not invented. Day Book ledger includes only posted journal entries. FBR review register does not implement official Annex file upload, digital invoice/HMAC/integrator workflow, complete statutory discount allocations or certification.
- **Official references consulted:** `https://fbr.gov.pk/categ/sales-tax-records/51148/50854/121149` (Sales records), `https://fbr.gov.pk/categ/sales-tax-records/51148/50854/121150` (Purchase records), `https://fbr.gov.pk/categ/sales-tax-rules-2006/302` (rules index), `https://fbr.gov.pk/faqs/173967/173969` (digital invoice integration FAQs).
- **Next checkpoint:** Pull `feature/reports`; run focused PHPUnit on `bluepos_test` and frontend typecheck/build. Resolve failures before accepting all six browser previews. No branch switch, migration, persisted DB modifications or merge to `main`.
