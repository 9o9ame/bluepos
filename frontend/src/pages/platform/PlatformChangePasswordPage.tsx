import { FormEvent, useState } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { ApiClientError } from '../../api/client'
import { usePlatformAuth } from '../../features/platform/PlatformAuthProvider'

export function PlatformChangePasswordPage() {
  const { user, isLoading, changePassword, logout } = usePlatformAuth()
  const navigate = useNavigate()
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  if (isLoading) {
    return <div className="platform-auth-page grid h-screen place-items-center bg-[var(--ui-bg)] text-sm text-[var(--ui-accent)]">Loading platform…</div>
  }
  if (!user) {
    return <Navigate to="/platform/login" replace />
  }
  if (!user.must_change_password) {
    return <Navigate to="/platform" replace />
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await changePassword({
        current_password: current,
        password,
        password_confirmation: confirm,
      })
      navigate('/platform', { replace: true })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to change password.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="platform-auth-page flex min-h-screen items-center justify-center bg-[var(--ui-bg)] p-4">
      <div className="platform-auth-card w-full max-w-md rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] text-[var(--ui-text)] shadow-xl">
        <div className="border-b border-[var(--ui-border)] px-5 py-3">
          <div className="text-[11px] font-black tracking-[0.18em] text-[var(--ui-accent)]">BLUEPOS PLATFORM</div>
          <h1 className="text-lg font-semibold">Set a new password</h1>
        </div>
        <form className="space-y-3 px-5 py-4" onSubmit={onSubmit}>
          <p className="text-[12px] text-[var(--ui-text-muted)]">You must replace the temporary password before using Super Admin.</p>
          <input
            className="h-9 w-full rounded border border-[var(--ui-border)] bg-[var(--ui-surface-subtle)] px-2 text-[12px]"
            type="password"
            autoComplete="current-password"
            placeholder="Current password"
            value={current}
            onChange={(event) => setCurrent(event.target.value)}
            required
          />
          <input
            className="h-9 w-full rounded border border-[var(--ui-border)] bg-[var(--ui-surface-subtle)] px-2 text-[12px]"
            type="password"
            autoComplete="new-password"
            placeholder="New password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            required
            minLength={8}
          />
          <input
            className="h-9 w-full rounded border border-[var(--ui-border)] bg-[var(--ui-surface-subtle)] px-2 text-[12px]"
            type="password"
            autoComplete="new-password"
            placeholder="Confirm password"
            value={confirm}
            onChange={(event) => setConfirm(event.target.value)}
            required
            minLength={8}
          />
          {error ? <p className="text-[12px] text-[var(--ui-danger)]">{error}</p> : null}
          <button
            type="submit"
            className="h-9 w-full rounded bg-[var(--ui-accent)] text-sm font-semibold text-white disabled:opacity-60"
            disabled={submitting}
          >
            {submitting ? 'Updating…' : 'Update password'}
          </button>
          <button
            type="button"
            className="h-9 w-full rounded border border-[var(--ui-border)] text-sm"
            onClick={() => {
              void logout().then(() => navigate('/platform/login', { replace: true }))
            }}
          >
            Sign out
          </button>
        </form>
      </div>
    </div>
  )
}
