import { FormEvent, useState } from 'react'
import { Link } from 'react-router-dom'
import { RefreshCw, Save, Trash2, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createRole, deleteRole, fetchRoles } from '../api/roles'
import { ApiClientError } from '../api/client'
import { DesktopButton, DesktopPanel, Field, FormGroup } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'

export function RolesPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab, openModule } = useWorkspace()
  const canCreate = useCan('roles.create')
  const canDelete = useCan('roles.delete')
  const rolesQuery = useQuery({ queryKey: ['roles'], queryFn: fetchRoles })
  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [selectedKey, setSelectedKey] = useState<string | null>(null)

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

  useWorkspaceHandlers({
    save: () => {
      document.getElementById('role-create-form') && (document.getElementById('role-create-form') as HTMLFormElement).requestSubmit()
    },
    refresh: () => {
      void rolesQuery.refetch()
    },
  })

  return (
    <DesktopPanel
      title="Manage Groups"
      toolbar={
        <>
          <DesktopButton icon={<Save size={13} />} label="Save" shortcut="F9" disabled={!canCreate} onClick={() => (document.getElementById('role-create-form') as HTMLFormElement | null)?.requestSubmit()} />
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" onClick={() => void rolesQuery.refetch()} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      {error ? <p className="mb-2 text-[12px] text-[var(--danger)]">{error}</p> : null}
      {canCreate ? (
        <form id="role-create-form" onSubmit={onCreate}>
          <FormGroup title="New role">
            <Field label="Name">
              <input className="desktop-input" value={name} onChange={(e) => setName(e.target.value)} required />
            </Field>
            <Field label="Code">
              <input className="desktop-input" value={code} onChange={(e) => setCode(e.target.value)} required />
            </Field>
          </FormGroup>
        </form>
      ) : null}
      <div style={{ height: 360, marginTop: 6 }}>
        <PosDataGrid
          columns={[
            { key: 'name', header: 'Name', render: (row) => `${row.name}${row.is_system ? ' (system)' : ''}` },
            { key: 'code', header: 'Code', render: (row) => row.code },
            { key: 'access', header: 'Branch access', render: (row) => (row.branch_access === 'all_branches' ? 'All branches' : 'Selected branches') },
            {
              key: 'actions',
              header: 'Actions',
              render: (row) => (
                <div className="flex justify-end gap-1">
                  <Link className="desktop-btn" to={`/administration/roles/${row.ulid}`}>Edit</Link>
                  {canDelete && !row.is_system ? (
                    <DesktopButton
                      icon={<Trash2 size={13} />}
                      label="Delete"
                      variant="danger"
                      onClick={() => {
                        void deleteRole(row.ulid).then(() => queryClient.invalidateQueries({ queryKey: ['roles'] }))
                      }}
                    />
                  ) : null}
                </div>
              ),
            },
          ]}
          rows={rolesQuery.data ?? []}
          rowKey={(row) => row.ulid}
          selectedKey={selectedKey}
          onSelect={(row) => setSelectedKey(row.ulid)}
          onActivate={(row) => openModule(`/administration/roles/${row.ulid}`)}
        />
      </div>
    </DesktopPanel>
  )
}
