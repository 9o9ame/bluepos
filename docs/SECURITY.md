# BluePOS Security

Production-grade controls for a multi-tenant POS that handles money, stock, PII, and offline terminals.

Authorization is **always** server-side. Hidden UI is not a control.

Companion: `docs/PERMISSIONS.md`, `docs/OFFLINE_SYNC.md`, `AGENTS.md`.

---

## 1. Threats this design addresses

Cross-tenant IDOR, cross-branch IDOR, privilege escalation, replay/duplicate posting, CSRF, XSS, SQLi, mass assignment, brute-force login, secret leakage, unsafe backups/restore, device theft/reuse, PII in logs, stack traces in production.

---

## 2. Authentication (AuthN)

### Browser SPA

- Laravel Sanctum **cookie session** (same-site, HTTP-only, Secure in production, `SameSite=Lax` or `Strict` as configured).
- CSRF: Sanctum CSRF cookie + `X-XSRF-TOKEN` on mutating requests. Laravel `PreventRequestForgery` / CSRF middleware stays enabled for cookie auth.
- Session invalidation on logout, password change, and membership suspend.

### Login identifier

Staff identity is **tenant code + username + password**. Username is unique per tenant, not globally. Email is a recovery channel, not the primary login identifier. Rate-limited (section 8).

Public self-registration is disabled (`REGISTRATION_DISABLED`). First tenants are provisioned with `php artisan bluepos:tenant-create`.

### POS device

- Device enrollment issues a server-generated credential (`ulid.secret`) stored hashed. The browser keeps it in an HttpOnly `bluepos_device` cookie or `X-BluePOS-Device` header.
- Browser fingerprints, IP, and user-agent are risk metadata only and never prove a trusted device.
- Staff login on an unknown device returns `DEVICE_NOT_APPROVED` and does not establish a session.
- Owner/Admin unknown-device login returns `MFA_REQUIRED` (email OTP now; WebAuthn/TOTP reserved).
- OTP policy is centralized in `config/security.php`. Delivery uses `SecurityMailService`. See `docs/MAIL.md`.
- Devices belong to the tenant/branch and may be shared by multiple staff. User identity is separate from device identity.
- Revoke → `DEVICE_REVOKED`. Bound sessions and offline leases are invalidated.

### Passwords

- Laravel hashing (`bcrypt`/`argon2`). Never store plaintext.
- Reset tokens hashed, short-lived.

### Local Platform login development

Platform administrators may bypass email OTP only when both settings are active:

```dotenv
APP_ENV=local
PLATFORM_DEV_BYPASS_MFA=true
```

In that local-only mode, a valid Platform email and password establish the Platform session directly and no OTP challenge is created or sent. The password, active-account, `security_version`, RBAC, forced-password-change, session-regeneration, and audit controls still apply. Local development login also satisfies the existing recent-MFA marker.

**Never rely on or enable this bypass outside local development.** The application ignores the flag in testing, staging, production, and every environment other than `local`.

Platform login supports secure server-side Remember Me through Laravel's HttpOnly remember cookie. Explicit logout and `security_version` changes invalidate remembered access. Tenant/POS Remember Me is intentionally not supported.

### Login identifier

Email (globally unique) plus optional username. Rate-limited (section 8).

### Impersonation

Super Admin only. Requires `platform.impersonate.start` (`superadmin.impersonate`), non-empty `reason`, audit `started_at` / `ended_at` / **`expires_at` (default 30 minutes)**, persistent banner. Session auto-ends at expiry. Does not skip tenant policies after start; it creates a membership-scoped session that is fully audited. Default Super Admin APIs still cannot read journals without impersonation.

---

## 3. Authorization (AuthZ)

- Every mutating endpoint: Form Request + Policy.
- Tenant from membership/device context, never from trusted payload fields.
- Global `BelongsToTenant` scope on tenant models. Removing it is allowed only in platform jobs/tests, never in tenant HTTP.
- Branch scope via `membership_branches` / `all_branches` on assigned roles. `memberships.is_owner` is a derived flag, not an authorization bypass.
- Licensed modules: `tenant_modules` gate (e.g. offline, claims).
- **Do not** use `Gate::before` that returns true for all platform admins on tenant models.
- **Do not** `Model::findOrFail($request->id)` (or ULID) then only check a generic permission. Required: membership + tenant scope + branch scope + parent ownership + permission. See `docs/ARCHITECTURE.md` §3.5.

See `docs/PERMISSIONS.md`.

---

## 4. IDOR and isolation

| Rule |
|---|
| Bind routes by public ULID inside tenant-scoped queries |
| Cross-tenant miss → **404**, not 403 with existence leak |
| Ignore client `tenant_id` / unauthorized `branch_id` / `warehouse_id` |
| Warehouse must belong to authorized branch |
| Reports and exports use the same scopes as list endpoints |
| Super Admin tenant financial access only via impersonation |

Tests: Tenant A cannot read Tenant B sale/product/customer/user/settings. Branch cashier cannot read another branch sale.

---

## 5. Mass assignment and validation

- Models: `$fillable` explicit; never `tenant_id`, `posted_at`, `avg_cost`, `document_no` from request on posted docs.
- Form Requests whitelist fields.
- Zod on the client is UX; server validation is authoritative.
- No `request()->all()` into `create()`/`update()`.

---

## 6. Injection and XSS

- Eloquent / query bindings only. No concatenated SQL.
- Report filters: whitelist columns and operators.
- Frontend: React default escaping. Do not `dangerouslySetInnerHTML` for user/party names on receipts without sanitization.
- CSP in production (Phase 19/20).
- File uploads: authenticated, size/type whitelist, stored outside public web root or with randomized names; virus scan later if needed. Never execute uploaded content.

---

## 7. CSRF, cookies, sessions

- Cookie SPA: CSRF required.
- Token-authenticated device API: CSRF not applicable; still require `Accept: application/json`, Sanctum token, device status.
- Production cookies: `secure`, `httpOnly`, `encrypted` Laravel session.
- Session fixation: regenerate on login.

---

## 8. Brute force and rate limiting

- `login`: throttle by IP + identifier (e.g. 5/min).
- Financial POST: throttle per user/device in addition to idempotency.
- Platform APIs: stricter throttle.
- Lock/alert after repeated failures (Phase 16+).

---

## 9. Secrets

- `.env` only, never committed. `.env.example` has empty placeholders.
- No secrets in frontend bundles.
- Backup encryption keys in env/secret manager, not in the repo.
- Rotate Sanctum keys / `APP_KEY` with a documented procedure (Phase 20).

---

## 10. PII-safe logging and audit

**Audit (`audit_logs`):** login, logout, sale create/void, returns, purchases, stock adjust/transfer, vouchers, role/permission/user/settings changes, backup/restore, price changes, impersonation, device revoke.

Store: tenant, branch, user, device, action, resource type, resource ULID, timestamp, IP, safe metadata, before/after **redacted**.

Tenant users may `audit.view` if granted. They **cannot** update or delete audit rows (no API, no policy). Platform retention jobs only.

**Never log or store:** password, session cookie, Sanctum token, Authorization header, database credentials, full PAN, CVV, track data, secrets. Do not log CNIC/NTN in application log files.

---

## 10A. Card / payment data (PCI-DSS minimization)

This POS records **payment methods and amounts**, not cardholder data.

- Do **not** store full PAN.
- Do **not** store CVV/CVC.
- Do **not** store track data.

External terminals/gateways: store `gateway_reference`, `terminal_reference`, optional masked last-4 if the integrator supplies it. Card capture UI must not bind PAN into IndexedDB or Laravel tables.

---

## 10B. File uploads

Attachments (expenses, claims, vouchers, supplier docs):

- Authenticated + authorized against the parent document tenant
- MIME verified from content, not client `Content-Type` alone
- Extension allow-list (pdf, jpg, png, webp — no exe/html/js)
- Size limit (e.g. 10 MB)
- Randomized server filename; original name metadata only
- Private disk; download through authorized route
- Malware scan hook (optional job)
- **Never execute** uploaded content; never serve as `text/html`

---

---

## 11. Production error handling

Never expose stack traces, SQL, internal paths, env values, or secrets.

Stable envelope:

```json
{
  "error": {
    "key": "INSUFFICIENT_STOCK",
    "message": "Requested quantity is not available.",
    "details": {}
  }
}
```

Reserved keys (non-exhaustive): `FORBIDDEN`, `UNAUTHENTICATED`, `VALIDATION_FAILED`, `INSUFFICIENT_STOCK`, `UNBALANCED_JOURNAL`, `IDEMPOTENCY_KEY_REUSED`, `DEVICE_REVOKED`, `TENANT_SUSPENDED`, `EXPIRED_BATCH`, `CREDIT_LIMIT_EXCEEDED`, `CONFLICT`, `SEQUENCE_FAILED`.

HTTP mapping: 401 unauthenticated, 403 forbidden, 404 not found, 409 conflict, 422 validation, 429 throttle, 500 generic `INTERNAL_ERROR` message only.

---

## 12. Financial integrity (security-relevant)

- Idempotency on all financial POSTs (section 13).
- Transactions + `lockForUpdate()`.
- Server recalculates money (BCMath).
- Posted documents: no hard delete, no silent edit.
- Restore and void always audited.

---

## 13. Idempotency

Required header `Idempotency-Key` on:

`POST /sales`, payments, sales-returns, purchases, purchase-returns, vouchers, stock-adjustments, stock-transfers, offline-sync items, cash session close.

Required unique: `(tenant_id, idempotency_scope, idempotency_key)`.

**Atomic claim:** `INSERT` row `status=started` inside the posting transaction. If a unique violation occurs, `SELECT … FOR UPDATE` the existing row.

| Case | Result |
|---|---|
| Same tenant + scope + key + same request hash, completed | Original response |
| Same key + same hash, started | Wait/retry or `409 IDEMPOTENCY_IN_PROGRESS` |
| Same key + different hash | `IDEMPOTENCY_KEY_REUSED` |
| Missing key on required route | `422 IDEMPOTENCY_KEY_REQUIRED` |

**Forbidden:** SELECT if key exists then INSERT without the unique constraint (two concurrent requests can both pass the lookup).

Retries must not duplicate sale, payment, movements, or journals.

---

## 14. Device revocation

Admin sets `devices.status = revoked`, invalidates Sanctum token.

Reconnect: `DEVICE_REVOKED`. Outbox remains local until a manager policy (Phase 16) exports/resolves. Revoked devices must not post.

---

## 15. Backup and restore security

- Backups encrypted at rest (key from secrets).
- `backups.create` vs `backups.restore` are separate permissions.
Restore is **not** a single button that overwrites production.

Workflow:

1. Select backup
2. Verify integrity (checksum / decrypt test)
3. Verify tenant/system context (wrong backup must not restore onto another tenant DB)
4. Permission `database.restore`
5. Re-authenticate
6. Explicit confirmation phrase
7. Maintenance mode / restore window
8. Restore to a **side location** or rehearsed procedure first when possible
9. Audit event
10. Post-restore health checks

Phase 20 includes restore **rehearsal** on non-production. Never `DROP DATABASE` as the restore mechanism in the app UI.
- Download of backup files: authenticated, authorized, logged.
- **Never** expose DB credentials to the frontend.
- **Never** offer Drop Database as a normal action.
- Agents and app code must not run `migrate:fresh`, `db:wipe`, `DROP DATABASE`, unstructured `DROP TABLE`, or `TRUNCATE` of business tables on persistent databases.

---

## 16. Headers and HTTPS

Production: HTTPS only, HSTS, `X-Content-Type-Options`, `X-Frame-Options`/`frame-ancestors` for the SPA, referrer policy. Sanctum stateful domains allowlist.

---

## 17. Tests (security)

- Unauthenticated 401.
- Cross-tenant 404.
- Cross-branch 404.
- Mass-assign `tenant_id` ignored.
- Missing idempotency key rejected on POST /sales.
- Duplicate key no duplicate sale.
- Rate limit login.
- Restore without `backups.restore` → 403.
- Production-like `APP_DEBUG=false` does not return stack traces (feature test).
