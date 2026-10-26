import { useQuery, useQueryClient } from '@tanstack/react-query'
import { RefreshCw, X } from 'lucide-react'
import { fetchBranches } from '../api/branches'
import { approveDevice, fetchDevices, revokeDevice, fetchDeviceAccessRequests, reviewDeviceAccessRequest } from '../api/devices'
import { ApiClientError } from '../api/client'
import { DesktopButton, DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useCan } from '../features/auth/useCan'
import { useAuth } from '../features/auth/AuthProvider'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Device } from '../types/auth'
import { useState } from 'react'

export function DevicesPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
  const canApprove = useCan('devices.approve')
  const { session } = useAuth()
  const canReviewRequests = Boolean(session?.roles.some((role) => role.code === 'owner' && role.is_active))
  const canRevoke = useCan('devices.revoke')
  const devicesQuery = useQuery({ queryKey: ['devices'], queryFn: fetchDevices })
  const requestsQuery = useQuery({ queryKey: ['device-access-requests'], queryFn: fetchDeviceAccessRequests, enabled: canReviewRequests })
  const [reviewing, setReviewing] = useState<string | null>(null)
  const branchesQuery = useQuery({ queryKey: ['branches'], queryFn: fetchBranches })
  const [error, setError] = useState<string | null>(null)
  const [selectedKey, setSelectedKey] = useState<string | null>(null)

  useWorkspaceHandlers({
    refresh: () => {
      void devicesQuery.refetch()
      if (canReviewRequests) void requestsQuery.refetch()
    },
  })

  async function onApprove(device: Device) {
    setError(null)
    try {
      await approveDevice(device.ulid, { branch_ulid: branchesQuery.data?.[0]?.ulid })
      await queryClient.invalidateQueries({ queryKey: ['devices'] })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to approve device.')
    }
  }

  async function onReview(ulid: string, decision: 'approved' | 'rejected') {
    setError(null)
    setReviewing(ulid)
    try {
      await reviewDeviceAccessRequest(ulid, decision)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['device-access-requests'] }),
        queryClient.invalidateQueries({ queryKey: ['devices'] }),
      ])
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to review access request.')
    } finally {
      setReviewing(null)
    }
  }

  async function onRevoke(device: Device) {
    setError(null)
    try {
      await revokeDevice(device.ulid)
      await queryClient.invalidateQueries({ queryKey: ['devices'] })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to revoke device.')
    }
  }

  return (
    <DesktopPanel
      title="Devices"
      toolbar={
        <>
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" onClick={() => { void devicesQuery.refetch(); if (canApprove) void requestsQuery.refetch() }} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
      flush
    >
      {error ? <p className="p-2 text-[12px] text-[var(--danger)]">{error}</p> : null}
      {canReviewRequests ? (
        <section className="p-2">
          <h3 className="mb-2 text-[12px] font-semibold">Pending employee device / network requests</h3>
          {requestsQuery.isLoading ? <p className="text-[12px]">Loading requests…</p> : null}
          {requestsQuery.isError ? <p className="text-[12px] text-[var(--ui-danger)]">Unable to load access requests.</p> : null}
          {(requestsQuery.data?.requests ?? []).length === 0 && !requestsQuery.isLoading && !requestsQuery.isError ? <p className="text-[12px] text-[var(--ui-text-muted)]">No pending requests.</p> : null}
          <div className="flex flex-col gap-1">
            {(requestsQuery.data?.requests ?? []).map((item) => (
              <div key={item.ulid} className="flex flex-wrap items-center justify-between gap-2 border border-[var(--ui-border)] p-2 text-[12px]">
                <span><strong>{item.username}</strong> — {item.device_name} · {item.created_at}</span>
                <span className="flex gap-1">
                  <DesktopButton label="Approve" onClick={() => void onReview(item.ulid, 'approved')} disabled={reviewing !== null} />
                  <DesktopButton label="Reject" variant="danger" onClick={() => void onReview(item.ulid, 'rejected')} disabled={reviewing !== null} />
                </span>
              </div>
            ))}
          </div>
        </section>
      ) : null}
      <PosDataGrid
        columns={[
          { key: 'name', header: 'Name', render: (row) => row.name },
          { key: 'ulid', header: 'ULID', render: (row) => row.ulid },
          { key: 'branch', header: 'Branch', render: (row) => row.branch?.code ?? '—' },
          { key: 'status', header: 'Status', render: (row) => row.status },
          { key: 'seen', header: 'Last seen', render: (row) => row.last_seen_at ?? '—' },
          {
            key: 'actions',
            header: 'Actions',
            render: (row) => (
              <div className="flex justify-end gap-1">
                {canApprove && row.status === 'pending' ? (
                  <DesktopButton label="Approve" onClick={() => void onApprove(row)} />
                ) : null}
                {canRevoke && row.status !== 'revoked' ? (
                  <DesktopButton label="Revoke" variant="danger" onClick={() => void onRevoke(row)} />
                ) : null}
              </div>
            ),
          },
        ]}
        rows={devicesQuery.data ?? []}
        rowKey={(row) => row.ulid}
        selectedKey={selectedKey}
        onSelect={(row) => setSelectedKey(row.ulid)}
      />
    </DesktopPanel>
  )
}
