import { useQuery } from '@tanstack/react-query'
import { fetchBranches } from '../api/branches'
import { DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useAuth } from '../features/auth/AuthProvider'

export function BranchesPage() {
  const query = useQuery({ queryKey: ['branches'], queryFn: fetchBranches })

  return (
    <DesktopPanel title="Branches" flush>
      <PosDataGrid
        columns={[
          { key: 'code', header: 'Code', width: 90, render: (row) => row.code },
          { key: 'name', header: 'Name', render: (row) => row.name },
          { key: 'status', header: 'Status', width: 110, render: (row) => row.status },
          { key: 'default', header: 'Default', width: 80, align: 'center', render: (row) => (row.is_default ? 'Yes' : '') },
        ]}
        rows={query.data ?? []}
        rowKey={(row) => row.ulid}
        emptyMessage="No branches available."
      />
      <p className="later-banner">Branch records are listed from the existing API. Create/edit remains a later administration phase.</p>
    </DesktopPanel>
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
