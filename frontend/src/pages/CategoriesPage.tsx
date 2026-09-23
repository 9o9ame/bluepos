import { FormEvent } from 'react'
import { Plus, RefreshCw, Save, Trash2, X } from 'lucide-react'
import { ApiClientError } from '../api/client'
import { useCatalogMasterEditor } from '../components/catalog/useCatalogMasterEditor'
import { DesktopButton, DesktopPanel, Field, FormGroup } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'

export function CategoriesPage() {
  const { closeActiveTab } = useWorkspace()
  const editor = useCatalogMasterEditor('category')

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    editor.setError(null)
    try {
      await editor.save()
    } catch (err) {
      editor.setError(err instanceof ApiClientError ? err.message : 'Unable to save category.')
    }
  }

  useWorkspaceHandlers({
    save: () => (document.getElementById('category-form') as HTMLFormElement | null)?.requestSubmit(),
    refresh: () => void editor.refresh(),
  })

  return (
    <DesktopPanel
      title="Categories"
      toolbar={
        <>
          <DesktopButton icon={<Plus size={13} />} label="New" disabled={!editor.canCreate} onClick={editor.startNew} />
          <DesktopButton icon={<Save size={13} />} label="Save" shortcut="F9" disabled={!editor.canSave || editor.isSaving} onClick={() => (document.getElementById('category-form') as HTMLFormElement | null)?.requestSubmit()} />
          <DesktopButton icon={<Trash2 size={13} />} label="Delete" disabled={!editor.canDelete || !editor.selected?.is_active || editor.isDeactivating} onClick={() => {
            if (!editor.selected || !window.confirm(`Deactivate ${editor.selected.name}?`)) return
            void editor.deactivate().catch((err) => {
              editor.setError(err instanceof ApiClientError ? err.message : 'Unable to deactivate category.')
            })
          }} />
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" onClick={() => void editor.refresh()} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      {editor.error ? <p className="mb-2 text-[12px] text-[var(--danger)]">{editor.error}</p> : null}
      {editor.canCreate || editor.canEdit ? (
        <form id="category-form" onSubmit={onSubmit}>
          <FormGroup title={editor.selected ? 'Edit category' : 'New category'}>
            <Field label="Code"><input className="desktop-input" value={editor.code} onChange={(e) => editor.setCode(e.target.value)} required /></Field>
            <Field label="Name"><input className="desktop-input" value={editor.name} onChange={(e) => editor.setName(e.target.value)} required /></Field>
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
          rows={editor.rows}
          rowKey={(row) => row.ulid}
          selectedKey={editor.selectedKey}
          onSelect={editor.select}
        />
      </div>
    </DesktopPanel>
  )
}
