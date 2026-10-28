import { useEffect, useRef, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, Volume2, X } from 'lucide-react'
import type { AuthSession } from '../../types/auth'
import { fetchDeviceAccessNotifications, type DeviceAccessNotification } from '../../api/devices'
import { UiSelect } from '../ui/UiSelect'
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
  void audio.play().catch(() => undefined)
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
  const [toast, setToast] = useState<DeviceAccessNotification | null>(null)
  const [ring, setRing] = useState(false)
  const known = useRef<Set<string> | null>(null)
  const lastScope = useRef(scope)
  const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const ringTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const notices = useQuery({
    queryKey: ['device-access-inbox', 1],
    queryFn: () => fetchDeviceAccessNotifications(1),
    enabled: isOwner,
    refetchInterval: 4000, // Resilient fallback; not equivalent to WebSocket push.
    refetchIntervalInBackground: false,
  })

  useEffect(() => {
    if (lastScope.current === scope) return
    lastScope.current = scope
    known.current = null
    setTone(localStorage.getItem(preferenceKey) || '07')
    setOpen(false)
    setToast(null)
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
    known.current = current
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

  if (!isOwner) return null

  const unread = notices.data?.unread_count ?? 0
  return (
    <div className="bp-notification-root">
      <button
        type="button"
        className={'bp-notification-trigger' + (ring ? ' bp-notification-ring' : '')}
        title="Notifications"
        aria-label={unread ? unread + ' unread notifications' : 'Notifications'}
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
      >
        <Bell size={17} aria-hidden="true" />
        {unread > 0 ? <span className="bp-notification-count">{unread > 99 ? '99+' : unread}</span> : null}
      </button>
      {open ? (
        <section className="bp-notification-popover" aria-label="Notifications">
          <div className="bp-notification-heading">
            <strong>Device approval alerts</strong>
            <button type="button" className="bp-notification-icon" onClick={() => setOpen(false)} aria-label="Close notifications"><X size={14} /></button>
          </div>
          <div className="bp-notification-list">
            {notices.data?.notifications.length ? notices.data.notifications.slice(0, 5).map((notice) => (
              <button type="button" key={notice.ulid} className="bp-notification-item" onClick={() => { setOpen(false); onOpenDevices() }}>
                <span>{notice.username} — {notice.device_name}</span>
                <small>{notice.read_at ? 'Read' : 'Unread'} · {notice.status}</small>
              </button>
            )) : <p className="bp-notification-empty">No notifications yet.</p>}
          </div>
          <div className="bp-notification-settings">
            <span>Alert sound</span>
            <UiSelect
              value={tone}
              aria-label="Notification sound"
              options={[{ value: 'off', label: 'Off' }, ...TONES.map(({ value, label }) => ({ value, label }))]}
              onChange={(value) => { setTone(value); localStorage.setItem(preferenceKey, value) }}
              searchable={false}
              menuPlacement="up"
            />
            <button type="button" className="bp-notification-icon" title="Preview sound" aria-label="Preview notification sound" onClick={() => playNotificationSound(tone)}><Volume2 size={16} /></button>
          </div>
          <button type="button" className="bp-notification-all" onClick={() => { setOpen(false); onOpenDevices() }}>Open Devices</button>
        </section>
      ) : null}
      {toast ? (
        <div className="bp-notification-toast" role="status">
          <Bell size={18} />
          <div><strong>New device approval request</strong><span>{toast.username} · {toast.device_name}</span></div>
          <button type="button" onClick={() => { setToast(null); onOpenDevices() }}>View</button>
          <button type="button" className="bp-notification-icon" onClick={() => setToast(null)} aria-label="Dismiss alert"><X size={14} /></button>
        </div>
      ) : null}
    </div>
  )
}
