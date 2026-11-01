import { useEffect, useRef, useState, type ReactNode } from 'react'
import { ChevronDown } from 'lucide-react'
import { useAppearance } from '../../features/appearance/AppearanceProvider'
import { useAuth } from '../../features/auth/AuthProvider'
import { laterPhaseHint, type RibbonCommandDef } from '../../features/workspace/modules'
import { resolveRibbonCommandIcon } from '../../features/workspace/blueposIconMap'
import { voucherAppearance } from '../../features/workspace/voucherAppearance'

type RibbonCommandProps = RibbonCommandDef & {
  active?: boolean
  onClick?: () => void
  onNavigate?: (path: string) => void
}

export function RibbonCommand({
  id,
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
  const { skin } = useAppearance()
  const allowed = !permission || Boolean(session?.permissions.includes(permission))
  const features = session?.entitlements?.features
  const entitled = !entitlement || !features || features.includes(entitlement)
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const customIconSrc = resolveRibbonCommandIcon(skin, id)

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
    <div className="ribbon-command-wrap" ref={rootRef}
      onPointerEnter={event => { if (event.pointerType !== 'touch' && hasMenu && !disabled) setOpen(true) }}
      onPointerLeave={event => { if (event.pointerType !== 'touch') setOpen(false) }}
      onBlur={event => { if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false) }}
      onKeyDown={event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); setOpen(false); rootRef.current?.querySelector<HTMLButtonElement>('.ribbon-command')?.focus() }
        if (hasMenu && !disabled && event.key === 'ArrowDown') { event.preventDefault(); setOpen(true); requestAnimationFrame(() => rootRef.current?.querySelector<HTMLButtonElement>('[role="menuitem"]:not(:disabled)')?.focus()) }
      }}>
      <button
        type="button"
        className={`ribbon-command ribbon-command-${id}${active ? ' is-active' : ''}${hasMenu ? ' has-menu' : ''}${open ? ' is-menu-open' : ''}`}
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
        <span className={`ribbon-icon-tile${customIconSrc ? ' has-custom-icon' : ` tone-${tone}`}`}>
          {customIconSrc ? (
            <img src={customIconSrc} alt="" draggable={false} aria-hidden />
          ) : (
            <Icon strokeWidth={1.9} aria-hidden />
          )}
        </span>
        <span className="ribbon-command-label">
          {label}
          {hasMenu ? <ChevronDown size={11} aria-hidden /> : null}
        </span>
      </button>
      {hasMenu ? (
        <div className={`ribbon-menu${menu?.some(item => item.icon) ? ' has-icons' : ''}`} role="menu" data-open={open} aria-hidden={!open} ref={el => { if (el) el.inert = !open }}>
          {(menu ?? []).map((item) => {
            const itemLater = (item.status ?? 'later') === 'later'
            const voucher = voucherAppearance(item.path)
            const MenuIcon = item.icon
            return (
              <button
                key={item.label}
                type="button"
                role="menuitem"
                data-voucher-type={voucher?.type}
                disabled={itemLater}
                title={itemLater ? laterPhaseHint() : item.label}
                onClick={() => {
                  setOpen(false)
                  if (item.path) {
                    onNavigate?.(item.path)
                  }
                }}
              >
                {MenuIcon ? <span className="ribbon-menu-icon" data-tone={item.iconTone ?? 'primary'} aria-hidden="true"><MenuIcon size={18} strokeWidth={1.9}/></span> : voucher ? <span className="ribbon-voucher-mark" aria-hidden="true">{voucher.badge}</span> : null}
                <span>{item.label}</span>
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
