# BluePOS — Development Log

> Append-only development history. Updated on each validated change. See `BLUEPOS_CURRENT_STATE.md` for the current active checkpoint. Historical test totals are snapshots, not cumulative.

## 2026-10-26 — Super Admin / Device Security

**Branch:** `feature/superadmin` (temporary legacy branch continuation, user-approved for ongoing work; `main` untouched).

| Commit | Change | User-reported local validation |
| --- | --- | --- |
| `9b0d2829` | Session revocation service freshness checks | 23 tests, 131 assertions |
| `5a47c01b` | Offline authorization rechecks lease/device/membership | 24 tests, 132 assertions |
| `e7ba5b64` | Device approval branch/warehouse consistency | 25 tests, 137 assertions |
| `c8286b40` | Device reassignment transactional consistency | 26 tests, 141 assertions |
| `a4b641a1` | Suspended tenant blocked during offline lease issuance | 27 tests, 143 assertions |
| `472ff4fe` | Device list identifies legacy_grandfathered vs managed without changing existing access | New test initially needed JSON-shape correction |
| `3de2c137` | Correct regression assertion for unwrapped resource collection | Focused test 1 passed / 5 assertions; full `AuthenticationTest.php` 28 passed / 148 assertions |

**Not yet verified/completed:**
- Independent PostgreSQL connections running simultaneous approve/reject or equivalent device-management race test (existing transaction-backed tests do not prove parallel behavior).
- Offline sync end-to-end authorization and revoke scenarios.
- Legacy terminal controlled migration/enforcement rollout (must not silently remove legacy access).
- Whole security module/release validation and branch reconciliation.

**Important constraints:** Use `bluepos_test` only for tests; no destructive DB commands; no forced Git operations; all tenant/branch/warehouse ownership server-enforced; public ULIDs only; preserve current legacy device policy until a controlled migration is approved.

## Future update format

### YYYY-MM-DD — Task/batch
- Branch and commit:
- Why / behavior:
- Exact changed files and migrations:
- Focused test command + actual result, identify local vs CI vs unrun:
- Remaining risk:
- Next concrete task:

### 2026-10-26 — Tenant-isolation regression fixture correction
- Commit: `0718e036` on `feature/superadmin`
- Changed: `backend/tests/Feature/AuthenticationTest.php`; no migrations or production behavior changes.
- User local run before fix: 29 tests, 148 assertions, 1 error (missing second tenant Device at line 293).
- Root cause: `provisionOwner()` does not automatically create a device; the test attached a trusted device only for the first tenant, then queried a device for the second tenant.
- Fix: use existing `attachTrustedDevice()` fixture for second tenant; restore first tenant's credential before login.
- Validation: NOT RUN after fix; user should pull and run focused test/full AuthenticationTest, using `bluepos_test`.
- Next: after PASS, independent PostgreSQL connection race test design/implementation; offline sync E2E thereafter.

### 2026-10-26 — Generic device approval operational-status guard
- User local validation before this batch: `AuthenticationTest.php` **29 passed, 154 assertions** at `44a7b8c5`.
- Commits: `dc26a1a5` (controller), `a144b177` (regression).
- Files: `backend/app/Http/Controllers/Api/DeviceController.php`, `backend/tests/Feature/AuthenticationTest.php`. Migrations: none.
- Pending device generic approval now uses the freshly locked tenant model for `assertOperational` and `assertCanRegisterDevice`, not potentially stale TenantContext tenant state.
- Regression: generic approval of pending device after tenant suspension returns `TENANT_DISABLED`, device remains pending. Note: auth middleware may also block suspension before controller, so this is not proof of the transaction-level race invariant.
- **Latest test not run locally yet; expected 30 total tests**. Independent-connection PostgreSQL simultaneous review/approval race still pending; do not claim covered.

### 2026-10-26 — PostgreSQL concurrency connection probe (not domain race test)
- Previous user local validation: `AuthenticationTest.php` **30 passed / 159 assertions** at `e490a322`.
- Commit: `fb609e32`; file: `backend/tests/Feature/PostgreSqlConcurrencyConnectionTest.php`. No migrations or production changes.
- Adds two independent PostgreSQL backend connections against `bluepos_test`; verifies distinct PostgreSQL backend PIDs, transaction-scoped advisory lock contention, and lock release after rollback. Read-only, no table inserts or cleanup of persistent data.
- **Unrun locally**. This proves connection isolation/locking only; **not** actual concurrent device approve/reject or HTTP request races.
- Next: validate probe locally, then add a proper committed-fixture, independently executing domain race regression with bounded waits and safe cleanup.

### 2026-10-26 — Device row-lock concurrency probe (pending validation)
- Local user validation before batch: PostgreSQL concurrency connection test **1 passed, 6 assertions** and AuthenticationTest **30 passed, 159 assertions**.
- Commit: `5d15492e`; file: `backend/tests/Feature/PostgreSqlConcurrencyConnectionTest.php`. No application changes/migrations.
- Added isolated, committed test-only pending device fixture in `bluepos_test`; primary PostgreSQL connection locks actual `devices` row and independent connection must receive SQLSTATE `55P03` with NOWAIT. After primary rollback, secondary acquires row lock. Both transactions rollback and fixture deleted in finally.
- **Unrun locally**; expected two tests in probe file. An interrupted process could leave a uniquely tagged test fixture; investigate before manual cleanup, never wipe DB.
- This is real table-row lock contention but still **not** an end-to-end concurrent approve/reject HTTP request race; that remains pending.

### 2026-10-26 — Concurrency probe local verification and domain-race scoping
- **Verified by user locally:** `PostgreSqlConcurrencyConnectionTest.php`: **2 passed, 11 assertions**; `AuthenticationTest.php`: **30 passed, 159 assertions**, after commit `b7205020`.
- Inspected: `DeviceController::reviewAccessRequest`, `DeviceController::approve`, `tests/TestCase.php`, `tests/Support/TestDatabaseGuard.php`.
- Both review and generic approval lock the tenant row; review also locks request/device. Existing `AuthenticationTest` employs `DatabaseTransactions`, making its fixtures invisible across PostgreSQL connections.
- **Next implementation boundary:** need a dedicated lifecycle for committed tenant, membership, device and request fixtures plus independent process/request coordination. Test-only cleanup must be precise and resilient; do not remove or wipe unrelated `bluepos_test` data.
- No new domain race test implemented or executed in this checkpoint; two successful probes are not evidence of actual conflicting HTTP requests.

### 2026-10-26 — Two-connection device decision collision regression (awaiting local run)
- Local validation before batch: PostgreSQL concurrency **2/2, 11 assertions**, Authentication **30/30, 159 assertions**.
- Commit `b5c15687`; changed `backend/tests/Feature/PostgreSqlConcurrencyConnectionTest.php` only. No migrations or production changes.
- New test uses two distinct connections and a committed test-only pending device in `bluepos_test`. First connection locks and activates; second cannot acquire the device row while the first transaction holds it (SQLSTATE 55P03), then after commit sees `active` and the conditional losing write updates zero rows. Finally rollback/cleanup.
- **Not yet run on user's PostgreSQL.** This is a database-level decision-collision test, not simultaneous HTTP requests or an end-to-end `reviewAccessRequest` test. Full approve/reject endpoint race remains pending.

### 2026-10-26 — Approved access-review replay regression (local validation pending)
- User confirmed latest `PostgreSqlConcurrencyConnectionTest.php`: **3 passed, 19 assertions** and `AuthenticationTest.php`: **30 passed, 159 assertions** at `f6993662`.
- Commit `a2552653` adds reverse-direction replay protection coverage in `backend/tests/Feature/AuthenticationTest.php`: after **approved**, attempt **rejected** must yield `ACCESS_REQUEST_ALREADY_REVIEWED`, preserving approved request and active device.
- Tests not run after commit; expected AuthenticationTest count 31. No migrations or production code changes.
- Existing sequential test covered rejected→approved; both directions are now covered sequentially. **Actual simultaneous HTTP approve/reject race still pending**. No claim of complete race coverage.
