import { FormEvent, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, Plus, RefreshCw, Save, X } from 'lucide-react'
import { createBranch, fetchBranches, updateBranch } from '../api/branches'
import { DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiModal } from '../components/ui/UiModal'
import { UiSelect } from '../components/ui/UiSelect'
import { useFeedback } from '../feedback/FeedbackProvider'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import type { Branch } from '../types/auth'
import './AdminShellPages.css'

export function BranchesPage() {
  const { session } = useAuth()
  const canManage = useCan('branches.manage')
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['branches'],
    queryFn: fetchBranches,
  })

  const [selectedUlid, setSelectedUlid] = useState<string | null>(null)
  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<Branch | null>(null)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [status, setStatus] = useState<'active' | 'inactive'>('active')
  const [isDefault, setIsDefault] = useState(false)

  const selected = useMemo(
    () => query.data?.find((row) => row.ulid === selectedUlid) ?? null,
    [query.data, selectedUlid],
  )

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (editing) {
        return updateBranch(editing.ulid, {
          code: code.trim(),
          name: name.trim(),
          status,
          is_default: isDefault,
        })
      }

      return createBranch({
        code: code.trim(),
        name: name.trim(),
      })
    },
    onSuccess: async (branch) => {
      setModalOpen(false)
      setEditing(null)
      setSelectedUlid(branch.ulid)
      feedback.success(editing ? 'Branch updated.' : 'Branch created.')
      await queryClient.invalidateQueries({ queryKey: ['branches'] })
    },
    onError: (error) => {
      feedback.fromApiError(error, editing ? 'Unable to update branch.' : 'Unable to create branch.')
    },
  })

  function openNew() {
    setEditing(null)
    setCode('')
    setName('')
    setStatus('active')
    setIsDefault(false)
    setModalOpen(true)
  }

  function openEdit(branch: Branch) {
    setEditing(branch)
    setCode(branch.code)
    setName(branch.name)
    setStatus(branch.status === 'inactive' ? 'inactive' : 'active')
    setIsDefault(branch.is_default)
    setModalOpen(true)
  }

  function submit(event: FormEvent) {
    event.preventDefault()
    if (!code.trim() || !name.trim() || saveMutation.isPending) return
    saveMutation.mutate()
  }

  return (
    <>
      <DesktopPanel
        title="Branches"
        flush
        toolbar={
          <>
            <UiButton
              variant="primary"
              icon={<Plus size={14} />}
              label="New"
              disabled={!canManage}
              onClick={openNew}
            />
            <UiButton
              icon={<Edit3 size={14} />}
              label="Edit"
              disabled={!canManage || !selected}
              onClick={() => {
                if (selected) openEdit(selected)
              }}
            />
            <UiButton
              variant="info"
              icon={<RefreshCw size={14} />}
              label={query.isFetching ? 'Refreshing…' : 'Refresh'}
              disabled={query.isFetching}
              onClick={() => void query.refetch()}
            />
            <UiButton
              variant="info"
              icon={<X size={14} />}
              label="Close"
              onClick={closeActiveTab}
            />
          </>
        }
      >
        <div className="admin-branch-summary">
          <span>
            Active branch:
            <strong>{session?.branch.code ?? '—'} — {session?.branch.name ?? '—'}</strong>
          </span>
          <span>
            Total branches:
            <strong>{query.data?.length ?? 0}</strong>
          </span>
          {!canManage ? <span>Read-only access</span> : null}
        </div>

        <div className="admin-branch-grid">
          <PosDataGrid
            columns={[
              { key: 'code', header: 'Code', width: 120, render: (row) => row.code },
              { key: 'name', header: 'Name', render: (row) => row.name },
              { key: 'status', header: 'Status', width: 110, render: (row) => row.status === 'active' ? 'Active' : 'Inactive' },
              { key: 'default', header: 'Default', width: 90, align: 'center', render: (row) => (row.is_default ? 'Yes' : '') },
              { key: 'current', header: 'Current', width: 90, align: 'center', render: (row) => (row.ulid === session?.branch.ulid ? 'Yes' : '') },
            ]}
            rows={query.data ?? []}
            rowKey={(row) => row.ulid}
            selectedKey={selectedUlid}
            onSelect={(row) => setSelectedUlid(row.ulid)}
            onActivate={(row) => {
              if (canManage) openEdit(row)
            }}
            emptyMessage={query.isLoading ? 'Loading branches…' : 'No branches available.'}
          />
        </div>
      </DesktopPanel>

      <UiModal
        open={modalOpen}
        title={editing ? 'Edit Branch' : 'New Branch'}
        onClose={() => {
          if (!saveMutation.isPending) setModalOpen(false)
        }}
        size="sm"
        footer={
          <>
            <UiButton
              variant="info"
              icon={<X size={14} />}
              label="Cancel"
              disabled={saveMutation.isPending}
              onClick={() => setModalOpen(false)}
            />
            <UiButton
              variant="success"
              icon={<Save size={14} />}
              label={saveMutation.isPending ? 'Saving…' : 'Save'}
              disabled={!code.trim() || !name.trim() || saveMutation.isPending}
              onClick={() => {
                const form = window.document.getElementById('branch-admin-form') as HTMLFormElement | null
                form?.requestSubmit()
              }}
            />
          </>
        }
      >
        <form id="branch-admin-form" className="admin-branch-form" onSubmit={submit}>
          <label>
            <span>Code</span>
            <input
              className="desktop-input"
              value={code}
              maxLength={32}
              autoComplete="off"
              onChange={(event) => setCode(event.target.value)}
              required
            />
          </label>

          <label>
            <span>Name</span>
            <input
              className="desktop-input"
              value={name}
              maxLength={120}
              autoComplete="off"
              onChange={(event) => setName(event.target.value)}
              required
            />
          </label>

          {editing ? (
            <>
              <label>
                <span>Status</span>
                <UiSelect
                  value={status}
                  searchable={false}
                  disabled={editing.ulid === session?.branch.ulid || editing.is_default}
                  options={[
                    { value: 'active', label: 'Active' },
                    { value: 'inactive', label: 'Inactive' },
                  ]}
                  onChange={(value) => setStatus(value as 'active' | 'inactive')}
                />
              </label>

              <label className="admin-branch-default">
                <input
                  type="checkbox"
                  checked={isDefault}
                  disabled={editing.is_default || status === 'inactive'}
                  onChange={(event) => setIsDefault(event.target.checked)}
                />
                <span>Default branch</span>
              </label>

              {editing.ulid === session?.branch.ulid ? (
                <p className="admin-branch-note">The current session branch cannot be deactivated.</p>
              ) : null}
              {editing.is_default ? (
                <p className="admin-branch-note">To change the default, set another active branch as default.</p>
              ) : null}
            </>
          ) : null}
        </form>
      </UiModal>
    </>
  )
}

export function WarehousesPage() {
  const { session } = useAuth()
  const warehouse = session?.warehouse
  const rows = warehouse ? [warehouse] : []

  return (
    <DesktopPanel title="Warehouses" flush>
      <PosDataGrid
        columns={[
          { key: 'code', header: 'Code', width: 90, render: (row) => row.code },
          { key: 'name', header: 'Name', render: (row) => row.name },
          { key: 'status', header: 'Status', width: 110, render: (row) => row.status },
          { key: 'default', header: 'Default', width: 80, align: 'center', render: (row) => (row.is_default ? 'Yes' : '') },
        ]}
        rows={rows}
        rowKey={(row) => row.ulid}
        emptyMessage="No warehouse in the current session."
      />
      <p className="later-banner">Warehouse administration is not implemented. The active session warehouse is shown for context.</p>
    </DesktopPanel>
  )
}

export function SecurityStatusPage() {
  const { session } = useAuth()
  if (!session) {
    return null
  }

  return (
    <DesktopPanel title="Security">
      <dl className="kv-grid max-w-xl">
        <dt>User status</dt>
        <dd>{session.user.status}</dd>
        <dt>Membership</dt>
        <dd>{session.membership.status}</dd>
        <dt>Device</dt>
        <dd>{session.device ? `${session.device.name} (${session.device.status})` : 'Not bound on this browser session'}</dd>
        <dt>Device type</dt>
        <dd>{session.device?.device_type ?? '—'}</dd>
        <dt>Last seen</dt>
        <dd>{session.device?.last_seen_at ?? '—'}</dd>
      </dl>
      <p className="later-banner">Device approval and MFA remain on existing Administration → Devices and account security flows. This page does not change backend policy.</p>
    </DesktopPanel>
  )
}
