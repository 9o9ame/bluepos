import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { fetchPlatformDeviceActivity } from '../../api/platform'
import { PosDataGrid } from '../../components/desktop/PosDataGrid'
import { UiButton } from '../../components/ui/UiButton'

export function PlatformDeviceActivityPage() {
  const query = useQuery({
    queryKey: ['platform', 'device-activity'],
    queryFn: fetchPlatformDeviceActivity,
    refetchInterval: 60000,
  })

  return (
    <section className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-lg font-semibold">Device and employee activity</h1>
        <UiButton variant="info" onClick={() => void query.refetch()}>Refresh</UiButton>
      </div>
      <p className="text-[12px] text-[var(--ui-text-muted)]">
        Latest 200 recorded sessions across tenants. IP addresses do not establish precise physical location.
      </p>
      {query.isLoading ? <p className="text-[12px]">Loading activity…</p> : null}
      {query.isError ? <p role="alert" className="text-[12px] text-[var(--ui-danger)]">Unable to load activity.</p> : null}
      {!query.isLoading && !query.isError ? (
        <PosDataGrid
          columns={[
            { key: 'tenant', header: 'Tenant', render: row => row.tenant ? <Link className="underline" to={`/platform/tenants/${row.tenant.ulid}`}>{row.tenant.name} ({row.tenant.code})</Link> : '—' },
            { key: 'employee', header: 'Employee', render: row => row.membership?.username ?? '—' },
            { key: 'device', header: 'Device', render: row => row.device?.name ?? '—' },
            { key: 'ip', header: 'IP', render: row => row.ip_address ?? '—' },
            { key: 'seen', header: 'Last seen', render: row => row.last_seen_at ?? '—' },
            { key: 'state', header: 'State', render: row => row.revoked_at ? 'Revoked' : 'Recorded session' },
          ]}
          rows={query.data?.sessions ?? []}
          rowKey={row => row.ulid}
        />
      ) : null}
    </section>
  )
}
