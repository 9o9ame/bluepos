import { FormEvent, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { ApiClientError } from '../../api/client'
import { updatePlatformProfile } from '../../api/platform'
import { usePlatformAuth } from '../../features/platform/PlatformAuthProvider'

export function PlatformAccountProfilePage() {
  const { user } = usePlatformAuth()
  const queryClient = useQueryClient()
  const [name, setName] = useState(user?.name ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [currentPassword, setCurrentPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    try {
      await updatePlatformProfile({
        name,
        email,
        current_password: email !== user?.email ? currentPassword : undefined,
      })
      await queryClient.invalidateQueries({ queryKey: ['platform', 'me'] })
      setNotice('Profile saved.')
      setCurrentPassword('')
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to save profile.')
    }
  }

  return (
    <section className="max-w-lg space-y-3">
      <h1 className="text-lg font-semibold">My Profile</h1>
      <p className="text-[12px] text-slate-600">Personal account settings. Platform-wide configuration lives under Settings.</p>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      {notice ? <p className="text-[12px] text-emerald-800">{notice}</p> : null}
      <form className="space-y-3 rounded border border-slate-300 bg-white p-3 text-[12px]" onSubmit={onSubmit}>
        <label className="block font-semibold">
          Name
          <input className="mt-1 h-8 w-full rounded border px-2" value={name} onChange={(event) => setName(event.target.value)} required />
        </label>
        <label className="block font-semibold">
          Email
          <input type="email" className="mt-1 h-8 w-full rounded border px-2" value={email} onChange={(event) => setEmail(event.target.value)} required />
        </label>
        {email !== user?.email ? (
          <label className="block font-semibold">
            Current password (required to change email)
            <input type="password" className="mt-1 h-8 w-full rounded border px-2" value={currentPassword} onChange={(event) => setCurrentPassword(event.target.value)} required />
          </label>
        ) : null}
        <button type="submit" className="rounded bg-slate-950 px-3 py-1 text-white">
          Save
        </button>
      </form>
    </section>
  )
}
