import { useMemo, useState } from 'react'
import { ChevronDown, Fingerprint, KeyRound, LogOut, MonitorSmartphone, ShieldCheck, UserRound, Layers3 } from 'lucide-react'
import './platform-account-dropdown.css'
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
        <div
          className="platform-account-dropdown relative text-[12px]"
          onMouseEnter={() => setMenuOpen(true)}
          onMouseLeave={() => setMenuOpen(false)}
          onBlur={(event) => {
            if (!event.currentTarget.contains(event.relatedTarget)) setMenuOpen(false)
          }}
          onKeyDown={(event) => {
            if (event.key === 'Escape') {
              setMenuOpen(false)
              event.currentTarget.querySelector<HTMLButtonElement>('.platform-account-trigger')?.focus()
            }
          }}
        >
          <button
            type="button"
            aria-haspopup="menu"
            aria-expanded={menuOpen}
            aria-controls="platform-account-menu"
            className="platform-account-trigger"
            onClick={() => setMenuOpen((open) => !open)}
          >
            <span className="platform-account-avatar"><Layers3 size={16} aria-hidden="true" /></span>
            <span className="platform-account-label">
              <strong>{user?.name ?? user?.email}</strong>
              <small>Manage your account</small>
            </span>
            <span className="platform-account-chevron"><ChevronDown size={16} aria-hidden="true" /></span>
          </button>
          <div id="platform-account-menu" role="menu" aria-label="Platform account" aria-hidden={!menuOpen} className="platform-account-menu" data-open={menuOpen}>
              {[
                { to: '/platform/account/profile', label: 'My Profile', hint: 'Personal information', icon: UserRound },
                { to: '/platform/account/password', label: 'Change Password', hint: 'Update your password', icon: KeyRound },
                { to: '/platform/account/security', label: 'Security', hint: 'Security overview', icon: ShieldCheck },
                { to: '/platform/account/security#mfa', label: 'MFA', hint: 'Multi-factor authentication', icon: Fingerprint },
                { to: '/platform/account/security#devices', label: 'Trusted Devices', hint: 'Recognized devices', icon: MonitorSmartphone },
                { to: '/platform/account/security#sessions', label: 'Active Sessions', hint: 'Review active sessions', icon: MonitorSmartphone },
              ].map(({ to, label, hint, icon: Icon }) => (
                <button
                  key={to}
                  type="button"
                  role="menuitem"
                  tabIndex={menuOpen ? 0 : -1}
                  className="platform-account-menu-item"
                  onClick={() => {
                    setMenuOpen(false)
                    navigate(to)
                  }}
                >
                  <span className="platform-account-item-icon"><Icon size={16} aria-hidden="true" /></span>
                  <span className="platform-account-item-text"><strong>{label}</strong><small>{hint}</small></span>
                  <span className="platform-account-item-arrow" aria-hidden="true">→</span>
                </button>
              ))}
              <div className="platform-account-menu-divider" />
              <button
                type="button"
                role="menuitem"
                tabIndex={menuOpen ? 0 : -1}
                className="platform-account-menu-item platform-account-menu-signout"
                onClick={() => {
                  setMenuOpen(false)
                  void logout()
                }}
              >
                <span className="platform-account-item-icon"><LogOut size={16} aria-hidden="true" /></span>
                <span className="platform-account-item-text"><strong>Sign Out</strong><small>End your session</small></span>
              </button>
          </div>
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
          {canAudit ? <NavItem to="/platform/security/device-activity" label="Device Activity" /> : null}
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
          isActive ? 'bg-[var(--ui-accent)] text-white' : 'text-[var(--ui-text)] hover:bg-[var(--ui-accent-soft)]'
        }`
      }
    >
      {label}
    </NavLink>
  )
}
