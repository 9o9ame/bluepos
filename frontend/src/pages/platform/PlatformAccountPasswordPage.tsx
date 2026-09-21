import { FormEvent, useState } from 'react'
import { ApiClientError } from '../../api/client'
import { usePlatformAuth } from '../../features/platform/PlatformAuthProvider'

export function PlatformAccountPasswordPage() {
  const { changePassword } = usePlatformAuth()
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    try {
      await changePassword({
        current_password: current,
        password,
        password_confirmation: confirm,
      })
      setCurrent('')
      setPassword('')
      setConfirm('')
      setNotice('Password updated. Other sessions were signed out.')
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to change password.')
    }
  }

  return (
    <section className="max-w-lg space-y-3">
      <h1 className="text-lg font-semibold">Change Password</h1>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      {notice ? <p className="text-[12px] text-emerald-800">{notice}</p> : null}
      <form className="space-y-3 rounded border border-slate-300 bg-white p-3 text-[12px]" onSubmit={onSubmit}>
        <input className="h-8 w-full rounded border px-2" type="password" placeholder="Current password" value={current} onChange={(e) => setCurrent(e.target.value)} required />
        <input className="h-8 w-full rounded border px-2" type="password" placeholder="New password" value={password} onChange={(e) => setPassword(e.target.value)} required minLength={8} />
        <input className="h-8 w-full rounded border px-2" type="password" placeholder="Confirm new password" value={confirm} onChange={(e) => setConfirm(e.target.value)} required minLength={8} />
        <button type="submit" className="rounded bg-slate-950 px-3 py-1 text-white">
          Update password
        </button>
      </form>
    </section>
  )
}
