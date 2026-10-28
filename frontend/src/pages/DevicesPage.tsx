import { summarizeUserAgent } from '../utils/userAgentSummary'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { RefreshCw, X } from 'lucide-react'
import { fetchBranches } from '../api/branches'
import { fetchSecuritySessions, fetchTenantLoginHistory, fetchTenantLocationHistory } from '../api/security'
import { approveDevice, fetchDevices, revokeDevice, restoreDevice, fetchDeviceAccessRequests, reviewDeviceAccessRequest, revokeDeviceAccessRequest, fetchDeviceAccessNotifications, markDeviceAccessNotificationRead } from '../api/devices'
import { ApiClientError } from '../api/client'
import { DesktopButton, DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useCan } from '../features/auth/useCan'
import { useAuth } from '../features/auth/AuthProvider'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Device } from '../types/auth'
import { useState } from 'react'
import { UiSelect } from '../components/ui/UiSelect'
import type { TenantLoginHistoryFilters } from '../api/security'

export function DevicesPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
  const canApprove = useCan('devices.approve')
  const { session } = useAuth()
  const canReviewRequests = Boolean(session?.roles.some((role) => role.code === 'owner' && role.is_active))
  const canRevoke = useCan('devices.revoke')
  const canViewSessions = useCan('security.sessions.view')
  const [requestStatus, setRequestStatus] = useState<'pending' | 'approved' | 'rejected' | 'all'>('pending')
  const [locationPage, setLocationPage] = useState(1)
  const locationQuery = useQuery({
    queryKey: ['tenant-location-history', locationPage],
    queryFn: () => fetchTenantLocationHistory(locationPage),
    enabled: canReviewRequests,
    refetchInterval: 60000,
  })
  const [historyPage, setHistoryPage] = useState(1)
  const [historyDraft, setHistoryDraft] = useState<TenantLoginHistoryFilters>({ event: 'all' })
  const [historyFilters, setHistoryFilters] = useState<TenantLoginHistoryFilters>({ event: 'all' })
  const historyQuery = useQuery({
    queryKey: ['tenant-login-history', historyPage, historyFilters],
    queryFn: () => fetchTenantLoginHistory(historyPage, historyFilters),
    enabled: canReviewRequests,
    refetchInterval: 60000,
  })
  const [inboxPage, setInboxPage] = useState(1)
  const inboxQuery = useQuery({
    queryKey: ['device-access-inbox', session?.tenant.ulid, session?.user.ulid, inboxPage],
    queryFn: () => fetchDeviceAccessNotifications(inboxPage),
    enabled: canReviewRequests,
    refetchInterval: 4000,
  })
  const [markingRead, setMarkingRead] = useState<string | null>(null)
  const devicesQuery = useQuery({ queryKey: ['devices'], queryFn: fetchDevices })
  const allAccessQuery = useQuery({
    queryKey: ['device-access-requests', 'all'],
    queryFn: () => fetchDeviceAccessRequests('all'),
    enabled: canReviewRequests,
    refetchInterval: 4000,
  })
  const requestsQuery = useQuery({ queryKey: ['device-access-requests', requestStatus], queryFn: () => fetchDeviceAccessRequests(requestStatus), enabled: canReviewRequests, refetchInterval: 4000 })
  const sessionsQuery = useQuery({ queryKey: ['security-sessions'], queryFn: fetchSecuritySessions, enabled: canViewSessions, refetchInterval: 60000 })
  const [reviewing, setReviewing] = useState<string | null>(null)
  const branchesQuery = useQuery({ queryKey: ['branches'], queryFn: fetchBranches })
  const [error, setError] = useState<string | null>(null)
  const [selectedKey, setSelectedKey] = useState<string | null>(null)

  useWorkspaceHandlers({
    refresh: () => {
      void devicesQuery.refetch()
      if (canReviewRequests) { void requestsQuery.refetch(); void inboxQuery.refetch() }
      if (canViewSessions) void sessionsQuery.refetch()
    },
  })

  async function onMarkRead(ulid: string) {
    setError(null)
    setMarkingRead(ulid)
    try {
      await markDeviceAccessNotificationRead(ulid)
      await queryClient.invalidateQueries({ queryKey: ['device-access-inbox'] })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to mark alert as read.')
    } finally {
      setMarkingRead(null)
    }
  }

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

  async function onRevokeAccess(ulid: string) {
    if (!window.confirm('Revoke only this employee’s approved device/network access? Other users on the same device will not be revoked.')) return
    setError(null)
    setReviewing(ulid)
    try {
      await revokeDeviceAccessRequest(ulid)
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['device-access-requests'] }),
        queryClient.invalidateQueries({ queryKey: ['security-sessions'] }),
      ])
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to revoke employee network access.')
    } finally {
      setReviewing(null)
    }
  }

  async function onRevoke(device: Device) {
    if (!window.confirm(`Revoke device ${device.name} (${device.associated_usernames?.join(', ') || 'owner unknown'})? Existing sessions will be terminated.`)) return
    setError(null)
    try {
      await revokeDevice(device.ulid)
      await queryClient.invalidateQueries({ queryKey: ['devices'] })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to revoke device.')
    }
  }

  async function onRestore(device: Device) {
    if (!window.confirm(`Restore device ${device.name} (${device.associated_usernames?.join(', ') || 'owner unknown'})? Existing sessions remain revoked; the user must sign in again.`)) return
    setError(null)
    try {
      await restoreDevice(device.ulid)
      await queryClient.invalidateQueries({ queryKey: ['devices'] })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to restore device.')
    }
  }

  return (
    <DesktopPanel
      title="Devices"
      toolbar={
        <>
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" onClick={() => { void devicesQuery.refetch(); if (canReviewRequests) { void requestsQuery.refetch(); void inboxQuery.refetch() } if (canViewSessions) void sessionsQuery.refetch() }} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
      flush
    >
      {error ? <p className="p-2 text-[12px] text-[var(--danger)]">{error}</p> : null}
      {canReviewRequests ? (
        <section className="p-2">
          <h3 className="mb-2 text-[12px] font-semibold">Device &amp; network alerts ({inboxQuery.data?.unread_count ?? 0} unread)</h3>
          {inboxQuery.isLoading ? <p className="text-[12px]">Loading alerts…</p> : null}
          {inboxQuery.isError ? <p role="alert" className="text-[12px] text-[var(--ui-danger)]">Unable to load alerts.</p> : null}
          {inboxQuery.data?.notifications.length === 0 ? <p className="text-[12px] text-[var(--ui-text-muted)]">No alerts recorded.</p> : null}
          <div className="flex flex-col gap-1">
            {(inboxQuery.data?.notifications ?? []).map(alert => (
              <div key={alert.ulid} className="flex flex-wrap items-center justify-between gap-2 border border-[var(--ui-border)] p-2 text-[12px]">
                <span>
                  {alert.read_at === null ? <strong>Unread · </strong> : null}
                  {alert.kind === 'new_device' ? 'New device' : 'New network/IP'} · {alert.username} {alert.role_names.length ? `(${alert.role_names.join(', ')})` : ''} · {alert.device_name}
                  {' · IP: '}{alert.request_ip ?? 'Unavailable'} · {new Date(alert.created_at).toLocaleString()}
                  {alert.request_latitude_approx !== null && alert.request_longitude_approx !== null ? ` · Approx. location: ${alert.request_latitude_approx}, ${alert.request_longitude_approx}` : ''}
                  {' · '}{alert.status}
                </span>
                {alert.read_at === null ? <DesktopButton label="Mark read" onClick={() => void onMarkRead(alert.ulid)} disabled={markingRead !== null} /> : null}
              </div>
            ))}
          </div>
          <div className="mt-2 flex items-center gap-2 text-[12px]">
            <span>Page {inboxPage} · {inboxQuery.data?.pagination.total ?? 0} alerts</span>
            <DesktopButton label="Previous" disabled={inboxPage <= 1} onClick={() => setInboxPage(p => Math.max(1, p - 1))} />
            <DesktopButton label="Next" disabled={!inboxQuery.data || inboxPage >= inboxQuery.data.pagination.last_page} onClick={() => setInboxPage(p => p + 1)} />
          </div>
        </section>
      ) : null}
      {canReviewRequests ? (
        <section className="p-2">
          <h3 className="mb-2 text-[12px] font-semibold">Employee device / network approval requests (pending: {requestsQuery.data?.pending_count ?? 0})</h3>
          <label className="mb-2 flex items-center gap-2 text-[12px]">Show
            <select className="rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] p-1" value={requestStatus} onChange={(event) => setRequestStatus(event.target.value as typeof requestStatus)}>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="all">All</option>
            </select>
          </label>
          {requestsQuery.isLoading ? <p className="text-[12px]">Loading requests…</p> : null}
          {requestsQuery.isError ? <p className="text-[12px] text-[var(--ui-danger)]">Unable to load access requests.</p> : null}
          {(requestsQuery.data?.requests ?? []).length === 0 && !requestsQuery.isLoading && !requestsQuery.isError ? <p className="text-[12px] text-[var(--ui-text-muted)]">No matching access requests.</p> : null}
          <div className="flex flex-col gap-1">
            {(requestsQuery.data?.requests ?? []).map((item) => (
              <div key={item.ulid} className="flex flex-wrap items-center justify-between gap-2 border border-[var(--ui-border)] p-2 text-[12px]">
                <span><strong>{item.username}</strong> {item.role_names.length ? `(${item.role_names.join(', ')})` : ''} — {item.device_name} · IP: {item.request_ip ?? 'Unavailable'} · Approx. location: {item.request_latitude_approx !== null && item.request_longitude_approx !== null ? `${item.request_latitude_approx}, ${item.request_longitude_approx}` : 'Unavailable'} · Browser: {item.request_user_agent ?? 'Unavailable'} · {new Date(item.created_at).toLocaleString()} · {item.status}{item.reviewer_name ? ` · Reviewed by ${item.reviewer_name}` : ''}</span>
                {item.status === 'pending' ? <span className="flex gap-1">
                  <DesktopButton label="Approve" onClick={() => void onReview(item.ulid, 'approved')} disabled={reviewing !== null} />
                  <DesktopButton label="Reject" variant="danger" onClick={() => void onReview(item.ulid, 'rejected')} disabled={reviewing !== null} />
                </span> : null}
                {item.status === 'rejected' ? (
                  <DesktopButton label="Reapprove access" onClick={() => void onReview(item.ulid, 'approved')} disabled={reviewing !== null} />
                ) : null}
                {item.status === 'approved' ? (
                  <DesktopButton label="Revoke network access" variant="danger" onClick={() => void onRevokeAccess(item.ulid)} disabled={reviewing !== null} />
                ) : null}
              </div>
            ))}
          </div>
        </section>
      ) : null}
      {canReviewRequests ? (
        <section className="p-2">
          <h3 className="mb-2 text-[12px] font-semibold">Staff login and public IP history</h3>
          <form
            className="mb-2 flex flex-wrap items-end gap-2 text-[12px]"
            onSubmit={event => {
              event.preventDefault()
              setHistoryPage(1)
              setHistoryFilters({
                username: historyDraft.username?.trim() || undefined,
                ip_address: historyDraft.ip_address?.trim() || undefined,
                event: historyDraft.event ?? 'all',
                date_from: historyDraft.date_from || undefined,
                date_to: historyDraft.date_to || undefined,
              })
            }}
          >
            <label className="flex flex-col gap-1">Employee
              <input aria-label="History employee" className="rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] p-1 text-[var(--ui-text)]" value={historyDraft.username ?? ''} onChange={e => setHistoryDraft(v => ({ ...v, username: e.target.value }))} placeholder="Username" maxLength={120} />
            </label>
            <label className="flex flex-col gap-1">Event
              <UiSelect
                aria-label="History event type"
                className="min-w-[130px]"
                value={historyDraft.event ?? 'all'}
                options={[
                  { value: 'all', label: 'All events' },
                  { value: 'LOGIN_SUCCESS', label: 'Login success' },
                  { value: 'LOGIN_FAILURE', label: 'Login failure' },
                  { value: 'LOGIN_BLOCKED', label: 'Login blocked' },
                ]}
                onChange={event => setHistoryDraft(v => ({ ...v, event: event as TenantLoginHistoryFilters['event'] }))}
                searchable={false}
              />
            </label>
            <label className="flex flex-col gap-1">From
              <input aria-label="History date from" type="date" className="rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] p-1 text-[var(--ui-text)]" value={historyDraft.date_from ?? ''} onChange={e => setHistoryDraft(v => ({ ...v, date_from: e.target.value }))} />
            </label>
            <label className="flex flex-col gap-1">To
              <input aria-label="History date to" type="date" min={historyDraft.date_from || undefined} className="rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] p-1 text-[var(--ui-text)]" value={historyDraft.date_to ?? ''} onChange={e => setHistoryDraft(v => ({ ...v, date_to: e.target.value }))} />
            </label>
            <label className="flex flex-col gap-1">IP address
              <input aria-label="History IP address" className="rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] p-1 text-[var(--ui-text)]" value={historyDraft.ip_address ?? ''} onChange={e => setHistoryDraft(v => ({ ...v, ip_address: e.target.value }))} placeholder="Exact IP" maxLength={45} />
            </label>
            <DesktopButton label="Apply filters" type="submit" />
            <DesktopButton label="Clear" type="button" onClick={() => {
              setHistoryDraft({ event: 'all' })
              setHistoryFilters({ event: 'all' })
              setHistoryPage(1)
            }} />
          </form>
          {historyQuery.isLoading ? <p className="text-[12px]">Loading login history…</p> : null}
          {historyQuery.isError ? <p role="alert" className="text-[12px] text-[var(--ui-danger)]">Unable to load login history.</p> : null}
          {!historyQuery.isLoading && !historyQuery.isError ? (
            <PosDataGrid
              columns={[
                { key: 'event', header: 'Event', render: row => row.event },
                { key: 'employee', header: 'Employee', render: row => row.username ?? 'Unknown' },
                { key: 'device', header: 'Device', render: row => row.device_name ?? '—' },
                { key: 'ip', header: 'Public IP', render: row => row.ip_address ?? '—' },
                { key: 'browser', header: 'Browser / OS (reported)', render: row => summarizeUserAgent(row.user_agent) },
                { key: 'time', header: 'Recorded time', render: row => row.occurred_at },
              ]}
              rows={historyQuery.data?.events ?? []}
              rowKey={row => row.ulid}
            />
          ) : null}
          <div className="mt-2 flex items-center gap-2 text-[12px]">
            <span>{historyQuery.data?.pagination.total ?? 0} events · page {historyPage}</span>
            <DesktopButton label="Previous" disabled={historyPage <= 1} onClick={() => setHistoryPage(n => Math.max(1, n - 1))} />
            <DesktopButton label="Next" disabled={!historyQuery.data || historyPage >= historyQuery.data.pagination.last_page} onClick={() => setHistoryPage(n => n + 1)} />
          </div>
        </section>
      ) : null}
      {canReviewRequests ? (
        <section className="p-2">
          <h3 className="mb-2 text-[12px] font-semibold">Approximate staff locations (last 30 days)</h3>
          <p className="mb-2 text-[12px] text-[var(--ui-text-muted)]">Location comes from browser permission at sign-in; coordinates are rounded for privacy, not reliable proof of physical presence.</p>
          {locationQuery.isError ? <p role="alert" className="text-[12px] text-[var(--ui-danger)]">Unable to load location history.</p> : null}
          {!locationQuery.isLoading && !locationQuery.isError ? (
            <PosDataGrid
              columns={[
                { key: 'employee', header: 'Employee', render: row => row.username },
                { key: 'device', header: 'Device', render: row => row.device_name ?? '—' },
                { key: 'location', header: 'Approx. place / coordinates', render: row => `${[row.city, row.region, row.country].filter(Boolean).join(', ') || 'Unknown'} · ${row.latitude_approx}, ${row.longitude_approx}` },
                { key: 'accuracy', header: 'Reported accuracy', render: row => `${row.accuracy_meters} m` },
                { key: 'time', header: 'Observed', render: row => row.observed_at },
              ]}
              rows={locationQuery.data?.locations ?? []}
              rowKey={row => row.ulid}
            />
          ) : null}
          <div className="mt-2 flex items-center gap-2 text-[12px]">
            <span>{locationQuery.data?.pagination.total ?? 0} records · page {locationPage}</span>
            <DesktopButton label="Previous" disabled={locationPage <= 1} onClick={() => setLocationPage(n => Math.max(1, n - 1))} />
            <DesktopButton label="Next" disabled={!locationQuery.data || locationPage >= locationQuery.data.pagination.last_page} onClick={() => setLocationPage(n => n + 1)} />
          </div>
        </section>
      ) : null}
      {canViewSessions ? (
        <section className="p-2">
          <h3 className="mb-2 text-[12px] font-semibold">Recent device / IP activity</h3>
          {sessionsQuery.isLoading ? <p className="text-[12px] text-[var(--ui-text-muted)]">Loading activity…</p> : null}
          {sessionsQuery.isError ? <p className="text-[12px] text-[var(--ui-danger)]">Unable to load session activity.</p> : null}
          {!sessionsQuery.isLoading && !sessionsQuery.isError ? (
            <PosDataGrid
              columns={[
                { key: 'employee', header: 'Employee', render: (row) => row.membership?.username ?? '—' },
                { key: 'device', header: 'Device', render: (row) => row.device?.name ?? '—' },
                { key: 'ip', header: 'IP address', render: (row) => row.ip_address ?? '—' },
                { key: 'seen', header: 'Last seen', render: (row) => row.last_seen_at ?? '—' },
                { key: 'status', header: 'Status', render: (row) => row.revoked_at ? 'Revoked' : 'Active' },
              ]}
              rows={(sessionsQuery.data ?? []).slice(0, 25)}
              rowKey={(row) => row.ulid}
            />
          ) : null}
        </section>
      ) : null}
      <section className="p-2">
        <h3 className="mb-2 text-[12px] font-semibold">Registered devices and individual user access</h3>
        <p className="mb-2 text-[12px] text-[var(--ui-text-muted)]">One browser device may be shared by multiple users. Revoke staff access for just that employee; revoking an entire device blocks everyone on it.</p>
        <PosDataGrid
          columns={[
            { key: 'name', header: 'Device', render: (row) => row.device.name },
            { key: 'user', header: 'User', render: (row) => row.username },
            { key: 'ulid', header: 'Device ULID', render: (row) => row.device.ulid },
            { key: 'status', header: 'Device status', render: (row) => row.device.status },
            { key: 'seen', header: 'Last seen', render: (row) => row.device.last_seen_at ?? '—' },
            {
              key: 'actions',
              header: 'Access action',
              render: (row) => {
                const access = (allAccessQuery.data?.requests ?? []).find(
                  item => item.device_ulid === row.device.ulid && item.username === row.username && item.status === 'approved'
                )
                const rejected = (allAccessQuery.data?.requests ?? []).find(
                  item => item.device_ulid === row.device.ulid && item.username === row.username && item.status === 'rejected'
                )
                const shared = (row.device.associated_usernames?.length ?? 0) > 1
                return (
                  <div className="flex justify-end gap-1">
                    {canReviewRequests && access ? (
                      <DesktopButton
                        label={`Revoke ${row.username} access`}
                        variant="danger"
                        disabled={reviewing !== null}
                        onClick={() => void onRevokeAccess(access.ulid)}
                      />
                    ) : null}
                    {canReviewRequests && !access && rejected && row.device.status === 'active' ? (
                      <DesktopButton
                        label={`Reapprove ${row.username} access`}
                        disabled={reviewing !== null}
                        onClick={() => void onReview(rejected.ulid, 'approved')}
                      />
                    ) : null}
                    {canReviewRequests && row.device.status === 'revoked' && row.first ? (
                      <DesktopButton label="Restore whole device" onClick={() => void onRestore(row.device)} />
                    ) : null}
                    {canApprove && row.device.status === 'pending' && row.device.device_type !== 'browser' && row.first ? (
                      <DesktopButton label="Approve terminal" onClick={() => void onApprove(row.device)} />
                    ) : null}
                    {canRevoke && row.device.status !== 'revoked' && row.first && !shared ? (
                      <DesktopButton label="Revoke whole device" variant="danger" onClick={() => void onRevoke(row.device)} />
                    ) : null}
                    {shared && !access && !rejected && row.device.status !== 'revoked' ? (
                      <span className="text-[var(--ui-text-muted)] text-[11px]">Shared device — no individual approval to revoke</span>
                    ) : null}
                  </div>
                )
              },
            },
          ]}
          rows={(devicesQuery.data ?? []).flatMap(device => {
            const users = device.associated_usernames?.length ? device.associated_usernames : ['Unknown']
            return users.map((username, index) => ({ device, username, first: index === 0, key: `${device.ulid}:${username}` }))
          })}
          rowKey={row => row.key}
          selectedKey={selectedKey}
          onSelect={row => setSelectedKey(row.key)}
        />
      </section>
    </DesktopPanel>
  )
}
