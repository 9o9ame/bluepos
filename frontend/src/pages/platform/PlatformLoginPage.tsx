import { FormEvent, useState } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { ApiClientError } from '../../api/client'
import { usePlatformAuth } from '../../features/platform/PlatformAuthProvider'

export function PlatformLoginPage() {
  const { user, isLoading, login, verifyMfa, resendMfa } = usePlatformAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
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
    setChallengeUlid(typeof err.extra.challenge_ulid === 'string' ? err.extra.challenge_ulid : null)
    setRecoveryHint(typeof err.extra.recovery_hint === 'string' ? err.extra.recovery_hint : null)
    setDeliveryHint(typeof err.extra.delivery_hint === 'string' ? err.extra.delivery_hint : null)
    setMfaCode('')
    setError(null)
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
      await login({ email, password })
    } catch (err) {
      if (err instanceof ApiClientError && err.key === 'MFA_REQUIRED') {
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
      if (err instanceof ApiClientError && err.key === 'MFA_REQUIRED') {
        applyMfaChallenge(err)
        return
      }
      setError(err instanceof ApiClientError ? err.message : 'Unable to resend the code.')
    } finally {
      setResending(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-950 p-4">
      <div className="w-full max-w-md rounded border border-slate-700 bg-slate-900 text-slate-100 shadow-xl">
        <div className="border-b border-slate-700 px-5 py-3">
          <div className="text-[11px] font-black tracking-[0.18em] text-amber-400">BLUEPOS PLATFORM</div>
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
              className="mt-1 h-9 w-full rounded border border-slate-600 bg-slate-800 px-2"
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
              className="mt-1 h-9 w-full rounded border border-slate-600 bg-slate-800 px-2"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
            />
          </label>
          {challengeUlid ? (
            <>
              <p className="text-[12px] text-slate-300">
                Additional verification is required{recoveryHint ? ` (${recoveryHint})` : ''}.
              </p>
              {deliveryHint ? <p className="text-[12px] text-amber-300">{deliveryHint}</p> : null}
              <label className="block text-[12px] font-semibold">
                Verification code
                <input
                  required
                  inputMode="numeric"
                  className="mt-1 h-9 w-full rounded border border-slate-600 bg-slate-800 px-2"
                  value={mfaCode}
                  onChange={(event) => setMfaCode(event.target.value)}
                />
              </label>
              <button
                type="button"
                className="text-[12px] font-semibold text-amber-400 underline disabled:opacity-60"
                disabled={resending}
                onClick={() => void onResend()}
              >
                {resending ? 'Sending…' : 'Resend code'}
              </button>
            </>
          ) : null}
          {error ? <p className="text-[12px] text-red-300">{error}</p> : null}
          <button
            type="submit"
            className="h-9 w-full rounded bg-amber-500 text-sm font-semibold text-slate-950 disabled:opacity-60"
            disabled={submitting}
          >
            {submitting ? 'Signing in…' : challengeUlid ? 'Verify' : 'Continue'}
          </button>
        </form>
      </div>
    </div>
  )
}
