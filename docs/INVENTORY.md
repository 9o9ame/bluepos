# BluePOS Inventory Engine

**Authoritative history:** `stock_movements` (immutable ledger).  
**Operational quantity:** `stock_balances` materialized cache, updated in the same transaction as the movement.  
**Never** a writable `products.stock_quantity` (or equivalent) as the source of truth.

Companion: `docs/DATABASE.md`, `docs/ACCOUNTING.md`, `docs/OFFLINE_SYNC.md`.

---

## 1. Principles

1. Every stock change is a movement row: `quantity_in` or `quantity_out`, never both.
2. Movements are append-only. Reverse with an opposite movement tied to a void/return/adjustment document.
3. Cache drift is a bug. A rebuild job can recompute balances from movements (Phase 19); the UI does not “fix qty” by editing the product.
4. Negative stock is forbidden unless `business_settings.allow_negative_stock = true`.
5. Posting uses `DB::transaction()` and `lockForUpdate()` on the relevant `stock_balances` rows (and sequence rows).
6. Costing v1: moving weighted average on the server. Client cost is ignored.

---

## 2. Movement types

| Type | Typical qty | When |
|---|---|---|
| `OPENING` | in | Opening stock |
| `PURCHASE` | in | Posted purchase |
| `PURCHASE_RETURN` | out | Posted purchase return |
| `SALE` | out | Posted sale |
| `SALE_RETURN` | in | Posted sale return |
| `TRANSFER_OUT` | out | Transfer dispatched |
| `TRANSFER_IN` | in | Transfer received |
| `ADJUSTMENT_IN` | in | Approved increase |
| `ADJUSTMENT_OUT` | out | Approved decrease |
| `CLAIM_OUT` | out | Claim removes stock (expired/damaged to supplier) |
| `CLAIM_IN` | in | Replacement stock from claim |
| `STOCK_TAKE` | in or out | Approved count difference (or pair with ADJUSTMENT_*) |
| `DAMAGE` | out | Damaged write-off |
| `EXPIRY` | out | Expired write-off |
| `CONVERSION` | in+out pair | Unit/pack conversion between SKUs or units |

`STOCK_TAKE` may be recorded as `ADJUSTMENT_IN`/`OUT` with `reference_type = stock_count`. Either is acceptable; **v1:** use `STOCK_TAKE` for count posts and `ADJUSTMENT_*` for manual adjustments so reports distinguish them.

---

## 3. Movement row

Required: `tenant_id`, `branch_id`, `warehouse_id`, `product_id`, `batch_id`, `type`, `reference_type`, `reference_id`, `quantity_in`, `quantity_out`, `unit_cost`, `occurred_at`.

Every movement has a `batch_id`. Products that do not track batches use the synthetic batch `batch_number = '-'`.

`unit_cost` is the cost used for that movement (average at sale; landed unit cost at purchase).

---

## 4. Cache: `stock_balances`

Unique `(tenant_id, warehouse_id, product_id, batch_id)`.

Columns: `qty`, `avg_cost`, `version`.

**Why it is justified:** barcode scanning and POS stock display cannot sum millions of movements per keystroke. The cache is a **read model**. Movements remain the audit source.

**Write rule:**

```text
BEGIN
  SELECT * FROM stock_balances
    WHERE tenant/warehouse/product/batch
    FOR UPDATE;           -- lockForUpdate()
  -- insert zero row if missing, still locked
  validate qty - out + in >= 0 unless negative allowed
  insert stock_movements
  update stock_balances.qty / avg_cost / version
  post inventory journal
COMMIT
```

Rebuild:

```text
qty = SUM(quantity_in) − SUM(quantity_out) FROM stock_movements
      WHERE same grain
```

If rebuild differs from cache, log `STOCK_CACHE_DRIFT` for investigation; do not silently trust the cache for financial rebuilds.

**Repair procedure (never delete movements):**

1. Run read-only comparison at grain `(tenant_id, warehouse_id, product_id, batch_id)`.
2. Open a manager conflict/repair record with both figures.
3. If cache is wrong: `UPDATE stock_balances` to the SUM of movements inside a transaction (projection repair). **Do not delete or rewrite `stock_movements`.**
4. If movements themselves are wrong: post an `ADJUSTMENT_*` (or void/reversal of the source document). Still no history delete.
5. Re-run comparison; audit the repair.

This is a Phase 19 job, not a cashier screen.

---

## 5. Batches and expiry

`product_batches`: `batch_number`, product, warehouse, manufacture/expiry dates, purchase_cost, sale_price.

Expiry reports: expired, today, 7/15/30/60/90 days, custom range.

UI: red expired, orange near (tenant threshold, default 30 days), green healthy.

POS: if `tracks_expiry` and `fefo_enabled`, recommend the nearest **valid** (not expired, unless setting allows expired sale) batch with qty.

Selling expired stock: default `allow_expired_sale = false` → `EXPIRED_BATCH`. An **authorized exception** (tenant setting + `sales.override_price` or a dedicated `inventory.sell_expired` permission) is required for an exceptional workflow. FEFO must never auto-select expired batches.

Offline terminals must not treat local batch qty/expiry as globally authoritative. Server re-validates batch, expiry, and qty at post.

---

## 6. FEFO

First Expiry First Out is a **recommendation and optional hard rule**.

- Soft (default): auto-select nearest expiry; cashier may override with permission `inventory.view` + sale create.
- Hard (setting): reject if selected batch is not the earliest expiry with stock.

FEFO does not replace the movement ledger. It only chooses `batch_id` before lock.

---

## 7. Negative stock

If `allow_negative_stock = false` (default):

- After lock, if `qty - quantity_out < 0` → `INSUFFICIENT_STOCK`, rollback.
- Applies to sales, transfers out, purchase returns, claim out, adjustments out, damage, expiry.

If true: allow and surface negative stock reports. Accounting still posts COGS at current avg (or zero if never purchased — reject `NO_COST` unless opening exists).

---

## 8. Concurrency

Critical sections use PostgreSQL row locks:

| Operation | Lock |
|---|---|
| Sale / return / purchase / adjustment / claim stock | `stock_balances` rows `lockForUpdate()` |
| Transfer dispatch | source balances |
| Transfer receive | destination balances (create if needed) |
| Document number | `document_sequences` row `lockForUpdate()` |
| Idempotency | `idempotency_keys` unique `(tenant_id, idempotency_scope, idempotency_key)` insert-first |
| Cash close | `cash_sessions` row `lockForUpdate()` |

Application-level `lockForUpdate()` on missing balances: `firstOrCreate` then lock (insert race handled by unique constraint retry).

## 9A. Atomic purchase (inventory + AP)

Same transaction as accounting:

1. Resolve tenant/branch/warehouse.
2. Authorize; idempotency insert-first.
3. Recalculate landed cost server-side.
4. Lock destination balances/batches.
5. Create purchase + items.
6. Create/update batches (number, expiry, received cost, warehouse).
7. `PURCHASE` movements; increase cache.
8. Payable and/or payment; balanced journal.
9. Document number; mark posted; commit or rollback all.

## 9B. Concurrent returns

`lockForUpdate()` original sale/purchase header and lines, then:

```text
return_qty ≤ sold_qty − SUM(posted returns on original_sale_item_id)
```

Store original document ULID and original line ULID on the return.

---

## 9. Atomic sale (inventory slice)

Inside the same transaction as accounting (see `docs/ARCHITECTURE.md`):

1. Validate sale and permissions.
2. Convert each line to base qty via barcode `conversion_factor`.
3. Resolve batch (FEFO or explicit).
4. Lock balances.
5. Recalculate money and COGS.
6. Insert sale + items + payments.
7. Insert `SALE` movements (`quantity_out`).
8. Update cache.
9. Insert balanced journal including COGS/inventory.
10. Commit or rollback all.

---

## 10. Stock transfer

Workflow: `draft` → `sent` → `in_transit` → `received` | `rejected` | `cancelled`.

- **Dispatch (`sent`):** `TRANSFER_OUT` at source; source qty decreases; destination **unchanged**.
- **Receive:** `TRANSFER_IN` at destination; destination qty increases at the cost captured on the transfer line.
- **Reject/cancel after send:** `TRANSFER_IN` back to source (or reversing out) — never delete the out movement.
- Branch→branch or warehouse→warehouse. Cross-branch requires permission on both ends or a two-step authorize (`inventory.transfer` + `inventory.transfer_receive`).

Track `sent_by`, `received_by`, `sent_at`, `received_at`.

---

## 11. Stock count (stock take)

Session: branch, warehouse, date, users.

Grid: product, system qty (from cache at count freeze), physical qty, difference, cost difference.

- Freeze: snapshot `system_qty` onto `stock_count_items` so later sales do not rewrite the counted baseline.
- Approve: for each difference, movement `STOCK_TAKE` + inventory adjustment journal. **Original count rows stay.**
- Unapproved counts do not move stock.

---

## 12. Opening stock

Records product, warehouse, branch, batch, expiry, qty, cost, total, opening date, financial year.

Posts `OPENING` movement + Dr Inventory / Cr Opening Balance Equity (or as in `docs/ACCOUNTING.md`).

---

## 13. Conversions

Pack/carton sold via barcode conversion is **not** `CONVERSION`; it is a `SALE` in base units.

`CONVERSION` is for changing one product/batch into another (repack, production-lite). Implemented as paired out/in movements in one transaction, costs conserved: `in_qty × in_cost = out_qty × out_cost`.

---

## 14. Offline estimated stock

Device stores:

```text
last_server_qty
local_offline_delta     -- unsynced sales out / returns in
estimated_qty = last_server_qty − local_offline_delta
```

UI: **Est. stock**, never live stock while offline. Server remains truth. Two terminals can both sell the last unit offline; sync yields one post and one `INSUFFICIENT_STOCK` conflict (unless negative stock allowed).

See `docs/OFFLINE_SYNC.md`.

---

## 15. Stock reservation (later)

Not in v1. If needed after Phase 16:

- `stock_reservations` rows for unpaid holds that should block qty
- v1 holds **do not** reserve warehouse stock (POS Plus-style hold is a parked invoice). Document this in cashier training.
- If reservation is added: insert reservation under lock; posting converts reservation to `SALE` movement; expiry job releases stale holds.

---

## 16. Reports (inventory)

Stock status, ledger, movement, activity between dates, warehouse/branch/batch, valuation (`qty × avg_cost`), reorder, low, dead, expired, near expiry, negative, adjustment, transfer.

All tenant-scoped; branch-scoped per RBAC.

---

## 17. Tests

- Sale deducts exact base qty from the locked batch.
- Return restores the same batch.
- Purchase increases; purchase return decreases.
- Negative stock blocked when disabled.
- Concurrent two-sales cannot oversell.
- Transfer in-transit does not increase destination.
- Count approval writes movements and keeps original count rows.
- Cache rebuild matches movements for a fixture.
- No API to PATCH product quantity.
