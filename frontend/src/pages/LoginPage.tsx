import { FormEvent, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router-dom'
import { ApiClientError } from '../api/client'
import { useAuth } from '../features/auth/AuthProvider'
import { AuthLayout } from '../layouts/AuthLayout'

export function LoginPage() {
  const { session, isLoading, login, verifyMfa } = useAuth()
  const navigate = useNavigate()
  const [tenantCode, setTenantCode] = useState('')
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [mfaCode, setMfaCode] = useState('')
  const [challengeUlid, setChallengeUlid] = useState<string | null>(null)
  const [recoveryHint, setRecoveryHint] = useState<string | null>(null)
  const [trustDevice, setTrustDevice] = useState(true)
  const [deviceMessage, setDeviceMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  if (!isLoading && session) {
    return <Navigate to={session.must_change_password ? '/change-password' : '/'} replace />
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setDeviceMessage(null)
    setSubmitting(true)
    try {
      if (challengeUlid) {
        const session = await verifyMfa({
          challenge_ulid: challengeUlid,
          code: mfaCode,
          trust_device: trustDevice,
        })
        navigate(session.must_change_password ? '/change-password' : '/', { replace: true })
        return
      }

      const session = await login({ tenant_code: tenantCode, username, password })
      navigate(session.must_change_password ? '/change-password' : '/', { replace: true })
    } catch (err) {
      if (err instanceof ApiClientError && err.key === 'MFA_REQUIRED') {
        setChallengeUlid(typeof err.extra.challenge_ulid === 'string' ? err.extra.challenge_ulid : null)
        setRecoveryHint(typeof err.extra.recovery_hint === 'string' ? err.extra.recovery_hint : null)
        setError(err.message)
        return
      }
      if (err instanceof ApiClientError && err.key === 'DEVICE_NOT_APPROVED') {
        setDeviceMessage('This device is not approved. Request approval from your administrator.')
        setError(err.message)
        return
      }
      setError(err instanceof ApiClientError ? err.message : 'Unable to sign in.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout title="Sign in">
      <form className="space-y-3" onSubmit={onSubmit}>
        <label className="block text-[12px] font-semibold">
          Tenant / Mart code
          <input
            autoFocus
            required
            autoComplete="organization"
            className="mt-1 h-9 w-full rounded border border-slate-300 px-2"
            value={tenantCode}
            onChange={(event) => setTenantCode(event.target.value)}
          />
        </label>
        <label className="block text-[12px] font-semibold">
          Username
          <input
            required
            autoComplete="username"
            className="mt-1 h-9 w-full rounded border border-slate-300 px-2"
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
            className="mt-1 h-9 w-full rounded border border-slate-300 px-2"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
        </label>
        {challengeUlid ? (
          <>
            <p className="text-[12px] text-slate-600">
              Additional verification is required{recoveryHint ? ` (${recoveryHint})` : ''}.
            </p>
            <label className="block text-[12px] font-semibold">
              Verification code
              <input
                required
                inputMode="numeric"
                className="mt-1 h-9 w-full rounded border border-slate-300 px-2"
                value={mfaCode}
                onChange={(event) => setMfaCode(event.target.value)}
              />
            </label>
            <label className="flex items-center gap-2 text-[12px]">
              <input type="checkbox" checked={trustDevice} onChange={(event) => setTrustDevice(event.target.checked)} />
              Trust this device
            </label>
          </>
        ) : null}
        {deviceMessage ? <p className="text-[12px] text-amber-800">{deviceMessage}</p> : null}
        {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
        <button
          type="submit"
          className="h-9 w-full rounded bg-[#1f4e79] text-sm font-semibold text-white disabled:opacity-60"
          disabled={submitting}
        >
          {submitting ? 'Signing in…' : challengeUlid ? 'Verify' : 'Login'}
        </button>
        <p className="text-center text-[12px] text-slate-600">
          <Link className="font-semibold text-[#1f4e79]" to="/forgot-password">
            Forgot password
          </Link>
        </p>
      </form>
    </AuthLayout>
  )
}
