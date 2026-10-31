import { useEffect, useState } from 'react'
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
    if (typeof window === 'undefined' || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
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
    <div className={`platform-dashboard-metric platform-dashboard-tone-${tone}`} style={{ animationDelay: `${index * 90}ms` }}>
      <div className="platform-dashboard-metric-label">{label}</div>
      <div className="platform-dashboard-metric-number" aria-label={value === null ? 'Unavailable' : `${value}`}>
        {value === null ? '—' : count.toLocaleString()}
      </div>
      <div className="platform-dashboard-track" role="progressbar" aria-label={`${label} share of total tenants`}
        aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(share)}>
        <span className="platform-dashboard-fill" style={{ '--metric-share': `${share}%` } as React.CSSProperties} />
      </div>
      <div className="platform-dashboard-metric-caption">{value === null ? 'No data' : total === null ? 'Total unavailable' : `${Math.round(share)}% of total tenants`}</div>
    </div>
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
