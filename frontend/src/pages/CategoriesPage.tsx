import { FormEvent, useMemo } from 'react'
import { Plus, RefreshCw, Save, Trash2, X } from 'lucide-react'
import { ApiClientError } from '../api/client'
import { useCatalogMasterEditor } from '../components/catalog/useCatalogMasterEditor'
import { DesktopButton, DesktopPanel, Field, FormGroup } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { askConfirm } from '../feedback/FeedbackProvider'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import './CategoriesPage.css'

export function CategoriesPage() {
  const { closeActiveTab } = useWorkspace()
  const editor = useCatalogMasterEditor('category')

  const filteredRows = useMemo(() => {
    const nameQuery = editor.name.trim().toLocaleLowerCase()
    if (!nameQuery || editor.selected) return editor.rows

    return editor.rows.filter((row) =>
      row.name.toLocaleLowerCase().includes(nameQuery),
    )
  }, [editor.name, editor.rows, editor.selected])

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
      className="categories-page"
      title="Categories"
      toolbar={
        <>
          <DesktopButton icon={<Plus size={13} />} label="New" disabled={!editor.canCreate} onClick={editor.startNew} />
          <DesktopButton icon={<Save size={13} />} label="Save" shortcut="F9" disabled={!editor.canSave || editor.isSaving} onClick={() => (document.getElementById('category-form') as HTMLFormElement | null)?.requestSubmit()} />
          <DesktopButton icon={<Trash2 size={13} />} label="Delete" disabled={!editor.canDelete || !editor.selected?.is_active || editor.isDeactivating} onClick={() => {
            void (async () => {
              if (!editor.selected || !(await askConfirm(`Deactivate ${editor.selected.name}?`))) return
              void editor.deactivate().catch((err) => {
                editor.setError(err instanceof ApiClientError ? err.message : 'Unable to deactivate category.')
              })
            })()
          }} />
          <DesktopButton
            icon={<RefreshCw size={13} />}
            label="Activate"
            disabled={
              !editor.canActivate ||
              !editor.selected ||
              editor.selected.is_active ||
              editor.isActivating
            }
            onClick={() => {
              void editor.activate().catch((err: unknown) => {
                editor.setError(
                  err instanceof ApiClientError
                    ? err.message
                    : 'Unable to activate category.',
                )
              })
            }}
          />
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" onClick={() => void editor.refresh()} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      {editor.error ? <p className="mb-2 text-[12px] text-[var(--danger)]">{editor.error}</p> : null}
      {editor.canCreate || editor.canEdit ? (
        <form id="category-form" onSubmit={onSubmit}>
          <FormGroup title={editor.selected ? 'Edit category' : 'New category'}>
            <Field label="Name">
              <input
                className="desktop-input"
                value={editor.name}
                placeholder={editor.selected ? 'Category name' : 'Search or enter category name'}
                onChange={(e) => editor.setName(e.target.value)}
                required
              />
            </Field>
          </FormGroup>
        </form>
      ) : null}
      <div className="categories-grid">
        <PosDataGrid
          columns={[
            { key: 'code', header: 'Code', width: 120, render: (row) => row.code },
            { key: 'name', header: 'Name', render: (row) => row.name },
            { key: 'status', header: 'Status', width: 100, render: (row) => (row.is_active ? 'Active' : 'Archived') },
          ]}
          rows={filteredRows}
          rowKey={(row) => row.ulid}
          selectedKey={editor.selectedKey}
          onSelect={editor.select}
        />
      </div>
    </DesktopPanel>
  )
}
