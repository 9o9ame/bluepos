import { APP_NAME, APP_VERSION } from '../../config/app'
import type { AuthSession } from '../../types/auth'

type StatusBarProps = {
  session: AuthSession
  online: boolean
}

export function StatusBar({ session, online }: StatusBarProps) {
  const role =
    session.roles.map((item) => item.name).join(', ') || (session.membership.is_owner ? 'Owner' : 'Member')

  return (
    <footer className="statusbar">
      <div className="statusbar-cluster">
        <span className="statusbar-item">{session.tenant.name}</span>
        <span className="statusbar-item">
          {session.branch.code} — {session.branch.name}
        </span>
        <span className="statusbar-item">
          {session.warehouse.code} — {session.warehouse.name}
        </span>
        <span className="statusbar-item">{session.user.name}</span>
        <span className="statusbar-item">{role}</span>
      </div>
      <div className="statusbar-cluster">
        <span className="statusbar-item">{online ? 'Online' : 'Offline'}</span>
        <span className="statusbar-item">
          {APP_NAME} v{APP_VERSION}
        </span>
      </div>
    </footer>
  )
}
