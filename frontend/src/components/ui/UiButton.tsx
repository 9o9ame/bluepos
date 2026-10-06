import type { ButtonHTMLAttributes, ReactNode } from 'react'
import './UiButton.css'

export type UiButtonVariant = 'default' | 'primary' | 'success' | 'warning' | 'danger' | 'info'

export type UiButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: UiButtonVariant
  icon?: ReactNode
  label?: ReactNode
}

export function UiButton({
  variant = 'default',
  icon,
  label,
  className,
  children,
  type = 'button',
  ...props
}: UiButtonProps) {
  return (
    <button
      {...props}
      type={type}
      className={['ui-button', `is-${variant}`, className].filter(Boolean).join(' ')}
    >
      {icon ? <span className="ui-button-icon" aria-hidden>{icon}</span> : null}
      {label ?? children}
    </button>
  )
}
