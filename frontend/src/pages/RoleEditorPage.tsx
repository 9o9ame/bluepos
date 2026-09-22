import { useMemo, useState } from 'react'
import { ChevronsLeft, ChevronsRight, ChevronLeft, ChevronRight, RefreshCw, Save, Trash2, X } from 'lucide-react'
import { useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchPermissions, fetchRole, saveRolePermissions } from '../api/roles'
import { ApiClientError } from '../api/client'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Permission } from '../types/auth'

export function RoleEditorPage() {
  const { roleUlid } = useParams()
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
  const canSave = useCan('roles.manage_permissions')
  const [error, setError] = useState<string | null>(null)
  const [granted, setGranted] = useState<string[] | null>(null)
  const [searchAvailable, setSearchAvailable] = useState('')
  const [searchGranted, setSearchGranted] = useState('')

  const roleQuery = useQuery({
    queryKey: ['roles', roleUlid],
    queryFn: () => fetchRole(roleUlid ?? ''),
    enabled: Boolean(roleUlid),
  })
  const permissionsQuery = useQuery({ queryKey: ['permissions'], queryFn: fetchPermissions })

  const grantedKeys = granted ?? (roleQuery.data?.permissions ?? []).map((permission) => permission.key)

  const available = useMemo(() => {
    const list = (permissionsQuery.data ?? []).filter((permission) => !grantedKeys.includes(permission.key))
    const q = searchAvailable.trim().toLowerCase()
    return q ? list.filter((p) => p.name.toLowerCase().includes(q) || p.key.toLowerCase().includes(q)) : list
  }, [permissionsQuery.data, grantedKeys, searchAvailable])

  const grantedPermissions = useMemo(() => {
    const list = (permissionsQuery.data ?? []).filter((permission) => grantedKeys.includes(permission.key))
    const q = searchGranted.trim().toLowerCase()
    return q ? list.filter((p) => p.name.toLowerCase().includes(q) || p.key.toLowerCase().includes(q)) : list
  }, [permissionsQuery.data, grantedKeys, searchGranted])

  const [selectedAvailable, setSelectedAvailable] = useState<string[]>([])
  const [selectedGranted, setSelectedGranted] = useState<string[]>([])

  const saveMutation = useMutation({
    mutationFn: (keys: string[]) => saveRolePermissions(roleUlid ?? '', keys),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
    },
  })

  function saveNow() {
    if (!canSave || roleQuery.data?.code === 'owner') return
    setError(null)
    saveMutation.mutateAsync(grantedKeys).catch((err) => {
      setError(err instanceof ApiClientError ? err.message : 'Unable to save permissions.')
    })
  }

  useWorkspaceHandlers({
    save: saveNow,
    refresh: () => {
      void roleQuery.refetch()
    },
  })

  if (!roleQuery.data) {
    return (
      <div className="classic-workspace is-groups">
        <div className="module-banner">Manage Groups</div>
        <p className="p-3 text-[12px]">Loading role…</p>
      </div>
    )
  }

  const locked = roleQuery.data.code === 'owner'

  return (
    <div className="classic-workspace is-groups">
      <div className="module-banner">Manage Groups</div>
      <div className="desktop-toolbar" style={{ justifyContent: 'space-between' }}>
        <div className="flex items-center gap-2">
          <span className="text-[12px] font-semibold">Group</span>
          <input className="desktop-input" style={{ width: 180 }} readOnly value={roleQuery.data.name} />
          <span className="text-[11px] text-[var(--text-muted)]">{roleQuery.data.code}</span>
        </div>
        {error ? <span className="text-[12px] text-[var(--danger)]">{error}</span> : null}
        {locked ? <span className="text-[12px] text-[var(--text-muted)]">Owner permissions are locked.</span> : null}
      </div>

      <div className="groups-layout">
        <PermissionBox
          title={`Available Commands (${available.length})`}
          search={searchAvailable}
          onSearch={setSearchAvailable}
          permissions={available}
          selected={selectedAvailable}
          onSelect={setSelectedAvailable}
        />
        <div className="transfer-col">
          <button
            type="button"
            className="transfer-btn"
            title="Add selected"
            disabled={locked}
            onClick={() => {
              setGranted([...grantedKeys, ...selectedAvailable])
              setSelectedAvailable([])
            }}
          >
            <ChevronRight size={18} />
          </button>
          <button
            type="button"
            className="transfer-btn"
            title="Add all"
            disabled={locked}
            onClick={() => setGranted((permissionsQuery.data ?? []).map((p) => p.key))}
          >
            <ChevronsRight size={18} />
          </button>
          <button
            type="button"
            className="transfer-btn"
            title="Remove selected"
            disabled={locked}
            onClick={() => {
              setGranted(grantedKeys.filter((key) => !selectedGranted.includes(key)))
              setSelectedGranted([])
            }}
          >
            <ChevronLeft size={18} />
          </button>
          <button
            type="button"
            className="transfer-btn"
            title="Remove all"
            disabled={locked}
            onClick={() => setGranted([])}
          >
            <ChevronsLeft size={18} />
          </button>
        </div>
        <PermissionBox
          title={`Granted Commands (${grantedPermissions.length})`}
          search={searchGranted}
          onSearch={setSearchGranted}
          permissions={grantedPermissions}
          selected={selectedGranted}
          onSelect={setSelectedGranted}
        />
      </div>

      <div className="invoice-bottom">
        <button type="button" className="desktop-btn is-danger" disabled title="Available in a later phase">
          <Trash2 size={13} /> Delete Group
        </button>
        <div className="flex gap-1">
          <button
            type="button"
            className="desktop-btn is-primary"
            disabled={!canSave || saveMutation.isPending || locked}
            onClick={saveNow}
          >
            <Save size={13} /> Save Changes [F9]
          </button>
          <button type="button" className="desktop-btn" onClick={() => void roleQuery.refetch()}>
            <RefreshCw size={13} /> Refresh [F8]
          </button>
          <button type="button" className="desktop-btn is-danger" onClick={closeActiveTab}>
            <X size={13} /> Close
          </button>
        </div>
      </div>
    </div>
  )
}

function PermissionBox({
  title,
  search,
  onSearch,
  permissions,
  selected,
  onSelect,
}: {
  title: string
  search: string
  onSearch: (value: string) => void
  permissions: Permission[]
  selected: string[]
  onSelect: (keys: string[]) => void
}) {
  return (
    <div className="command-list-box">
      <h3>{title}</h3>
      <input
        className="desktop-input"
        placeholder="Enter text to search…"
        value={search}
        onChange={(event) => onSearch(event.target.value)}
      />
      <div className="command-list-scroll">
        {permissions.map((permission) => (
          <label key={permission.key}>
            <input
              type="checkbox"
              checked={selected.includes(permission.key)}
              onChange={(event) => {
                onSelect(
                  event.target.checked
                    ? [...selected, permission.key]
                    : selected.filter((key) => key !== permission.key),
                )
              }}
            />
            <span>{permission.name}</span>
            <span className="text-[10px] text-[var(--text-muted)]">{permission.key}</span>
          </label>
        ))}
      </div>
    </div>
  )
}
