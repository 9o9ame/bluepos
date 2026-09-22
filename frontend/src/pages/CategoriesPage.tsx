import { FormEvent, useState } from 'react'
import { RefreshCw, Save, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createCategory, fetchCategories } from '../api/catalog'
import { ApiClientError } from '../api/client'
import { DesktopButton, DesktopPanel, Field, FormGroup } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'

export function CategoriesPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
  const canCreate = useCan('categories.create') || useCan('categories.manage')
  const query = useQuery({ queryKey: ['categories'], queryFn: fetchCategories })
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const mutation = useMutation({
    mutationFn: createCategory,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['categories'] })
      setCode('')
      setName('')
    },
  })

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await mutation.mutateAsync({ code, name })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to create category.')
    }
  }

  useWorkspaceHandlers({
    save: () => (document.getElementById('category-form') as HTMLFormElement | null)?.requestSubmit(),
    refresh: () => void query.refetch(),
  })

  return (
    <DesktopPanel
      title="Categories"
      toolbar={
        <>
          <DesktopButton icon={<Save size={13} />} label="Save" shortcut="F9" disabled={!canCreate} onClick={() => (document.getElementById('category-form') as HTMLFormElement | null)?.requestSubmit()} />
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" onClick={() => void query.refetch()} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      {error ? <p className="mb-2 text-[12px] text-[var(--danger)]">{error}</p> : null}
      {canCreate ? (
        <form id="category-form" onSubmit={onSubmit}>
          <FormGroup title="New category">
            <Field label="Code"><input className="desktop-input" value={code} onChange={(e) => setCode(e.target.value)} required /></Field>
            <Field label="Name"><input className="desktop-input" value={name} onChange={(e) => setName(e.target.value)} required /></Field>
          </FormGroup>
        </form>
      ) : null}
      <div style={{ height: 380, marginTop: 6 }}>
        <PosDataGrid
          columns={[
            { key: 'code', header: 'Code', width: 120, render: (row) => row.code },
            { key: 'name', header: 'Name', render: (row) => row.name },
            { key: 'status', header: 'Status', width: 100, render: (row) => (row.is_active ? 'Active' : 'Archived') },
          ]}
          rows={query.data ?? []}
          rowKey={(row) => row.ulid}
          selectedKey={selectedKey}
          onSelect={(row) => setSelectedKey(row.ulid)}
        />
      </div>
    </DesktopPanel>
  )
}
