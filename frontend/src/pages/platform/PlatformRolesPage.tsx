import { FormEvent, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiClientError } from '../../api/client'
import { createPlatformRole, deletePlatformRole, fetchPlatformRoles, updatePlatformRole } from '../../api/platform'
import { usePlatformCan } from '../../features/platform/usePlatformCan'

export function PlatformRolesPage() {
  const queryClient = useQueryClient()
  const canCreate = usePlatformCan('platform.roles.create')
  const canEdit = usePlatformCan('platform.roles.edit')
  const canDelete = usePlatformCan('platform.roles.delete')
  const query = useQuery({ queryKey: ['platform', 'roles'], queryFn: fetchPlatformRoles })
  const [error, setError] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')

  async function run(action: () => Promise<unknown>) {
    setError(null)
    try {
      await action()
      await queryClient.invalidateQueries({ queryKey: ['platform', 'roles'] })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Request failed.')
    }
  }

  return (
    <section className="space-y-4">
      <h1 className="text-lg font-semibold">Platform Roles</h1>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      {canCreate ? (
        <form
          className="flex flex-wrap gap-2 rounded border border-slate-300 bg-white p-3 text-[12px]"
          onSubmit={(event: FormEvent) => {
            event.preventDefault()
            void run(async () => {
              await createPlatformRole({ code, name, description: description || undefined })
              setCode('')
              setName('')
              setDescription('')
            })
          }}
        >
          <input className="h-8 rounded border px-2" placeholder="Role name" value={name} onChange={(e) => setName(e.target.value)} required />
          <input className="h-8 rounded border px-2" placeholder="CODE" value={code} onChange={(e) => setCode(e.target.value)} required />
          <input className="h-8 rounded border px-2" placeholder="Description" value={description} onChange={(e) => setDescription(e.target.value)} />
          <button type="submit" className="h-8 rounded bg-slate-950 px-3 font-semibold text-white">
            Create role
          </button>
        </form>
      ) : null}
      <table className="w-full border border-slate-300 bg-white text-left text-[12px]">
        <thead className="bg-slate-50 text-slate-500">
          <tr>
            <th className="p-2">Role</th>
            <th>Code</th>
            <th>Type</th>
            <th>Status</th>
            <th>Users Assigned</th>
            <th>Updated At</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {(query.data ?? []).map((role) => (
            <tr key={role.ulid} className="border-t border-slate-200">
              <td className="p-2">{role.name}</td>
              <td>{role.code}</td>
              <td>{role.is_system ? 'System' : 'Custom'}{role.code === 'super_admin' ? ' · Protected' : ''}</td>
              <td>{role.is_active ? 'Active' : 'Inactive'}</td>
              <td>{role.users_assigned ?? 0}</td>
              <td>{role.updated_at ? role.updated_at.slice(0, 16).replace('T', ' ') : '—'}</td>
              <td className="space-x-2 whitespace-nowrap p-1">
                <Link className="font-semibold underline" to={`/platform/access/roles/${role.ulid}`}>
                  {role.is_system ? 'View' : 'Permissions'}
                </Link>
                {canEdit && !role.is_system ? (
                  <button
                    type="button"
                    className="underline"
                    onClick={() => void run(() => updatePlatformRole(role.ulid, { is_active: !role.is_active }))}
                  >
                    {role.is_active ? 'Deactivate' : 'Activate'}
                  </button>
                ) : null}
                {canDelete && !role.is_system ? (
                  <button type="button" className="underline" onClick={() => void run(() => deletePlatformRole(role.ulid))}>
                    Delete
                  </button>
                ) : null}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
