import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { fetchPlatformDashboard } from '../../api/platform'

export function PlatformDashboardPage() {
  const query = useQuery({ queryKey: ['platform', 'dashboard'], queryFn: fetchPlatformDashboard })
  const data = query.data

  return (
    <section className="space-y-4">
      <h1 className="text-lg font-semibold">Platform dashboard</h1>
      <div className="grid grid-cols-4 gap-3">
        {[
          ['Total tenants', data?.tenants.total ?? '—'],
          ['Trial', data?.tenants.trial ?? '—'],
          ['Active', data?.tenants.active ?? '—'],
          ['Suspended', data?.tenants.suspended ?? '—'],
        ].map(([label, value]) => (
          <div key={label} className="rounded border border-slate-300 bg-white p-3">
            <div className="text-[11px] uppercase tracking-wide text-slate-500">{label}</div>
            <div className="text-2xl font-semibold">{value}</div>
          </div>
        ))}
      </div>
      <div className="grid grid-cols-2 gap-4">
        <div className="rounded border border-slate-300 bg-white p-3">
          <h2 className="mb-2 text-sm font-semibold">Plans</h2>
          <ul className="space-y-1 text-[12px]">
            {(data?.plans ?? []).map((plan) => (
              <li key={plan.ulid}>
                <Link className="font-semibold text-slate-800" to={`/platform/plans/${plan.ulid}`}>
                  {plan.name}
                </Link>{' '}
                <span className="text-slate-500">{plan.code}</span>
              </li>
            ))}
          </ul>
        </div>
        <div className="rounded border border-slate-300 bg-white p-3">
          <h2 className="mb-2 text-sm font-semibold">Security alerts</h2>
          <ul className="space-y-1 text-[12px]">
            {(data?.security_alerts ?? []).map((row) => (
              <li key={row.ulid}>
                {row.event} {row.occurred_at ? `· ${row.occurred_at}` : ''}
              </li>
            ))}
            {(data?.security_alerts ?? []).length === 0 ? <li className="text-slate-500">No recent alerts.</li> : null}
          </ul>
        </div>
      </div>
      <div className="rounded border border-slate-300 bg-white p-3">
        <h2 className="mb-2 text-sm font-semibold">Recently created tenants</h2>
        <table className="w-full text-left text-[12px]">
          <thead>
            <tr className="border-b border-slate-200 text-slate-500">
              <th className="py-1">Code</th>
              <th>Name</th>
              <th>Status</th>
              <th>Plan</th>
            </tr>
          </thead>
          <tbody>
            {(data?.recent_tenants ?? []).map((tenant) => (
              <tr key={tenant.ulid} className="border-b border-slate-100">
                <td className="py-1">
                  <Link className="font-semibold" to={`/platform/tenants/${tenant.ulid}`}>
                    {tenant.code}
                  </Link>
                </td>
                <td>{tenant.name}</td>
                <td>{tenant.status}</td>
                <td>{tenant.plan?.code ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}
