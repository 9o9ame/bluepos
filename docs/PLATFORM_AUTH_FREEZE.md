# Temporary platform authentication freeze

Implemented on `feature/platform-tenant-import`, starting at `07dfedf71c6f10e5ae810cf447b8457a42412fd3`.

Super Admin platform login now uses email/password without an MFA challenge. Successful browser session verification navigates to Dashboard. Existing mandatory password changes still redirect to Change Password before Dashboard. Tenant/POS authentication is unchanged.

`PLATFORM_MFA_ENABLED=false` is the temporary default. Login, recent-MFA checks, and MFA status use this server setting; MFA verify/resend/confirm endpoints return `MFA_DISABLED`. No MFA emails, challenges, or fake verification timestamps are created in disabled mode. The retained implementation can be restored with `PLATFORM_MFA_ENABLED=true` and a configuration-cache refresh. PHPUnit explicitly enables retained MFA tests, while the new disabled-mode suite tests the temporary behavior.

Remember Me is a checkbox selected by default. Laravel stores an encrypted HttpOnly recaller cookie, never a browser-stored password. Selected logins retain that cookie after ordinary Sign Out, as explicitly requested. Sign Out revokes the current platform session and clears its cached platform data; the login page remains visible until another visit or reload restores access. Unselected logins clear an existing recaller. Account disablement, password/security-version changes, and Logout All still revoke access. Cookie expiry or deletion ends remembered access.

No schema changes or dependencies were needed. `php artisan config:clear` was run locally; a read-only Laravel bootstrap confirmed `platform_mfa_enabled: false`. Browser verification remains a manual check; automated coverage exercises cookie responses, fresh requests, cache observers, and dashboard authorization.

Changed/added files:

- `backend/.env.example`
- `backend/config/security.php`
- `backend/phpunit.xml`
- `backend/app/Platform/PlatformMfaPolicy.php`
- `backend/app/Platform/RecentPlatformMfa.php`
- `backend/app/Actions/Platform/LoginPlatformUserAction.php`
- `backend/app/Actions/Platform/EstablishPlatformSessionAction.php`
- `backend/app/Http/Controllers/Platform/PlatformAuthController.php`
- `backend/app/Http/Middleware/EnsurePlatformContext.php`
- `backend/app/Http/Resources/Platform/PlatformUserResource.php`
- `backend/tests/Feature/PlatformMfaDisabledTest.php` (new)
- `frontend/src/features/platform/PlatformAuthProvider.tsx`
- `frontend/src/features/platform/authenticatePlatformSession.ts`
- `frontend/src/pages/platform/PlatformLoginPage.tsx`
- `frontend/src/pages/platform/PlatformAccountSecurityPage.tsx`
- `frontend/tests/platform-session.test.mjs`
- `docs/SECURITY.md`
- `docs/PLATFORM_AUTH_FREEZE.md` (new)

Commands/validation:

- Backend tests run with explicit `APP_ENV=testing`, `DB_CONNECTION=pgsql`, `DB_DATABASE=bluepos_test`, and an empty `DB_URL`, using `DatabaseTransactions`.
- `php artisan test --env=testing --filter=PlatformMfaDisabledTest`: 10 passed, 66 assertions.
- `php artisan test --env=testing --filter='PlatformMfaDisabledTest|PlatformPersistentLoginTest|PlatformRememberMeTest|PlatformAccessTest|PlatformTest|AuthenticationTest'`: 84 passed; one retained MFA test initially received email-delivery 503 because the process inherited the local mail environment. No application/test change was needed for that failure.
- With explicit `MAIL_MAILER=array`, `MAIL_FROM_ADDRESS=no-reply@example.com`, and `MAIL_FROM_NAME=BluePOS`, rerunning `test_valid_platform_password_requires_mfa` passed (6 assertions).
- Added disabled-mode permission test `test_disabling_mfa_does_not_grant_missing_platform_permissions`: passed (6 assertions). This makes 86 distinct backend tests passing across the combined run and focused reruns, including 11 disabled-mode tests.
- `php vendor/bin/pint` on the changed PHP files: passed (controller formatting adjusted).
- `node --test tests/platform-session.test.mjs`: 4 passed, including sign-out followed by a new remembered visit.
- `npm.cmd run typecheck`: passed.
- `npx.cmd vite build`: passed; existing bundle-size and mixed-import warnings remain.
- ESLint on changed React files: no errors; existing Fast Refresh warning remains in the provider.
- `php artisan config:clear`: passed. Tinker could not write its history outside the workspace, so runtime inspection used a read-only PHP bootstrap instead.
- Git diff/branch checks confirm only the listed files were changed; main and sales-ui-fixes references and Sales Invoice files remain untouched by this change. No merge or push was performed.
