import { useEffect, useRef, useState } from 'react'
import { ChevronDown, UserRound } from 'lucide-react'
import type { AuthSession } from '../../types/auth'

type UserAccountMenuProps = {
  session: AuthSession
  busy: boolean
  onLogout: () => void
  onChangePassword: () => void
  onAccount: () => void
}

export function UserAccountMenu({
  session,
  busy,
  onLogout,
  onChangePassword,
  onAccount,
}: UserAccountMenuProps) {
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const role =
    session.roles.map((item) => item.name).join(', ') || (session.membership.is_owner ? 'Owner' : 'Member')

  useEffect(() => {
    function onDocClick(event: MouseEvent) {
      if (rootRef.current && !rootRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', onDocClick)
    return () => document.removeEventListener('mousedown', onDocClick)
  }, [])

  return (
    <div className="account-menu" ref={rootRef}>
      <button
        type="button"
        className="account-menu-btn"
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
      >
        <UserRound size={14} aria-hidden />
        <span>
          {session.user.name}
          <span style={{ opacity: 0.75 }}> · {role}</span>
        </span>
        <ChevronDown size={12} aria-hidden />
      </button>
      {open ? (
        <div className="account-menu-panel" role="menu">
          <button type="button" role="menuitem" onClick={() => { setOpen(false); onAccount() }}>
            My Account
          </button>
          <button type="button" role="menuitem" onClick={() => { setOpen(false); onChangePassword() }}>
            Change Password
          </button>
          <button type="button" role="menuitem" disabled title="Available in a later phase">
            Lock
          </button>
          <button type="button" role="menuitem" disabled={busy} onClick={onLogout}>
            {busy ? 'Logging out…' : 'Logout'}
          </button>
        </div>
      ) : null}
    </div>
  )
}
