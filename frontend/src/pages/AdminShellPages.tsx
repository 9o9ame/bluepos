import { FormEvent, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit3, LogOut, Plus, RefreshCw, Save, ShieldCheck, X } from 'lucide-react'
import { createBranch, fetchBranches, updateBranch } from '../api/branches'
import { createWarehouse, fetchWarehouses, updateWarehouse } from '../api/inventory'
import { fetchSecuritySessions, revokeOtherSecuritySessions, revokeSecuritySession } from '../api/security'
import { DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiModal } from '../components/ui/UiModal'
import { UiSelect } from '../components/ui/UiSelect'
import { useFeedback } from '../feedback/FeedbackProvider'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import type { Branch, Warehouse } from '../types/auth'
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
  const canManage = useCan('warehouses.manage')
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['warehouses', session?.branch.ulid],
    queryFn: fetchWarehouses,
    enabled: Boolean(session?.branch.ulid),
  })

  const [selectedUlid, setSelectedUlid] = useState<string | null>(null)
  const [modalOpen, setModalOpen] = useState(false)
  const [editing, setEditing] = useState<Warehouse | null>(null)
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
      if (!session) {
        throw new Error('No active session.')
      }

      if (editing) {
        return updateWarehouse(editing.ulid, {
          code: code.trim(),
          name: name.trim(),
          status,
          is_default: isDefault,
        })
      }

      return createWarehouse({
        code: code.trim(),
        name: name.trim(),
      })
    },
    onSuccess: async (warehouse) => {
      setModalOpen(false)
      setEditing(null)
      setSelectedUlid(warehouse.ulid)
      feedback.success(editing ? 'Warehouse updated.' : 'Warehouse created.')
      await queryClient.invalidateQueries({ queryKey: ['warehouses'] })
    },
    onError: (error) => {
      feedback.fromApiError(error, editing ? 'Unable to update warehouse.' : 'Unable to create warehouse.')
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

  function openEdit(warehouse: Warehouse) {
    setEditing(warehouse)
    setCode(warehouse.code)
    setName(warehouse.name)
    setStatus(warehouse.status === 'inactive' ? 'inactive' : 'active')
    setIsDefault(warehouse.is_default)
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
        title="Warehouses"
        flush
        toolbar={
          <>
            <UiButton variant="primary" icon={<Plus size={14} />} label="New" disabled={!canManage} onClick={openNew} />
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
            <UiButton variant="info" icon={<X size={14} />} label="Close" onClick={closeActiveTab} />
          </>
        }
      >
        <div className="admin-branch-summary">
          <span>
            Branch:
            <strong>{session?.branch.code ?? '—'} — {session?.branch.name ?? '—'}</strong>
          </span>
          <span>
            Active warehouse:
            <strong>{session?.warehouse.code ?? '—'} — {session?.warehouse.name ?? '—'}</strong>
          </span>
          <span>
            Total warehouses:
            <strong>{query.data?.length ?? 0}</strong>
          </span>
        </div>

        <div className="admin-branch-grid">
          <PosDataGrid
            columns={[
              { key: 'code', header: 'Code', width: 120, render: (row) => row.code },
              { key: 'name', header: 'Name', render: (row) => row.name },
              { key: 'status', header: 'Status', width: 110, render: (row) => row.status === 'active' ? 'Active' : 'Inactive' },
              { key: 'default', header: 'Default', width: 90, align: 'center', render: (row) => (row.is_default ? 'Yes' : '') },
              { key: 'current', header: 'Current', width: 90, align: 'center', render: (row) => (row.ulid === session?.warehouse.ulid ? 'Yes' : '') },
            ]}
            rows={query.data ?? []}
            rowKey={(row) => row.ulid}
            selectedKey={selectedUlid}
            onSelect={(row) => setSelectedUlid(row.ulid)}
            onActivate={(row) => {
              if (canManage) openEdit(row)
            }}
            emptyMessage={query.isLoading ? 'Loading warehouses…' : 'No warehouses available for this branch.'}
          />
        </div>
      </DesktopPanel>

      <UiModal
        open={modalOpen}
        title={editing ? 'Edit Warehouse' : 'New Warehouse'}
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
                const form = window.document.getElementById('warehouse-admin-form') as HTMLFormElement | null
                form?.requestSubmit()
              }}
            />
          </>
        }
      >
        <form id="warehouse-admin-form" className="admin-branch-form" onSubmit={submit}>
          <label>
            <span>Branch</span>
            <input className="desktop-input" value={session ? `${session.branch.code} — ${session.branch.name}` : ''} readOnly />
          </label>

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
                  disabled={editing.ulid === session?.warehouse.ulid || editing.is_default}
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
                <span>Default warehouse for this branch</span>
              </label>

              {editing.ulid === session?.warehouse.ulid ? (
                <p className="admin-branch-note">The current session warehouse cannot be deactivated.</p>
              ) : null}
              {editing.is_default ? (
                <p className="admin-branch-note">To change the default, set another active warehouse as default.</p>
              ) : null}
            </>
          ) : null}
        </form>
      </UiModal>
    </>
  )
}

export function SecurityStatusPage() {
  const { session } = useAuth()
  const canRevokeAny = useCan('security.sessions.revoke')
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const queryClient = useQueryClient()
  const [selectedUlid, setSelectedUlid] = useState<string | null>(null)

  const sessionsQuery = useQuery({
    queryKey: ['security', 'sessions'],
    queryFn: fetchSecuritySessions,
  })

  const selected = useMemo(
    () => sessionsQuery.data?.find((row) => row.ulid === selectedUlid) ?? null,
    [sessionsQuery.data, selectedUlid],
  )

  const revokeMutation = useMutation({
    mutationFn: revokeSecuritySession,
    onSuccess: async () => {
      setSelectedUlid(null)
      feedback.success('Session revoked.')
      await queryClient.invalidateQueries({ queryKey: ['security', 'sessions'] })
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to revoke session.'),
  })

  const revokeOthersMutation = useMutation({
    mutationFn: revokeOtherSecuritySessions,
    onSuccess: async () => {
      setSelectedUlid(null)
      feedback.success('Other sessions revoked.')
      await queryClient.invalidateQueries({ queryKey: ['security', 'sessions'] })
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to revoke other sessions.'),
  })

  if (!session) {
    return null
  }

  async function revokeSelected() {
    if (!selected || selected.is_current || selected.revoked_at) return
    const username = selected.membership?.username ?? 'this user'
    if (!(await feedback.confirm(`Revoke the selected session for ${username}?`))) return
    revokeMutation.mutate(selected.ulid)
  }

  async function revokeOthers() {
    if (!(await feedback.confirm('Revoke all of your other active sessions?'))) return
    revokeOthersMutation.mutate()
  }

  function formatDate(value: string | null): string {
    if (!value) return '—'
    const parsed = new Date(value)
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString()
  }

  return (
    <DesktopPanel
      title="Security"
      flush
      toolbar={
        <>
          <UiButton
            variant="info"
            icon={<RefreshCw size={14} />}
            label={sessionsQuery.isFetching ? 'Refreshing…' : 'Refresh'}
            disabled={sessionsQuery.isFetching}
            onClick={() => void sessionsQuery.refetch()}
          />
          <UiButton
            variant="warning"
            icon={<LogOut size={14} />}
            label="Sign out other sessions"
            disabled={revokeOthersMutation.isPending}
            onClick={() => void revokeOthers()}
          />
          <UiButton
            variant="danger"
            icon={<ShieldCheck size={14} />}
            label="Revoke selected"
            disabled={
              !selected ||
              selected.is_current ||
              Boolean(selected.revoked_at) ||
              revokeMutation.isPending
            }
            onClick={() => void revokeSelected()}
          />
          <UiButton variant="info" icon={<X size={14} />} label="Close" onClick={closeActiveTab} />
        </>
      }
    >
      <div className="admin-security-summary">
        <span>
          User:
          <strong>{session.user.name}</strong>
        </span>
        <span>
          Membership:
          <strong>{session.membership.status}</strong>
        </span>
        <span>
          Current device:
          <strong>{session.device ? `${session.device.name} (${session.device.status})` : 'Not bound'}</strong>
        </span>
        <span>
          Visible sessions:
          <strong>{sessionsQuery.data?.length ?? 0}</strong>
        </span>
        {canRevokeAny ? <span>Tenant session administrator</span> : <span>Own sessions only</span>}
      </div>

      <div className="admin-security-grid">
        <PosDataGrid
          columns={[
            {
              key: 'user',
              header: 'User',
              width: 150,
              render: (row) => row.membership?.user?.name ?? row.membership?.username ?? '—',
            },
            {
              key: 'username',
              header: 'Username',
              width: 140,
              render: (row) => row.membership?.username ?? '—',
            },
            {
              key: 'device',
              header: 'Device',
              width: 180,
              render: (row) => row.device?.name ?? 'Browser session',
            },
            {
              key: 'ip',
              header: 'IP Address',
              width: 130,
              render: (row) => row.ip_address ?? '—',
            },
            {
              key: 'seen',
              header: 'Last Seen',
              width: 180,
              render: (row) => formatDate(row.last_seen_at),
            },
            {
              key: 'status',
              header: 'Status',
              width: 110,
              render: (row) => row.revoked_at ? 'Revoked' : row.is_current ? 'Current' : 'Active',
            },
            {
              key: 'agent',
              header: 'User Agent',
              render: (row) => row.user_agent ?? '—',
            },
          ]}
          rows={sessionsQuery.data ?? []}
          rowKey={(row) => row.ulid}
          selectedKey={selectedUlid}
          onSelect={(row) => setSelectedUlid(row.ulid)}
          emptyMessage={sessionsQuery.isLoading ? 'Loading security sessions…' : 'No security sessions found.'}
        />
      </div>

      <div className="admin-security-note">
        Device approval/revocation stays under Administration → Devices. Password changes stay under My Account.
        This page manages authenticated sessions using the existing backend security policy.
      </div>
    </DesktopPanel>
  )
}
