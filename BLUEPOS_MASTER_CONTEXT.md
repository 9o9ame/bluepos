# BluePOS — Master Project Context

_Last updated: 2026-10-04_

## 1. Project Overview

BluePOS is a commercial multi-tenant POS/ERP system being built for retail/wholesale businesses.

The product direction is inspired by an existing desktop POS workflow, but BluePOS is a modern web/PWA application. The goal is to preserve fast desktop-style POS interaction while using a secure Laravel/React architecture.

Current primary focus: **Sales Invoice**.

---

## 2. Technology Stack

### Frontend
- React
- TypeScript
- Vite
- Tailwind/CSS
- React Query
- PWA / standalone desktop-style install
- Fullscreen support

### Backend
- Laravel 13
- PHP 8.3+
- REST APIs
- Laravel Sanctum SPA session authentication

### Database
- PostgreSQL 16+

### Other Architecture
- Shared-schema multitenancy
- Tenant/branch/warehouse context
- RBAC
- Device/session security
- Audit events
- PHPUnit backend tests

---

## 3. Core Architectural Invariants

These rules must not be broken.

### Identity
- Internal database identity may use BIGINT.
- Public APIs expose ULIDs only.
- Never expose internal BIGINT IDs through public APIs.

### Tenant / Branch / Warehouse Security
- Never trust tenant_id from the client.
- Never trust branch_id or warehouse_id from the client when server session/context can resolve them.
- All tenant-owned records must remain tenant-scoped.
- Cross-tenant ULID access must return a safe not-found/forbidden result according to existing conventions.

### Numeric Types
- Money: `NUMERIC(20,4)`
- Quantities: `NUMERIC(20,6)`
- Percentages / conversion factors: `NUMERIC(12,8)`

### Accounting
- Posted financial documents are immutable.
- Journal posting must balance.
- Do not invent hidden control accounts.
- If a required control account is missing, fail explicitly.

### Inventory
- Stock movements are immutable / append-only.
- Reuse the existing stock movement architecture.
- Do not create a second stock system.
- Stock validation must remain server-authoritative.

### Database Safety
Never run destructive commands on persistent databases:
- `migrate:fresh`
- `db:wipe`
- truncate
- drop-all / destructive reset

Test database:
- `bluepos_test`

---

## 4. Development Workflow

BluePOS is now often developed manually, file-by-file.

Preferred workflow:

1. Inspect only the files directly required.
2. Reuse existing architecture.
3. Make the smallest safe change.
4. Add focused tests.
5. Run focused tests before wider suites.
6. Preserve unrelated modules.
7. Avoid broad refactors.
8. Never replace working API data with fake/local demo data.

When giving manual code files, use this exact presentation order:

```text
REPLACE or NEW
path/to/file.ext
download/file
```

Then immediately move to the next file.

Do **not** list all paths first and all files later.

---

## 5. UI / Design Rules

BluePOS has an appearance system:

### Theme
- System
- Light
- Dark

### Interface Style
- Classic
- Hybrid
- Advanced

Theme and interface style are independent.

### CSS-First Cleanup Rule
Before starting UI or responsiveness work on any new module or page, inspect the relevant page/module CSS against the canonical global design system and shared components first.

Required workflow:
- identify duplicate, legacy, inline, or conflicting page-level CSS before adding new responsive rules
- verify shared/global sources first, especially `PROJECT_UI_DESIGN_SYSTEM.md`, `appearance.css`, canonical component CSS, and existing shared layout/density rules
- remove or neutralize only the relevant conflicting/dead local overrides; do not perform unrelated CSS cleanup
- shared components such as `UiSelect`, `UiButton`, `PosDataGrid`, and `PosEntryGrid` own their visual chrome and states; page CSS should control layout/geometry only unless a documented exception exists
- do not fix a global/shared visual property by repeatedly overriding it per page
- after cleanup, apply the smallest page-scoped responsive/layout change required
- preserve frozen/approved geometry unless the user explicitly requests a redesign

This CSS-first inspection/cleanup step is mandatory before UI changes on a new page/module.

### Frozen UI Rule
Once a page/section is approved, its design is frozen unless the user explicitly asks to redesign it.

For frozen screens:
- preserve geometry
- preserve positions
- preserve field order
- preserve accepted spacing
- only make minimal functional/bug-fix changes

### Important Frozen Areas
- Define Products — frozen for now
- Barcode Printing — frozen for now
- Purchase Invoice — approved/frozen
- Sales Invoice — approved/frozen
- Sales Invoice **Amounts section geometry** must not change

---

## 6. Completed Platform / Foundation Work

Completed major areas include:

### Authentication / Tenancy
- Tenant model
- Membership model
- Session SPA auth
- Sanctum
- `/api/auth/me`
- tenant context
- active branch / warehouse

### RBAC
- Roles
- Permissions
- membership roles
- owner protection
- tenant isolation
- IDOR protections

### Security / Device Lifecycle
- approved device flow
- staff lifecycle controls
- session revocation
- admin MFA / OTP foundation
- audit events

### Platform / Administration
- Super Admin foundation
- tenant provisioning
- plans/subscriptions
- appearance preferences

### Branches / Warehouses
- branches
- warehouses
- defaults
- membership branch access
- branch switching

---

## 7. Catalog / Product Foundation

Completed:
- Products
- Categories
- Subcategories
- Brands
- Units
- Suppliers
- Multiple product barcodes
- Barcode → unit mapping
- Conversion factors
- Product prices
- Product image support
- Product stock lookup
- Opening stock

Key product concepts:
- base unit
- optional secondary unit
- multiple barcodes
- barcode may map to a unit
- barcode conversion factor
- retail price
- wholesale price
- minimum sale price
- product tax settings

Define Products and Barcode Printing are currently frozen.

---

## 8. Purchasing / Inventory

Completed:
- Purchase Invoice
- Purchase Return
- Posting
- Inventory movements
- Warehouse stock balances
- Opening balances

Existing stock architecture:
- `StockBalance`
- `StockMovement`
- `PostStockMovementAction`

Stock balance is currently aggregated by:
- tenant
- branch
- warehouse
- product

Purchase invoice lines contain:
- batch_number
- expiry_date

However, current stock balance/movement architecture does **not** preserve stock by batch/expiry lot. Therefore, Sales batch/expiry allocation must not be faked in the frontend. A proper lot layer is required before reliable batch-wise selling.

---

## 9. Parties / Vendor / Customer / Accounts

Vendor / Customer / Accounts module has been implemented and is considered frozen unless a bug is found.

Major work completed:
- Vendor CRUD using Supplier
- Customer CRUD
- Manual Account master
- linked leaf accounts for vendors/customers
- Account Type assignment
- Bank accounts
- Others fields
- Opening balances
- Party ledger
- Bulk update
- Excel template/import
- Chart of Accounts view
- COA hierarchy

### COA Model Mapping

Four-level hierarchy:

```text
Main Head
  → Head
    → Account Type / Sub Head
      → End / Leaf Account
```

Model mapping:
- Main Head → `AccountMainHead`
- Head → `AccountSubHead`
- Account Type/Sub Head → `AccountType`
- End/Leaf Account → `Account`

Important:
- Do not confuse Account Type with leaf Account.
- Vendor/customer linked accounts are leaf Accounts.
- Do not seed screenshot customer/vendor names as COA master data.

---

## 10. Accounting Foundation

Implemented:
- `Account`
- `AccountType`
- `AccountMainHead`
- `AccountSubHead`
- `JournalEntry`
- `JournalLine`
- party opening balance posting
- party ledger from posted journal lines
- opening balance equity account setting

Opening balance behavior:
- draft first
- post only when configured opening balance equity account exists
- posted result immutable
- explicit error if offset/control account is missing

Sales payment journals exist.

Full Sale Revenue / COGS / Inventory accounting posting is still a later Sales phase unless already added after this document.

---

## 11. Sales Invoice — Current Architecture

Sales Invoice is the active development module.

Current BluePOS route:
- `/daily/sales`

Current design is approved/frozen.

### Current Backend Sales Capabilities

Implemented and tested:
- sale creation
- tenant isolation
- branch/warehouse context
- idempotency
- product pricing
- retail price
- wholesale price
- barcode resolution
- unit resolution
- conversion factor handling
- base stock deduction
- decimal-unit validation
- line discount percent
- line discount amount
- product tax
- gross amount
- net amount
- sale schemes
- free scheme lines
- free packaging
- stock deduction
- customer/payment flow foundation
- receipt preview/print foundation

### Current Passing Focused Test Results

Latest confirmed backend results:

#### CreateSaleTest
- 14 passed
- 120 assertions

#### SalePricingAndUnitTest
- 4 passed
- 42 assertions

#### SaleDiscountTaxTest
- 4 passed
- 36 assertions

Combined:
- 22 tests passed
- 198 assertions

### Current Frontend Sales Wiring

Implemented:
- Default / Wholesale / Retail options
- product entry
- exact barcode path
- barcode-selected unit
- unit conversion preview
- quantity
- price
- amount
- discount %
- discount Rs
- tax preview
- net amount
- real active-warehouse stock display
- customer selection foundation
- payment panel
- schemes/free packaging UI
- hold-cart foundation
- posted invoice/history foundation
- receipt preview/print foundation

---

## 12. Reference Sales Invoice Behavior

Reference application shows these major areas:

### Main Invoice
- Default / Wholesale / Retail
- Invoice #
- Date
- Quotation #
- Salesman
- Customer
- Name
- CNIC
- Copy From
- Payment Due
- On Hold
- Get
- Discount %
- Sales Tax %

### Product Grid
- Item / Product Description
- In Stock
- Sales Qty
- Price
- AMT
- Disc %
- Disc Rs
- Net Amt

### Right Panel
- Amount Options
- Discount
- Payment Method
- Remarks
- Total
- Received
- Credit Card
- Balance

### Actions
- Save F9
- Refresh F8
- Preview F3
- Print F11
- Close

### Other Reference Tabs
- Search / Posted Invoices
- Pending Invoices
- Expenses
- Product Wise Summary

These should be implemented using BluePOS architecture, not copied blindly.

---

## 13. Sales Invoice Current UI Bugs / Active Fixes

Current immediate UI bugs:

1. Product suggestions should appear while typing.
   - User should not need to press Enter first.
   - Keyboard Enter may still select the highlighted/first suggestion.

2. Editable input contrast.
   - Quantity / discount / product / received / remarks etc. must always be readable.
   - Light background → dark text.
   - Dark background → light text.
   - Yellow editable cells → dark text.
   - Must work in Light/Dark and Classic/Hybrid/Advanced.

3. Loader behavior.
   - Do not show a blocking loader while:
     - typing product search
     - entering quantity
     - entering discount
     - normal local editing
   - Loader should be used for explicit user actions such as:
     - Save
     - Refresh
     - Preview
     - Print
     - other deliberate long-running operations

4. A Sales interaction currently shows:
   - `An unexpected database error occurred. Please try again.`
   - This must be traced separately from the UI fixes.

---

## 14. Sales Invoice Remaining Roadmap

Recommended order:

1. Fix current UI bugs
2. Trace current database error
3. Customer behavior refinement
4. Salesman behavior
5. Payment Due / received / balance
6. Search / Posted Invoices
7. Pending Invoices / due payments
8. Expenses
9. Product Wise Summary
10. Quotation / Copy From
11. On Hold semantics
12. Full Sales accounting
13. Batch/expiry lot architecture if required
14. Keyboard workflow
15. Printing polish
16. final regression suite
17. freeze

---

## 15. Things Not To Do

- Do not redesign Sales Invoice.
- Do not alter the approved Amounts geometry.
- Do not redesign the global ribbon/shell.
- Do not touch Define Products while it is frozen.
- Do not touch Barcode Printing while it is frozen.
- Do not create fake accounting data.
- Do not create fake batch/expiry logic.
- Do not expose internal IDs.
- Do not duplicate inventory/accounting architecture.
- Do not run destructive DB commands.
- Do not make broad repo changes for a narrow task.
- Do not silently change unrelated pages.

---

## 16. Response Style for Development Tasks

Keep implementation responses practical and concise.

For every coding phase, state:
1. exact files required
2. whether each file is NEW or REPLACE
3. exact target path
4. focused validation commands
5. expected result

Prefer small phases over large rewrites.

If a required architectural fact is unknown:
- inspect the repository first
- do not guess

## Git Module Branch Policy

BluePOS has five long-lived module branches only:

- feature/definition
- feature/daily-entries
- feature/reports
- feature/tools
- feature/administration

Submodule and bug-fix work stays on the owning module branch. Do not create extra branches unless explicitly approved.

Before switching modules, finish/validate/review current work, safely fast-forward it into main, push and verify main, then switch to the target branch and fast-forward-sync it from latest main before coding.

If `--ff-only` fails at any point, stop and inspect divergence. Never force-push, rebase, or create an unapproved merge commit to bypass conflicts.
