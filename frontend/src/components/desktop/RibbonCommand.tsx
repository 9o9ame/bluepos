import { useEffect, useRef, useState, type ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import { useAuth } from '../../features/auth/AuthProvider'
import { laterPhaseHint, type RibbonCommandDef } from '../../features/workspace/modules'

type RibbonCommandProps = RibbonCommandDef & {
  active?: boolean
  onClick?: () => void
  onNavigate?: (path: string) => void
}

export function RibbonCommand({
  icon: Icon,
  label,
  shortcut,
  status,
  permission,
  entitlement,
  tone = 'blue',
  hasMenu,
  menu,
  active = false,
  onClick,
  onNavigate,
}: RibbonCommandProps) {
  const { session } = useAuth()
  const allowed = !permission || Boolean(session?.permissions.includes(permission))
  const features = session?.entitlements?.features
  const entitled = !entitlement || !features || features.includes(entitlement)
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    function onDocClick(event: MouseEvent) {
      if (rootRef.current && !rootRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', onDocClick)
    return () => document.removeEventListener('mousedown', onDocClick)
  }, [])

  if (!allowed) {
    return null
  }

  const later = status === 'later'
  const disabled = (!hasMenu && later) || !entitled
  const title = later && !hasMenu
    ? laterPhaseHint()
    : !entitled
      ? 'Not included in the current plan'
      : shortcut
        ? `${label} (${shortcut})`
        : label

  return (
    <div className="ribbon-command-wrap" ref={rootRef}>
      <button
        type="button"
        className={`ribbon-command${active ? ' is-active' : ''}${hasMenu ? ' has-menu' : ''}${open ? ' is-menu-open' : ''}`}
        disabled={disabled}
        title={title}
        aria-label={label}
        aria-pressed={active}
        aria-haspopup={hasMenu ? 'menu' : undefined}
        aria-expanded={hasMenu ? open : undefined}
        onClick={() => {
          if (hasMenu) {
            setOpen((current) => !current)
            return
          }
          onClick?.()
        }}
      >
        <span className={`ribbon-icon-tile tone-${tone}`}>
          <Icon size={26} strokeWidth={1.75} aria-hidden />
        </span>
        <span className="ribbon-command-label">
          {label}
          {hasMenu ? <ChevronDown size={11} aria-hidden /> : null}
        </span>
      </button>
      {open && hasMenu ? (
        <div className="ribbon-menu" role="menu">
          {(menu ?? []).map((item) => {
            const itemLater = (item.status ?? 'later') === 'later'
            return (
              <button
                key={item.label}
                type="button"
                role="menuitem"
                disabled={itemLater}
                title={itemLater ? laterPhaseHint() : item.label}
                onClick={() => {
                  setOpen(false)
                  if (item.path) {
                    onNavigate?.(item.path)
                  }
                }}
              >
                {item.label}
              </button>
            )
          })}
        </div>
      ) : null}
    </div>
  )
}

export function RibbonGroup({
  caption,
  children,
}: {
  caption: string
  children: ReactNode
}) {
  return (
    <div className="ribbon-group">
      <div className="ribbon-group-items">{children}</div>
      <div className="ribbon-group-caption">{caption}</div>
    </div>
  )
}
