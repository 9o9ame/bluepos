import { useMemo, useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import { usePlatformAuth } from '../features/platform/PlatformAuthProvider'
import { usePlatformCan } from '../features/platform/usePlatformCan'

const PRIMARY = [
  { to: '/platform', label: 'Dashboard', end: true, can: 'platform.dashboard.view' },
  { to: '/platform/tenants', label: 'Tenants', can: 'platform.tenants.view' },
  { to: '/platform/plans', label: 'Plans', can: 'platform.plans.view' },
]

const ACCESS = [
  { to: '/platform/access/users', label: 'Users', can: 'platform.users.view' },
  { to: '/platform/access/roles', label: 'Roles', can: 'platform.roles.view' },
  { to: '/platform/access/permissions', label: 'Permissions', can: 'platform.permissions.view' },
]

export function PlatformShell() {
  const { user, logout } = usePlatformAuth()
  const navigate = useNavigate()
  const canUsers = usePlatformCan('platform.users.view')
  const canRoles = usePlatformCan('platform.roles.view')
  const canPermissions = usePlatformCan('platform.permissions.view')
  const canAudit = usePlatformCan('platform.audit.view')
  const canSettings = usePlatformCan('platform.settings.view')
  const [menuOpen, setMenuOpen] = useState(false)
  const [accessOpen, setAccessOpen] = useState(true)
  const canAccess = canUsers || canRoles || canPermissions

  const visiblePrimary = useMemo(
    () => PRIMARY.filter((link) => !user?.permissions.length || user.permissions.includes(link.can)),
    [user],
  )

  return (
    <div className="platform-shell flex h-screen flex-col bg-[var(--ui-bg)] text-[var(--ui-text)]">
      <header className="platform-header flex items-center justify-between border-b border-[var(--ui-border)] bg-[var(--ui-accent-strong)] px-4 py-2 text-white">
        <div>
          <div className="text-[10px] font-black tracking-[0.2em] text-[var(--ui-accent)]">PLATFORM ADMINISTRATION</div>
          <div className="text-sm font-semibold">BluePOS Super Admin</div>
        </div>
        <div className="relative text-[12px]">
          <button
            type="button"
            className="platform-account-trigger rounded border border-[var(--ui-border)] px-2 py-1"
            onClick={() => setMenuOpen((open) => !open)}
          >
            {user?.name ?? user?.email} ▾
          </button>
          {menuOpen ? (
            <div className="platform-account-menu absolute right-0 z-20 mt-1 w-52 rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] py-1 shadow-lg">
              {[
                ['/platform/account/profile', 'My Profile'],
                ['/platform/account/password', 'Change Password'],
                ['/platform/account/security', 'Security'],
                ['/platform/account/security#mfa', 'MFA'],
                ['/platform/account/security#devices', 'Trusted Devices'],
                ['/platform/account/security#sessions', 'Active Sessions'],
              ].map(([to, label]) => (
                <button
                  key={to}
                  type="button"
                  className="platform-account-menu-item block w-full px-3 py-1.5 text-left hover:bg-[var(--ui-accent-soft)]"
                  onClick={() => {
                    setMenuOpen(false)
                    navigate(to)
                  }}
                >
                  {label}
                </button>
              ))}
              <button
                type="button"
                className="platform-account-menu-item platform-account-menu-signout block w-full border-t border-[var(--ui-border)] px-3 py-1.5 text-left hover:bg-[var(--ui-accent-soft)]"
                onClick={() => {
                  setMenuOpen(false)
                  void logout()
                }}
              >
                Sign Out
              </button>
            </div>
          ) : null}
        </div>
      </header>
      <div className="platform-body flex min-h-0 flex-1">
        <nav className="platform-nav w-52 shrink-0 overflow-auto border-r border-[var(--ui-border)] bg-[var(--ui-surface-subtle)] p-2">
          {visiblePrimary.map((link) => (
            <NavItem key={link.to} to={link.to} label={link.label} end={link.end} />
          ))}
          {canAccess ? (
            <div className="mt-2">
              <button
                type="button"
                className="platform-access-toggle mb-1 w-full rounded px-2 py-1.5 text-left text-[12px] font-semibold text-[var(--ui-text)] hover:bg-[var(--ui-accent-soft)]"
                onClick={() => setAccessOpen((open) => !open)}
              >
                Platform Access {accessOpen ? '▾' : '▸'}
              </button>
              {accessOpen
                ? ACCESS.filter((item) => user?.permissions.includes(item.can)).map((item) => (
                    <NavItem key={item.to} to={item.to} label={item.label} indent />
                  ))
                : null}
            </div>
          ) : null}
          {canAudit ? <NavItem to="/platform/security/audit" label="Audit" /> : null}
          {canSettings ? <NavItem to="/platform/settings" label="Settings" /> : null}
        </nav>
        <main className="platform-main min-w-0 flex-1 overflow-auto p-4">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

function NavItem({
  to,
  label,
  end,
  indent,
}: {
  to: string
  label: string
  end?: boolean
  indent?: boolean
}) {
  return (
    <NavLink
      to={to}
      end={end}
      className={({ isActive }) =>
        `platform-nav-item mb-1 block rounded px-2 py-1.5 text-[12px] font-semibold ${indent ? 'ml-3' : ''} ${
          isActive ? 'bg-slate-950 text-amber-300' : 'text-[var(--ui-text)] hover:bg-[var(--ui-accent-soft)]'
        }`
      }
    >
      {label}
    </NavLink>
  )
}
