# BluePOS Permissions (RBAC)

Authorization is enforced by **Laravel policies and server queries**. Hiding a button in the UI is not security.

Companion: `docs/SECURITY.md`, `docs/ARCHITECTURE.md` §3.

---

## 1. Model

```text
users  (global login)
  └── memberships  (user × tenant)   ← session tenant context
        ├── membership_roles → roles (tenant)
        │                     └── role_permissions → permissions (global catalog)
        │                           scope_type: all_branches | selected_branches
        └── membership_branches     ← allow-list when scope is selected_branches

devices bind branch + warehouse; they cannot exceed the membership's branches.
```

**Owner source of truth:** `Membership::hasOwnerAuthority()` / `hasRoleCode('owner')` — the membership must be **active**, belong to the **current tenant**, and hold that tenant’s **active Owner role** through `membership_roles`. `memberships.is_owner` is derived display/compatibility metadata (kept in sync when roles change; API `is_owner` is derived from the role). It is **not** sufficient for authorization. Final-owner protection counts locked active memberships that currently hold the Owner role.

### Request authorization order

1. Authenticated Sanctum user/session.
2. Active membership for the session tenant (from session, not JSON `tenant_id`).
3. Tenant status allows access (`trial`/`active`).
4. Permission key present on at least one assigned role.
5. Branch: grant `all_branches` **or** target `branch_id` ∈ `membership_branches`.
6. Warehouse belongs to that branch.
7. Policy extras (document status, own cash session, module licensed via `tenant_modules`).

Failure: `403` `{ "error": { "key": "FORBIDDEN" } }` or `404` for cross-tenant ULID lookups.

---

## 2. Key format

`{module}.{action}`

Use the catalogue below. Tenants cannot invent keys; they only grant seeded keys to roles.

Draft vs posted:

- `*.edit_draft` — unposted holds/drafts only
- `*.post` — transition to posted (creates stock + journals)
- `*.void` — posted documents only; never a silent edit

---

## 3. Permission catalogue

### Platform (Super Admin only — never on tenant roles)

| Key | Purpose |
|---|---|
| `platform.tenants.view` | List tenants and status |
| `platform.tenants.manage` | Create, activate, suspend |
| `platform.plans.manage` | Plans and limits |
| `platform.subscriptions.manage` | Subscriptions, trials |
| `platform.modules.manage` | `tenant_modules` |
| `platform.health.view` | Health, version, backup status |
| `platform.support.notes` | Support notes |
| `platform.impersonate.start` | Start impersonation (reason required) |
| `platform.impersonate.end` | End impersonation |

### Identity and administration

| Key |
|---|
| `users.view` `users.manage` |
| `roles.view` `roles.manage` |
| `settings.view` `settings.manage` |
| `branches.view` `branches.manage` |
| `warehouses.view` `warehouses.manage` |
| `devices.view` `devices.manage` `devices.revoke` |
| `backups.create` `backups.restore` `backups.view` |
| `audit.view` |
| `columns.manage` |
| `shortcuts.manage` |

`users.manage` includes create, edit, disable. Split later if needed; do not skip policy checks.

### Catalog

| Key |
|---|
| `products.view` `products.create` `products.edit` `products.delete` `products.manage_prices` `products.manage_barcodes` |
| `categories.view` `categories.create` `categories.edit` `categories.delete` `categories.manage` |
| `brands.view` `brands.create` `brands.edit` `brands.delete` `brands.manage` |
| `units.view` `units.create` `units.edit` `units.delete` `units.manage` |
| `settings.view` `settings.manage` |
| `opening_stock.view` `opening_stock.create` `opening_stock.post` (Phase 4) |

### Parties

| Key |
|---|
| `customers.view` `customers.create` `customers.edit` `customers.delete` `customers.export` |
| `vendors.view` `vendors.create` `vendors.edit` `vendors.delete` `vendors.export` |

### Sales

| Key | Meaning |
|---|---|
| `sales.view` | List/show posted and permitted drafts |
| `sales.create` | Open POS / create draft or hold |
| `sales.edit_draft` | Mutate unposted hold/draft |
| `sales.discount` | Apply line/invoice discount above role threshold |
| `sales.override_price` | Sell below listed rate (still ≥ min unless also granted) |
| `sales.post` | Post (stock + journal) |
| `sales.void` | Void posted sale |
| `sales.print` | Print/reprint |
| `sales.export` | Export |
| `sales.hold` | Hold |
| `sales.recall` | Recall hold |
| `payments.create` | Collect/split pay on a sale |
| `payments.approve` | Approve exceptional payments if required |

Do **not** ship `admin.all`. Tenant admin is a role that is granted explicit keys.

### Sales returns

| Key | Meaning |
|---|---|
| `sales.return` | Create/view return against an invoice (enforce with `sales_returns.create` / `view` if split in UI) |
| `sales.return_without_invoice` | Return without original invoice — **online only** |
| `sales_returns.approve` | Approve when SoD enabled |
| `sales_returns.void` | Void a posted return |

`sales.return` and `sales.return_without_invoice` **must** be separately enforceable. Do not fold them into `sales.create`.

### Purchasing

| Key |
|---|
| `purchases.view` `purchases.create` `purchases.edit_draft` |
| `purchases.post` |
| `purchases.void` |
| `purchases.approve` `purchases.print` `purchases.export` |
| `purchase_returns.view` `purchase_returns.create` `purchase_returns.approve` `purchase_returns.void` |

`purchases.post` and `purchases.void` are separate from create/edit_draft.

### Canonical sensitive permissions (must not be merged into admin.all)

These keys are required in the seeder as distinct rows:

- `sales.discount`
- `sales.override_price`
- `sales.void`
- `sales.return`
- `sales.return_without_invoice`
- `purchases.post`
- `purchases.void`
- `inventory.adjust`
- `inventory.transfer.dispatch`
- `inventory.transfer.receive`
- `inventory.stock_take.approve`
- `accounting.journal.create`
- `accounting.journal.post`
- `accounting.journal.reverse`
- `vouchers.create`
- `vouchers.approve`
- `database.backup`
- `database.restore`
- `settings.financial`
- `settings.security`
- `superadmin.impersonate` (same capability as `platform.impersonate.start`; seed both keys or one key with two aliases — never implied by `platform.tenants.manage`)

### Inventory

| Key | Meaning |
|---|---|
| `inventory.view` | Stock status, ledger, expiry |
| `inventory.adjust` | Manual adjustments |
| `inventory.transfer.dispatch` | Create/send transfer (`inventory.transfer` alias) |
| `inventory.transfer.receive` | Receive inbound (`inventory.transfer_receive` alias) |
| `inventory.stock_take` | Count sessions |
| `inventory.stock_take.approve` | Post count differences |
| `inventory.export` | Export |

### Accounting

| Key |
|---|
| `accounts.view` `accounts.create` `accounts.edit` |
| `accounting.journal.create` `accounting.journal.post` `accounting.journal.reverse` (aliases: `journals.*`) |
| `vouchers.view` `vouchers.create` `vouchers.approve` `vouchers.post` `vouchers.void` `vouchers.print` |
| `expenses.create` `expenses.view` |
| `settings.financial` | COA defaults, costing, period close, tax |
| `settings.security` | Password/session/device policies |

### Backups

| Key |
|---|
| `database.backup` / `backups.create` |
| `database.restore` / `backups.restore` |
| `backups.view` |

Restore is never implied by backup.

### Platform

`superadmin.impersonate` / `platform.impersonate.start`: reason required, time-limited, audited. Not granted to tenant roles.

### Claims

| Key |
|---|
| `claims.view` `claims.create` `claims.edit_draft` `claims.approve` `claims.reject` `claims.settle` `claims.cancel` |

### Cash

| Key |
|---|
| `cash_sessions.open` `cash_sessions.close` `cash_sessions.view` `cash_sessions.approve` |

### Reports

| Key |
|---|
| `reports.sales` `reports.purchases` `reports.inventory` `reports.accounting` `reports.profit_loss` |
| `reports.tax` `reports.cash` `reports.export` |

---

## 4. Seeded tenant roles

| Role code | Typical grants |
|---|---|
| `tenant_admin` | All tenant keys, `all_branches`. Cannot receive platform keys. Last admin guard on `roles.manage` / `users.manage`. |
| `branch_manager` | Branch-scoped operations, inventory, limited settings, sales void optional |
| `cashier` | `sales.create/view/edit_draft/post/print/hold/recall`, `payments.create`, `cash_sessions.*`, `customers.view`, `products.view`, `inventory.view`. **No** `sales.void`, `sales.discount` (above threshold), `sales.override_price`, `sales.return_without_invoice` unless granted. |
| `accountant` | accounts, journals, vouchers, `reports.accounting`, `reports.profit_loss` |
| `inventory` | `inventory.*`, `products.view`, `opening_stock.*` |

Custom roles: copy + edit on Role Management screen.

---

## 5. Role Management UI

Desktop two-list editor:

- Available Permissions
- Granted Permissions
- Add / Remove / Add All / Remove All / Copy Role / Save
- Branch scope: All branches / Selected branches

Keyboard: F9 save, ESC close.

Copy Role duplicates keys and default scope; new name must be unique per tenant.

---

## 6. Branch-scoped permissions

| `scope_type` | Effect |
|---|---|
| `all_branches` | Any branch in the tenant (still tenant-isolated) |
| `selected_branches` | Only `membership_branches` (and role-level selected list if stored on `role_permissions`) |

**Document visibility:** out-of-scope `branch_id` rows are omitted from queries. ULID access returns 404. This is mandatory for IDOR.

If a membership has multiple roles, the union of keys applies. For a given key, if *any* granting role is `all_branches`, that key is unscoped; otherwise the union of selected branches applies.

---

## 7. Guard rails

1. Last membership with `roles.manage` cannot lose that permission.
2. Last active `tenant_admin` membership cannot be suspended.
3. Tenant roles cannot grant `platform.*`.
4. `backups.restore` requires elevated confirmation (see `docs/SECURITY.md`).
5. `sales.void`, `inventory.adjust`, `journals.reverse` always write audit logs.
6. Impersonation: `platform.impersonate.start` + non-empty reason + banner + `started_at`/`ended_at`.
7. Offline grant snapshots are hints. Server re-checks on every post/sync.
8. No `admin.all` meta-permission. Tenant admin is a bundle of keys.
9. Tenant users cannot update/delete `audit_logs`.

---

## 7.1 Segregation of duties (optional)

Small marts can run with approvals **off**. When enabled per tenant (thresholds in `business_settings`):

| Operation | Default | Optional approval |
|---|---|---|
| Discount above X% | cashier blocked without `sales.discount` | manager approve |
| Price override | requires `sales.override_price` | manager approve |
| Stock adjustment / write-off | `inventory.adjust` | second user approve |
| Manual journal | `accounting.journal.post` | `vouchers.approve` |
| Large refund / return | `sales_returns.approve` | |
| Purchase post over amount | `purchases.approve` | |
| Database restore | `database.restore` + re-auth always | |

Do not force dual control on every small tenant at launch. Keep the **keys split** so it can be turned on without a schema rewrite.

---

## 8. Policy mapping examples

| Endpoint | Ability |
|---|---|
| `GET /api/v1/sales` | `viewAny` + branch constraint |
| `POST /api/v1/sales` | `create`; if posting immediately also `post` |
| `PATCH /api/v1/sales/{ulid}` | `edit_draft` and status ∈ hold/draft |
| `POST /api/v1/sales/{ulid}/void` | `void` and status posted |
| `POST /api/v1/sales-returns` without invoice | `sales_returns.create_without_invoice` |
| `POST /api/v1/stock-adjustments` | `inventory.adjust` |
| `POST /api/platform/tenants/{ulid}/impersonate` | `platform.impersonate.start` |

---

## 9. Tests (from Phase 2)

- Tenant A token cannot read Tenant B product/sale/user by ULID (404).
- Branch-scoped cashier cannot open another branch invoice.
- Cashier cannot void without `sales.void`.
- `sales.edit_draft` cannot mutate posted sales.
- Role copy cannot include `platform.*`.
- Last admin cannot strip `roles.manage`.
- Impersonation without reason rejected.
- UI-only hide is insufficient: API still 403 when the client calls anyway.
