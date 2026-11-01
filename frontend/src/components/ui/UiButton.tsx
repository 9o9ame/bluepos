import { useEffect, useRef, useState, type ButtonHTMLAttributes, type ReactNode } from 'react'
import './UiButton.css'

export type UiButtonVariant = 'default' | 'primary' | 'success' | 'warning' | 'danger' | 'info'

export type UiButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: UiButtonVariant
  icon?: ReactNode
  label?: ReactNode
  feedback?: 'copy' | 'download'
  onAction?: () => void | boolean | Promise<void | boolean>
}

export function UiButton({
  variant = 'default',
  icon,
  label,
  className,
  children,
  type = 'button',
  feedback,
  onAction,
  ...props
}: UiButtonProps) {
  const [state, setState] = useState<'idle' | 'busy' | 'done' | 'error'>('idle')
  const active = useRef(false)
  const mounted = useRef(true)
  const resetTimer = useRef<ReturnType<typeof setTimeout>>()
  useEffect(() => {
    mounted.current = true
    return () => { mounted.current = false; clearTimeout(resetTimer.current) }
  }, [])
  async function runAction() {
    if (!onAction || active.current) return
    active.current = true
    clearTimeout(resetTimer.current)
    setState('busy')
    try {
      const result = await onAction()
      if (mounted.current) setState(result === false ? 'error' : 'done')
    } catch {
      if (mounted.current) setState('error')
    } finally {
      active.current = false
      if (mounted.current) resetTimer.current = setTimeout(() => setState('idle'), 1800)
    }
  }
  const message = state === 'done' ? (feedback === 'copy' ? 'Copied!' : 'Ready!')
    : state === 'error' ? 'Failed' : feedback === 'copy' ? 'Copying…' : 'Exporting…'
  return (
    <button
      {...props}
      type={type}
      className={['ui-button', `is-${variant}`, 'bp-motion-action', feedback && `bp-motion-${feedback}`, className].filter(Boolean).join(' ')}
      data-feedback={feedback ? state : undefined}
      {...(feedback ? { 'data-copied': feedback === 'copy' && state === 'done', 'data-busy': state === 'busy' } : {})}
      aria-busy={feedback ? state === 'busy' : props['aria-busy']}
      disabled={props.disabled || (feedback ? state === 'busy' : false)}
      onClick={onAction ? () => { void runAction() } : props.onClick}
    >
      {icon ? <span className="ui-button-icon" aria-hidden>
        {feedback ? <span className="ui-button-icon-slot" style={{ visibility: state === 'done' || state === 'busy' ? 'hidden' : 'visible' }}>{icon}</span> : icon}
        {feedback && (state === 'done' || state === 'busy') ? <svg className="ui-button-feedback-ring" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
          <circle className="ui-button-ring-track" cx="12" cy="12" r="10"/>
          <circle className="ui-button-ring-progress" cx="12" cy="12" r="10" pathLength="1"/>
          {state === 'done' ? <path className="ui-button-ring-check" d="m7 12 3 3 7-7" pathLength="1"/> : null}
        </svg> : null}
      </span> : null}
      {feedback ? <span className="ui-button-feedback-label">
        <span style={{ visibility: state === 'idle' ? 'visible' : 'hidden' }}>{label ?? children}</span>
        <span className="ui-button-feedback-message" role="status">{state !== 'idle' ? message : ''}</span>
      </span> : label ?? children}
    </button>
  )
}
