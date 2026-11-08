# BluePOS Reports — RR-13 release-readiness gate (2026-11-08)

**Branch:** `feature/reports`. **Status:** NOT RELEASE APPROVED. The draft PR remains unmerged; neither `main` nor production must be changed before explicit user review.

## Validated automated scope

The GitHub `.github/workflows/reports-validation.yml` workflow runs against a fresh PostgreSQL 16 **`bluepos_test`** database (not any persistent development or production database), PHP 8.3, npm locked dependencies, the UI design check, TypeScript typecheck and Vite production build.

Expanded RR-13 gate includes:
- Product stock status/reorder, product ledger, Product GL, stock activity, daily product reports, dead stock, purchase expiry reference.
- Outstanding balances, inactive customers, salesman commission posted source, party ledger and cross-branch summaries.
- Restricted-role 403 authorization checks for stock status, product ledger, GL, stock activity, dead stock, expiry reference, product profit, salesman commission, outstanding and inactive customer endpoints.
- Earlier six-report family, daily activity summary, Trial Balance and report-heading tests to detect cross-report regressions.
- Workflow triggers on changes to relevant accounting readers, stock movement/balance models, purchase/sale effective snapshot readers, report controllers/tests, report routes, and frontend.

These checks are necessary but do not independently prove all report PDF output matches reference screenshots, actual browser printer alignment, spreadsheet import safety, or all production user roles. Validate the exact current PR head SHA and workflow run before claiming green release readiness.

## Unresolved development blockers — cannot be marked complete

| Area | Blocker | Required resolution |
| --- | --- | --- |
| RR-4 Sales & Stock | Exact sale-value reference parity is incomplete; movement cost is **not** sale revenue | Link and reconcile effective posted sale/return amounts with inventory movements, including discounts and amendments |
| RR-6 Outstanding Balances | Invoice-level allocations and reliable due-date aging/current vs future due are missing | Implement verified settlement allocations and test posted/reversed receipts without fabricating due amounts |
| RR-9 Salesman Commissions | Commission rates/basis and payout rules are not configured or verified | Define authoritative commission policy, posted adjustments/returns and posting/approval semantics before computing payable commission |
| RR-11 Expiry Stock | No lot-level remaining quantity or allocations from sales, returns, adjustments and transfers | Implement coordinated append-only lot inventory with warehouse/tenant locking, posting, returns, FEFO and reconciliation before releasing available-expiry-stock report |
| RR-12 Barcode Printing | Real label alignment, native print selection and scanner tests not automatically verified | User must compare browser/print previews and physical labels to reference; native print bridge separate |
| RR-2–RR-10 reference parity | Browser/PDF layout, export pages and exact reference formats not completely user accepted | Guided screenshot/print comparison and focused adjustments, preserving frozen UI |

## Final user acceptance before any merge

1. Pull `feature/reports` only after verifying a clean working tree and synchronized branch.
2. Verify permissions for owner, authorized report role and restricted role; confirm tenant/branch/warehouse switching does not leak cached reports.
3. Compare actual report output to supplied reference screenshots/PDFs, with intentional differences for unavailable source data explicitly labeled.
4. Review sales/return/stock/accounting totals with representative posted, amended and reversed cases; ensure all financial values retain required decimal precision.
5. Test CSV formula escaping, print/PDF pagination, barcode label counts and physical printer margins.
6. Review draft PR and all changes; do **not** merge until explicit user approval, all mandatory gate checks pass, and remaining release blockers are resolved or the release scope is explicitly reduced.

**Forbidden:** `migrate:fresh`, `db:wipe`, `truncate`, `drop`, rewriting frozen report UIs, force-pushing, unapproved merge or deployments.
