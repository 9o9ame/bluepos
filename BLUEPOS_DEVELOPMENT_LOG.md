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
