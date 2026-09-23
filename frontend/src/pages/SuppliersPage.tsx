import { FormEvent, useEffect, useMemo, useState } from 'react'
import { Plus, RefreshCw, Save, Trash2, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createSupplier,
  deactivateSupplier,
  fetchSuppliers,
  updateSupplier,
} from '../api/catalog'
import { ApiClientError } from '../api/client'
import { DesktopButton, DesktopPanel, Field, FormGroup } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Supplier } from '../types/catalog'

export function SuppliersPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()

  const canManage = useCan('suppliers.manage')
  const canCreate = useCan('suppliers.create') || canManage
  const canEdit = useCan('suppliers.edit') || canManage
  const canDelete = useCan('suppliers.delete') || canManage

  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [contactPerson, setContactPerson] = useState('')
  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [address, setAddress] = useState('')
  const [taxNumber, setTaxNumber] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<string | null>(null)

  const listQuery = useQuery({
    queryKey: ['suppliers'],
    queryFn: fetchSuppliers,
  })

  const rows = listQuery.data ?? []
  const selected = useMemo(
    () => rows.find((row) => row.ulid === selectedKey) ?? null,
    [rows, selectedKey],
  )

  function clearForm() {
    setSelectedKey(null)
    setCode('')
    setName('')
    setContactPerson('')
    setPhone('')
    setEmail('')
    setAddress('')
    setTaxNumber('')
    setNotes('')
    setError(null)
  }

  useEffect(() => {
    if (!selected) return
    setCode(selected.code)
    setName(selected.name)
    setContactPerson(selected.contact_person ?? '')
    setPhone(selected.phone ?? '')
    setEmail(selected.email ?? '')
    setAddress(selected.address ?? '')
    setTaxNumber(selected.tax_number ?? '')
    setNotes(selected.notes ?? '')
  }, [selected])

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        code,
        name,
        contact_person: contactPerson || null,
        phone: phone || null,
        email: email || null,
        address: address || null,
        tax_number: taxNumber || null,
        notes: notes || null,
      }
      return selectedKey
        ? updateSupplier(selectedKey, payload)
        : createSupplier(payload)
    },
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: ['suppliers'] })
      setSelectedKey(saved.ulid)
      setError(null)
    },
  })

  const deactivateMutation = useMutation({
    mutationFn: async (ulid: string) => deactivateSupplier(ulid),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['suppliers'] })
    },
  })

  const activateMutation = useMutation({
    mutationFn: async (ulid: string) => updateSupplier(ulid, { is_active: true }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['suppliers'] })
    },
  })

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await saveMutation.mutateAsync()
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to save supplier.')
    }
  }

  useWorkspaceHandlers({
    save: () => (document.getElementById('supplier-form') as HTMLFormElement | null)?.requestSubmit(),
    refresh: () => void listQuery.refetch(),
  })

  const canSave = selectedKey ? canEdit : canCreate

  return (
    <DesktopPanel
      title="Suppliers"
      toolbar={
        <>
          <DesktopButton icon={<Plus size={13} />} label="New" disabled={!canCreate} onClick={clearForm} />
          <DesktopButton
            icon={<Save size={13} />}
            label="Save"
            shortcut="F9"
            disabled={!canSave || saveMutation.isPending}
            onClick={() => (document.getElementById('supplier-form') as HTMLFormElement | null)?.requestSubmit()}
          />
          <DesktopButton
            icon={<Trash2 size={13} />}
            label="Delete"
            disabled={!canDelete || !selected?.is_active || deactivateMutation.isPending}
            onClick={() => {
              if (!selected || !window.confirm(`Deactivate ${selected.name}?`)) return
              void deactivateMutation.mutateAsync(selected.ulid).catch((err) => {
                setError(err instanceof ApiClientError ? err.message : 'Unable to deactivate supplier.')
              })
            }}
          />
          <DesktopButton
            icon={<RefreshCw size={13} />}
            label="Activate"
            disabled={!canEdit || !selected || selected.is_active || activateMutation.isPending}
            onClick={() => {
              if (!selected) return
              void activateMutation.mutateAsync(selected.ulid).catch((err) => {
                setError(err instanceof ApiClientError ? err.message : 'Unable to activate supplier.')
              })
            }}
          />
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" onClick={() => void listQuery.refetch()} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      {error ? <p className="mb-2 text-[12px] text-[var(--danger)]">{error}</p> : null}
      {canSave ? (
        <form id="supplier-form" onSubmit={onSubmit}>
          <FormGroup title={selected ? 'Edit supplier' : 'New supplier'}>
            <Field label="Code">
              <input className="desktop-input" value={code} onChange={(e) => setCode(e.target.value)} required />
            </Field>
            <Field label="Supplier Name">
              <input className="desktop-input" value={name} onChange={(e) => setName(e.target.value)} required />
            </Field>
            <Field label="Contact Person">
              <input className="desktop-input" value={contactPerson} onChange={(e) => setContactPerson(e.target.value)} />
            </Field>
            <Field label="Phone">
              <input className="desktop-input" value={phone} onChange={(e) => setPhone(e.target.value)} />
            </Field>
            <Field label="Email">
              <input className="desktop-input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
            </Field>
            <Field label="Address">
              <input className="desktop-input" value={address} onChange={(e) => setAddress(e.target.value)} />
            </Field>
            <Field label="Tax Number">
              <input className="desktop-input" value={taxNumber} onChange={(e) => setTaxNumber(e.target.value)} />
            </Field>
            <Field label="Notes">
              <input className="desktop-input" value={notes} onChange={(e) => setNotes(e.target.value)} />
            </Field>
          </FormGroup>
        </form>
      ) : null}
      <div style={{ height: 380, marginTop: 6 }}>
        <PosDataGrid
          columns={[
            { key: 'code', header: 'Code', width: 120, render: (row: Supplier) => row.code },
            { key: 'name', header: 'Supplier Name', render: (row: Supplier) => row.name },
            { key: 'phone', header: 'Phone', width: 140, render: (row: Supplier) => row.phone ?? '—' },
            {
              key: 'status',
              header: 'Status',
              width: 100,
              render: (row: Supplier) => (row.is_active ? 'Active' : 'Archived'),
            },
          ]}
          rows={rows}
          rowKey={(row) => row.ulid}
          selectedKey={selectedKey}
          onSelect={(row) => setSelectedKey(row.ulid)}
        />
      </div>
    </DesktopPanel>
  )
}
