import { FormEvent, useState } from 'react'
import { RefreshCw, Save, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createBrand, fetchBrands } from '../api/catalog'
import { ApiClientError } from '../api/client'
import { DesktopButton, DesktopPanel, Field, FormGroup } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'

export function BrandsPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
  const canCreate = useCan('brands.create') || useCan('brands.manage')
  const query = useQuery({ queryKey: ['brands'], queryFn: fetchBrands })
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const mutation = useMutation({
    mutationFn: createBrand,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['brands'] })
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
      setError(err instanceof ApiClientError ? err.message : 'Unable to create brand.')
    }
  }

  useWorkspaceHandlers({
    save: () => (document.getElementById('brand-form') as HTMLFormElement | null)?.requestSubmit(),
    refresh: () => void query.refetch(),
  })

  return (
    <DesktopPanel
      title="Brands"
      toolbar={
        <>
          <DesktopButton icon={<Save size={13} />} label="Save" shortcut="F9" disabled={!canCreate} onClick={() => (document.getElementById('brand-form') as HTMLFormElement | null)?.requestSubmit()} />
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" onClick={() => void query.refetch()} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      {error ? <p className="mb-2 text-[12px] text-[var(--danger)]">{error}</p> : null}
      {canCreate ? (
        <form id="brand-form" onSubmit={onSubmit}>
          <FormGroup title="New brand">
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
