import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { apiFetch } from '../../api/client'
import { useEffect, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, BellRing, CheckCheck, X } from 'lucide-react'
import type { AuthSession } from '../../types/auth'
import { fetchDeviceAccessNotifications, markDeviceAccessNotificationRead, type DeviceAccessNotification } from '../../api/devices'
import { UI_LAYER } from '../ui/uiLayers'
import './NotificationBell.css'

const TONES = [
  { value: '07', label: 'Notification 07', file: 'universfield-new-notification-07-210334.mp3' },
  { value: '024', label: 'Notification 024', file: 'universfield-new-notification-024-370048.mp3' },
  { value: '050', label: 'Notification 050', file: 'universfield-new-notification-050-494248.mp3' },
  { value: '051', label: 'Notification 051', file: 'universfield-new-notification-051-494246.mp3' },
  { value: '057', label: 'Notification 057', file: 'universfield-new-notification-057-494255.mp3' },
]

function soundFor(value: string): string | null {
  const tone = TONES.find((item) => item.value === value)
  return tone ? '/sounds/notifications/' + tone.file : null
}

function playNotificationSound(value: string): void {
  const source = soundFor(value)
  if (!source) return
  const audio = new Audio(source)
  audio.volume = 0.65
  // Browser audio autoplay policies may block playback until first user gesture.
  void audio.play().catch((error) => {
    if (import.meta.env.DEV) console.warn('Notification sound could not play. Check audio file and browser sound permissions.', error)
    const fallback = new Audio('/sounds/error-notification.mp3')
    fallback.volume = 0.65
    void fallback.play().catch(() => undefined)
  })
}

export function NotificationBell({ session, onOpenDevices }: {
  session: AuthSession
  onOpenDevices: () => void
}) {
  const queryClient = useQueryClient()
  const isOwner = session.roles.some((role) => role.code === 'owner' && role.is_active)
  const scope = session.tenant.ulid + ':' + session.user.ulid
  const preferenceKey = 'bluepos-notification-tone:' + scope
  const [tone, setTone] = useState(() => localStorage.getItem(preferenceKey) || '07')
  const [open, setOpen] = useState(false)
  const hasRealtime = Boolean(import.meta.env.VITE_PUSHER_APP_KEY && import.meta.env.VITE_PUSHER_APP_CLUSTER)
  const [toast, setToast] = useState<DeviceAccessNotification | null>(null)
  const [ring, setRing] = useState(false)
  const known = useRef<Set<string> | null>(null)
  const [openingNotice, setOpeningNotice] = useState<string | null>(null)
  const [noticeError, setNoticeError] = useState(false)
  const lastScope = useRef(scope)
  const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const ringTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const notices = useQuery({
    queryKey: ['device-access-inbox', scope, 1],
    queryFn: () => fetchDeviceAccessNotifications(1),
    enabled: isOwner,
    refetchInterval: hasRealtime ? 300000 : 60000, // Pusher-first; a five-minute safety recovery if connected.
    refetchIntervalInBackground: false,
  })

  useEffect(() => {
    const key = import.meta.env.VITE_PUSHER_APP_KEY
    const cluster = import.meta.env.VITE_PUSHER_APP_CLUSTER
    if (!isOwner || !key || !cluster) return

    const pusher = new Pusher(key, { cluster, forceTLS: true })
    const echo = new Echo({
      broadcaster: 'pusher',
      key,
      cluster,
      client: pusher,
      authorizer: (channel: { name: string }) => ({
        authorize: (socketId: string, callback: (error: Error | null, data: { auth: string; channel_data?: string } | null) => void) => {
          void apiFetch('/api/broadcasting/auth', {
            method: 'POST',
            busy: 'none',
            body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
          }).then((result) => callback(null, result as { auth: string; channel_data?: string })).catch((error: Error) => callback(error, null))
        },
      }),
    })
    const channel = 'bluepos.tenant.' + session.tenant.ulid + '.member.' + session.membership.ulid
    echo.private(channel).listen('.device.access.requested', () => {
      void queryClient.invalidateQueries({ queryKey: ['device-access-inbox'] })
      void queryClient.invalidateQueries({ queryKey: ['device-access-requests'] })
    })
    return () => {
      echo.leave(channel)
      pusher.disconnect()
    }
  }, [isOwner, session.tenant.ulid, session.membership.ulid, queryClient])

  useEffect(() => {
    const handleTone = (event: Event) => {
      const detail = (event as CustomEvent<{ key: string; value: string }>).detail
      if (detail?.key === preferenceKey) setTone(detail.value)
    }
    window.addEventListener('bluepos-notification-tone-change', handleTone)
    return () => window.removeEventListener('bluepos-notification-tone-change', handleTone)
  }, [preferenceKey])

  useEffect(() => {
    if (lastScope.current === scope) return
    lastScope.current = scope
    known.current = null
    setTone(localStorage.getItem(preferenceKey) || '07')
    setOpen(false)
    setToast(null)
    setNoticeError(false)
  }, [scope, preferenceKey])

  useEffect(() => {
    if (!isOwner || !notices.data) return
    const current = new Set(notices.data.notifications.map((notice) => notice.ulid))
    if (known.current === null) {
      known.current = current
      return
    }
    const incoming = notices.data.notifications.filter((notice) =>
      notice.read_at === null && !known.current?.has(notice.ulid))
    // Preserve observed IDs between page refreshes; never replay a sound/toast
    // when an older unread notice reappears on the first page.
    known.current = new Set([...known.current, ...current].slice(-200))
    if (!incoming.length) return

    // This also refreshes an already-open Devices page without page reload.
    void queryClient.invalidateQueries({ queryKey: ['device-access-requests'] })
    void queryClient.invalidateQueries({ queryKey: ['device-access-requests', 'all'] })
    void queryClient.invalidateQueries({ queryKey: ['devices'] })
    setToast(incoming[0])
    setRing(true)
    playNotificationSound(tone)
    if (toastTimer.current) clearTimeout(toastTimer.current)
    if (ringTimer.current) clearTimeout(ringTimer.current)
    toastTimer.current = setTimeout(() => setToast(null), 7000)
    ringTimer.current = setTimeout(() => setRing(false), 1800)
  }, [isOwner, notices.data, queryClient, tone])

  useEffect(() => () => {
    if (toastTimer.current) clearTimeout(toastTimer.current)
    if (ringTimer.current) clearTimeout(ringTimer.current)
  }, [])

  async function openNotice(notice: DeviceAccessNotification) {
    if (openingNotice) return
    setOpeningNotice(notice.ulid)
    setNoticeError(false)
    try {
      if (notice.read_at === null) {
        await markDeviceAccessNotificationRead(notice.ulid)
        await queryClient.invalidateQueries({ queryKey: ['device-access-inbox', scope] })
      }
      setOpen(false)
      setToast(null)
      onOpenDevices()
    } catch {
      // Leave the unread item visible: a failed acknowledgement must not
      // pretend that the tenant owner's security alert was marked read.
      setNoticeError(true)
      setOpen(true)
    } finally {
      setOpeningNotice(null)
    }
  }

  if (!isOwner) return null

  const unread = notices.data?.unread_count ?? 0
  return (
    <div className="bp-notification-root" onMouseEnter={() => setOpen(true)} onMouseLeave={() => setOpen(false)} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false) }} onKeyDown={(event) => { if (event.key === 'Escape') setOpen(false) }}>
      <button
        type="button"
        className={'bp-notification-trigger bp-motion-bell' + (ring ? ' bp-notification-ring' : '')}
        title="Notifications"
        aria-label={unread ? unread + ' unread notifications' : 'Notifications'}
        aria-expanded={open}
        aria-haspopup="dialog"
        onClick={() => setOpen((value) => !value)}
      >
        <Bell size={17} aria-hidden="true" />
        {unread > 0 ? <span className="bp-notification-count">{unread > 99 ? '99+' : unread}</span> : null}
      </button>
      {open ? (
        <section className="bp-notification-popover" role="dialog" aria-label="Notifications" style={{ zIndex: UI_LAYER.dropdown }}>
          <div className="bp-notification-heading"><span className="bp-notification-heading-mark"><BellRing size={16} aria-hidden="true" /></span><div><strong>Notifications</strong><small>Device approval activity</small></div>{unread > 0 ? <span className="bp-notification-heading-count">{unread} new</span> : null}</div>
          {noticeError ? <p className="bp-notification-error" role="alert">Could not mark this alert as read. Please try again.</p> : null}
          <div className="bp-notification-list">
            {notices.data?.notifications.length ? notices.data.notifications.slice(0, 5).map((notice) => (
              <button type="button" key={notice.ulid} className="bp-notification-item bp-motion-notice" disabled={openingNotice === notice.ulid} onClick={() => void openNotice(notice)}>
                <span className="bp-notification-card-icon"><Bell size={15} aria-hidden="true" /></span><span className="bp-notification-card-content"><strong>{notice.kind === 'new_network' ? 'New network request' : 'New device request'}</strong><span>{notice.username} · {notice.device_name}</span><small>{notice.read_at ? 'Read' : 'Unread'} · {notice.status}</small></span>{notice.read_at ? <CheckCheck size={15} className="bp-notification-read-icon" aria-hidden="true" /> : <span className="bp-notification-unread-dot" aria-hidden="true" />}
              </button>
            )) : <p className="bp-notification-empty">No notifications yet.</p>}
          </div>
          <button type="button" className="bp-notification-all" onClick={() => { setOpen(false); onOpenDevices() }}>View all in Devices</button>
        </section>
      ) : null}
      {toast ? (
        <div className="bp-notification-toast bp-motion-notice" role="status" style={{ zIndex: UI_LAYER.feedback }}>
          <Bell size={18} />
          <div><strong>New device approval request</strong><span>{toast.username} · {toast.device_name}</span></div>
          <button type="button" onClick={() => void openNotice(toast)}>View</button>
          <button type="button" className="bp-notification-icon" onClick={() => setToast(null)} aria-label="Dismiss alert"><X size={14} /></button>
        </div>
      ) : null}
    </div>
  )
}
