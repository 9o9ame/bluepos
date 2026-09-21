import { FormEvent, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createPlatformAdmin, fetchPlatformAdmins } from '../../api/platform'
import { ApiClientError } from '../../api/client'

export function PlatformAdminsPage() {
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ['platform', 'admins'], queryFn: fetchPlatformAdmins })
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [temporaryPassword, setTemporaryPassword] = useState<string | null>(null)
  const createMutation = useMutation({
    mutationFn: createPlatformAdmin,
    onSuccess: async (admin) => {
      await queryClient.invalidateQueries({ queryKey: ['platform', 'admins'] })
      setTemporaryPassword(admin.temporary_password)
      setName('')
      setEmail('')
    },
  })

  async function onCreate(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await createMutation.mutateAsync({ name, email })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to create platform admin.')
    }
  }

  return (
    <section className="space-y-4">
      <h1 className="text-lg font-semibold">Platform administrators</h1>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      {temporaryPassword ? (
        <p className="rounded border border-amber-300 bg-amber-50 p-2 text-[12px]">
          Temporary password (shown once): <strong>{temporaryPassword}</strong>
        </p>
      ) : null}
      <form className="flex gap-2 text-[12px]" onSubmit={onCreate}>
        <input className="h-8 rounded border px-2" placeholder="Name" value={name} onChange={(event) => setName(event.target.value)} />
        <input className="h-8 rounded border px-2" placeholder="Email" value={email} onChange={(event) => setEmail(event.target.value)} />
        <button type="submit" className="h-8 rounded bg-slate-950 px-3 text-white">
          Create Super Admin
        </button>
      </form>
      <table className="w-full border border-slate-300 bg-white text-left text-[12px]">
        <thead className="bg-slate-50 text-slate-500">
          <tr>
            <th className="p-2">Name</th>
            <th>Email</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          {(query.data ?? []).map((admin) => (
            <tr key={admin.ulid} className="border-t border-slate-200">
              <td className="p-2">{admin.name}</td>
              <td>{admin.email}</td>
              <td>{admin.status}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
