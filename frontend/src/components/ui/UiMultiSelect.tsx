import {
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
} from 'react'
import { createPortal } from 'react-dom'
import type { UiSelectOption } from './UiSelect'
import { UI_LAYER } from './uiLayers'
import './UiMultiSelect.css'

export function UiMultiSelect({
  value,
  options,
  onChange,
  disabled = false,
  className,
  placeholder = 'Select…',
  menuZIndex = UI_LAYER.dropdown,
  maxMenuHeight = 260,
  'aria-label': ariaLabel,
}: {
  value: string[]
  options: UiSelectOption[]
  onChange: (value: string[]) => void
  disabled?: boolean
  className?: string
  placeholder?: string
  menuZIndex?: number
  maxMenuHeight?: number
  'aria-label'?: string
}) {
  const listId = useId()
  const rootRef = useRef<HTMLDivElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState<{
    top: number
    left: number
    width: number
    maxHeight: number
    openUp: boolean
  } | null>(null)

  const selectedLabels = options
    .filter((option) => value.includes(option.value))
    .map((option) => option.label)

  function updatePosition() {
    const trigger = rootRef.current
    if (!trigger) return
    const rect = trigger.getBoundingClientRect()
    const gap = 4
    const below = window.innerHeight - rect.bottom - 8
    const above = rect.top - 8
    const preferred = Math.max(140, maxMenuHeight)
    const openUp = below < preferred || (above > 160 && below < above + 40)
    const available = openUp ? above - gap : below - gap
    setPos({
      top: openUp ? rect.top - gap : rect.bottom + gap,
      left: Math.max(8, Math.min(rect.left, window.innerWidth - rect.width - 8)),
      width: rect.width,
      maxHeight: Math.max(140, Math.min(preferred, available)),
      openUp,
    })
  }

  useLayoutEffect(() => {
    if (open) updatePosition()
  }, [open, maxMenuHeight])

  useEffect(() => {
    if (!open) return
    const outside = (event: MouseEvent) => {
      const target = event.target as Node
      if (rootRef.current?.contains(target) || menuRef.current?.contains(target)) return
      setOpen(false)
    }
    const escape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }
    const reposition = () => updatePosition()
    window.addEventListener('mousedown', outside)
    window.addEventListener('keydown', escape)
    window.addEventListener('resize', reposition)
    window.addEventListener('scroll', reposition, true)
    return () => {
      window.removeEventListener('mousedown', outside)
      window.removeEventListener('keydown', escape)
      window.removeEventListener('resize', reposition)
      window.removeEventListener('scroll', reposition, true)
    }
  }, [open, maxMenuHeight])

  function toggle(next: string) {
    onChange(value.includes(next) ? value.filter((item) => item !== next) : [...value, next])
  }

  return (
    <div ref={rootRef} className={['ui-multi-select', open ? 'is-open' : '', disabled ? 'is-disabled' : '', className ?? ''].filter(Boolean).join(' ')}>
      <button
        type="button"
        className="ui-select-trigger ui-multi-select-trigger"
        disabled={disabled}
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        onClick={() => !disabled && setOpen((current) => !current)}
      >
        <span className="ui-select-value">
          {selectedLabels.length > 0 ? selectedLabels.join(', ') : placeholder}
        </span>
        <span className="ui-select-arrow" aria-hidden>
          <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
            <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
          </svg>
        </span>
      </button>

      {open && pos
        ? createPortal(
            <div
              ref={menuRef}
              id={listId}
              className={['ui-select-menu', 'ui-multi-select-menu', pos.openUp ? 'is-up' : 'is-down'].join(' ')}
              role="listbox"
              aria-multiselectable="true"
              aria-label={ariaLabel}
              style={{
                position: 'fixed',
                top: pos.openUp ? undefined : pos.top,
                bottom: pos.openUp ? window.innerHeight - pos.top : undefined,
                left: pos.left,
                width: pos.width,
                maxHeight: pos.maxHeight,
                zIndex: menuZIndex,
              }}
            >
              <div className="ui-select-menu-inner">
                {options.map((option) => {
                  const selected = value.includes(option.value)
                  return (
                    <label
                      key={option.value}
                      className={['ui-multi-select-option', selected ? 'is-selected' : '', option.disabled ? 'is-disabled' : ''].filter(Boolean).join(' ')}
                    >
                      <input
                        type="checkbox"
                        checked={selected}
                        disabled={option.disabled}
                        onChange={() => !option.disabled && toggle(option.value)}
                      />
                      <span>{option.label}</span>
                    </label>
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
