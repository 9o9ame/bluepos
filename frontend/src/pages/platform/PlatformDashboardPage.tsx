import { useEffect, useState, type CSSProperties } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { fetchPlatformDashboard } from '../../api/platform'
import './platform-dashboard.css'

function useCountUp(target: number | null, duration = 1250) {
  const [value, setValue] = useState(0)

  useEffect(() => {
    if (target === null) {
      setValue(0)
      return
    }
    if (typeof window === 'undefined' || window.matchMedia('(prefers-reduced-motion: reduce)').matches || document.documentElement.dataset.animations === 'off') {
      setValue(target)
      return
    }
    let frame = 0
    const started = performance.now()
    const tick = (now: number) => {
      const elapsed = Math.min(1, (now - started) / duration)
      const eased = 1 - (1 - elapsed) ** 3
      setValue(Math.round(target * eased))
      if (elapsed < 1) frame = window.requestAnimationFrame(tick)
    }
    frame = window.requestAnimationFrame(tick)
    return () => window.cancelAnimationFrame(frame)
  }, [target, duration])

  return value
}

function TenantMetric({ label, value, total, tone, index }: {
  label: string
  value: number | null
  total: number | null
  tone: 'primary' | 'success' | 'warning' | 'danger'
  index: number
}) {
  const count = useCountUp(value)
  const share = value !== null && total !== null && total > 0
    ? Math.min(100, Math.max(0, (value / total) * 100))
    : 0

  return (
    <div className={`platform-dashboard-metric bp-motion-enter platform-dashboard-tone-${tone}`} style={{ animationDelay: `${index * 90}ms` }}>
      <div className="platform-dashboard-metric-label">{label}</div>
      <div className="platform-dashboard-metric-number" aria-label={value === null ? 'Unavailable' : `${value}`}>
        {value === null ? '—' : count.toLocaleString()}
      </div>
      <div className="platform-dashboard-track" role="progressbar" aria-label={`${label} share of total tenants`}
        aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(share)}>
        <span key={value ?? 'loading'} className="platform-dashboard-fill" style={{ '--metric-share': `${share}%` } as CSSProperties} />
      </div>
      <div className="platform-dashboard-metric-caption">{value === null ? 'No data' : total === null ? 'Total unavailable' : `${Math.round(share)}% of total tenants`}</div>
    </div>
  )
}


type StatusDatum = { label: string; count: number; tone: 'success' | 'warning' | 'danger' | 'primary' }

function StatusCharts({ values, total }: { values: StatusDatum[]; total: number | null }) {
  const known = values.reduce((sum, item) => sum + item.count, 0)
  // The API exposes more statuses than the three summary counts; show their remainder explicitly.
  const remainder = total === null ? 0 : Math.max(0, total - known)
  const rows: StatusDatum[] = remainder > 0 ? [...values, { label: 'Other statuses', count: remainder, tone: 'primary' }] : values
  const max = Math.max(1, ...rows.map(item => item.count))
  const [selectedStatus, setSelectedStatus] = useState<string | null>(null)
  const selectedRow = rows.find(row => row.label === selectedStatus) ?? null
  const points = rows.map((item, index) => ({
    x: rows.length === 1 ? 50 : 8 + (index * 84) / (rows.length - 1),
    y: 82 - (item.count / max) * 60,
    item,
  }))
  const linePath = points.length ? points.slice(1).reduce((path, point, index) => { const prev = points[index]; const mid = (prev.x + point.x) / 2; return `${path} C ${mid} ${prev.y}, ${mid} ${point.y}, ${point.x} ${point.y}` }, `M ${points[0].x} ${points[0].y}`) : ''
  const areaPath = points.length ? `${linePath} L ${points[points.length - 1].x} 88 L ${points[0].x} 88 Z` : ''
  let shareOffset = 0
  const slices = rows.map(item => {
    const start = shareOffset
    shareOffset += total && total > 0 ? (item.count / total) * 100 : 0
    const color = item.tone === 'success' ? 'var(--ui-success)' : item.tone === 'warning' ? 'var(--ui-warning)' : item.tone === 'danger' ? 'var(--ui-danger)' : 'var(--ui-accent)'
    return `${color} ${start}% ${shareOffset}%`
  })
  const donutGradient = total && total > 0 ? `conic-gradient(${slices.join(', ')})` : 'var(--ui-surface-muted)'
  if (total === null) return null

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <div className="platform-dashboard-card platform-dashboard-chart-card">
        <h2 className="mb-1 text-sm font-semibold">Tenant distribution</h2>
        <p className="platform-dashboard-chart-hint">Current share of tenant statuses</p>
        <div className="platform-dashboard-donut-layout">
          <div className="platform-dashboard-donut bp-motion-enter" role="img"
            aria-label={`Tenant status: ${rows.map(item => `${item.label} ${item.count}`).join(', ')}${selectedRow ? `. Selected: ${selectedRow.label} ${selectedRow.count}` : ''}`}
            style={{ '--donut-gradient': donutGradient } as CSSProperties}>
            {selectedRow ? <div className="platform-dashboard-donut-selection" aria-hidden="true" /> : null}
            <div className="platform-dashboard-donut-center">
              <strong>{(selectedRow?.count ?? total).toLocaleString()}</strong><span>{selectedRow?.label ?? 'Tenants'}</span>
            </div>
          </div>
          <div className="platform-dashboard-chart-legend">
            {rows.map(item => <button type="button" className={`platform-dashboard-legend-line platform-dashboard-legend-action${selectedStatus === item.label ? " is-selected" : ""}${selectedRow && selectedRow.label !== item.label ? ' is-muted' : ''}`} key={item.label} onClick={() => setSelectedStatus(selectedStatus === item.label ? null : item.label)} aria-pressed={selectedStatus === item.label} aria-label={`${item.label}: ${item.count} tenants, ${total ? Math.round(item.count / total * 100) : 0}% of total`}>
              <span className={`platform-dashboard-legend-dot platform-dashboard-tone-${item.tone}`} />
              <span>{item.label}</span><strong>{item.count.toLocaleString()}</strong>
            </button>)}
            {total === 0 ? <p className="text-[var(--ui-text-muted)]">No tenants yet.</p> : null}
          </div>
        </div>
      </div>
      <div className="platform-dashboard-card platform-dashboard-chart-card">
        <div className="platform-dashboard-chart-heading">
          <div><h2 className="mb-1 text-sm font-semibold">Tenant status profile</h2>
            <p className="platform-dashboard-chart-hint">Current statuses, not a time-series or revenue chart</p></div>
          <span className="platform-dashboard-chart-tag">{total.toLocaleString()} total</span>
        </div>
        <div className="platform-dashboard-profile-chart">
          <svg viewBox="0 0 100 100" preserveAspectRatio="none" role="img"
            aria-label={`Current status counts: ${rows.map(item => `${item.label} ${item.count}`).join(', ')}`}>
            <defs><linearGradient id="platform-status-profile-fill" x1="0" x2="0" y1="0" y2="1">
              <stop offset="0%" stopColor="var(--ui-accent)" stopOpacity=".20" />
              <stop offset="100%" stopColor="var(--ui-accent)" stopOpacity=".01" />
            </linearGradient></defs>
            {[22, 42, 62, 82].map(y => <line key={y} x1="4" x2="96" y1={y} y2={y} className="platform-dashboard-profile-gridline" />)}
            {points.length > 0 ? <path d={areaPath} fill="url(#platform-status-profile-fill)" /> : null}
            {points.length > 0 ? <path key={linePath} d={linePath} className="platform-dashboard-profile-line" pathLength={100} /> : null}
            {points.map(point => <circle key={point.item.label} cx={point.x} cy={point.y} r="1.5"
              className={`platform-dashboard-profile-point${selectedRow && selectedRow.label !== point.item.label ? ' is-muted' : ''}${selectedRow?.label === point.item.label ? ' is-selected' : ''}`} />)}
          </svg>
        </div>
        <div className="platform-dashboard-profile-labels">
          {rows.map(row => <div key={row.label} className={selectedRow && selectedRow.label !== row.label ? 'is-muted' : selectedRow?.label === row.label ? 'is-selected' : ''}><span>{row.label}</span><strong>{row.count.toLocaleString()}</strong></div>)}
        </div>
      </div>    </div>
  )
}

export function PlatformDashboardPage() {
  const query = useQuery({ queryKey: ['platform', 'dashboard'], queryFn: fetchPlatformDashboard })
  const data = query.data
  const total = typeof data?.tenants.total === 'number' ? data.tenants.total : null
  const metrics = [
    { label: 'Total tenants', value: typeof data?.tenants.total === 'number' ? data.tenants.total : null, tone: 'primary' as const },
    { label: 'Trial', value: typeof data?.tenants.trial === 'number' ? data.tenants.trial : null, tone: 'warning' as const },
    { label: 'Active', value: typeof data?.tenants.active === 'number' ? data.tenants.active : null, tone: 'success' as const },
    { label: 'Suspended', value: typeof data?.tenants.suspended === 'number' ? data.tenants.suspended : null, tone: 'danger' as const },
  ]

  return (
    <section className="platform-dashboard space-y-4">
      <h1 className="text-lg font-semibold">Platform dashboard</h1>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {metrics.map((metric, index) => (
          <TenantMetric key={metric.label} label={metric.label} value={metric.value} total={total} tone={metric.tone} index={index} />
        ))}
      </div>
      {query.isError ? <div className="platform-dashboard-card" role="alert">Unable to load dashboard charts. <button type="button" className="platform-dashboard-retry" onClick={() => void query.refetch()}>Retry</button></div> : null}
      {query.isPending ? <div className="platform-dashboard-card" role="status">Loading tenant status charts…</div> : null}
      {!query.isError && !query.isPending ? <StatusCharts total={total} values={[
        { label: 'Active', count: data?.tenants.active ?? 0, tone: 'success' },
        { label: 'Trial', count: data?.tenants.trial ?? 0, tone: 'warning' },
        { label: 'Suspended', count: data?.tenants.suspended ?? 0, tone: 'danger' },
      ]} /> : null}
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div className="platform-dashboard-card">
          <h2 className="mb-2 text-sm font-semibold">Plans</h2>
          <ul className="space-y-1 text-[12px]">
            {(data?.plans ?? []).map((plan) => (
              <li key={plan.ulid}>
                <Link className="font-semibold text-[var(--ui-text)]" to={`/platform/plans/${plan.ulid}`}>{plan.name}</Link>{' '}
                <span className="text-[var(--ui-text-muted)]">{plan.code}</span>
              </li>
            ))}
          </ul>
        </div>
        <div className="platform-dashboard-card">
          <h2 className="mb-2 text-sm font-semibold">Security alerts</h2>
          <ul className="space-y-1 text-[12px]">
            {(data?.security_alerts ?? []).map((row) => (
              <li key={row.ulid}>{row.event} {row.occurred_at ? `· ${row.occurred_at}` : ''}</li>
            ))}
            {(data?.security_alerts ?? []).length === 0 ? <li className="text-[var(--ui-text-muted)]">No recent alerts.</li> : null}
          </ul>
        </div>
      </div>
      <div className="platform-dashboard-card">
        <h2 className="mb-2 text-sm font-semibold">Recently created tenants</h2>
        <div className="overflow-x-auto">
          <table className="w-full text-left text-[12px]">
            <thead><tr className="border-b border-[var(--ui-border)] text-[var(--ui-text-muted)]"><th className="py-1">Code</th><th>Name</th><th>Admin Username</th><th>Tenant Status</th><th>Plan</th></tr></thead>
            <tbody>{(data?.recent_tenants ?? []).map((tenant) => (
              <tr key={tenant.ulid} className="border-b border-[var(--ui-border)]">
                <td className="py-1"><Link className="font-semibold" to={`/platform/tenants/${tenant.ulid}`}>{tenant.code}</Link></td>
                <td>{tenant.name}</td><td className="font-mono">{tenant.admin_username ?? '—'}</td><td>{tenant.status}</td><td>{tenant.plan?.code ?? '—'}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      </div>
    </section>
  )
}
