import { FormEvent, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createRole, deleteRole, fetchRoles } from '../api/roles'
import { ApiClientError } from '../api/client'
import { useCan } from '../features/auth/useCan'

export function RolesPage() {
  const queryClient = useQueryClient()
  const canCreate = useCan('roles.create')
  const canDelete = useCan('roles.delete')
  const rolesQuery = useQuery({ queryKey: ['roles'], queryFn: fetchRoles })
  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)

  const createMutation = useMutation({
    mutationFn: createRole,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
      setName('')
      setCode('')
    },
  })

  async function onCreate(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await createMutation.mutateAsync({
        name,
        code,
        branch_access: 'selected_branches',
      })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to create role.')
    }
  }

  return (
    <section className="space-y-4">
      <h2 className="text-base font-semibold">Roles</h2>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}

      {canCreate ? (
        <form className="flex flex-wrap gap-2 rounded border border-slate-300 bg-white p-3" onSubmit={onCreate}>
          <input className="h-8 rounded border px-2 text-[12px]" placeholder="Role name" value={name} onChange={(e) => setName(e.target.value)} required />
          <input className="h-8 rounded border px-2 text-[12px]" placeholder="code" value={code} onChange={(e) => setCode(e.target.value)} required />
          <button type="submit" className="h-8 rounded bg-[#1f4e79] px-3 text-[12px] font-semibold text-white">Create role</button>
        </form>
      ) : null}

      <table className="w-full border border-slate-300 bg-white text-[12px]">
        <thead className="bg-slate-100">
          <tr>
            <th className="p-2 text-left">Name</th>
            <th className="p-2 text-left">Code</th>
            <th className="p-2 text-left">Branch access</th>
            <th className="p-2"></th>
          </tr>
        </thead>
        <tbody>
          {(rolesQuery.data ?? []).map((role) => (
            <tr key={role.ulid} className="border-t border-slate-200">
              <td className="p-2">{role.name}{role.is_system ? ' (system)' : ''}</td>
              <td className="p-2">{role.code}</td>
              <td className="p-2">{role.branch_access === 'all_branches' ? 'All branches' : 'Selected branches'}</td>
              <td className="p-2 text-right space-x-2">
                <Link className="font-semibold text-[#1f4e79]" to={`/administration/roles/${role.ulid}`}>Edit</Link>
                {canDelete && !role.is_system ? (
                  <button
                    type="button"
                    className="rounded border px-2 py-1"
                    onClick={() => {
                      void deleteRole(role.ulid).then(() => queryClient.invalidateQueries({ queryKey: ['roles'] }))
                    }}
                  >
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
