# BluePOS transactional email

Production-ready security email for MFA and password recovery. Provider credentials stay in the environment. Application code is transport-agnostic (SMTP today; Postmark / SES / Resend / Mailgun via env).

## Configuration

Copy placeholders from `backend/.env.example`. Never commit real `MAIL_USERNAME` / `MAIL_PASSWORD`.

| Environment | Mailer |
|---|---|
| local | SMTP to Mailpit (`127.0.0.1:1025`, UI typically `:8025`) |
| testing | `MAIL_MAILER=array` (PHPUnit). `Notification::fake()` / `Mail::fake()` in tests. No external delivery. |
| production | SMTP (or Laravel mailer) for Postmark / Amazon SES / Resend / Mailgun |

```
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=
MAIL_FROM_ADDRESS=no-reply@your-domain
MAIL_FROM_NAME="BluePOS"
```

OTP policy (`config/security.php`, env overrides):

```
OTP_LENGTH=6
OTP_TTL_MINUTES=10
OTP_RESEND_COOLDOWN_SECONDS=60
OTP_MAX_VERIFY_ATTEMPTS=5
```

Sender identity always comes from `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME`. Do not hardcode a personal Gmail address in code.

## Operator SMTP test

A. Set local SMTP in `backend/.env` (Mailpit recommended).

B. `php artisan config:clear`

C. `php artisan bluepos:mail-test YOUR_TEST_EMAIL`

The command does not print `MAIL_PASSWORD`, does not create an MFA challenge, and does not change authentication state.

D. Start backend (`php artisan serve`) and frontend (`npm run dev`).

## Platform MFA OTP

E. Sign in at `/platform/login` from a browser (new device).

F. Confirm the verification email arrives (Mailpit or production provider).

G. Enter the 6-digit code.

H. Confirm the platform session is established (`/platform`).

I. Repeat with an invalid code → `MFA_INVALID`.

J. Click Resend immediately → cooldown (`OTP_RESEND_COOLDOWN`). Wait 60 seconds, resend, previous code must not work.

## Tenant MFA (new/untrusted device)

Owner/Admin login from an unknown device follows the same OTP email (`NEW_DEVICE_VERIFICATION`). Resend is `/api/auth/mfa/resend`.

## Forgot password

K. Tenant: `/forgot-password` with mart code + username. Response is always generic: “If the account exists, password reset instructions have been sent.”

Platform: `/platform/forgot-password` with the stored platform email. Lookup is separate from tenant users.

OTP destination is never chosen by the client. Platform MFA uses the authenticated platform user email. Recovery uses the stored recovery/platform email.

## Safety

- OTP is hashed at rest. Plaintext exists only while sending.
- OTP is not written to application or audit logs.
- SMTP failures return `EMAIL_DELIVERY_FAILED` without transport secrets.
- Forgot-password always returns the generic message (no account enumeration).
- `MAIL_MAILER=log` writes full message bodies to `storage/logs` — do not use it for OTP in shared environments. Prefer Mailpit.
