import { FormEvent, useState } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { changePassword } from '../api/auth'
import { ApiClientError } from '../api/client'
import { useAuth } from '../features/auth/AuthProvider'
import { AuthLayout } from '../layouts/AuthLayout'

export function ChangePasswordPage() {
  const { session, isLoading, logout } = useAuth()
  const navigate = useNavigate()
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState<string | null>(null)

  if (isLoading) {
    return null
  }
  if (!session) {
    return <Navigate to="/login" replace />
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await changePassword({
        current_password: current,
        password,
        password_confirmation: confirm,
      })
      navigate('/', { replace: true })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to change password.')
    }
  }

  return (
    <AuthLayout title="Change password">
      <form className="space-y-3" onSubmit={onSubmit}>
        <p className="text-[12px] text-slate-600">You must set a new password before using BluePOS.</p>
        <input className="h-9 w-full rounded border px-2 text-[12px]" type="password" placeholder="Current password" value={current} onChange={(e) => setCurrent(e.target.value)} required />
        <input className="h-9 w-full rounded border px-2 text-[12px]" type="password" placeholder="New password" value={password} onChange={(e) => setPassword(e.target.value)} required minLength={8} />
        <input className="h-9 w-full rounded border px-2 text-[12px]" type="password" placeholder="Confirm password" value={confirm} onChange={(e) => setConfirm(e.target.value)} required minLength={8} />
        {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
        <button type="submit" className="h-9 w-full rounded bg-[#1f4e79] text-sm font-semibold text-white">Update password</button>
        <button
          type="button"
          className="h-9 w-full rounded border text-sm"
          onClick={() => {
            void logout().then(() => navigate('/login', { replace: true }))
          }}
        >
          Logout
        </button>
      </form>
    </AuthLayout>
  )
}
