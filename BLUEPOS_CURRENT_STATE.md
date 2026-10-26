## LIVE ROADMAP — 2026-10-26 (code batch pending local verification)

- Verified baseline: Authentication **35 tests PASS / 191 assertions**; PostgreSQL concurrency **3 PASS / 19 assertions**. New branch reassignment and inactive-branch checks remain UNTESTED locally.
- Production commits: `223eae30` invalidates offline lease when device branch changes; `6986c9eb` rejects lease issue and validation for inactive tenant-owned branch. Test commits `67525068` and `680177f5` cover both. Expected Authentication count **37 tests**.
- Milestones: device/authorization foundation established; **simultaneous HTTP approve/reject pending**; offline lease validation hardened but **offline sync E2E pending**; controlled legacy POS migration pending; final security review pending. Do not count documentation or pull-only updates as progress.
- No migrations/UI changes. Main untouched. See `BLUEPOS_DEVELOPMENT_LOG.md`.

---

## LIVE ROADMAP — 2026-10-26 (offline branch lease hardening pending local test)

- Last user-verified baseline: Authentication **35/35 PASS, 191 assertions**, PostgreSQL concurrency **3/3 PASS, 19 assertions**.
- This code batch: `223eae30` existing offline leases invalidated upon device branch reassignment or branch no longer owned by tenant; `67525068` focused reassignment regression. New AuthenticationTest count **36 expected**, locally unverified.
- Roadmap: actual two-worker HTTP review race **pending**; offline lease validation **advanced** but offline sync E2E **pending** (no `/sync` or `/offline` API in inspected backend routes); legacy POS managed-access migration **pending**; final security review **pending**.
- No migrations/UI changes. Main untouched. Pull and run focused suites on `bluepos_test`. No standalone documentation-only pull intended.

---

## LIVE ROADMAP — 2026-10-26 (new offline security batch awaits tests)

- Latest locally verified baseline: Authentication **34/34 PASS (188 assertions)**; PostgreSQL concurrency **3/3 PASS (19 assertions)**.
- New code: `c777101e` revalidates enabled user and lease-user binding for existing/new offline leases; `5c93b7d9` adds disabled-user regression. Expected Authentication count **35**, **NOT RUN** yet. No migrations or UI changes.
- Roadmap: (1) device/authorization foundation: established and passing before this batch, but **two-worker HTTP race remains outstanding**; (2) offline authorization hardening: expanded, **offline sync E2E outstanding**; (3) legacy managed-access rollout: **pending**; (4) focused final security review: **pending**.
- Next after passing tests: prioritize real concurrent HTTP review harness and offline sync entry-point integration; do not add repeated DB-lock-only probes. Main untouched; temporary `feature/superadmin` continuation.

---

## LIVE CHECKPOINT — 2026-10-26 (combined security implementation awaiting validation)

- Prior user-verified results: Authentication **33 PASS / 187 assertions**; PostgreSQL concurrency **3 PASS / 19 assertions**.
- New implementation: `dd4eaa30` employee access review requires exactly one tenant-scoped pending row transition; `ee78cd19` offline lease validation rechecks operational status against fresh tenant; `1c62e921` regression for suspended tenant invalidating previously valid offline lease.
- Expected `AuthenticationTest.php` **34 tests** (unverified); concurrency unchanged, **3 tests**. Run only on `bluepos_test`.
- Real two-worker authenticated HTTP approve/reject race and offline sync E2E remain pending; controlled legacy POS migration pending. No migrations, no UI changes, main untouched.
- See `BLUEPOS_DEVELOPMENT_LOG.md`. Do not make documentation-only commits unless needed for chat handoff.

---

## LIVE UPDATE — 2026-10-26 (batched security regressions awaiting validation)

- User-confirmed baseline: Authentication 31/31 PASS, 166 assertions; PostgreSQL concurrency 3/3 PASS, 19 assertions.
- Latest code `737e16f0` (preceded by `dc67d651`): two added authentication feature tests group negative cases: suspended employee, revoked device, invalid/missing review decision and owner-membership invalid request. **Not locally validated**; expected AuthenticationTest 33 tests. No production changes or migrations.
- Run combined pull plus AuthenticationTest and PostgreSqlConcurrencyConnectionTest on `bluepos_test`. Keep `feature/superadmin` isolated; main untouched.
- Still pending: actual simultaneous authenticated HTTP approve/reject race, offline sync E2E and controlled legacy policy migration. Existing PostgreSQL probes plus sequential access review tests do not prove HTTP concurrency.

---

## LIVE CHECKPOINT — 2026-10-26 (tests confirmed; HTTP race harness still pending)

- User validated after `27bb8c9f`: `AuthenticationTest.php` **31 passed / 166 assertions**, `PostgreSqlConcurrencyConnectionTest.php` **3 passed / 19 assertions**.
- No production or test-code edits in this checkpoint. The genuine two-worker HTTP approve-versus-reject race is NOT IMPLEMENTED, and prior database probes plus sequential replay tests do not establish HTTP race safety.
- Auth route requires Sanctum + tenant middleware; a real race harness needs independently authenticated workers, committed isolated fixtures, bounded waits, reliable scoped cleanup and `bluepos_test` guard.
- Next: implement **this HTTP race harness**, not another simulated locking test. See `BLUEPOS_DEVELOPMENT_LOG.md`. `main` untouched.

---

## LIVE CHECKPOINT — 2026-10-26 (31 authentication + 3 concurrency PASS)

- User validated at `68e1b88c`: `AuthenticationTest.php` **31/31 passed, 166 assertions**; `PostgreSqlConcurrencyConnectionTest.php` **3/3 passed, 19 assertions**.
- No new backend implementation in this checkpoint. Genuine simultaneous HTTP `POST /api/devices/access-requests/{requestUlid}/review` approve/reject is **still pending**; existing tests cover SQL lock contention and sequential replay only.
- Next task: isolated worker-based HTTP race harness with committed tenant/member/device/request fixtures, scoped cleanup, synchronization and bounded timeouts. Test only on `bluepos_test`, never dev/persistent DB; document actual results and next action.
- Log updated; `main` untouched.

---

## LIVE CHECKPOINT — 2026-10-26 (approved-review replay test awaiting validation)

- Last user local validation: PostgreSQL concurrency **3/3 passed, 19 assertions**; AuthenticationTest **30/30 passed, 159 assertions**.
- New test commit `a2552653`: sequential approved→rejected replay must return 409 ACCESS_REQUEST_ALREADY_REVIEWED, preserving approved request and active device. No production code/migrations. Expected AuthenticationTest **31 tests**, not yet run locally.
- Next after PASS: real independent-process simultaneous approve/reject HTTP race test with committed scoped fixtures; existing database lock probes and sequential replay tests do not prove this.
- Keep `bluepos_test` only; preserve `feature/superadmin` as temporarily approved continuation, do not merge main without explicit approval. History in `BLUEPOS_DEVELOPMENT_LOG.md`.

---

## LIVE CHECKPOINT — 2026-10-26 (decision collision regression awaiting local run)

- User verified: PostgreSQL concurrency probes **2/2 tests, 11 assertions**; Authentication **30/30 tests, 159 assertions**.
- New commit `b5c15687`: test two independent PostgreSQL connections competing over a pending `devices` row; second fails NOWAIT while first decision is uncommitted, then sees committed winner and conditional losing write affects no rows. **Not yet executed locally.**
- Test is database-level only, **NOT** an HTTP endpoint concurrency verification. Genuine simultaneous `reviewAccessRequest` approve/reject authorization test with committed tenant/membership/request fixtures remains pending.
- Pull branch, run `PostgreSqlConcurrencyConnectionTest.php` (expected 3 tests) plus `AuthenticationTest.php` (expected 30 tests). Only `bluepos_test`. Main untouched. See `BLUEPOS_DEVELOPMENT_LOG.md`.

---

## LIVE CHECKPOINT — 2026-10-26 (verified probes; domain race pending)

- User's local results after `b7205020`: **2/2 PostgreSQL concurrency tests, 11 assertions** and **30/30 AuthenticationTest, 159 assertions**, all PASS.
- Real two-connection advisory and device-row locking verified on `bluepos_test`; **simultaneous approve/reject HTTP workflow still NOT tested**.
- Inspected existing controller lock order and test fixture setup. Current `DatabaseTransactions` tests cannot expose uncommitted records to concurrent connections. Next task requires a safe committed-fixture lifecycle and separate worker/process coordination, timeout protection, deterministic cleanup, then genuine opposing review decisions with exactly-one-winner expectation.
- No new domain test in this checkpoint; only docs history updated. Do not claim scope completed.
- See `BLUEPOS_DEVELOPMENT_LOG.md`; main untouched.

---

## LIVE UPDATE — 2026-10-26 (device row-lock test awaiting local run)

- Last user-provided results: `PostgreSqlConcurrencyConnectionTest.php` 1 passed / 6 assertions; `AuthenticationTest.php` 30 passed / 159 assertions.
- New commit `5d15492e`: second PostgreSQL probe verifies actual `devices` row lock conflicts (NOWAIT SQLSTATE `55P03`) between independent connections, and lock release after rollback. Uses `bluepos_test` committed isolated fixture and finally cleanup. **Not yet run.** No migrations or production changes.
- Next: user pull + run both focused test files. After PASS, actual simultaneous approve/reject HTTP workflow with committed fixture, cross-process coordination and bounded waits remains pending; do not present row-lock probe as end-to-end race coverage.
- `BLUEPOS_DEVELOPMENT_LOG.md` updated; `main` untouched.

---

## LIVE UPDATE — 2026-10-26 (two-connection probe pending validation)

- User locally confirmed **30/30 AuthenticationTest tests, 159 assertions** after `e490a322`.
- Created `backend/tests/Feature/PostgreSqlConcurrencyConnectionTest.php` at `fb609e32`: two independent PostgreSQL connections, PID independence, advisory transaction-lock contention and rollback release. **This is a concurrency test harness/probe, not the actual device approve/reject race regression.** Local test not yet run.
- Test must use `bluepos_test`; no migrations. Next: pull, run probe then AuthenticationTest; after PASS, implement actual device review/approval race test with independent committed fixtures and bounded concurrency. Continue offline sync E2E and controlled legacy rollout afterwards.
- See `BLUEPOS_DEVELOPMENT_LOG.md` for chronological commits. `main` untouched.

---

## LIVE UPDATE — 2026-10-26 (pending local validation)

- Previous user validation at `44a7b8c5`: **29 authentication tests passed, 154 assertions**.
- Latest security change: `dc26a1a5` controller and `a144b177` regression. Pending generic device approval rechecks freshly locked tenant's operational status and plan limit. **No migrations.**
- New local test **not run**; expected `AuthenticationTest.php` **30 tests**, actual results pending.
- Run pull + `php vendor/bin/phpunit tests/Feature/AuthenticationTest.php` on `bluepos_test`.
- Next after passing: genuine independent-connection PostgreSQL approve/reject race test; current new regression is not a substitute for real parallel testing. Then offline sync E2E and controlled legacy access migration.
- Documentation log: `BLUEPOS_DEVELOPMENT_LOG.md`. Main untouched.

---

## LIVE HANDOFF — 2026-10-26 (latest verified)

**Active work:** Super Admin / device security / offline authorization hardening. **Existing working branch:** `feature/superadmin` (temporary continuation approved by user; not one of the five standard long-lived branches). Do not merge to `main` without review/approval. Local working-tree cleanliness has NOT been verified.

**Last verified commit:** `3de2c137` (test correction for unwrapped device JSON collection). **User local validation:** `php vendor/bin/phpunit tests/Feature/AuthenticationTest.php` — **28 passed, 148 assertions** (PHP 8.3.33, PHPUnit 12.5.35). Focused success does not certify entire security module or independent-connection concurrency.

**Verified work via previous conversation:** session revocation freshness; offline authorization fresh checks; branch/warehouse device assignment consistency; suspended tenant lease issuance guard; legacy vs managed device policy exposed without changing access. Commits in `BLUEPOS_DEVELOPMENT_LOG.md`.

**Immediate next technical task:** inspect existing device approval/rejection flows and test DB isolation setup; design a *real independent PostgreSQL connection* approve-vs-reject race regression using `bluepos_test` only. Do not treat sequential DatabaseTransactions tests as proof of races. Then offline sync E2E security; then controlled legacy POS enforcement rollout (never lock out legacy terminals without an approved migration).

**Session-to-session protocol:** read this section first, then `BLUEPOS_DEVELOPMENT_LOG.md` and minimal code. After each validated batch, update this live section + append log entry in the same small documentation batch. Record commit, files, exact local/test status and next action. Do not claim local tests passed until user supplies output. Preserve historical handoffs below; older recommendations may be superseded by this dated live checkpoint.

---

# BluePOS — Current State / Active Handoff

_Last updated: 2026-10-04_

## Active Module

**Sales Invoice**

Route:
- `/daily/sales`

The current Sales Invoice UI design is approved and frozen.

Do not redesign:
- overall page geometry
- ribbon/shell
- field positions
- product grid structure
- right payment panel
- **Amounts section geometry**

---

## Current Sales Phase Status

### SALE-1A — Pricing / Unit / Barcode Foundation
Status: **COMPLETE**

Implemented:
- Retail/Wholesale server-side pricing
- Default remains backward-compatible
- barcode resolves Product + Unit
- barcode conversion factor
- selected unit support
- base-stock quantity conversion
- decimal-unit validation
- pricing/unit snapshots

Confirmed tests:
- `SalePricingAndUnitTest`
  - 4 passed
  - 42 assertions

---

### SALE-1B — Discount / Tax / Amounts
Status: **COMPLETE**

Implemented:
- gross amount
- discount %
- fixed discount amount
- derived discount percent
- product tax
- tax amount
- net amount
- server-side recalculation
- invalid dual-discount rejection
- fixed discount cannot exceed gross

Confirmed tests:
- `SaleDiscountTaxTest`
  - 4 passed
  - 36 assertions

Regression:
- `CreateSaleTest`
  - 14 passed
  - 120 assertions

Combined confirmed backend result:
- **22 tests passed**
- **198 assertions**

---

### SALE-2 — Frontend Sales Wiring
Status: **IMPLEMENTED**

Implemented:
- Default / Wholesale / Retail
- barcode path
- selected unit
- quantity
- price
- AMT
- Disc %
- Disc Rs
- tax preview
- Net Amt
- scheme evaluation with discounted subtotal
- hold-cart price mode persistence

Important:
- Existing Sales Invoice design was preserved.
- Amounts geometry was preserved.

---

### SALE-3A — Real Warehouse Stock
Status: **IMPLEMENTED IN UI**

Implemented:
- real stock lookup using:
  - `GET /api/products/{productUlid}/stock`
- active warehouse quantity
- display stock in selected unit
- conversion-factor-aware display

Example:
```text
Base stock = 20 PCS
Carton factor = 10

PCS selected    → In Stock = 20
Carton selected → In Stock = 2
```

Frontend also attempts a pre-save stock warning when negative stock is disallowed.

Backend remains final stock authority.

Validation after the latest UI changes should be re-run when continuing work:
```powershell
cd C:\laragon\www\bluepos\frontend
npm run typecheck
npx vite build
```

---

## Current Sales UI Bugs

These are the immediate active bugs to fix next.

### Bug 1 — Product suggestions
Current behavior needs refinement.

Required behavior:
- as soon as the user types in product input, show matching products
- do not require Enter before showing suggestions
- clicking a suggestion adds it
- Enter may add/select the first/highlighted suggestion
- avoid blocking loader during typing

---

### Bug 2 — Editable input contrast

Current issue:
- Sales Qty input can have white background + white text
- value becomes unreadable

Required behavior:
- all editable fields must have readable contrast
- light background → dark text
- dark background → light text
- yellow editable background → dark text

Apply to Sales Invoice editable inputs including:
- product entry
- Sales Qty
- Disc %
- Disc Rs
- Received
- Remarks
- Payment Reference
- other editable fields

Must work with:
- Light / Dark
- Classic / Hybrid / Advanced

Do not broadly rewrite global CSS.

Prefer Sales Invoice page CSS only.

---

### Bug 3 — Loader behavior

Current requirement:
- no blocking loader while:
  - searching products
  - typing product name
  - entering Sales Qty
  - entering Disc %
  - entering Disc Rs
  - local form edits

Loader/busy feedback should be limited to explicit actions such as:
- Save
- Refresh
- Preview
- Print
- other deliberate async actions

Search suggestions may use a small non-blocking inline indicator only if truly necessary.

---

### Bug 4 — Database Error

Current screen can show:

```text
An unexpected database error occurred. Please try again.
```

This must be traced separately.

Do not assume the cause.

When debugging:
1. reproduce
2. inspect Laravel log
3. inspect failing endpoint
4. inspect SQL/exception
5. fix only the real root cause
6. add a regression test if applicable

---

## Current Sales Screenshot State

Latest observed working behavior:
- real In Stock value appears
- product row appears
- price calculation appears
- discount amount affects Net Amt
- total/balance update on screen

Example observed row:
- product: `00001 — coca-cola2`
- In Stock: `1000.000`
- Price: `300.00`
- AMT: `300000.00`
- Disc Rs: `10.00`
- Net Amt: `299990.00`

This confirms the stock + amount UI wiring is active.

---

## Current Important Sales Backend Files

Key files already involved:

```text
backend/app/Actions/Sales/CreateSaleAction.php
backend/app/Http/Requests/Sales/StoreSaleRequest.php
backend/app/Models/Sale.php
backend/app/Models/SaleItem.php
backend/app/Http/Resources/Sales/SaleResource.php
backend/app/Http/Resources/Sales/SaleItemResource.php
backend/app/Models/Product.php
backend/app/Models/ProductBarcode.php
backend/app/Models/ProductPrice.php
backend/app/Models/Unit.php
backend/app/Actions/Inventory/PostStockMovementAction.php
backend/app/Http/Controllers/Api/Inventory/StockController.php
```

Sales routes currently include:
```text
GET  /api/sales
POST /api/sales
GET  /api/sales/{saleUlid}
GET  /api/sales/{saleUlid}/payments
POST /api/sales/{saleUlid}/payments
```

Product stock route:
```text
GET /api/products/{productUlid}/stock
```

---

## Current Important Sales Frontend Files

```text
frontend/src/pages/SalesInvoicePage.tsx
frontend/src/pages/SalesInvoicePage.theme.css
frontend/src/pages/SalesInvoicePage.cart.css
frontend/src/features/sales/useSaleCart.ts
frontend/src/features/sales/saleReceipt.ts
frontend/src/features/sales/SalePaymentPanel.tsx
frontend/src/features/sales/SchemeOfferPrompt.tsx
frontend/src/features/sales/PackagingPicker.tsx
frontend/src/features/sales/SalesInvoiceHistory.tsx
frontend/src/api/sales.ts
frontend/src/api/catalog.ts
frontend/src/types/sales.ts
frontend/src/types/catalog.ts
frontend/src/features/gridLayout/columnCatalog.ts
```

---

## Current Stock / Batch Reality

Current stock balances are aggregate product/warehouse balances.

Current stock layer does not safely preserve:
- batch-wise remaining quantity
- expiry-wise remaining quantity

Purchase line data has:
- `batch_number`
- `expiry_date`

But that is not enough to safely sell by lot.

Therefore:
- do not add fake batch dropdowns
- do not guess FEFO/FIFO lots
- do not claim expiry-safe sales yet

A real lot/batch inventory layer is required first if this feature is pursued.

---

## Other Module Freeze State

### Frozen for now
- Define Products
- Barcode Printing
- Purchase Invoice
- Vendor / Customers / Accounts
- Sales Invoice visual design

### Do not revisit unless requested
- large landing-page redesign threads
- broad appearance redesign
- unrelated catalog refactors

---

## Manual Collaboration Rule

Because Cursor usage may be limited, continue in small manual steps.

Preferred flow:

1. user uploads exact existing files
2. inspect those files only
3. provide exact replacement/new files
4. for each file show:

```text
REPLACE
path/to/file
download link
```

then next file

5. run focused tests
6. user posts output
7. continue to next phase

Avoid large multi-module patches.

---

## Next Immediate Task

Fix these 3 Sales UI bugs first:

1. live product suggestions while typing
2. readable editable input colors
3. loader only for explicit actions

Files expected to be relevant:
```text
frontend/src/pages/SalesInvoicePage.tsx
frontend/src/pages/SalesInvoicePage.theme.css
frontend/src/pages/SalesInvoicePage.cart.css
frontend/src/api/client.ts
frontend/src/feedback/FeedbackProvider.tsx
```

After those are stable:
- trace current Sales database error
- then continue remaining Sales roadmap

---

## Vendor / Customer Ledger — Current State (2026-10-11)

Feature branch:
```text
feature/vendor-customer-ledger
```

Route:
```text
/daily/ledger
```

### Implemented and verified

Core ledger:
- Vendor / Customer / Account selection
- multi-account checkbox selection
- Select All
- Show Balances
- Ledger for All Dates
- From / To date range
- Don't Include Opening Bal
- In Reverse Order
- Without Heading
- Book No Also
- Remarks Font Size
- Preview
- Print
- Each Ledger on Separate Page
- full multi-page ledger fetch beyond the first 100 rows

Supported narration modes:
- Remarks
- OnlyDesc

Supported document filters:
- PURCHASES
- PURCHASE RETURN
- PAYMENT VOUCHER
- RECEIVING VOUCHER
- OPENING VOUCHER
- JOURNAL VOUCHER
- SALE PAYMENT

Currently unavailable document filters:
- SALES INVOICE
- SALES RETURN

Reason:
- BluePOS does not yet have complete posted Sales Invoice revenue/COGS journal posting.
- Sales Return does not yet have a complete posted accounting journal flow.
- These filters must stay disabled until real accounting journals exist.

Grouping / filtering:
- Document
- Date
- User Name
- Area Name
- Sales Person Name

Show Only:
- All
- Non-Whole Sale
- Whole Sale

Important scope note:
- Whole Sale / Non-Whole Sale and Sales Person use sale-linked posted ledger rows.
- They do not create or replace missing Sales Invoice accounting journals.

Subgroup behavior:
- Don't Show Total (Subgroup)
- Reset Total (Subgroup)
- subgroup totals
- subgroup running balance

Batch balances:
- POST `/api/parties/ledger-balances`
- tenant scoped
- public ULIDs only
- inaccessible / cross-tenant ULIDs do not leak balances

Print:
- multi-ledger print works
- `Each Ledger on Separate Page` verified in Chrome Print Preview
- separate selected ledgers correctly render as separate pages

### Salesman schema reconciliation

Current Sales code uses:
```text
sales.salesman_party_profile_id
```

A compatibility migration was added:
```text
backend/database/migrations/2026_10_11_120000_add_salesman_party_profile_to_sales_table.php
```

Behavior:
- additive only
- tolerates databases where `salesman_party_profile_id` already exists
- does not rewrite existing salesman data
- rollback intentionally does not drop a possibly pre-existing persisted salesman column

Validated:
```text
Tests\Feature\PartyLedgerTest
3 passed, 109 assertions

Tests\Feature\CreateSaleTest
23 passed, 244 assertions

Tests\Feature\CollectSalePaymentTest
16 passed, 144 assertions
```

Frontend validation:
- TypeScript typecheck passed
- Vite production build passed
- known chunk-size warning remains; no functional failure

### Intentionally disabled / deferred controls

These controls are visible for POS PLUS reference alignment but intentionally disabled because BluePOS currently has no reliable matching persisted semantics or data source.

#### User Defined Narration
Reason:
- no separate user-defined ledger narration field exists
- current real sources are journal line description / remarks and journal entry description

Available alternatives:
- Remarks
- OnlyDesc

#### Detail1 / Detail2 / Detail3 / DetailALL
Reason:
- journal entries / lines do not have separate Detail1/2/3 persisted fields
- do not fabricate these values

#### Auto Sale Man
Reason:
- no stored automatic account-to-salesman selection rule exists
- Sales Person grouping/filtering is available for sale-linked rows

#### Treat as Sales Officer
Reason:
- no separate Sales Officer relation is stored on posted ledger journals
- do not equate Sales Person and Sales Officer without an explicit business rule

#### ZM / ZM Selection
Reason:
- no ZM entity, table, role, or persisted mapping exists in the current BluePOS model
- exact POS PLUS semantics are unknown

#### Main Area / Category grouping
Reason:
- matching source data / relationship is not currently available in the ledger source model

#### Alternate ledger layouts
Currently implemented:
- Summary Ledger

Disabled:
- Detailed Ledger (Short Form)
- Invoice - Receipt Ledger
- Category Wise VC Ledger
- Detailed Ledger (Landscape)
- Vochers Seperate Ledger
- Above List View
- Ledger With Product Grouping
- POS Ledger

Reason:
- exact report columns, grouping, totals, pagination, and print semantics are not fully mapped
- do not present unsupported layouts as functional

### Important design / safety rules for future ledger work

- Preserve current approved compact ledger geometry unless explicitly changed.
- Continue using BluePOS canonical UI components and Theme & Appearance tokens.
- Public APIs expose ULIDs only.
- Never expose BIGINT IDs.
- Keep tenant / branch isolation and RBAC server-authoritative.
- Recompute financial values server-side.
- Posted journals remain immutable.
- Do not enable Sales Invoice / Sales Return ledger sources until real posting exists.
- Do not invent ZM, Sales Officer, custom narration, or Detail1/2/3 semantics.

### Recommended next move

The Vendor / Customer Ledger feature is review-ready.

Before starting another feature:
1. verify `feature/vendor-customer-ledger` working tree is clean
2. fetch origin and confirm branch is not behind `origin/main`
3. review the branch diff
4. after explicit approval, perform the normal safe branch handoff and fast-forward merge to `main`
5. only then create the next feature branch

If the goal is to make the Ledger more complete, the next high-value technical phase should be:

```text
Full Sales accounting posting
```

That phase should cover:
- posted Sales Invoice revenue journal
- COGS journal
- customer receivable / settlement behavior
- Sales Return accounting reversal / return journals
- idempotency
- transaction safety
- posted immutability
- focused financial and tenant-isolation tests

Only after that should:
- SALES INVOICE ledger filter
- SALES RETURN ledger filter
- broader Sales Person / sales-ledger reporting

be enabled.

This is financial/accounting work and should be handled as a separate SOL-level phase on a dedicated branch.


