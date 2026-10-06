import {
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
  type FocusEventHandler,
} from 'react'
import { createPortal } from 'react-dom'
import './BpFancySelect.css'

export type BpFancySelectOption = {
  value: string
  label: string
  disabled?: boolean
  title?: string
}

type BpFancySelectProps = {
  value: string
  options: BpFancySelectOption[]
  onChange: (value: string) => void
  disabled?: boolean
  className?: string
  title?: string
  onFocus?: FocusEventHandler<HTMLButtonElement>
  'aria-label'?: string
}

type MenuPos = {
  top: number
  left: number
  width: number
  maxHeight: number
  openUp: boolean
}

export function BpFancySelect({
  value,
  options,
  onChange,
  disabled = false,
  className,
  title,
  onFocus,
  'aria-label': ariaLabel,
}: BpFancySelectProps) {
  const listId = useId()
  const rootRef = useRef<HTMLDivElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState<MenuPos | null>(null)

  const selected =
    options.find((option) => option.value === value) ?? options[0] ?? null

  function updatePosition() {
    const trigger = rootRef.current
    if (!trigger) return

    const rect = trigger.getBoundingClientRect()
    const gap = 4
    const preferredMax = 240
    const spaceBelow = window.innerHeight - rect.bottom - 8
    const spaceAbove = rect.top - 8
    // Keep long menus (Barcode Types) off the preview: open upward when below is crowded.
    const openUp =
      spaceBelow < preferredMax ||
      (spaceAbove > 140 && spaceBelow < spaceAbove + 40)
    const maxHeight = Math.max(
      120,
      Math.min(preferredMax, openUp ? spaceAbove - gap : spaceBelow - gap),
    )

    setPos({
      top: openUp ? rect.top - gap : rect.bottom + gap,
      left: Math.max(8, Math.min(rect.left, window.innerWidth - rect.width - 8)),
      width: rect.width,
      maxHeight,
      openUp,
    })
  }

  useLayoutEffect(() => {
    if (!open) return
    updatePosition()
  }, [open])

  useEffect(() => {
    if (!open) return

    function onPointerDown(event: MouseEvent) {
      const target = event.target as Node
      if (rootRef.current?.contains(target)) return
      if (menuRef.current?.contains(target)) return
      setOpen(false)
    }

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        event.preventDefault()
        setOpen(false)
      }
    }

    function onReposition() {
      updatePosition()
    }

    window.addEventListener('mousedown', onPointerDown)
    window.addEventListener('keydown', onKeyDown)
    window.addEventListener('resize', onReposition)
    window.addEventListener('scroll', onReposition, true)

    return () => {
      window.removeEventListener('mousedown', onPointerDown)
      window.removeEventListener('keydown', onKeyDown)
      window.removeEventListener('resize', onReposition)
      window.removeEventListener('scroll', onReposition, true)
    }
  }, [open])

  function pick(next: string) {
    onChange(next)
    setOpen(false)
  }

  return (
    <div
      ref={rootRef}
      className={`bp-fancy-select${open ? ' is-open' : ''}${disabled ? ' is-disabled' : ''}${className ? ` ${className}` : ''}`}
    >
      <button
        type="button"
        className="bp-fancy-select-trigger"
        disabled={disabled}
        title={title}
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        onFocus={onFocus}
        onClick={() => {
          if (disabled) return
          setOpen((current) => !current)
        }}
      >
        <span className="bp-fancy-select-value">
          {selected?.label ?? '—'}
        </span>
        <span className="bp-fancy-select-arrow" aria-hidden>
          <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
            <path
              d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z"
              fill="currentColor"
            />
          </svg>
        </span>
      </button>

      {open && pos
        ? createPortal(
            <div
              ref={menuRef}
              id={listId}
              className={`bp-fancy-select-menu${pos.openUp ? ' is-up' : ' is-down'}`}
              role="listbox"
              style={{
                position: 'fixed',
                top: pos.openUp ? undefined : pos.top,
                bottom: pos.openUp
                  ? window.innerHeight - pos.top
                  : undefined,
                left: pos.left,
                width: pos.width,
                maxHeight: pos.maxHeight,
                zIndex: 1600,
              }}
            >
              <div className="bp-fancy-select-menu-inner">
                {options.map((option) => {
                  const isSelected = option.value === value
                  return (
                    <button
                      key={option.value}
                      type="button"
                      role="option"
                      aria-selected={isSelected}
                      disabled={option.disabled}
                      title={option.title}
                      className={`bp-fancy-select-option${isSelected ? ' is-selected' : ''}${option.disabled ? ' is-disabled' : ''}`}
                      onClick={() => {
                        if (option.disabled) return
                        pick(option.value)
                      }}
                    >
                      <span>{option.label}</span>
                      {isSelected ? (
                        <span className="bp-fancy-select-check" aria-hidden>
                          ✓
                        </span>
                      ) : null}
                    </button>
                  )
                })}
              </div>
            </div>,
            document.body,
          )
        : null}
    </div>
  )
}
