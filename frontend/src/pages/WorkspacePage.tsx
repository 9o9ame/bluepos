import { useOutletContext } from 'react-router-dom'
import { useAuth } from '../features/auth/AuthProvider'
import type { RibbonTab } from '../components/TopRibbon'

type ShellContext = {
  ribbonTab: RibbonTab
  workspaceTab: string
}

export function WorkspacePage() {
  const { session } = useAuth()
  const { ribbonTab } = useOutletContext<ShellContext>()

  if (!session) {
    return null
  }

  return (
    <section className="h-full rounded border border-slate-300 bg-white p-4 shadow-sm">
      <h2 className="text-base font-semibold">Welcome, {session.user.name}</h2>
      <p className="mt-1 text-[13px] text-slate-600">
        {session.tenant.name} is ready. {ribbonTab} tools are placeholders until later phases.
      </p>
      <dl className="mt-4 grid max-w-xl grid-cols-2 gap-2 text-[12px]">
        <dt className="text-slate-500">Tenant</dt>
        <dd>{session.tenant.name}</dd>
        <dt className="text-slate-500">Branch</dt>
        <dd>
          {session.branch.code} — {session.branch.name}
        </dd>
        <dt className="text-slate-500">Warehouse</dt>
        <dd>
          {session.warehouse.code} — {session.warehouse.name}
        </dd>
        <dt className="text-slate-500">Role</dt>
        <dd>{session.roles.map((role) => role.name).join(', ') || (session.membership.is_owner ? 'Owner' : 'Member')}</dd>
      </dl>
    </section>
  )
}
