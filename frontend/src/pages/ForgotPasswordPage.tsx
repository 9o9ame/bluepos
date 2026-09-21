import { FormEvent, useState } from 'react'
import { Link } from 'react-router-dom'
import { forgotPassword, resetPassword } from '../api/auth'
import { ApiClientError } from '../api/client'
import { AuthLayout } from '../layouts/AuthLayout'

export function ForgotPasswordPage() {
  const [tenantCode, setTenantCode] = useState('')
  const [username, setUsername] = useState('')
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
      const result = await forgotPassword({ tenant_code: tenantCode, username })
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
      await resetPassword({
        tenant_code: tenantCode,
        username,
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
    <AuthLayout title="Forgot password">
      {done ? (
        <p className="text-[13px]">
          Password updated.{' '}
          <Link className="font-semibold text-[#1f4e79]" to="/login">
            Sign in
          </Link>
        </p>
      ) : sent ? (
        <form className="space-y-3" onSubmit={onReset}>
          <p className="text-[12px] text-slate-600">{message}</p>
          <input className="h-9 w-full rounded border px-2 text-[12px]" placeholder="Reset code" value={token} onChange={(e) => setToken(e.target.value)} required />
          <input className="h-9 w-full rounded border px-2 text-[12px]" type="password" placeholder="New password" value={password} onChange={(e) => setPassword(e.target.value)} required minLength={8} />
          <input className="h-9 w-full rounded border px-2 text-[12px]" type="password" placeholder="Confirm password" value={confirm} onChange={(e) => setConfirm(e.target.value)} required minLength={8} />
          {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
          <button type="submit" className="h-9 w-full rounded bg-[#1f4e79] text-sm font-semibold text-white">Reset password</button>
        </form>
      ) : (
        <form className="space-y-3" onSubmit={onRequest}>
          <input className="h-9 w-full rounded border px-2 text-[12px]" placeholder="Mart code" value={tenantCode} onChange={(e) => setTenantCode(e.target.value)} required />
          <input className="h-9 w-full rounded border px-2 text-[12px]" placeholder="Username" value={username} onChange={(e) => setUsername(e.target.value)} required />
          {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
          <button type="submit" className="h-9 w-full rounded bg-[#1f4e79] text-sm font-semibold text-white">Send reset instructions</button>
          <p className="text-center text-[12px]">
            <Link className="font-semibold text-[#1f4e79]" to="/login">Back to login</Link>
          </p>
        </form>
      )}
    </AuthLayout>
  )
}
