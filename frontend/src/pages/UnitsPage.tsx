import { FormEvent, useState } from 'react'
import { RefreshCw, Save, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createUnit, fetchUnits } from '../api/catalog'
import { ApiClientError } from '../api/client'
import { DesktopButton, DesktopPanel, Field, FormGroup } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'

export function UnitsPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
  const canCreate = useCan('units.create') || useCan('units.manage')
  const query = useQuery({ queryKey: ['units'], queryFn: fetchUnits })
  const [code, setCode] = useState('PCS')
  const [name, setName] = useState('')
  const [symbol, setSymbol] = useState('')
  const [allowsDecimal, setAllowsDecimal] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const mutation = useMutation({
    mutationFn: createUnit,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['units'] })
      setName('')
      setSymbol('')
    },
  })

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await mutation.mutateAsync({ code, name, symbol, allows_decimal: allowsDecimal })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to create unit.')
    }
  }

  useWorkspaceHandlers({
    save: () => (document.getElementById('unit-form') as HTMLFormElement | null)?.requestSubmit(),
    refresh: () => void query.refetch(),
  })

  return (
    <DesktopPanel
      title="Units"
      toolbar={
        <>
          <DesktopButton icon={<Save size={13} />} label="Save" shortcut="F9" disabled={!canCreate} onClick={() => (document.getElementById('unit-form') as HTMLFormElement | null)?.requestSubmit()} />
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" onClick={() => void query.refetch()} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      {error ? <p className="mb-2 text-[12px] text-[var(--danger)]">{error}</p> : null}
      {canCreate ? (
        <form id="unit-form" onSubmit={onSubmit}>
          <FormGroup title="New unit">
            <Field label="Code"><input className="desktop-input" value={code} onChange={(e) => setCode(e.target.value)} required /></Field>
            <Field label="Name"><input className="desktop-input" value={name} onChange={(e) => setName(e.target.value)} required /></Field>
            <Field label="Symbol"><input className="desktop-input" value={symbol} onChange={(e) => setSymbol(e.target.value)} required /></Field>
            <label className="desktop-field"><span>Decimal qty</span><input type="checkbox" checked={allowsDecimal} onChange={(e) => setAllowsDecimal(e.target.checked)} /></label>
          </FormGroup>
        </form>
      ) : null}
      <div style={{ height: 380, marginTop: 6 }}>
        <PosDataGrid
          columns={[
            { key: 'code', header: 'Code', width: 90, render: (row) => row.code },
            { key: 'name', header: 'Name', render: (row) => row.name },
            { key: 'symbol', header: 'Symbol', width: 80, render: (row) => row.symbol },
            { key: 'decimal', header: 'Decimal', width: 80, align: 'center', render: (row) => (row.allows_decimal ? 'Yes' : 'No') },
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
