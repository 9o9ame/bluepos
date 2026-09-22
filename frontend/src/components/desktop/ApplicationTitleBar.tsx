import { APP_NAME } from '../../config/app'
import type { AuthSession } from '../../types/auth'
import { useWorkspace } from '../../features/workspace/WorkspaceProvider'
import { BranchSwitcher } from './BranchSwitcher'
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
  const { tabs, activeKey } = useWorkspace()
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
        <BranchSwitcher session={session} />
        <span className="titlebar-warehouse" title="Active warehouse">
          {session.warehouse.code} — {session.warehouse.name}
        </span>
        <UserAccountMenu
          session={session}
          busy={busy}
          onLogout={onLogout}
          onChangePassword={onChangePassword}
          onAccount={onAccount}
        />
      </div>
    </header>
  )
}
