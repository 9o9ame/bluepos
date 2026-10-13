import {
  useEffect,
  useLayoutEffect,
  useRef,
  useState,
  type ChangeEvent,
} from 'react'
import { createPortal } from 'react-dom'
import { UI_LAYER } from './uiLayers'
import './UiEditableCombo.css'

export type UiEditableComboOption = {
  value: string
  label: string
  disabled?: boolean
  title?: string
}

type UiEditableComboProps = {
  value: string
  onChange: (value: string) => void
  options: UiEditableComboOption[]
  placeholder?: string
  disabled?: boolean
  ariaLabel?: string
  className?: string
}

type MenuPosition = {
  top: number
  left: number
  width: number
}

export function UiEditableCombo({
  value,
  onChange,
  options,
  placeholder,
  disabled = false,
  ariaLabel,
  className,
}: UiEditableComboProps) {
  const rootRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)
  const [position, setPosition] = useState<MenuPosition | null>(null)

  function updatePosition() {
    const root = rootRef.current
    if (!root) return

    const rect = root.getBoundingClientRect()
    setPosition({
      top: rect.bottom + 4,
      left: rect.left,
      width: rect.width,
    })
  }

  function toggleMenu() {
    if (disabled || options.length === 0) return

    if (!open) {
      updatePosition()
      requestAnimationFrame(() => {
        inputRef.current?.focus()
        inputRef.current?.select()
      })
    }

    setOpen((current) => !current)
  }

  function pick(option: UiEditableComboOption) {
    if (option.disabled) return

    onChange(option.value)
    setOpen(false)

    requestAnimationFrame(() => {
      inputRef.current?.focus()
      inputRef.current?.select()
    })
  }

  function handleChange(event: ChangeEvent<HTMLInputElement>) {
    onChange(event.target.value)
  }

  useLayoutEffect(() => {
    if (open) updatePosition()
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
      if (event.key === 'Escape') setOpen(false)
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

  return (
    <div
      ref={rootRef}
      className={[
        'ui-editable-combo',
        open ? 'is-open' : '',
        disabled ? 'is-disabled' : '',
        className ?? '',
      ].filter(Boolean).join(' ')}
    >
      <input
        ref={inputRef}
        className="ui-editable-combo-input"
        value={value}
        onChange={handleChange}
        placeholder={placeholder}
        disabled={disabled}
        aria-label={ariaLabel}
        autoComplete="off"
      />
      <button
        type="button"
        className="ui-editable-combo-toggle"
        disabled={disabled || options.length === 0}
        aria-label={`${ariaLabel ?? 'Combo field'} options`}
        aria-haspopup="listbox"
        aria-expanded={open}
        onClick={toggleMenu}
      >
        <span className="ui-editable-combo-arrow" aria-hidden>
          <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
            <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
          </svg>
        </span>
      </button>

      {open && position
        ? createPortal(
            <div
              ref={menuRef}
              className="ui-editable-combo-menu"
              role="listbox"
              aria-label={`${ariaLabel ?? 'Combo field'} options`}
              style={{
                position: 'fixed',
                top: position.top,
                left: position.left,
                width: position.width,
                zIndex: UI_LAYER.dropdown,
              }}
            >
              {options.map((option) => (
                <button
                  key={option.value}
                  type="button"
                  className="ui-editable-combo-option"
                  role="option"
                  aria-selected={false}
                  disabled={option.disabled}
                  title={option.title}
                  onClick={() => pick(option)}
                >
                  {option.label}
                </button>
              ))}
            </div>,
            document.body,
          )
        : null}
    </div>
  )
}
