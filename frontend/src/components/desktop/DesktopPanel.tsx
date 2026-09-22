import type { ReactNode } from 'react'

type DesktopPanelProps = {
  title?: string
  toolbar?: ReactNode
  footer?: ReactNode
  flush?: boolean
  className?: string
  children: ReactNode
}

export function DesktopPanel({ title, toolbar, footer, flush = false, className, children }: DesktopPanelProps) {
  return (
    <section className={`desktop-panel${className ? ` ${className}` : ''}`}>
      {title ? <header className="desktop-panel-header">{title}</header> : null}
      {toolbar ? <div className="desktop-toolbar">{toolbar}</div> : null}
      <div className={`desktop-panel-body${flush ? ' is-flush' : ''}`}>{children}</div>
      {footer ? <footer className="desktop-panel-footer">{footer}</footer> : null}
    </section>
  )
}

export function DesktopToolbar({ children }: { children: ReactNode }) {
  return <div className="desktop-toolbar">{children}</div>
}

type DesktopButtonProps = {
  type?: 'button' | 'submit'
  icon?: ReactNode
  label: string
  shortcut?: string
  disabled?: boolean
  variant?: 'default' | 'primary' | 'danger'
  onClick?: () => void
}

export function DesktopButton({
  type = 'button',
  icon,
  label,
  shortcut,
  disabled,
  variant = 'default',
  onClick,
}: DesktopButtonProps) {
  const extra = variant === 'primary' ? ' is-primary' : variant === 'danger' ? ' is-danger' : ''
  return (
    <button
      type={type}
      className={`desktop-btn${extra}`}
      disabled={disabled}
      title={shortcut ? `${label} (${shortcut})` : label}
      onClick={onClick}
    >
      {icon}
      <span>{label}</span>
      {shortcut ? <span className="shortcut">{shortcut}</span> : null}
    </button>
  )
}

export function FormGroup({
  title,
  columns,
  children,
}: {
  title: string
  columns?: 1 | 2
  children: ReactNode
}) {
  return (
    <div className="form-group" role="group" aria-label={title}>
      <div className="form-group-title">{title}</div>
      <div className="form-group-body" style={columns === 1 ? { gridTemplateColumns: '1fr' } : undefined}>
        {children}
      </div>
    </div>
  )
}

export function Field({
  label,
  htmlFor,
  span2,
  children,
}: {
  label: string
  htmlFor?: string
  span2?: boolean
  children: ReactNode
}) {
  return (
    <label className={`desktop-field${span2 ? ' is-span-2' : ''}`} htmlFor={htmlFor}>
      <span>{label}</span>
      {children}
    </label>
  )
}

export function ShortcutHint({ keys }: { keys: string }) {
  return <span className="shortcut">{keys}</span>
}
