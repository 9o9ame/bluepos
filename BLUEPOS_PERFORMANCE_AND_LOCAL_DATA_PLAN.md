# BluePOS Performance & Local Data Plan

> Status: Planned / foundation not yet implemented  
> Current owning branch: `feature/daily-entries`  
> Current implementation focus: Daily Entries → Purchase Invoice

## 1. Purpose

This document is the source of truth for BluePOS frontend performance, reusable local-data caching, and the future offline foundation.

The immediate goal is to make data-heavy workflows feel fast for end users, especially POS/mart users during rush periods, without weakening server authority, multi-tenant isolation, RBAC, financial integrity, or inventory correctness.

This plan is intentionally global and reusable. Performance work started while implementing Purchase Invoice must not become Purchase-Invoice-specific infrastructure.

## 2. Current Scope

### Implement now

Build reusable performance/local-data infrastructure while working on Purchase Invoice:

- shared IndexedDB foundation
- shared product catalogue repository/cache
- shared supplier repository/cache
- reusable local-first read APIs/hooks
- fast product lookup/search using local cached data where safe
- background refresh / stale-while-revalidate behavior
- tenant/branch/warehouse scoped cache keys
- safe cache invalidation and refresh rules
- performance validation with large catalogues

The same infrastructure must be reusable later by:

- Sales Invoice
- Sales Return
- Purchase Order
- Purchase Return
- Quotation
- other modules that need the same reference/master data

### Do not implement now

Offline transaction processing is deferred until Sales Invoice / Sales Return work begins.

Do not add yet:

- offline invoice posting
- offline return posting
- outbox queue
- reconnect transaction replay
- offline conflict resolution
- background transaction sync
- offline payment confirmation logic

## 3. Future Offline Scope

Only these modules are planned to support offline transactional work:

- Sales Invoice
- Sales Return

When BluePOS is offline, other transactional, reporting, configuration, and administration modules should be disabled unless explicitly approved later.

The local-data foundation built now must be reusable by future offline Sales work, but offline transaction complexity must not be introduced during Purchase Invoice work.

## 4. Architectural Principle

BluePOS should use a hybrid model:

```text
PostgreSQL / Laravel APIs
        ↓
Shared Data Access / Sync Layer
        ↓
IndexedDB Local Cache
        ↓
Reusable Repositories / Hooks
        ↓
Purchase / Sales / Returns / Other UI
```

The UI should not need to know whether safe reference data came from IndexedDB or a fresh server response.

Preferred behavior:

```text
First use:
server → IndexedDB → UI

Later use:
IndexedDB → UI immediately
        +
background server refresh → IndexedDB update → UI refresh
```

This is a stale-while-revalidate style model for safe reference data.

## 5. IndexedDB, Not localStorage

Use IndexedDB for catalogue/reference datasets.

Do not use `localStorage` for large datasets because it is synchronous, limited, poorly queryable, and can block the main UI thread.

`localStorage` may remain suitable only for tiny non-sensitive UI preferences where appropriate.

## 6. Data Suitable for Shared Local Cache

Good candidates include:

- products
- product numbers / SKUs
- barcodes
- units
- categories
- brands / company labels
- sale prices
- purchase reference rates where safe
- suppliers
- customers when required by a module
- warehouse/reference metadata
- tax/reference settings needed for UI lookup
- selected warehouse stock snapshot where useful for display

These datasets may have different freshness policies.

## 7. Data That Remains Server-Authoritative

Local cached data must never become authoritative for:

- tenant ownership
- branch ownership
- warehouse ownership
- RBAC / authorization
- final stock truth
- balances
- receivables / payables
- posted financial totals
- accounting entries
- inventory posting
- valuation
- payments
- fees
- FX
- final tax calculations
- invoice totals accepted for posting
- posted-document mutability rules

The server must recompute and validate authoritative financial/inventory state.

## 8. Multi-Tenant Local Isolation

All local persistent data must be isolated by context.

At minimum, cache identity must account for:

- tenant ULID
- branch ULID where data is branch-specific
- warehouse ULID where data is warehouse-specific
- entity type
- entity ULID

Conceptual key:

```text
tenant:{tenant_ulid}:branch:{branch_ulid}:warehouse:{warehouse_ulid}:product:{product_ulid}
```

Rules:

- never expose BIGINT IDs through public/local contracts where ULIDs are expected
- never mix data across tenants
- never show previous-tenant cached data after context switch
- logout/session invalidation must prevent unauthorized cached-data exposure
- secrets, tokens, passwords, signing keys, and unnecessary PII must not be persisted in local catalogue stores

## 9. Shared Data Access Layer

Do not create page-specific caches such as a Purchase-only product cache.

Prefer reusable abstractions such as:

```text
ProductRepository
SupplierRepository
CustomerRepository
CatalogSyncService
LocalDataStore
```

and reusable hooks such as:

```text
useProductLookup()
useProducts()
useSuppliers()
useCustomers()
useUnits()
```

Exact naming should follow current BluePOS code patterns when implementation begins.

The goal is one shared path for the same data, reused across modules.

## 10. Product Lookup Target Behavior

Product lookup is the first performance proof-of-concept.

### User-visible behavior

- dropdown should feel instant after the catalogue has been cached
- the complete catalogue must be available to browse
- typing/search must return relevant products quickly
- selected product should reopen in context and remain visible/highlighted
- user must be able to browse above and below the selected product
- large catalogues must remain responsive

### Implementation principle

“Complete catalogue available” does not require thousands of DOM nodes to render simultaneously.

Use:

- IndexedDB as the reusable local catalogue source
- efficient indexed local queries
- list virtualization/windowing for large result sets
- lightweight row models
- background refresh

Avoid rendering the whole catalogue into the DOM at once.

## 11. Current Temporary Purchase Lookup State

The current Purchase Invoice implementation includes a temporary `lookup_all=true` path that can return the full product catalogue from the server.

This is not the target production architecture for large tenants.

It exists to satisfy the current Purchase Invoice browsing behavior while the shared local-data foundation is not yet implemented.

Target replacement:

```text
server full/relevant sync
        ↓
IndexedDB catalogue
        ↓
local product lookup
        ↓
background refresh / delta sync
```

Once the shared IndexedDB product repository is stable, Purchase Invoice should stop repeatedly downloading the full catalogue on dropdown open.

## 12. Refresh and Sync Strategy

Do not repeatedly download the full dataset after every initial load.

Preferred progression:

### Initial implementation

- first full fetch when local cache is absent
- write to IndexedDB
- subsequent UI reads from IndexedDB
- background refresh checks the server

### Scalable implementation

Move toward incremental/delta refresh:

```text
last sync cursor/version
        ↓
server returns changed/new/deactivated records
        ↓
local transaction updates IndexedDB
        ↓
new sync cursor stored
```

Support deactivated/deleted records safely through tombstones or equivalent versioned invalidation where needed.

Do not invent a delta API until existing backend patterns are inspected.

## 13. Freshness Classes

Different datasets require different freshness policies.

### Relatively stable

Examples:

- units
- categories
- brands
- product master metadata

These can use longer-lived caches with background refresh.

### Moderately dynamic

Examples:

- product prices
- supplier/customer details

Use shorter refresh windows and invalidation after local app mutations.

### Highly dynamic

Examples:

- stock balances
- financial balances

A cached snapshot may be used for fast display, but it must be treated as potentially stale and non-authoritative.

## 14. Stock Rules

Stock display may use a locally cached warehouse snapshot for speed.

However:

- final posting must use server-authoritative stock/inventory flows
- no client stock snapshot may authorize a financial/inventory posting
- no parallel inventory logic may be created in the frontend
- existing posting/inventory services remain authoritative

Future offline Sales stock conflicts will be designed separately when offline Sales work begins.

## 15. Search Performance

Product and party searches should be fast locally after cache population.

Where server search is still used, inspect PostgreSQL query plans and indexing before adding ad-hoc application workarounds.

Potential areas to validate when needed:

- tenant-scoped product number indexes
- SKU/barcode indexes
- tenant/branch/warehouse/product stock indexes
- PostgreSQL trigram indexes for contains-style search if justified
- query payload size
- N+1 relation loading
- heavy resource serialization

Do not add indexes speculatively without inspecting existing migrations/schema/query behavior.

## 16. Frontend Rendering Performance

Large catalogue performance is not only a network problem.

Avoid:

- thousands of mounted row components
- whole-catalogue DOM rendering
- expensive recalculation on each keypress
- repeated serialization/parsing of identical data
- redundant network requests

Prefer:

- virtualization/windowing
- memoized derived rows where justified
- lightweight lookup DTOs
- debounced search
- cancellation of stale requests where server search remains
- cached query results
- prefetch only where it improves real workflows

## 17. Supplier and Other Reference Lookups

After Product lookup foundation is stable, use the same shared pattern for Suppliers.

Expected flow:

```text
IndexedDB supplier cache → immediate dropdown
                    +
background server refresh → local update
```

Do not build separate one-off caching mechanisms per page.

Customer and other shared reference caches should follow the same foundation when their modules need them.

## 18. Performance Targets

These are target goals, not claims about current measured performance:

- cached dropdown reopen: approximately under 100 ms perceived
- normal local product search first result: approximately under 100–200 ms perceived
- server-backed exact barcode/product lookup: target under 100–150 ms where infrastructure permits
- general search first paint: target under 250 ms
- row selection/editing: immediate perceived response
- large catalogue scrolling: smooth without whole-list DOM rendering

Measure before/after. Do not claim targets are achieved without validation.

## 19. Future Offline Sales Reuse

When Sales Invoice / Sales Return offline work begins, reuse this local-data foundation and add:

- local transaction drafts
- outbox queue
- idempotency keys
- retry state
- reconnect synchronization
- server recomputation
- duplicate prevention
- conflict states
- offline/online status controls
- device/session authorization
- required recent Sales Invoice data for offline Sales Return

Do not trust locally calculated totals, stock, balances, or payment state as final server truth.

## 20. Future Offline Module Policy

Planned offline behavior:

```text
Online:
all permitted modules available

Offline:
Sales Invoice   enabled
Sales Return    enabled
other modules   disabled unless explicitly approved later
```

Disabled modules should present a clear connectivity requirement rather than fail unpredictably.

## 21. Recommended Implementation Order

### Phase A — current Purchase Invoice performance work

1. inspect current shared frontend/API patterns
2. design small reusable IndexedDB wrapper/schema
3. implement tenant/branch/warehouse cache namespace
4. implement shared Product repository/cache
5. populate Product cache from existing server API or a lean safe endpoint
6. implement fast local Product lookup
7. virtualize/window large Product lookup UI if needed
8. migrate Purchase Invoice Product lookup to shared repository
9. implement shared Supplier repository/cache
10. migrate Purchase Invoice Supplier lookup
11. add safe background refresh/invalidation
12. measure performance with realistic large datasets
13. remove/retire temporary repeated full-catalogue lookup path when no longer needed

### Phase B — reuse in other online modules

Adopt the same shared repositories/hooks as those modules are worked on.

### Phase C — deferred offline Sales work

Add offline transaction/outbox/sync behavior only when Sales Invoice and Sales Return implementation begins.

## 22. Implementation Safety Rules

All work under this plan must continue to obey BluePOS core rules:

- public APIs expose ULIDs only
- use server tenant/branch/warehouse context
- never trust client tenant IDs
- never trust client permissions
- never trust client money/totals/fees/FX/balances
- recompute financial values server-side
- posted financial records remain immutable
- stock movements remain append-only
- reuse existing accounting/inventory/posting flows
- no parallel financial/inventory logic
- preserve tenant isolation and RBAC
- focused tests required for security-sensitive and financial paths
- no destructive persistent DB commands

## 23. Testing Expectations

For shared local-data infrastructure, cover where relevant:

- first-load cache population
- cached reload
- stale/background refresh
- cache invalidation after mutation
- tenant switch isolation
- branch switch isolation
- warehouse switch isolation
- deactivated/deleted records
- large catalogue lookup
- search correctness
- duplicate local records
- interrupted refresh
- IndexedDB schema/version migration
- browser refresh/restart
- logout/session invalidation
- fallback when IndexedDB is unavailable or fails

For future offline transactions, separate focused tests will be required for idempotency, duplicates, retries, conflicts, server recomputation, stock authority, posting, and recovery.

## 24. Status Checklist

### Planned

- [ ] shared IndexedDB/local-data foundation
- [ ] tenant/branch/warehouse cache namespace
- [ ] shared Product repository/cache
- [ ] local Product lookup
- [ ] Product lookup virtualization/windowing if required
- [ ] background Product refresh
- [ ] shared Supplier repository/cache
- [ ] Purchase Invoice migration to shared Product cache
- [ ] Purchase Invoice migration to shared Supplier cache
- [ ] performance measurements with large catalogue

### Temporary current behavior

- [x] Purchase Invoice can request complete Product catalogue using `lookup_all=true`
- [x] Purchase Product search behavior exists
- [x] selected Product can reopen/highlight in the lookup UI
- [ ] replace repeated full-catalogue server lookup with shared IndexedDB repository

### Deferred

- [ ] offline Sales Invoice
- [ ] offline Sales Return
- [ ] offline outbox
- [ ] reconnect transaction synchronization
- [ ] offline stock conflict policy
- [ ] offline payment rules
- [ ] disable unsupported modules while offline

## 25. Decision Summary

BluePOS will not become a client-authoritative application.

The intended direction is:

**fast local reads + background synchronization + server-authoritative financial/inventory writes.**

Purchase Invoice is the first consumer of this global performance foundation.

Sales Invoice and Sales Return will later reuse the same foundation for offline capability.
