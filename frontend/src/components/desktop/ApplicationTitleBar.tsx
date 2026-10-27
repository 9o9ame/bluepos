import { useEffect, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell } from 'lucide-react'
import { fetchDeviceAccessNotifications, fetchDeviceAccessRequests, markDeviceAccessNotificationRead } from '../../api/devices'
import { APP_NAME } from '../../config/app'
import type { AuthSession } from '../../types/auth'
import { useWorkspace } from '../../features/workspace/WorkspaceProvider'
import { AppearanceMenu } from './AppearanceMenu'
import { BranchSwitcher } from './BranchSwitcher'
import { DesktopChromeControls } from './DesktopChromeControls'
import { UserAccountMenu } from './UserAccountMenu'
import { UI_LAYER } from '../ui/uiLayers'

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
  const queryClient = useQueryClient()
  const [bellOpen, setBellOpen] = useState(false)
  const [bellError, setBellError] = useState<string | null>(null)
  const bellRef = useRef<HTMLDivElement>(null)
  const isTenantOwner = session.roles.some((role) => role.code === 'owner' && role.is_active)
  const pendingQuery = useQuery({
    queryKey: ['device-access-requests'],
    queryFn: () => fetchDeviceAccessRequests(),
    enabled: isTenantOwner,
    refetchInterval: 10000,
    refetchOnWindowFocus: true,
  })
  const notificationsQuery = useQuery({
    queryKey: ['device-access-inbox', 1],
    queryFn: () => fetchDeviceAccessNotifications(1),
    enabled: isTenantOwner,
    refetchInterval: 10000,
    refetchOnWindowFocus: true,
  })
  useEffect(() => {
    if (!bellOpen) return
    const onOutside = (event: MouseEvent) => {
      if (!bellRef.current?.contains(event.target as Node)) setBellOpen(false)
    }
    const onEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setBellOpen(false)
    }
    document.addEventListener('mousedown', onOutside)
    document.addEventListener('keydown', onEscape)
    return () => {
      document.removeEventListener('mousedown', onOutside)
      document.removeEventListener('keydown', onEscape)
    }
  }, [bellOpen])
  async function markRead(ulid: string) {
    setBellError(null)
    try {
      await markDeviceAccessNotificationRead(ulid)
      await queryClient.invalidateQueries({ queryKey: ['device-access-inbox'] })
    } catch {
      setBellError('Could not mark notification as read.')
    }
  }
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
        <BranchSwitcher session={session} />
        <span className="titlebar-warehouse" title="Active warehouse">
          {session.warehouse.code} — {session.warehouse.name}
        </span>
        {isTenantOwner ? (
          <div ref={bellRef} className="relative">
            <button
              type="button"
              className="desktop-chrome-btn relative"
              title="Device and network notifications"
              aria-label={`Notifications: ${notificationsQuery.data?.unread_count ?? 0} unread, ${pendingQuery.data?.pending_count ?? 0} pending approvals`}
              aria-expanded={bellOpen}
              aria-haspopup="dialog"
              onClick={() => setBellOpen(value => !value)}
            >
              <Bell size={15} aria-hidden />
              {(notificationsQuery.data?.unread_count ?? 0) > 0 ? (
                <span className="absolute -right-1 -top-1 min-w-4 rounded-full bg-[var(--ui-danger)] px-1 text-[10px] leading-4 text-white">
                  {notificationsQuery.data?.unread_count}
                </span>
              ) : null}
            </button>
            {bellOpen ? (
              <div
                role="dialog"
                aria-label="Device and network notifications"
                style={{ zIndex: UI_LAYER.dropdown }}
                className="absolute right-0 top-full mt-1 w-[min(380px,90vw)] rounded-[var(--panel-radius)] border border-[var(--ui-border)] bg-[var(--ui-surface)] p-2 text-[var(--ui-text)] shadow-[var(--ui-card-shadow)]"
              >
                <div className="mb-2 flex items-center justify-between gap-2 text-[12px]">
                  <strong>Device &amp; network alerts</strong>
                  <span>{pendingQuery.data?.pending_count ?? 0} pending</span>
                </div>
                {bellError ? <p role="alert" className="text-[var(--ui-danger)] text-[12px]">{bellError}</p> : null}
                {notificationsQuery.isError ? <p role="alert" className="text-[var(--ui-danger)] text-[12px]">Unable to load notifications.</p> : null}
                {notificationsQuery.isLoading ? <p className="text-[12px]">Loading notifications…</p> : null}
                <div className="max-h-64 overflow-y-auto">
                  {(notificationsQuery.data?.notifications ?? []).slice(0, 8).map(item => (
                    <div key={item.ulid} className="border-b border-[var(--ui-border)] py-2 text-[12px]">
                      <div><strong>{item.read_at === null ? '● ' : ''}{item.username}</strong> · {item.kind === 'new_device' ? 'New device' : 'New IP/network'} · {item.status}</div>
                      <div className="text-[var(--ui-text-muted)]">{item.device_name} · {new Date(item.created_at).toLocaleString()}</div>
                      {item.read_at === null ? (
                        <button type="button" className="mt-1 text-[var(--ui-accent)] underline" onClick={() => void markRead(item.ulid)}>Mark read</button>
                      ) : null}
                    </div>
                  ))}
                  {!notificationsQuery.isLoading && !notificationsQuery.isError && (notificationsQuery.data?.notifications.length ?? 0) === 0 ? (
                    <p className="py-2 text-[12px] text-[var(--ui-text-muted)]">No notifications.</p>
                  ) : null}
                </div>
                <button
                  type="button"
                  className="mt-2 w-full rounded-[var(--control-radius)] border border-[var(--ui-border)] bg-[var(--ui-accent-soft)] p-1 text-[12px]"
                  onClick={() => { setBellOpen(false); openModule('/administration/devices') }}
                >
                  Open Devices · Review approvals
                </button>
              </div>
            ) : null}
          </div>
        ) : null}
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
