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
    return <div className="grid h-screen place-items-center bg-slate-950 text-sm text-amber-300">Loading platform…</div>
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
    <div className="flex min-h-screen items-center justify-center bg-slate-950 p-4">
      <div className="w-full max-w-md rounded border border-slate-700 bg-slate-900 text-slate-100 shadow-xl">
        <div className="border-b border-slate-700 px-5 py-3">
          <div className="text-[11px] font-black tracking-[0.18em] text-amber-400">BLUEPOS PLATFORM</div>
          <h1 className="text-lg font-semibold">Set a new password</h1>
        </div>
        <form className="space-y-3 px-5 py-4" onSubmit={onSubmit}>
          <p className="text-[12px] text-slate-300">You must replace the temporary password before using Super Admin.</p>
          <input
            className="h-9 w-full rounded border border-slate-600 bg-slate-800 px-2 text-[12px]"
            type="password"
            autoComplete="current-password"
            placeholder="Current password"
            value={current}
            onChange={(event) => setCurrent(event.target.value)}
            required
          />
          <input
            className="h-9 w-full rounded border border-slate-600 bg-slate-800 px-2 text-[12px]"
            type="password"
            autoComplete="new-password"
            placeholder="New password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            required
            minLength={8}
          />
          <input
            className="h-9 w-full rounded border border-slate-600 bg-slate-800 px-2 text-[12px]"
            type="password"
            autoComplete="new-password"
            placeholder="Confirm password"
            value={confirm}
            onChange={(event) => setConfirm(event.target.value)}
            required
            minLength={8}
          />
          {error ? <p className="text-[12px] text-red-300">{error}</p> : null}
          <button
            type="submit"
            className="h-9 w-full rounded bg-amber-500 text-sm font-semibold text-slate-950 disabled:opacity-60"
            disabled={submitting}
          >
            {submitting ? 'Updating…' : 'Update password'}
          </button>
          <button
            type="button"
            className="h-9 w-full rounded border border-slate-600 text-sm"
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
