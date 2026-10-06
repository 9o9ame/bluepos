import { useEffect, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'
import { UI_LAYER } from './uiLayers'
import './UiModal.css'

export type UiModalSize = 'sm' | 'md' | 'lg' | 'xl'

export function UiModal({
  open = true,
  title,
  ariaLabel,
  onClose,
  children,
  footer,
  size = 'md',
  zIndex = UI_LAYER.modal,
  className,
  bodyClassName,
  closeOnEscape = true,
}: {
  open?: boolean
  title?: ReactNode
  ariaLabel?: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
  size?: UiModalSize
  zIndex?: number
  className?: string
  bodyClassName?: string
  closeOnEscape?: boolean
}) {
  useEffect(() => {
    if (!open || !closeOnEscape) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        onClose()
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [closeOnEscape, onClose, open])

  if (!open || typeof document === 'undefined') return null

  return createPortal(
    <div className="ui-modal-layer" style={{ zIndex }}>
      <section
        className={['ui-modal', `is-${size}`, className].filter(Boolean).join(' ')}
        role="dialog"
        aria-modal="true"
        aria-label={ariaLabel || (typeof title === 'string' ? title : undefined)}
      >
        {title ? (
          <header className="ui-modal-header">
            <div className="ui-modal-title">{title}</div>
            <button type="button" className="ui-modal-close" aria-label="Close" onClick={onClose}>
              <X size={15} />
            </button>
          </header>
        ) : null}
        <div className={['ui-modal-body', bodyClassName].filter(Boolean).join(' ')}>{children}</div>
        {footer ? <footer className="ui-modal-footer">{footer}</footer> : null}
      </section>
    </div>,
    document.body,
  )
}
