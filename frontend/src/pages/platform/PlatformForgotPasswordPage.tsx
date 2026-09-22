import { FormEvent, useState } from 'react'
import { Link } from 'react-router-dom'
import { platformForgotPassword, platformResetPassword } from '../../api/platform'
import { ApiClientError } from '../../api/client'

export function PlatformForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [token, setToken] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [sent, setSent] = useState(false)
  const [done, setDone] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  async function onRequest(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      const result = await platformForgotPassword(email)
      setMessage(result.message)
      setSent(true)
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to request a reset.')
    }
  }

  async function onReset(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await platformResetPassword({
        email,
        token,
        password,
        password_confirmation: confirm,
      })
      setDone(true)
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to reset password.')
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-950 p-4">
      <div className="w-full max-w-md rounded border border-slate-700 bg-slate-900 p-5 text-slate-100 shadow-xl">
        <div className="text-[11px] font-black tracking-[0.18em] text-amber-400">BLUEPOS PLATFORM</div>
        <h1 className="mb-4 text-lg font-semibold">Forgot password</h1>
        {done ? (
          <p className="text-[13px]">
            Password updated.{' '}
            <Link className="font-semibold text-amber-400" to="/platform/login">
              Sign in
            </Link>
          </p>
        ) : sent ? (
          <form className="space-y-3" onSubmit={onReset}>
            <p className="text-[12px] text-slate-300">{message}</p>
            <input
              className="h-9 w-full rounded border border-slate-600 bg-slate-800 px-2 text-[12px]"
              placeholder="Reset code"
              value={token}
              onChange={(event) => setToken(event.target.value)}
              required
            />
            <input
              className="h-9 w-full rounded border border-slate-600 bg-slate-800 px-2 text-[12px]"
              type="password"
              placeholder="New password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
              minLength={8}
            />
            <input
              className="h-9 w-full rounded border border-slate-600 bg-slate-800 px-2 text-[12px]"
              type="password"
              placeholder="Confirm password"
              value={confirm}
              onChange={(event) => setConfirm(event.target.value)}
              required
              minLength={8}
            />
            {error ? <p className="text-[12px] text-red-300">{error}</p> : null}
            <button type="submit" className="h-9 w-full rounded bg-amber-500 text-sm font-semibold text-slate-950">
              Reset password
            </button>
          </form>
        ) : (
          <form className="space-y-3" onSubmit={onRequest}>
            <input
              className="h-9 w-full rounded border border-slate-600 bg-slate-800 px-2 text-[12px]"
              type="email"
              placeholder="Platform email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              required
            />
            {error ? <p className="text-[12px] text-red-300">{error}</p> : null}
            <button type="submit" className="h-9 w-full rounded bg-amber-500 text-sm font-semibold text-slate-950">
              Send reset instructions
            </button>
            <p className="text-center text-[12px]">
              <Link className="font-semibold text-amber-400" to="/platform/login">
                Back to login
              </Link>
            </p>
          </form>
        )}
      </div>
    </div>
  )
}
