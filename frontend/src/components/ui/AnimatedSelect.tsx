import {
  useEffect,
  useRef,
  useState,
  type SelectHTMLAttributes,
} from 'react'
import { ChevronDown } from 'lucide-react'
import './AnimatedSelect.css'

type Props = SelectHTMLAttributes<HTMLSelectElement> & {
  shellClassName?: string
}

export function AnimatedSelect({
  className,
  shellClassName,
  disabled,
  onMouseDown,
  onKeyDown,
  onBlur,
  onChange,
  ...props
}: Props) {
  const shellRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)

  useEffect(() => {
    if (!open) return

    const closeOutside = (event: Event) => {
      const target = event.target
      if (target instanceof Node && shellRef.current?.contains(target)) return
      setOpen(false)
    }
    const close = () => setOpen(false)
    const timer = window.setTimeout(() => {
      window.addEventListener('pointerdown', closeOutside, true)
      window.addEventListener('keydown', closeOutside, true)
      window.addEventListener('scroll', close, true)
      window.addEventListener('blur', close)
    }, 0)

    return () => {
      window.clearTimeout(timer)
      window.removeEventListener('pointerdown', closeOutside, true)
      window.removeEventListener('keydown', closeOutside, true)
      window.removeEventListener('scroll', close, true)
      window.removeEventListener('blur', close)
    }
  }, [open])

  return (
    <div
      ref={shellRef}
      className={`vca-select-shell${open ? ' is-open' : ''}${disabled ? ' is-disabled' : ''}${shellClassName ? ` ${shellClassName}` : ''}`}
    >
      <select
        {...props}
        disabled={disabled}
        className={['vca-select', className].filter(Boolean).join(' ')}
        onMouseDown={(event) => {
          if (!disabled) setOpen((current) => !current)
          onMouseDown?.(event)
        }}
        onKeyDown={(event) => {
          if (disabled) {
            onKeyDown?.(event)
            return
          }
          if (event.key === 'Escape') {
            setOpen(false)
          } else if (
            event.key === 'Enter' ||
            event.key === ' ' ||
            event.key === 'ArrowDown' ||
            event.key === 'ArrowUp'
          ) {
            setOpen(true)
          }
          onKeyDown?.(event)
        }}
        onBlur={(event) => {
          setOpen(false)
          onBlur?.(event)
        }}
        onChange={(event) => {
          setOpen(false)
          onChange?.(event)
        }}
      />
      <span className="vca-select-caret" aria-hidden="true">
        <ChevronDown size={12} strokeWidth={2.75} />
      </span>
    </div>
  )
}
