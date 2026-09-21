import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchPlatformPermissions } from '../../api/platform'

export function PlatformPermissionsPage() {
  const [q, setQ] = useState('')
  const [module, setModule] = useState('')
  const query = useQuery({
    queryKey: ['platform', 'permissions', q, module],
    queryFn: () => fetchPlatformPermissions({ q: q || undefined, module: module || undefined }),
  })
  const modules = useMemo(
    () => Array.from(new Set((query.data ?? []).map((permission) => permission.module))).sort(),
    [query.data],
  )

  return (
    <section className="space-y-3">
      <h1 className="text-lg font-semibold">Platform Permissions</h1>
      <p className="text-[12px] text-slate-600">System catalogue only. Permissions cannot be created from this screen.</p>
      <div className="flex gap-2 text-[12px]">
        <input className="h-8 rounded border px-2" placeholder="Search key or name" value={q} onChange={(event) => setQ(event.target.value)} />
        <select className="h-8 rounded border px-2" value={module} onChange={(event) => setModule(event.target.value)}>
          <option value="">All modules</option>
          {modules.map((item) => (
            <option key={item} value={item}>
              {item}
            </option>
          ))}
        </select>
      </div>
      <table className="w-full border border-slate-300 bg-white text-left text-[12px]">
        <thead className="bg-slate-50 text-slate-500">
          <tr>
            <th className="p-2">Permission Key</th>
            <th>Module</th>
            <th>Description</th>
          </tr>
        </thead>
        <tbody>
          {(query.data ?? []).map((permission) => (
            <tr key={permission.ulid} className="border-t border-slate-200">
              <td className="p-2 font-mono">{permission.key}</td>
              <td>{permission.module}</td>
              <td>{permission.description ?? permission.name}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
