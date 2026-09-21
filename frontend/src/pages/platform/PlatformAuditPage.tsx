import { useQuery } from '@tanstack/react-query'
import { fetchPlatformAudit } from '../../api/platform'

export function PlatformAuditPage() {
  const query = useQuery({ queryKey: ['platform', 'audit'], queryFn: () => fetchPlatformAudit(1) })
  const rows = query.data?.data ?? []

  return (
    <section className="space-y-3">
      <h1 className="text-lg font-semibold">Platform security audit</h1>
      <p className="text-[12px] text-slate-600">Records are append-only. Super Admin cannot erase this history from the UI.</p>
      <table className="w-full border border-slate-300 bg-white text-left text-[12px]">
        <thead className="bg-slate-50 text-slate-500">
          <tr>
            <th className="p-2">When</th>
            <th>Event</th>
            <th>Resource</th>
            <th>Actor</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={row.ulid} className="border-t border-slate-200">
              <td className="p-2">{row.occurred_at}</td>
              <td>{row.event}</td>
              <td>{row.resource_ulid}</td>
              <td>{row.actor?.email ?? '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
