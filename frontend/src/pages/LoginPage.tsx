import { requireBrowserLocation } from '../utils/browserLocation'
import { FormEvent, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { ApiClientError } from '../api/client'
import { useAuth } from '../features/auth/AuthProvider'
import { AuthLayout } from '../layouts/AuthLayout'
import { PremiumOtpInput } from '../components/auth/PremiumOtpInput'

export function LoginPage() {
  const { session, isLoading, login, verifyMfa, resendMfa } = useAuth()
  const navigate = useNavigate()
  const [tenantCode, setTenantCode] = useState('')
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [mfaCode, setMfaCode] = useState('')
  const [challengeUlid, setChallengeUlid] = useState<string | null>(null)
  const [recoveryHint, setRecoveryHint] = useState<string | null>(null)
  const [trustDevice, setTrustDevice] = useState(true)
  const [deviceMessage, setDeviceMessage] = useState<string | null>(null)
  const [approvalPending, setApprovalPending] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const [resending, setResending] = useState(false)

  if (!isLoading && session) {
    return <Navigate to={session.must_change_password ? '/change-password' : '/'} replace />
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setDeviceMessage(null)
    setApprovalPending(false)
    setSubmitting(true)
    try {
      const location = await requireBrowserLocation()
      if (challengeUlid) {
        const session = await verifyMfa({
          challenge_ulid: challengeUlid,
          code: mfaCode,
          trust_device: trustDevice,
          ...location,
        })
        navigate(session.must_change_password ? '/change-password' : '/', { replace: true })
        return
      }

      const session = await login({ tenant_code: tenantCode, username, password, ...location })
      navigate(session.must_change_password ? '/change-password' : '/', { replace: true })
    } catch (err) {
      if (err instanceof ApiClientError && (err.key === 'MFA_REQUIRED' || err.key === 'EMAIL_DELIVERY_FAILED')) {
        setChallengeUlid(typeof err.extra.challenge_ulid === 'string' ? err.extra.challenge_ulid : null)
        setRecoveryHint(typeof err.extra.recovery_hint === 'string' ? err.extra.recovery_hint : null)
        setMfaCode('')
        setError(err.key === 'MFA_REQUIRED' ? null : err.message)
        return
      }
      if (err instanceof ApiClientError && err.key === 'DEVICE_NOT_APPROVED') {
        const status = err.extra.approval_status
        const isPending = status === 'pending'
        setApprovalPending(isPending)
        setDeviceMessage(
          isPending
            ? 'Device/network approval request is pending with your tenant owner. Once approved, try signing in again.'
            : status === 'rejected'
              ? 'Your tenant owner rejected this device/network request. Contact your tenant owner before trying again.'
              : 'This device/network is not approved. Contact your tenant owner. If you are the owner, use your recovery-email verification flow.',
        )
        setError(null)
        return
      }
      setError(err instanceof Error ? err.message : 'Unable to sign in.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout title={challengeUlid ? "Verify your account" : "Sign in"}>
      {challengeUlid ? <div className="auth-otp-emblem" aria-hidden="true">✦</div> : null}
      <form className="space-y-3" onSubmit={onSubmit}>
        {!challengeUlid ? <>
        <label className="block text-[12px] font-semibold">
          Tenant / Mart code
          <input
            autoFocus
            required
            autoComplete="organization"
            className="mt-1 h-9 w-full rounded border border-[var(--ui-border)] px-2"
            value={tenantCode}
            onChange={(event) => setTenantCode(event.target.value)}
          />
        </label>
        <label className="block text-[12px] font-semibold">
          Username
          <input
            required
            autoComplete="username"
            className="mt-1 h-9 w-full rounded border border-[var(--ui-border)] px-2"
            value={username}
            onChange={(event) => setUsername(event.target.value)}
          />
        </label>
        <label className="block text-[12px] font-semibold">
          Password
          <input
            type="password"
            required={!challengeUlid}
            autoComplete="current-password"
            className="mt-1 h-9 w-full rounded border border-[var(--ui-border)] px-2"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
        </label>
        </> : null}
        {challengeUlid ? (
          <div className="space-y-3">
            <p className="auth-otp-copy">Enter the six-digit recovery code{recoveryHint ? ` (${recoveryHint})` : ''}.</p>
            <PremiumOtpInput value={mfaCode} onChange={setMfaCode} disabled={submitting} />
            <label className="flex items-center justify-center gap-2 text-[12px]">
              <input type="checkbox" checked={trustDevice} onChange={event => setTrustDevice(event.target.checked)} />
              Trust this device
            </label>
            <button type="button" className="auth-otp-back" disabled={resending} onClick={() => {
              if (!challengeUlid) return
              setError(null); setResending(true)
              void resendMfa(challengeUlid).catch(err => {
                if (err instanceof ApiClientError && (err.key === 'MFA_REQUIRED' || err.key === 'EMAIL_DELIVERY_FAILED')) {
                  setChallengeUlid(typeof err.extra.challenge_ulid === 'string' ? err.extra.challenge_ulid : challengeUlid)
                  setRecoveryHint(typeof err.extra.recovery_hint === 'string' ? err.extra.recovery_hint : recoveryHint)
                  setError(err.key === 'EMAIL_DELIVERY_FAILED' ? err.message : null)
                  return
                }
                setError(err instanceof ApiClientError ? err.message : 'Unable to resend the code.')
              }).finally(() => setResending(false))
            }}>{resending ? 'Sending…' : 'Resend code'}</button>
            <button type="button" className="auth-otp-back block mx-auto" onClick={() => { setChallengeUlid(null); setMfaCode(''); setError(null) }}>Back to sign in</button>
          </div>
        ) : null}
        {deviceMessage ? <p role="status" className="text-[12px] text-[var(--ui-warning)]">{deviceMessage}</p> : null}
        {approvalPending ? <p className="text-[12px] text-[var(--ui-text-muted)]">Approval does not sign you in automatically. Use Login again after your tenant owner approves the request.</p> : null}
        {error ? <p className="text-[12px] text-[var(--ui-danger)]">{error}</p> : null}
        <button
          type="submit"
          className="h-9 w-full rounded bg-[var(--ui-accent)] text-sm font-semibold text-white disabled:opacity-60"
          disabled={submitting || (!!challengeUlid && mfaCode.length !== 6)}
        >
          {submitting ? 'Signing in…' : challengeUlid ? 'Verify' : 'Login'}
        </button>
        {!challengeUlid ? <p className="text-center text-[12px] text-[var(--ui-text-muted)]">
          <Link className="font-semibold text-[var(--ui-accent)]" to="/forgot-password">
            Forgot password
          </Link>
        </p> : null}
      </form>
    </AuthLayout>
  )
}
