# BluePOS Offline Synchronization

Offline is a first-class POS mode. PostgreSQL is the system of record. The browser uses **IndexedDB via Dexie.js** as a replica plus an **outbox**.

**Never store transactional business data in localStorage.** localStorage may hold harmless UI preferences only.

Companion: `docs/SECURITY.md` (idempotency, device revoke), `docs/INVENTORY.md` (estimated stock), `docs/ACCOUNTING.md` (server recalculation).

---

## 1. Principles

1. Every locally created financial document has `client_ulid` (ULID), `device_ulid`, `idempotency_key`, `created_at_device`.
2. Server document numbers (`SALE-2026-000001`) are assigned at post time and do **not** replace the ULID.
3. Posting APIs are idempotent. The same `Idempotency-Key` never creates a second sale, payment, stock movement set, or journal.
4. Posted finance is immutable. Sync posts, voids, or returns — it does not rewrite history.
5. Local stock and balances are **snapshots**. UI must show `Last synchronized at: <timestamp>` and must not present them as live when offline or stale.
6. Connectivity: `ONLINE`, `OFFLINE`, `SYNCING`, `SYNC ERROR`.
7. The server is the final source of truth.
8. Offline authorization uses a **short-lived signed lease** (default 8 hours, typically the current shift). Immediate revocation cannot reach a disconnected terminal. Controls: lease expiry + reconnect revalidation + limited offline capabilities + audit/sync review. Expired lease → `OFFLINE_AUTH_EXPIRED`.

---

## 2. Local Dexie stores

| Store | Contents |
|---|---|
| `products` | Master snapshot |
| `product_barcodes` | Barcode → product + conversion |
| `product_prices` | Retail/wholesale/min/levels |
| `customers` | Lookup + credit_limit (not live balance) |
| `vendors` | Lookup |
| `stock_snapshots` | `last_server_qty`, `local_delta`, grain: warehouse/product/batch |
| `balance_snapshots` | Party/account amounts + `as_of` |
| `settings` | POS, tax, print, FEFO, negative stock flag |
| `permissions_snapshot` | Keys + branch allow-list + `captured_at` |
| `offline_sales` | Headers |
| `offline_sale_items` | Lines |
| `offline_payments` | Tender lines |
| `held_sales` | Holds (device-local in v1) |
| `sync_outbox` | Pending mutations |
| `sync_metadata` | `last_sync_at`, `device_ulid`, cursors, status |

Money as strings with **4** decimals (`NUMERIC(20,4)` preview). Quantities as strings with **6** decimals. Percentages/rates as strings with **8** decimals (`NUMERIC(12,8)` preview). Laravel still recomputes.

v1 offline workflows: product search, barcode **cash** sale, hold/recall, local print, local history, customer/vendor lookup, snapshot balances.

**Credit sales:** disabled offline by default. Enable only with `allow_offline_credit_sales` plus snapshot credit-limit rules. Never silent unlimited credit.

**Returns:** only against an original invoice present in local Dexie with intact lines. Otherwise require online validation.

Online-only until later: purchases, vouchers, stock take, claims, role/settings, Super Admin, backup, credit (unless setting), returns without local original.

---

## 3. Outbox record

```text
id
entity_type
entity_ulid            -- client_ulid
operation
payload
idempotency_key
device_ulid
created_at_device
last_sync_attempt
attempt_count
last_error
sync_status            -- pending | syncing | synced | failed | conflict
```

Push via `POST /api/v1/offline-sync` (batch of operations) and/or the same REST endpoints used online, always with `Idempotency-Key`.

---

## 4. Offline sale state machine

A cash sale created on a terminal:

```text
DRAFT LOCAL
  cashier scanning / editing; not in outbox (or outbox not pending)
  stock delta not applied yet (or applied only as UI estimate)

→ COMPLETED LOCAL
  cashier tendered; client_ulid + idempotency_key assigned
  local receipt may print with LOCAL-* display number + ULID
  local stock delta applied (estimated)
  cash session local totals updated

→ PENDING SYNC
  outbox row status=pending
  sync_status on sale=pending

→ RECEIVED SERVER
  API accepted the HTTP request; idempotency row status=started

→ VALIDATED
  tenant/device/user/permissions OK
  server recalculated qty, prices, tax, payments
  stock rows locked

→ POSTED SERVER
  sale + items + payments + movements + journal + document_no committed
  idempotency completed with response payload

→ ACKNOWLEDGED
  HTTP 200/201 with resource ULID + document_no reached the client
  (if this step never happens, see failure: internet dies during response)

→ SYNCED LOCAL
  outbox status=synced
  local sale stores official document_no
  local_delta for this sale cleared; next pull overwrites last_server_qty
```

Holds stay `DRAFT LOCAL` / `held_sales` until recalled and completed. v1 holds do not reserve warehouse stock and do not sync until posted.

---

## 5. Idempotency (server)

See also `docs/SECURITY.md` §13.

Persisted unique: `(tenant_id, idempotency_scope, idempotency_key)`. Atomic INSERT; unique violation → lock and replay or `IDEMPOTENCY_KEY_REUSED`.

| Retry situation | Outcome |
|---|---|
| Same key + same payload hash | Return original result. **No** second sale/payment/movement/journal |
| Same key + different payload | `IDEMPOTENCY_KEY_REUSED` |
| In-progress | `IDEMPOTENCY_IN_PROGRESS` — client retries later |

---

## 6. Failure cases (A–J)

IndexedDB is **not trusted**. The backend revalidates identity, prices, qty, stock, permissions, device, and membership on every item. Tampered Dexie rows cannot become posted truth.

| ID | Scenario | Client | Server | Conflict / audit |
|---|---|---|---|---|
| A | Internet dies mid-sync after local complete | Outbox stays `pending`/`syncing`; retry same key | If not committed: no sale. If committed: idempotent replay | Audit on successful post only |
| B | Client timeout but server posted | Retry same idempotency key | Return stored 200/201; no second sale/movement/journal | Single audit create |
| C | Same transaction retried 10 times | Same key + hash | 9 replays of original resource | One sale |
| D | Two devices sell last unit offline | Both complete locally with est. stock | First posts; second `INSUFFICIENT_STOCK` unless negative allowed | `sync_conflicts` + manager inbox; server stock wins |
| E | User disabled while offline | May keep selling locally until handshake | Membership inactive → `FORBIDDEN` / unauthenticated; no post | Audit denied attempts; outbox `failed` |
| F | Device revoked while offline | Local queue remains | Token/device `DEVICE_REVOKED`; no post | Audit revoke + rejected sync |
| G | HO price change | Cart may show old price | Recalculate from current masters; below min → reject | Optional price-diff warning; receipt uses server |
| H | Credit limit change | Snapshot labeled `as of` | Credit sale: check **live** limit. Default: credit not accepted offline | `CREDIT_LIMIT_EXCEEDED` if enabled and over |
| I | Offline return, invoice not cached | Block locally; prompt go online | Reject `ORIGINAL_SALE_NOT_FOUND` if attempted | No stock/AR mutation |
| J | User edits IndexedDB | UI may look altered | Full revalidation; hash/payload must match rules; cannot set prices/stock/tenant | Failed/conflict; do not trust client cost/qty/net |

### 6.8–6.9 (legacy detail)

Handshake on reconnect: `DEVICE_REVOKED` or suspended membership. Permission snapshot never bypasses server `sales.post`.

---

## 6A. Offline credit sales (v1)

**Offline CASH sales: supported.**  
**Offline CREDIT sales: DISABLED by default.**

Browser AR snapshot must show `Balance as of <last_sync_at>` and must never be labelled live/real-time.

It may later be enabled **per tenant** only (`allow_offline_credit_sales`, default false) with stale-credit controls (snapshot limit, `as of` banner, server live re-check at sync which can still reject, max offline credit). Never silent unlimited offline credit. Server rejects `payment_type=credit` / `on_account` while the tenant setting is off (`OFFLINE_CREDIT_DISABLED`).

---

## 6B. Offline returns (v1)

**Conservative:** an offline return may be considered only when the original sale **and** remaining returnable qty/batch state are safely present in local Dexie (integrity-checkable lines).

Otherwise require **online** validation.

`sales.return_without_invoice` is **online only**. Do not permit uncontrolled return-without-invoice while offline. IndexedDB remains untrusted; the server revalidates original sale ownership, qty cap, and batch.

---

---

## 7. Pull protocol

`GET /api/v1/sync/changes?since=&cursor=`

Chunked: products, barcodes, prices, customers, settings, grants, stock balances for the **device warehouse**.

Masters carry `version`. Cashiers do not dirty-edit masters offline in v1.

After successful push, pull to refresh snapshots and set `sync_metadata.last_sync_at`.

---

## 8. Stock and balance display

```text
estimated_qty = last_server_qty − local_offline_sales_delta + local_returns_delta
```

Label: **Est. stock**. Offline FEFO/batch lists are snapshots, **not** global availability. Server re-selects/validates batch and expiry at post; expired stock is rejected unless an authorized exception workflow is enabled **and** the user has the exception permission.

Balances:

```text
Last synchronized at: 2026-09-20 16:02
Balance as of 2026-09-20 16:02
```

Never imply live ledger while `OFFLINE` or while snapshot older than a configured freshness window (even if ONLINE but pull failed → `SYNC ERROR`).

---

## 9. PWA / service worker

- Precache app shell (ribbon, POS route, print CSS).
- API POST success is **not** faked by the SW.
- Mutations succeed locally only by writing Dexie + outbox, then syncing when online.

---

## 10. Server ingest

`OfflineSyncService`:

1. Authenticate device + membership.
2. For each outbox item, in order: idempotency lock → validate → post service → record `sync_operations`.
3. Item failures do not erase the batch; remaining items may continue unless a fatal `DEVICE_REVOKED`.
4. Return per-item results.

`sync_batches` + `sync_operations` persist support history.

---

## 11. Tests (Phases 14–16)

- Same idempotency key → one sale, one movement set, one journal.
- Simulated dropped response + retry → still one sale.
- Two-device oversell → one posted, one `INSUFFICIENT_STOCK`.
- Revoked device cannot post.
- Stale permission cannot post.
- UI contract: snapshot label includes last synchronized at (frontend test when harness exists).
