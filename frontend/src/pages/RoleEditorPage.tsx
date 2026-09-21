import { useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchPermissions, fetchRole, saveRolePermissions } from '../api/roles'
import { ApiClientError } from '../api/client'
import { useCan } from '../features/auth/useCan'
import type { Permission } from '../types/auth'

export function RoleEditorPage() {
  const { roleUlid } = useParams()
  const queryClient = useQueryClient()
  const canSave = useCan('roles.manage_permissions')
  const [error, setError] = useState<string | null>(null)
  const [granted, setGranted] = useState<string[] | null>(null)

  const roleQuery = useQuery({
    queryKey: ['roles', roleUlid],
    queryFn: () => fetchRole(roleUlid ?? ''),
    enabled: Boolean(roleUlid),
  })
  const permissionsQuery = useQuery({ queryKey: ['permissions'], queryFn: fetchPermissions })

  const grantedKeys = granted ?? (roleQuery.data?.permissions ?? []).map((permission) => permission.key)

  const grouped = useMemo(() => {
    const map = new Map<string, Permission[]>()
    for (const permission of permissionsQuery.data ?? []) {
      const list = map.get(permission.module) ?? []
      list.push(permission)
      map.set(permission.module, list)
    }
    return map
  }, [permissionsQuery.data])

  const available = (permissionsQuery.data ?? []).filter((permission) => !grantedKeys.includes(permission.key))
  const grantedPermissions = (permissionsQuery.data ?? []).filter((permission) => grantedKeys.includes(permission.key))
  const [selectedAvailable, setSelectedAvailable] = useState<string[]>([])
  const [selectedGranted, setSelectedGranted] = useState<string[]>([])

  const saveMutation = useMutation({
    mutationFn: (keys: string[]) => saveRolePermissions(roleUlid ?? '', keys),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
    },
  })

  if (!roleQuery.data) {
    return <p className="text-[12px]">Loading role…</p>
  }

  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between">
        <h2 className="text-base font-semibold">Role editor — {roleQuery.data.name}</h2>
        <Link className="text-[12px] text-[#1f4e79]" to="/administration/roles">Back to roles</Link>
      </div>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      {roleQuery.data.code === 'owner' ? (
        <p className="text-[12px] text-slate-600">Owner permissions are locked to all tenant capabilities.</p>
      ) : null}

      <div className="grid gap-3 md:grid-cols-2">
        <PermissionList
          title="Available permissions"
          permissions={available}
          grouped={grouped}
          selected={selectedAvailable}
          onSelect={setSelectedAvailable}
        />
        <PermissionList
          title="Granted permissions"
          permissions={grantedPermissions}
          grouped={grouped}
          selected={selectedGranted}
          onSelect={setSelectedGranted}
        />
      </div>

      <div className="flex flex-wrap gap-2">
        <button type="button" className="rounded border bg-white px-3 py-1 text-[12px]" onClick={() => {
          setGranted([...grantedKeys, ...selectedAvailable])
          setSelectedAvailable([])
        }}>Add</button>
        <button type="button" className="rounded border bg-white px-3 py-1 text-[12px]" onClick={() => {
          setGranted(grantedKeys.filter((key) => !selectedGranted.includes(key)))
          setSelectedGranted([])
        }}>Remove</button>
        <button type="button" className="rounded border bg-white px-3 py-1 text-[12px]" onClick={() => setGranted((permissionsQuery.data ?? []).map((p) => p.key))}>Add All</button>
        <button type="button" className="rounded border bg-white px-3 py-1 text-[12px]" onClick={() => setGranted([])}>Remove All</button>
        <button
          type="button"
          className="rounded bg-[#1f4e79] px-3 py-1 text-[12px] font-semibold text-white disabled:opacity-60"
          disabled={!canSave || saveMutation.isPending || roleQuery.data.code === 'owner'}
          onClick={() => {
            setError(null)
            saveMutation.mutateAsync(grantedKeys).catch((err) => {
              setError(err instanceof ApiClientError ? err.message : 'Unable to save permissions.')
            })
          }}
        >
          Save
        </button>
      </div>
    </section>
  )
}

function PermissionList({
  title,
  permissions,
  grouped,
  selected,
  onSelect,
}: {
  title: string
  permissions: Permission[]
  grouped: Map<string, Permission[]>
  selected: string[]
  onSelect: (keys: string[]) => void
}) {
  const modules = Array.from(grouped.keys())

  return (
    <div className="rounded border border-slate-300 bg-white">
      <div className="border-b bg-slate-100 px-3 py-2 text-[12px] font-bold uppercase">{title}</div>
      <div className="max-h-[28rem] overflow-auto p-2 text-[12px]">
        {modules.map((module) => {
          const items = permissions.filter((permission) => permission.module === module)
          if (items.length === 0) {
            return null
          }
          return (
            <div key={module} className="mb-2">
              <div className="font-semibold capitalize text-slate-500">{module}</div>
              {items.map((permission) => (
                <label key={permission.key} className="flex items-center gap-2 py-0.5">
                  <input
                    type="checkbox"
                    checked={selected.includes(permission.key)}
                    onChange={(event) => {
                      onSelect(event.target.checked
                        ? [...selected, permission.key]
                        : selected.filter((key) => key !== permission.key))
                    }}
                  />
                  <span>{permission.name}</span>
                  <span className="text-slate-400">{permission.key}</span>
                </label>
              ))}
            </div>
          )
        })}
      </div>
    </div>
  )
}
