import { useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiClientError } from '../../api/client'
import { fetchPlatformPermissions, fetchPlatformRole, syncPlatformRolePermissions, updatePlatformRole } from '../../api/platform'
import { usePlatformCan } from '../../features/platform/usePlatformCan'
import type { PlatformPermission } from '../../types/platform'

const MODULE_ORDER = [
  'dashboard',
  'tenants',
  'plans',
  'subscriptions',
  'features',
  'limits',
  'users',
  'roles',
  'security',
  'audit',
  'settings',
]

export function PlatformRoleEditorPage() {
  const { roleUlid = '' } = useParams()
  const queryClient = useQueryClient()
  const canSave = usePlatformCan('platform.roles.manage_permissions')
  const canEdit = usePlatformCan('platform.roles.edit')
  const [error, setError] = useState<string | null>(null)
  const [granted, setGranted] = useState<string[] | null>(null)
  const [availableQuery, setAvailableQuery] = useState('')
  const [grantedQuery, setGrantedQuery] = useState('')
  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [description, setDescription] = useState('')
  const [selectedAvailable, setSelectedAvailable] = useState<string[]>([])
  const [selectedGranted, setSelectedGranted] = useState<string[]>([])

  const roleQuery = useQuery({
    queryKey: ['platform', 'role', roleUlid],
    queryFn: () => fetchPlatformRole(roleUlid),
    enabled: roleUlid !== '',
  })
  const permissionsQuery = useQuery({ queryKey: ['platform', 'permissions'], queryFn: () => fetchPlatformPermissions() })

  const role = roleQuery.data
  const grantedKeys = granted ?? (role?.permissions ?? []).map((permission) => permission.key)
  const catalog = permissionsQuery.data ?? []

  const grouped = useMemo(() => {
    const map = new Map<string, PlatformPermission[]>()
    for (const permission of catalog) {
      const list = map.get(permission.module) ?? []
      list.push(permission)
      map.set(permission.module, list)
    }
    return map
  }, [catalog])

  const available = catalog.filter((permission) => !grantedKeys.includes(permission.key))
  const grantedPermissions = catalog.filter((permission) => grantedKeys.includes(permission.key))

  const saveMutation = useMutation({
    mutationFn: (keys: string[]) => syncPlatformRolePermissions(roleUlid, keys),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['platform', 'roles'] })
      await queryClient.invalidateQueries({ queryKey: ['platform', 'role', roleUlid] })
    },
  })

  if (!role) {
    return <p className="text-[12px]">Loading role…</p>
  }

  const locked = role.is_system || role.code === 'super_admin'

  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">Role editor — {role.name}</h1>
        <Link className="text-[12px] underline" to="/platform/access/roles">
          Back to roles
        </Link>
      </div>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      {locked ? <p className="text-[12px] text-slate-600">SUPER_ADMIN is a protected system role. Permissions stay synchronized with the catalogue.</p> : null}

      <form
        className="grid grid-cols-3 gap-2 rounded border border-slate-300 bg-white p-3 text-[12px]"
        onSubmit={(event) => {
          event.preventDefault()
          if (!canEdit || locked) {
            return
          }
          void updatePlatformRole(role.ulid, {
            name: name || role.name,
            code: code || role.code,
            description: description || role.description,
          }).catch((err) => setError(err instanceof ApiClientError ? err.message : 'Unable to save role.'))
        }}
      >
        <label className="font-semibold">
          Role name
          <input className="mt-1 h-8 w-full rounded border px-2" defaultValue={role.name} onChange={(e) => setName(e.target.value)} disabled={locked} />
        </label>
        <label className="font-semibold">
          Code
          <input className="mt-1 h-8 w-full rounded border px-2" defaultValue={role.code} onChange={(e) => setCode(e.target.value)} disabled={locked} />
        </label>
        <label className="font-semibold">
          Description
          <input className="mt-1 h-8 w-full rounded border px-2" defaultValue={role.description ?? ''} onChange={(e) => setDescription(e.target.value)} disabled={locked} />
        </label>
        {canEdit && !locked ? (
          <button type="submit" className="h-8 rounded border px-3">
            Save details
          </button>
        ) : null}
      </form>

      <div className="grid gap-3 md:grid-cols-[1fr_auto_1fr]">
        <PermissionPane
          title="Available Permissions"
          permissions={available}
          grouped={grouped}
          selected={selectedAvailable}
          onSelect={setSelectedAvailable}
          search={availableQuery}
          onSearch={setAvailableQuery}
        />
        <div className="flex flex-col justify-center gap-2">
          <button type="button" className="rounded border bg-white px-3 py-1 text-[12px]" disabled={locked} onClick={() => { setGranted([...grantedKeys, ...selectedAvailable]); setSelectedAvailable([]) }}>
            Add &gt;
          </button>
          <button type="button" className="rounded border bg-white px-3 py-1 text-[12px]" disabled={locked} onClick={() => { setGranted(grantedKeys.filter((key) => !selectedGranted.includes(key))); setSelectedGranted([]) }}>
            &lt; Remove
          </button>
          <button type="button" className="rounded border bg-white px-3 py-1 text-[12px]" disabled={locked} onClick={() => setGranted(catalog.map((permission) => permission.key))}>
            Add All &gt;&gt;
          </button>
          <button type="button" className="rounded border bg-white px-3 py-1 text-[12px]" disabled={locked} onClick={() => setGranted([])}>
            &lt;&lt; Remove All
          </button>
        </div>
        <PermissionPane
          title="Granted Permissions"
          permissions={grantedPermissions}
          grouped={grouped}
          selected={selectedGranted}
          onSelect={setSelectedGranted}
          search={grantedQuery}
          onSearch={setGrantedQuery}
        />
      </div>
      <button
        type="button"
        className="rounded bg-slate-950 px-3 py-1 text-[12px] font-semibold text-white disabled:opacity-60"
        disabled={!canSave || locked || saveMutation.isPending}
        onClick={() => {
          setError(null)
          saveMutation.mutateAsync(grantedKeys).catch((err) => {
            setError(err instanceof ApiClientError ? err.message : 'Unable to save permissions.')
          })
        }}
      >
        Save
      </button>
    </section>
  )
}

function PermissionPane({
  title,
  permissions,
  grouped,
  selected,
  onSelect,
  search,
  onSearch,
}: {
  title: string
  permissions: PlatformPermission[]
  grouped: Map<string, PlatformPermission[]>
  selected: string[]
  onSelect: (keys: string[]) => void
  search: string
  onSearch: (value: string) => void
}) {
  const modules = [...MODULE_ORDER, ...Array.from(grouped.keys()).filter((module) => !MODULE_ORDER.includes(module))]
  const filtered = permissions.filter(
    (permission) =>
      permission.key.toLowerCase().includes(search.toLowerCase()) ||
      permission.name.toLowerCase().includes(search.toLowerCase()),
  )

  return (
    <div className="rounded border border-slate-300 bg-white">
      <div className="border-b bg-slate-100 px-3 py-2 text-[12px] font-bold uppercase">{title}</div>
      <div className="p-2">
        <input className="h-8 w-full rounded border px-2 text-[12px]" placeholder="Search" value={search} onChange={(event) => onSearch(event.target.value)} />
      </div>
      <div className="max-h-[28rem] overflow-auto p-2 text-[12px]">
        {modules.map((module) => {
          const items = filtered.filter((permission) => permission.module === module)
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
                      onSelect(event.target.checked ? [...selected, permission.key] : selected.filter((key) => key !== permission.key))
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
