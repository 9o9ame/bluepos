import { useMemo, useState } from 'react'
import { ChevronDown, ChevronRight, Fingerprint, KeyRound, LogOut, MonitorSmartphone, ShieldCheck, UserRound, Layers3, LayoutDashboard, Building2, CreditCard, Users, LockKeyhole, ClipboardList, Settings, Search } from 'lucide-react'
import './platform-account-dropdown.css'
import './platform-sidebar.css'
import './platform-neumorphic.css'
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
  const [accessOpen, setAccessOpen] = useState(false)
  const [sidebarCollapsed, setSidebarCollapsed] = useState(true)
  const [sidebarSearch, setSidebarSearch] = useState('')
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
            className="platform-account-trigger bp-motion-action"
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
                  className="platform-account-menu-item bp-motion-action"
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
                className="platform-account-menu-item platform-account-menu-signout bp-motion-action"
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
        <nav className="platform-sidebar-frame" data-collapsed={sidebarCollapsed} aria-label="Platform navigation"
          onMouseEnter={() => setSidebarCollapsed(false)}
          onMouseLeave={() => { setSidebarCollapsed(true); setAccessOpen(false) }}
          onFocusCapture={() => setSidebarCollapsed(false)}
          onBlurCapture={event => {
            if (!event.currentTarget.contains(event.relatedTarget)) {
              setSidebarCollapsed(true)
              setAccessOpen(false)
            }
          }}>
          <div className="platform-sidebar-panel">
            <div className="platform-sidebar-brand">
              <span className="platform-sidebar-brand-icon"><Layers3 size={18} aria-hidden="true" /></span>
              <span className="platform-sidebar-brand-text">BluePOS Platform</span>
            </div>
            <label className="platform-sidebar-search">
              <Search size={15} aria-hidden="true" />
              {sidebarCollapsed ? null : <input type="search" aria-label="Search platform navigation" value={sidebarSearch}
                onChange={event => setSidebarSearch(event.target.value)} placeholder="Search menu..." />}
            </label>
            <div className="platform-sidebar-label">{sidebarCollapsed ? '•' : 'MENU'}</div>
            <div className="platform-sidebar-links">
              {visiblePrimary.filter(item => item.label.toLowerCase().includes(sidebarSearch.toLowerCase())).map(item => (
                <NavItem key={item.to} to={item.to} label={item.label} end={item.end} collapsed={sidebarCollapsed}
                  icon={item.label === 'Dashboard' ? LayoutDashboard : item.label === 'Tenants' ? Building2 : CreditCard} />
              ))}
              {canAccess && (sidebarSearch === '' || 'platform access'.includes(sidebarSearch.toLowerCase()) ||
                ACCESS.some(item => item.label.toLowerCase().includes(sidebarSearch.toLowerCase()))) ? (
                <div className="platform-sidebar-group" onMouseEnter={() => setAccessOpen(true)} onMouseLeave={() => setAccessOpen(false)}
                  onFocusCapture={() => setAccessOpen(true)} onBlurCapture={event => { if (!event.currentTarget.contains(event.relatedTarget)) setAccessOpen(false) }}>
                  <button type="button" className="platform-sidebar-group-trigger" onClick={() => setAccessOpen(value => !value)}
                    aria-expanded={accessOpen} title="Platform Access">
                    <LockKeyhole size={16} aria-hidden="true" />
                    <span className="platform-sidebar-item-label">Platform Access</span>
                    {accessOpen ? <ChevronDown size={13} className="platform-sidebar-group-chevron" /> : <ChevronRight size={13} className="platform-sidebar-group-chevron" />}
                  </button>
                  <div className="platform-sidebar-submenu" data-open={accessOpen} aria-hidden={!accessOpen}>
                    <div className="platform-sidebar-submenu-inner">
                      {ACCESS.filter(item => user?.permissions.includes(item.can) && item.label.toLowerCase().includes(sidebarSearch.toLowerCase())).map(item => (
                        <NavItem key={item.to} to={item.to} label={item.label} collapsed={sidebarCollapsed}
                          icon={item.label === 'Users' ? Users : item.label === 'Roles' ? ShieldCheck : KeyRound} indent
                          tabIndex={accessOpen ? undefined : -1} />
                      ))}
                    </div>
                  </div>
                </div>
              ) : null}
              {canAudit && 'audit'.includes(sidebarSearch.toLowerCase()) ? <NavItem to="/platform/security/audit" label="Audit" icon={ClipboardList} collapsed={sidebarCollapsed} /> : null}
              {canAudit && 'device activity'.includes(sidebarSearch.toLowerCase()) ? <NavItem to="/platform/security/device-activity" label="Device Activity" icon={MonitorSmartphone} collapsed={sidebarCollapsed} /> : null}
              {canSettings && 'settings'.includes(sidebarSearch.toLowerCase()) ? <NavItem to="/platform/settings" label="Settings" icon={Settings} collapsed={sidebarCollapsed} /> : null}
            </div>
            <div className="platform-sidebar-footer">
              <div className="platform-sidebar-footer-rule" />
              <button type="button" className="platform-sidebar-footer-item" onClick={() => navigate('/platform/account/profile')}
                title="My Profile"><UserRound size={16} /><span className="platform-sidebar-item-label">My Profile</span></button>
              <button type="button" className="platform-sidebar-footer-item" onClick={() => void logout()}
                title="Sign Out"><LogOut size={16} /><span className="platform-sidebar-item-label">Sign Out</span></button>
            </div>
          </div>
        </nav>
        <main className="platform-main min-w-0 flex-1 overflow-auto p-4">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

type SidebarIcon = typeof LayoutDashboard

function NavItem({ to, label, end, indent, icon: Icon, collapsed, tabIndex }: {
  to: string
  label: string
  end?: boolean
  indent?: boolean
  icon: SidebarIcon
  collapsed: boolean
  tabIndex?: number
}) {
  return (
    <NavLink to={to} end={end} title={collapsed ? label : undefined} tabIndex={tabIndex}
      className={({ isActive }) => `platform-sidebar-link${isActive ? ' is-active' : ''}${indent ? ' is-indent' : ''}`}>
      <Icon size={16} aria-hidden="true" />
      <span className="platform-sidebar-item-label">{label}</span>
    </NavLink>
  )
}
