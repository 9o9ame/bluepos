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

### 2026-10-26 — Approved/rejected replay validation and HTTP concurrency boundary
- **User local validation confirmed:** AuthenticationTest.php **31 passed / 166 assertions**; PostgreSqlConcurrencyConnectionTest.php **3 passed / 19 assertions** at `68e1b88c`.
- Inspected routes: `POST /api/devices/access-requests/{requestUlid}/review` requires `auth:sanctum` and tenant middleware; device management lives in DeviceController.
- An actual simultaneous HTTP race requires independently authenticated workers, committed isolated tenant/device/member/access-request fixtures visible across processes, a synchronization barrier, bounded timeouts, deterministic winner/loser assertions and robust cleanup.
- Existing 3 DB concurrency tests **are not** HTTP-level proof. No new concurrency code committed in this checkpoint: avoiding a misleading sequential test or unsafe persistent fixture creation.
- Next: implement isolated HTTP race harness before claiming concurrency phase complete. Keep it restricted to `bluepos_test`, never use destructive cleanup. 

### 2026-10-26 — Verified replay tests; HTTP concurrency implementation safety gate
- User validated at `27bb8c9f`: **31/31 authentication tests, 166 assertions** and **3/3 concurrency tests, 19 assertions**.
- Current review route `POST /api/devices/access-requests/{requestUlid}/review` requires Sanctum session and tenant middleware. Cross-process request execution needs authenticated sessions and committed fixtures visible to both workers. Existing `DatabaseTransactions` fixture approach cannot be reused.
- Implementation safety gate: first establish an isolated, abort-safe fixture lifecycle (tenant, membership, device, request, owner session) and bounded two-worker synchronization without pointing a server to a persistent/dev DB. No unsafe test harness or unverified simultaneous HTTP test was committed.
- **Next implementation is still the real HTTP approval/rejection race test**, not another PostgreSQL lock simulation. Preserve exactly-one-success result, 409 losing decision, no unintended device activation, no extra audit side effects. Do not call this completed before local execution.

### 2026-10-26 — Combined access-review negative-case security batch (awaiting local validation)
- Last user-confirmed baseline: `AuthenticationTest.php` 31/31 PASS (166 assertions), `PostgreSqlConcurrencyConnectionTest.php` 3/3 PASS (19 assertions).
- Code commits: `dc67d651`, `737e16f0` to `backend/tests/Feature/AuthenticationTest.php`. No production code or migrations.
- Added coverage: both approve and reject refuse suspended employee; both decisions refuse revoked device; invalid/missing decision rejected; owner membership cannot be processed as employee access. No mutations on rejected attempts.
- Expected `AuthenticationTest.php` count **33** (assertion total unknown). **Not run locally yet.**
- Outstanding: genuine two-worker authenticated HTTP approve/reject race, offline sync end-to-end tests, controlled legacy terminal policy rollout; existing lock probes are not a substitute for HTTP race proof.

### 2026-10-26 — Batched device review and offline lease hardening (local validation pending)
- User verified prior baseline: `AuthenticationTest.php` **33 PASS / 187 assertions**; `PostgreSqlConcurrencyConnectionTest.php` **3 PASS / 19 assertions**.
- Production commits: `dd4eaa30` `DeviceController::reviewAccessRequest` scopes final update by tenant + `status=pending`, requires exactly one affected row, rejects conflicting/repeated decision with 409 transaction rollback. `ee78cd19` `OfflineAuthorizationService::assertValid` rechecks actual tenant operational status, denying old leases on suspension.
- Test commit: `1c62e921` adds valid-then-suspended tenant offline lease regression to `AuthenticationTest.php`. Expected **34 authentication tests**; **not run locally**. The earlier 3 PostgreSQL concurrency tests remain unchanged.
- No migrations or UI changes. Main untouched. Actual two-worker HTTP review race and offline sync E2E still pending; do not infer they pass from this batch.

### 2026-10-26 — Offline authorization disabled-user guard (combined security work; pending local validation)
- Last user verified: AuthenticationTest **34/34, 188 assertions**, PostgreSQL concurrency **3/3, 19 assertions**.
- `c777101e`: `OfflineAuthorizationService` checks freshly loaded user account status during issuance and validation, and validates lease.user_id equals membership.user_id. Suspended/nonoperational tenant guard from prior batch retained.
- `5c93b7d9`: regression validates a disabled user's existing lease becomes invalid and subsequent issuance cannot create another lease.
- Production + test files changed; no migrations/UI changes. Local tests not yet run; expected **35 AuthenticationTest** + **3 PostgreSQL concurrency**.
- Progress by milestone: Device authorization/lease hardening implemented and tested to prior baseline, new disabled-user guard pending validation. Actual two-worker HTTP review race NOT complete. Offline sync E2E NOT complete. Controlled legacy POS migration NOT complete. Final regression/review NOT complete.

### 2026-10-26 — Offline lease branch-reassignment enforcement (validation pending)
- User confirmed baseline after `276b67ba`: AuthenticationTest 35 PASS / 191 assertions, PostgreSQLConcurrencyConnectionTest 3 PASS / 19 assertions.
- `223eae30`: OfflineAuthorizationService now rejects existing leases when a device is reassigned to another branch or lease branch is missing/outside tenant.
- `67525068`: regression issues valid lease, reassigns device to a different tenant-owned branch, then expects `OFFLINE_AUTH_EXPIRED`. Expected AuthenticationTest **36 tests**, not yet verified locally.
- Inspection: backend `routes/api.php` does not currently expose an offline sync endpoint; therefore offline *sync E2E* cannot be claimed complete or safely invented without inspecting existing offline client workflow/domain integration.
- Roadmap: HTTP approve/reject race **pending**; offline authorization hardening **advanced**, offline sync E2E **pending**; legacy device rollout **pending**; final regression/review **pending**. No migrations or UI changes.

### 2026-10-26 — Offline branch-state enforcement batch (awaiting local test)
- Last user-confirmed baseline: AuthenticationTest **35 tests / 191 assertions PASS** and PostgreSqlConcurrencyConnectionTest **3 tests / 19 assertions PASS**. Branch-reassignment regression added before this batch has not yet been validated by user.
- Code commit `6986c9eb`: `OfflineAuthorizationService` checks fresh tenant-owned branch status is active before issuing a lease and during validation; denies inactive branches, while retaining reassignment and ownership checks.
- Regression commit `680177f5`: previously valid lease fails after branch deactivation; subsequent issuance blocked; no second lease created.
- Together with immediately preceding `223eae30`/`67525068` reassignment batch, expected `AuthenticationTest.php` count **37**, PostgreSQL concurrency **3**. Actual tests pending; no migrations/UI changes.
- Roadmap status: foundation verified; real simultaneous authenticated HTTP review race PENDING; offline lease guards advanced but offline sync E2E PENDING; legacy POS managed-access rollout PENDING; final security review PENDING. Main untouched.

### 2026-10-26 — Functional device access review history and pagination
- Based on latest user-verified 38 Auth tests (211 assertions) and 3 PG concurrency tests (19 assertions).
- `7f6cea49`: `GET /api/devices/access-requests` adds status filter `pending|approved|rejected|all`, validated `page` and `per_page` (1..100), deterministic order, reviewed_at, pagination metadata; pending_count remains independent of filter. Tenant-scoped join and existing viewAny authorization preserved.
- `5f036107`: focused feature regression for owner filtering, pagination and 422 bad query parameters.
- Expected 39 Authentication tests (local validation pending). No migration/UI. HTTP concurrency stress testing deferred, not reported as verified.
