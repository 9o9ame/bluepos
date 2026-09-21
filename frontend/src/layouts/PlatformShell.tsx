import { NavLink, Outlet } from 'react-router-dom'
import { usePlatformAuth } from '../features/platform/PlatformAuthProvider'

const LINKS = [
  { to: '/platform', label: 'Dashboard', end: true },
  { to: '/platform/tenants', label: 'Tenants' },
  { to: '/platform/plans', label: 'Plans' },
  { to: '/platform/security/audit', label: 'Audit' },
  { to: '/platform/admins', label: 'Admins' },
]

export function PlatformShell() {
  const { user, logout } = usePlatformAuth()

  return (
    <div className="flex h-screen flex-col bg-slate-100 text-slate-900">
      <header className="flex items-center justify-between border-b border-slate-800 bg-slate-950 px-4 py-2 text-slate-100">
        <div>
          <div className="text-[10px] font-black tracking-[0.2em] text-amber-400">PLATFORM ADMINISTRATION</div>
          <div className="text-sm font-semibold">BluePOS Super Admin</div>
        </div>
        <div className="flex items-center gap-3 text-[12px]">
          <span>{user?.email}</span>
          <button type="button" className="rounded border border-slate-600 px-2 py-1" onClick={() => void logout()}>
            Sign out
          </button>
        </div>
      </header>
      <div className="flex min-h-0 flex-1">
        <nav className="w-52 shrink-0 border-r border-slate-300 bg-slate-200 p-2">
          {LINKS.map((link) => (
            <NavLink
              key={link.to}
              to={link.to}
              end={link.end}
              className={({ isActive }) =>
                `mb-1 block rounded px-2 py-1.5 text-[12px] font-semibold ${
                  isActive ? 'bg-slate-950 text-amber-300' : 'text-slate-800 hover:bg-slate-300'
                }`
              }
            >
              {link.label}
            </NavLink>
          ))}
        </nav>
        <main className="min-w-0 flex-1 overflow-auto p-4">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
