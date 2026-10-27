import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { fetchPlatformDeviceActivity } from '../../api/platform'
import { PosDataGrid } from '../../components/desktop/PosDataGrid'
import { UiButton } from '../../components/ui/UiButton'
import { UiSelect } from '../../components/ui/UiSelect'

export function PlatformDeviceActivityPage() {
  const [tenantCode, setTenantCode] = useState('')
  const [username, setUsername] = useState('')
  const [event, setEvent] = useState('')
  const [ipAddress, setIpAddress] = useState('')
  const [eventPage, setEventPage] = useState(1)
  const [sessionPage, setSessionPage] = useState(1)
  const [since, setSince] = useState('')
  const [until, setUntil] = useState('')
  const filters = { tenant_code: tenantCode.trim() || undefined, username: tenantCode.trim() ? username.trim() || undefined : undefined, event: event || undefined, ip_address: ipAddress.trim() || undefined, since: since || undefined, until: until || undefined, event_page: eventPage, session_page: sessionPage }
  const query = useQuery({
    queryKey: ['platform', 'device-activity', filters.tenant_code, filters.username, filters.event, filters.ip_address, filters.since, filters.until, filters.event_page, filters.session_page],
    queryFn: () => fetchPlatformDeviceActivity(filters),
    refetchInterval: 60000,
  })

  const updateFilters = () => { setEventPage(1); setSessionPage(1) }

  return (
    <section className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-lg font-semibold">Device and employee activity</h1>
        <UiButton variant="info" onClick={() => void query.refetch()}>Refresh</UiButton>
      </div>
      <div className="flex flex-wrap items-end gap-2 text-[12px]">
        <label className="flex flex-col gap-1">Tenant code
          <input className="h-8 rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] px-2" value={tenantCode} onChange={e => { setTenantCode(e.target.value); updateFilters() }} placeholder="All tenants" />
        </label>
        <label className="flex flex-col gap-1">Username (within tenant)
          <input className="h-8 rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] px-2" value={username} onChange={e => { setUsername(e.target.value); updateFilters() }} disabled={!tenantCode.trim()} placeholder="All users" />
        </label>
        <label className="flex flex-col gap-1">IP address
          <input className="h-8 rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] px-2" value={ipAddress} onChange={e => { setIpAddress(e.target.value); updateFilters() }} placeholder="Any IP" />
        </label>
        <label className="flex flex-col gap-1">From date
          <input type="date" className="h-8 rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] px-2" value={since} onChange={e => { setSince(e.target.value); updateFilters() }} />
        </label>
        <label className="flex flex-col gap-1">To date
          <input type="date" min={since || undefined} className="h-8 rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] px-2" value={until} onChange={e => { setUntil(e.target.value); updateFilters() }} />
        </label>
        <div className="min-w-[190px]"><span>Event type</span>
          <UiSelect value={event} onChange={value => { setEvent(value); updateFilters() }} options={[
            {value:'',label:'All security events'}, {value:'LOGIN_SUCCESS',label:'Login success'},
            {value:'LOGIN_FAILURE',label:'Login failure'}, {value:'LOGIN_BLOCKED',label:'Login blocked'}, {value:'DEVICE_ACCESS_REQUESTED',label:'Access requested'},
            {value:'DEVICE_ACCESS_APPROVED',label:'Access approved'}, {value:'DEVICE_ACCESS_REJECTED',label:'Access rejected'},
            {value:'DEVICE_REVOKED',label:'Device revoked'},
          ]}/>
        </div>
        <UiButton variant="info" onClick={() => {setTenantCode('');setUsername('');setEvent('');setIpAddress('');setSince('');setUntil('');updateFilters()}}>Clear filters</UiButton>
      </div>
      <p className="text-[12px] text-[var(--ui-text-muted)]">
        Recorded sessions across tenants, 100 per page. IP addresses do not establish precise physical location.
      </p>
      <h2 className="text-[13px] font-semibold">Recent authentication and device security events</h2>
      <p className="text-[12px] text-[var(--ui-text-muted)]">
        Recorded audit events, 100 per page. Failed attempts without a known tenant/user remain unidentified; no username is inferred from credential hashes.
      </p>
      {!query.isLoading && !query.isError ? (
        <PosDataGrid
          columns={[
            { key: 'event', header: 'Event', render: row => row.event },
            { key: 'reason', header: 'Denial reason', render: row => row.reason ?? '—' },
            { key: 'tenant', header: 'Tenant', render: row => row.tenant ? <Link className="underline" to={`/platform/tenants/${row.tenant.ulid}`}>{row.tenant.name}</Link> : 'Unknown' },
            { key: 'user', header: 'User', render: row => row.membership?.username ?? 'Unknown' },
            { key: 'device', header: 'Device', render: row => row.device?.name ?? '—' },
            { key: 'ip', header: 'IP', render: row => row.ip_address ?? '—' },
            { key: 'browser', header: 'Browser', render: row => row.user_agent ?? '—' },
            { key: 'when', header: 'Time', render: row => row.occurred_at ?? '—' },
          ]}
          rows={query.data?.events ?? []}
          rowKey={row => row.ulid}
        />
      ) : null}
      <div className="flex items-center gap-2 text-[12px]">
        <span>Events: {query.data?.pagination.events.total ?? 0} · page {eventPage}</span>
        <UiButton variant="info" disabled={eventPage <= 1} onClick={() => setEventPage(page => Math.max(1, page - 1))}>Previous events</UiButton>
        <UiButton variant="info" disabled={!query.data || eventPage >= query.data.pagination.events.last_page} onClick={() => setEventPage(page => page + 1)}>Next events</UiButton>
      </div>
      <h2 className="text-[13px] font-semibold">Recorded sessions</h2>
      {query.isLoading ? <p className="text-[12px]">Loading activity…</p> : null}
      {query.isError ? <p role="alert" className="text-[12px] text-[var(--ui-danger)]">Unable to load activity.</p> : null}
      {!query.isLoading && !query.isError ? (
        <PosDataGrid
          columns={[
            { key: 'tenant', header: 'Tenant', render: row => row.tenant ? <Link className="underline" to={`/platform/tenants/${row.tenant.ulid}`}>{row.tenant.name} ({row.tenant.code})</Link> : '—' },
            { key: 'employee', header: 'Employee', render: row => row.membership?.username ?? '—' },
            { key: 'roles', header: 'Roles', render: row => row.membership?.roles.map(role => role.name).join(', ') || '—' },
            { key: 'device', header: 'Device', render: row => row.device?.name ?? '—' },
            { key: 'ip', header: 'IP', render: row => row.ip_address ?? '—' },
            { key: 'seen', header: 'Last seen', render: row => row.last_seen_at ?? '—' },
            { key: 'state', header: 'State', render: row => row.revoked_at ? 'Revoked' : 'Recorded session' },
          ]}
          rows={query.data?.sessions ?? []}
          rowKey={row => row.ulid}
        />
      ) : null}
      <div className="flex items-center gap-2 text-[12px]">
        <span>Sessions: {query.data?.pagination.sessions.total ?? 0} · page {sessionPage}</span>
        <UiButton variant="info" disabled={sessionPage <= 1} onClick={() => setSessionPage(page => Math.max(1, page - 1))}>Previous sessions</UiButton>
        <UiButton variant="info" disabled={!query.data || sessionPage >= query.data.pagination.sessions.last_page} onClick={() => setSessionPage(page => page + 1)}>Next sessions</UiButton>
      </div>
    </section>
  )
}
