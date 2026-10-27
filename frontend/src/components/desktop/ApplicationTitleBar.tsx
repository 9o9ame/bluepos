import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Bell } from 'lucide-react'
import { fetchDeviceAccessRequests } from '../../api/devices'
import { APP_NAME } from '../../config/app'
import type { AuthSession } from '../../types/auth'
import { useWorkspace } from '../../features/workspace/WorkspaceProvider'
import { AppearanceMenu } from './AppearanceMenu'
import { BranchSwitcher } from './BranchSwitcher'
import { DesktopChromeControls } from './DesktopChromeControls'
import { UserAccountMenu } from './UserAccountMenu'

type ApplicationTitleBarProps = {
  session: AuthSession
  busy: boolean
  onHome: () => void
  onLogout: () => void
  onChangePassword: () => void
  onAccount: () => void
}

export function ApplicationTitleBar({
  session,
  busy,
  onHome,
  onLogout,
  onChangePassword,
  onAccount,
}: ApplicationTitleBarProps) {
  const { tabs, activeKey, openModule } = useWorkspace()
  const isTenantOwner = session.roles.some((role) => role.code === 'owner' && role.is_active)
  const pendingQuery = useQuery({
    queryKey: ['device-access-requests'],
    queryFn: () => fetchDeviceAccessRequests(),
    enabled: isTenantOwner,
    refetchInterval: 30000,
  })
  const [appearanceOpen, setAppearanceOpen] = useState(false)
  const active = tabs.find((tab) => tab.key === activeKey)

  return (
    <header className="titlebar">
      <div className="titlebar-brand">
        <button type="button" className="titlebar-logo" onClick={onHome} title="Home">
          {APP_NAME}
        </button>
        <div className="titlebar-meta">
          <span>{session.tenant.name}</span>
          {import.meta.env.DEV ? <span className="titlebar-env">DEV</span> : null}
        </div>
      </div>
      <div className="titlebar-center">
        {active?.title ?? 'Home'} — {session.tenant.name}
      </div>
      <div className="titlebar-meta titlebar-controls">
        {isTenantOwner && (pendingQuery.data?.pending_count ?? 0) > 0 ? (
          <button
            type="button"
            className="inline-flex items-center gap-1 rounded-[var(--control-radius)] border border-[var(--ui-border)] bg-[var(--ui-surface)] px-2 py-1 text-[12px] text-[var(--ui-text)]"
            onClick={() => openModule('/administration/devices')}
            title="Open pending employee device approvals"
            aria-label={`${pendingQuery.data?.pending_count ?? 0} pending device approvals`}
          >
            <Bell size={14} />
            <span>{pendingQuery.data?.pending_count}</span>
          </button>
        ) : null}
        <BranchSwitcher session={session} />
        <span className="titlebar-warehouse" title="Active warehouse">
          {session.warehouse.code} — {session.warehouse.name}
        </span>
        <DesktopChromeControls />
        <AppearanceMenu open={appearanceOpen} onOpenChange={setAppearanceOpen} />
        <UserAccountMenu
          session={session}
          busy={busy}
          onLogout={onLogout}
          onChangePassword={onChangePassword}
          onAccount={onAccount}
          onAppearance={() => setAppearanceOpen(true)}
        />
      </div>
    </header>
  )
}
