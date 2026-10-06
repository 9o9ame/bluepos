import {
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
  type ButtonHTMLAttributes,
  type FocusEventHandler,
  type KeyboardEventHandler,
  type MouseEventHandler,
} from 'react'
import { createPortal } from 'react-dom'
import { UI_LAYER } from './uiLayers'
import './UiSelect.css'

export type UiSelectOption = {
  value: string
  label: string
  disabled?: boolean
  title?: string
}

export type UiSelectProps = {
  value?: string
  defaultValue?: string
  options: UiSelectOption[]
  onChange?: (value: string) => void
  disabled?: boolean
  className?: string
  triggerClassName?: string
  id?: string
  name?: string
  title?: string
  placeholder?: string
  onFocus?: FocusEventHandler<HTMLButtonElement>
  onBlur?: FocusEventHandler<HTMLButtonElement>
  onKeyDown?: KeyboardEventHandler<HTMLButtonElement>
  onMouseDown?: MouseEventHandler<HTMLButtonElement>
  triggerProps?: Omit<
    ButtonHTMLAttributes<HTMLButtonElement>,
    'type' | 'disabled' | 'className' | 'onClick' | 'onFocus' | 'onBlur' | 'onKeyDown' | 'onMouseDown'
  > & {
    [key: `data-${string}`]: string | number | boolean | undefined
  }
  menuZIndex?: number
  maxMenuHeight?: number
  'aria-label'?: string
}

type MenuPos = {
  top: number
  left: number
  width: number
  maxHeight: number
  openUp: boolean
}

export function UiSelect({
  value,
  defaultValue,
  options,
  onChange,
  disabled = false,
  className,
  triggerClassName,
  id,
  name,
  title,
  placeholder = '—',
  onFocus,
  onBlur,
  onKeyDown,
  onMouseDown,
  triggerProps,
  menuZIndex = UI_LAYER.dropdown,
  maxMenuHeight = 240,
  'aria-label': ariaLabel,
}: UiSelectProps) {
  const listId = useId()
  const rootRef = useRef<HTMLDivElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const optionRefs = useRef<Array<HTMLButtonElement | null>>([])
  const [open, setOpen] = useState(false)
  const [pos, setPos] = useState<MenuPos | null>(null)
  const [uncontrolledValue, setUncontrolledValue] = useState(
    defaultValue ?? options[0]?.value ?? '',
  )
  const currentValue = value ?? uncontrolledValue
  const selectedIndex = Math.max(
    0,
    options.findIndex((option) => option.value === currentValue),
  )
  const [activeIndex, setActiveIndex] = useState(selectedIndex)

  const selected = options.find((option) => option.value === currentValue) ?? null

  function firstEnabledIndex(from: number, direction: 1 | -1) {
    if (options.length === 0) return -1
    for (let step = 0; step < options.length; step += 1) {
      const index = (from + step * direction + options.length) % options.length
      if (!options[index]?.disabled) return index
    }
    return -1
  }

  function updatePosition() {
    const trigger = rootRef.current
    if (!trigger) return

    const rect = trigger.getBoundingClientRect()
    const gap = 4
    const spaceBelow = window.innerHeight - rect.bottom - 8
    const spaceAbove = rect.top - 8
    const openUp =
      spaceBelow < maxMenuHeight ||
      (spaceAbove > 140 && spaceBelow < spaceAbove + 40)
    const available = openUp ? spaceAbove - gap : spaceBelow - gap
    const maxHeight = Math.max(120, Math.min(maxMenuHeight, available))

    setPos({
      top: openUp ? rect.top - gap : rect.bottom + gap,
      left: Math.max(8, Math.min(rect.left, window.innerWidth - rect.width - 8)),
      width: rect.width,
      maxHeight,
      openUp,
    })
  }

  function openMenu(preferredIndex = selectedIndex) {
    if (disabled || options.length === 0) return
    const enabled = firstEnabledIndex(Math.max(preferredIndex, 0), 1)
    setActiveIndex(enabled >= 0 ? enabled : 0)
    setOpen(true)
  }

  function pick(next: string) {
    if (value === undefined) {
      setUncontrolledValue(next)
    }
    onChange?.(next)
    setOpen(false)
  }

  useLayoutEffect(() => {
    if (!open) return
    updatePosition()
  }, [open])

  useEffect(() => {
    if (!open) return
    optionRefs.current[activeIndex]?.scrollIntoView({ block: 'nearest' })
  }, [activeIndex, open])

  useEffect(() => {
    if (!open) return

    function onPointerDown(event: MouseEvent) {
      const target = event.target as Node
      if (rootRef.current?.contains(target)) return
      if (menuRef.current?.contains(target)) return
      setOpen(false)
    }

    function onEscape(event: KeyboardEvent) {
      if (event.key !== 'Escape') return
      event.preventDefault()
      setOpen(false)
    }

    function onReposition() {
      updatePosition()
    }

    window.addEventListener('mousedown', onPointerDown)
    window.addEventListener('keydown', onEscape)
    window.addEventListener('resize', onReposition)
    window.addEventListener('scroll', onReposition, true)

    return () => {
      window.removeEventListener('mousedown', onPointerDown)
      window.removeEventListener('keydown', onEscape)
      window.removeEventListener('resize', onReposition)
      window.removeEventListener('scroll', onReposition, true)
    }
  }, [open])

  return (
    <div
      ref={rootRef}
      className={[
        'ui-select',
        'bp-fancy-select',
        open ? 'is-open' : '',
        disabled ? 'is-disabled' : '',
        className ?? '',
      ].filter(Boolean).join(' ')}
    >
      {name ? <input type="hidden" name={name} value={currentValue} /> : null}
      <button
        {...triggerProps}
        id={id}
        type="button"
        className={['ui-select-trigger', 'bp-fancy-select-trigger', 'vca-select', triggerClassName ?? ''].filter(Boolean).join(' ')}
        disabled={disabled}
        title={title}
        aria-label={ariaLabel}
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={open ? listId : undefined}
        aria-activedescendant={open && activeIndex >= 0 ? `${listId}-option-${activeIndex}` : undefined}
        onFocus={onFocus}
        onBlur={onBlur}
        onMouseDown={onMouseDown}
        onClick={() => {
          if (open) setOpen(false)
          else openMenu()
        }}
        onKeyDown={(event) => {
          onKeyDown?.(event)
          if (event.defaultPrevented || disabled) return

          if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault()
            if (!open) {
              openMenu()
              return
            }

            const direction: 1 | -1 = event.key === 'ArrowDown' ? 1 : -1
            const next = firstEnabledIndex(
              (activeIndex + direction + options.length) % options.length,
              direction,
            )
            if (next >= 0) setActiveIndex(next)
            return
          }

          if (event.key === 'Home' && open) {
            event.preventDefault()
            const next = firstEnabledIndex(0, 1)
            if (next >= 0) setActiveIndex(next)
            return
          }

          if (event.key === 'End' && open) {
            event.preventDefault()
            for (let index = options.length - 1; index >= 0; index -= 1) {
              if (!options[index]?.disabled) {
                setActiveIndex(index)
                break
              }
            }
            return
          }

          if ((event.key === 'Enter' || event.key === ' ') && open) {
            event.preventDefault()
            const option = options[activeIndex]
            if (option && !option.disabled) pick(option.value)
            return
          }

          if ((event.key === 'Enter' || event.key === ' ') && !open) {
            event.preventDefault()
            openMenu()
          }
        }}
      >
        <span className="ui-select-value bp-fancy-select-value">
          {selected?.label ?? placeholder}
        </span>
        <span className="ui-select-arrow bp-fancy-select-arrow vca-select-caret" aria-hidden>
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
              className={[
                'ui-select-menu',
                'bp-fancy-select-menu',
                pos.openUp ? 'is-up' : 'is-down',
              ].join(' ')}
              role="listbox"
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
              <div className="ui-select-menu-inner bp-fancy-select-menu-inner">
                {options.map((option, index) => {
                  const isSelected = option.value === currentValue
                  const isActive = index === activeIndex
                  return (
                    <button
                      ref={(node) => {
                        optionRefs.current[index] = node
                      }}
                      id={`${listId}-option-${index}`}
                      key={option.value || `empty-${index}`}
                      type="button"
                      role="option"
                      aria-selected={isSelected}
                      disabled={option.disabled}
                      title={option.title}
                      className={[
                        'ui-select-option',
                        'bp-fancy-select-option',
                        isSelected ? 'is-selected' : '',
                        isActive ? 'is-active' : '',
                        option.disabled ? 'is-disabled' : '',
                      ].filter(Boolean).join(' ')}
                      onMouseEnter={() => setActiveIndex(index)}
                      onClick={() => {
                        if (option.disabled) return
                        pick(option.value)
                      }}
                    >
                      <span>{option.label}</span>
                      {isSelected ? (
                        <span className="ui-select-check bp-fancy-select-check" aria-hidden>✓</span>
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
