import { FormEvent, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { ApiClientError } from '../../api/client'
import { usePlatformAuth } from '../../features/platform/PlatformAuthProvider'

export function PlatformLoginPage() {
  const { user, isLoading, login, verifyMfa, resendMfa } = usePlatformAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)
  const [mfaCode, setMfaCode] = useState('')
  const [challengeUlid, setChallengeUlid] = useState<string | null>(null)
  const [recoveryHint, setRecoveryHint] = useState<string | null>(null)
  const [deliveryHint, setDeliveryHint] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [resending, setResending] = useState(false)

  if (!isLoading && user) {
    return <Navigate to={user.must_change_password ? '/platform/change-password' : '/platform'} replace />
  }

  function applyMfaChallenge(err: ApiClientError): void {
    if (typeof err.extra.challenge_ulid === 'string') {
      setChallengeUlid(err.extra.challenge_ulid)
    }
    setRecoveryHint(typeof err.extra.recovery_hint === 'string' ? err.extra.recovery_hint : null)
    setDeliveryHint(typeof err.extra.delivery_hint === 'string' ? err.extra.delivery_hint : null)
    setMfaCode('')
    setError(err.key === 'MFA_REQUIRED' ? null : err.message)
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      if (challengeUlid) {
        const signedIn = await verifyMfa({ challenge_ulid: challengeUlid, code: mfaCode, trust_device: true })
        navigate(signedIn.must_change_password ? '/platform/change-password' : '/platform', { replace: true })
        return
      }
      const signedIn = await login({ email, password, remember })
      navigate(signedIn.must_change_password ? '/platform/change-password' : '/platform', { replace: true })
    } catch (err) {
      if (
        err instanceof ApiClientError &&
        (err.key === 'MFA_REQUIRED' || err.key === 'EMAIL_DELIVERY_FAILED' || err.key === 'OTP_RESEND_COOLDOWN')
      ) {
        applyMfaChallenge(err)
        return
      }
      setError(err instanceof ApiClientError ? err.message : 'Unable to sign in.')
    } finally {
      setSubmitting(false)
    }
  }

  async function onResend() {
    if (!challengeUlid) {
      return
    }
    setError(null)
    setResending(true)
    try {
      await resendMfa(challengeUlid)
    } catch (err) {
      if (
        err instanceof ApiClientError &&
        (err.key === 'MFA_REQUIRED' || err.key === 'EMAIL_DELIVERY_FAILED' || err.key === 'OTP_RESEND_COOLDOWN')
      ) {
        applyMfaChallenge(err)
        return
      }
      setError(err instanceof ApiClientError ? err.message : 'Unable to resend the code.')
    } finally {
      setResending(false)
    }
  }

  return (
    <div className="platform-auth-page flex min-h-screen items-center justify-center bg-[var(--ui-bg)] p-4">
      <div className="platform-auth-card w-full max-w-md rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] text-[var(--ui-text)] shadow-xl">
        <div className="border-b border-[var(--ui-border)] px-5 py-3">
          <div className="text-[11px] font-black tracking-[0.18em] text-[var(--ui-accent)]">BLUEPOS PLATFORM</div>
          <h1 className="text-lg font-semibold">Platform Administration</h1>
        </div>
        <form className="space-y-3 px-5 py-4" onSubmit={onSubmit}>
          <label className="block text-[12px] font-semibold">
            Email
            <input
              autoFocus
              required
              type="email"
              autoComplete="username"
              className="mt-1 h-9 w-full rounded border border-[var(--ui-border)] bg-[var(--ui-surface-subtle)] px-2"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          </label>
          <label className="block text-[12px] font-semibold">
            Password
            <input
              type="password"
              required={!challengeUlid}
              autoComplete="current-password"
              className="mt-1 h-9 w-full rounded border border-[var(--ui-border)] bg-[var(--ui-surface-subtle)] px-2"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          </label>
          <label className="flex items-center gap-2 text-[12px] font-semibold">
            <input type="checkbox" checked={remember} disabled={submitting || !!challengeUlid}
              onChange={(event) => setRemember(event.target.checked)} />
            Remember Me
          </label>
          <p className="text-[12px] text-[var(--ui-text-muted)]">Remembered browsers can return without signing in again, including after Sign Out.</p>
          {challengeUlid ? (
            <>
              <p className="text-[12px] text-[var(--ui-text-muted)]">
                Additional verification is required{recoveryHint ? ` (${recoveryHint})` : ''}.
              </p>
              {deliveryHint ? <p className="text-[12px] text-[var(--ui-warning)]">{deliveryHint}</p> : null}
              <label className="block text-[12px] font-semibold">
                Verification code
                <input
                  required
                  inputMode="numeric"
                  className="mt-1 h-9 w-full rounded border border-[var(--ui-border)] bg-[var(--ui-surface-subtle)] px-2"
                  value={mfaCode}
                  onChange={(event) => setMfaCode(event.target.value)}
                />
              </label>
              <button
                type="button"
                className="text-[12px] font-semibold text-[var(--ui-accent)] underline disabled:opacity-60"
                disabled={resending}
                onClick={() => void onResend()}
              >
                {resending ? 'Sending…' : 'Resend code'}
              </button>
            </>
          ) : null}
          {error ? <p className="text-[12px] text-[var(--ui-danger)]">{error}</p> : null}
          <button
            type="submit"
            className="h-9 w-full rounded bg-[var(--ui-accent)] text-sm font-semibold text-white disabled:opacity-60"
            disabled={submitting}
          >
            {submitting ? 'Signing in…' : challengeUlid ? 'Verify' : 'Login'}
          </button>
          <p className="text-center text-[12px] text-[var(--ui-text-muted)]">
            <Link className="font-semibold text-[var(--ui-accent)]" to="/platform/forgot-password">
              Forgot password
            </Link>
          </p>
        </form>
      </div>
    </div>
  )
}
