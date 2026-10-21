import {
  useEffect,
  useId,
  useLayoutEffect,
  useMemo,
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
  columns?: string[]
  disabled?: boolean
  title?: string
}

export type UiSelectMenuColumn = {
  header: string
  width?: string
  tone?: 'identity' | 'stock' | 'quantity' | 'price' | 'cost' | 'amount'
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
  searchable?: boolean
  searchPlaceholder?: string
  searchInTrigger?: boolean
  onSearchChange?: (query: string) => void
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
  menuColumns?: UiSelectMenuColumn[]
  menuMinWidth?: number
  menuResultLimit?: number
  menuPlacement?: 'auto' | 'down' | 'up'
  menuMotion?: 'default' | 'reveal'
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
  searchable = true,
  searchPlaceholder = 'Search…',
  searchInTrigger = false,
  onSearchChange,
  onFocus,
  onBlur,
  onKeyDown,
  onMouseDown,
  triggerProps,
  menuZIndex = UI_LAYER.dropdown,
  maxMenuHeight = 240,
  menuColumns,
  menuMinWidth,
  menuResultLimit,
  menuPlacement = 'auto',
  menuMotion = 'default',
  'aria-label': ariaLabel,
}: UiSelectProps) {
  const listId = useId()
  const rootRef = useRef<HTMLDivElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const searchRef = useRef<HTMLInputElement>(null)
  const triggerSearchRef = useRef<HTMLInputElement>(null)
  const optionRefs = useRef<Array<HTMLButtonElement | null>>([])

  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [pos, setPos] = useState<MenuPos | null>(null)
  const [uncontrolledValue, setUncontrolledValue] = useState(
    defaultValue ?? options[0]?.value ?? '',
  )
  const currentValue = value ?? uncontrolledValue

  const filteredOptions = useMemo(() => {
    const query = search.trim().toLocaleLowerCase()
    const matches =
      !searchable || !query
        ? options
        : options.filter((option) => {
            const label = option.label.toLocaleLowerCase()
            const valueText = option.value.toLocaleLowerCase()
            const columnsText = option.columns?.join(' ').toLocaleLowerCase() ?? ''
            return label.includes(query) || valueText.includes(query) || columnsText.includes(query)
          })

    if (!menuResultLimit || matches.length <= menuResultLimit) {
      return matches
    }

    const limited = matches.slice(0, menuResultLimit)
    const selectedMatch = matches.find((option) => option.value === currentValue)

    if (
      selectedMatch &&
      !limited.some((option) => option.value === selectedMatch.value)
    ) {
      limited[limited.length - 1] = selectedMatch
    }

    return limited
  }, [currentValue, menuResultLimit, options, search, searchable])

  const selected =
    options.find((option) => option.value === currentValue) ?? null
  const [triggerSearchText, setTriggerSearchText] = useState(selected?.label ?? '')

  const selectedFilteredIndex = Math.max(
    0,
    filteredOptions.findIndex((option) => option.value === currentValue),
  )

  const [activeIndex, setActiveIndex] = useState(selectedFilteredIndex)

  function firstEnabledIndex(
    source: UiSelectOption[],
    from: number,
    direction: 1 | -1,
  ) {
    if (source.length === 0) return -1

    for (let step = 0; step < source.length; step += 1) {
      const index =
        (from + step * direction + source.length) % source.length

      if (!source[index]?.disabled) return index
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
    const autoOpenUp =
      spaceBelow < maxMenuHeight ||
      (spaceAbove > 140 && spaceBelow < spaceAbove + 40)
    const openUp =
      menuPlacement === 'up'
        ? true
        : menuPlacement === 'down'
          ? false
          : autoOpenUp
    const available = openUp ? spaceAbove - gap : spaceBelow - gap
    const resolvedMaxHeight = Math.max(
      120,
      Math.min(maxMenuHeight, available),
    )

    const menuWidth = Math.max(rect.width, menuMinWidth ?? 0)

    setPos({
      top: openUp ? rect.top - gap : rect.bottom + gap,
      left: Math.max(
        8,
        Math.min(rect.left, window.innerWidth - menuWidth - 8),
      ),
      width: rect.width,
      maxHeight: resolvedMaxHeight,
      openUp,
    })
  }

  function openMenu() {
    if (disabled || options.length === 0) return

    setSearch('')
    setTriggerSearchText(selected?.label ?? '')
    const selectedIndex = Math.max(
      0,
      options.findIndex((option) => option.value === currentValue),
    )
    const enabled = firstEnabledIndex(options, selectedIndex, 1)
    setActiveIndex(enabled >= 0 ? enabled : 0)
    setOpen(true)
  }

  function closeMenu() {
    setOpen(false)
    setSearch('')
    setTriggerSearchText(selected?.label ?? '')
  }

  function pick(next: string) {
    if (value === undefined) {
      setUncontrolledValue(next)
    }

    onChange?.(next)
    closeMenu()
  }

  function moveActive(direction: 1 | -1) {
    if (filteredOptions.length === 0) return

    const start =
      activeIndex >= 0
        ? (activeIndex + direction + filteredOptions.length) %
          filteredOptions.length
        : direction === 1
          ? 0
          : filteredOptions.length - 1

    const next = firstEnabledIndex(filteredOptions, start, direction)
    if (next >= 0) setActiveIndex(next)
  }

  useLayoutEffect(() => {
    if (!open) return
    updatePosition()
  }, [open])

  useEffect(() => {
    if (!open) return

    const selectedIndex = filteredOptions.findIndex(
      (option) => option.value === currentValue,
    )
    const next = firstEnabledIndex(
      filteredOptions,
      selectedIndex >= 0 ? selectedIndex : 0,
      1,
    )
    setActiveIndex(next)
  }, [search, open, currentValue, filteredOptions])

  useEffect(() => {
    if (!open) return

    if (searchable) {
      requestAnimationFrame(() => {
        if (searchInTrigger) {
          triggerSearchRef.current?.focus()
          triggerSearchRef.current?.select()
        } else {
          searchRef.current?.focus()
        }
      })
    }
  }, [open, searchable, searchInTrigger])

  useEffect(() => {
    if (open) return
    setTriggerSearchText(selected?.label ?? '')
  }, [open, selected?.label])

  useEffect(() => {
    if (!open || activeIndex < 0) return
    optionRefs.current[activeIndex]?.scrollIntoView({ block: 'nearest' })
  }, [activeIndex, open])

  useEffect(() => {
    if (!open) return

    function onPointerDown(event: MouseEvent) {
      const target = event.target as Node

      if (rootRef.current?.contains(target)) return
      if (menuRef.current?.contains(target)) return

      closeMenu()
    }

    function onEscape(event: KeyboardEvent) {
      if (event.key !== 'Escape') return

      event.preventDefault()
      closeMenu()
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

  const columnTemplate = menuColumns?.length
    ? menuColumns.map((column) => column.width ?? 'minmax(0, 1fr)').join(' ')
    : undefined

  return (
    <div
      ref={rootRef}
      className={[
        'ui-select',
        'bp-fancy-select',
        open ? 'is-open' : '',
        disabled ? 'is-disabled' : '',
        className ?? '',
      ]
        .filter(Boolean)
        .join(' ')}
    >
      {name ? (
        <input type="hidden" name={name} value={currentValue} />
      ) : null}

      {searchInTrigger ? (
        <div
          className={[
            'ui-select-trigger',
            'ui-select-combobox-trigger',
            'bp-fancy-select-trigger',
            'vca-select',
            triggerClassName ?? '',
          ]
            .filter(Boolean)
            .join(' ')}
        >
          <input
            ref={triggerSearchRef}
            id={id}
            type="text"
            className="ui-select-trigger-search"
            value={triggerSearchText}
            disabled={disabled}
            title={title}
            placeholder={placeholder}
            role="combobox"
            aria-label={ariaLabel}
            aria-haspopup="listbox"
            aria-expanded={open}
            aria-controls={open ? listId : undefined}
            aria-activedescendant={
              open && activeIndex >= 0
                ? `${listId}-option-${activeIndex}`
                : undefined
            }
            onFocus={() => {
              if (!open) openMenu()
            }}
            onClick={() => {
              if (!open) openMenu()
            }}
            onChange={(event) => {
              const next = event.target.value
              setTriggerSearchText(next)
              setSearch(next)
              onSearchChange?.(next)
              if (!open) setOpen(true)
            }}
            onKeyDown={(event) => {
              if (disabled) return

              if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault()
                if (!open) {
                  openMenu()
                  return
                }
                moveActive(event.key === 'ArrowDown' ? 1 : -1)
                return
              }

              if (event.key === 'Enter') {
                event.preventDefault()
                if (!open) {
                  openMenu()
                  return
                }
                const option = filteredOptions[activeIndex]
                if (option && !option.disabled) pick(option.value)
                return
              }

              if (event.key === 'Escape' && open) {
                event.preventDefault()
                closeMenu()
              }
            }}
          />

          <button
            type="button"
            className="ui-select-combobox-toggle"
            disabled={disabled}
            aria-label={open ? 'Close options' : 'Open options'}
            tabIndex={-1}
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => {
              if (open) closeMenu()
              else openMenu()
            }}
          >
            <span
              className="ui-select-arrow bp-fancy-select-arrow vca-select-caret"
              aria-hidden
            >
              <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                <path
                  d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z"
                  fill="currentColor"
                />
              </svg>
            </span>
          </button>
        </div>
      ) : (
        <button
          {...triggerProps}
          id={id}
          type="button"
          className={[
            'ui-select-trigger',
            'bp-fancy-select-trigger',
            'vca-select',
            triggerClassName ?? '',
          ]
            .filter(Boolean)
            .join(' ')}
          disabled={disabled}
          title={title}
          aria-label={ariaLabel}
          aria-haspopup="listbox"
          aria-expanded={open}
          aria-controls={open ? listId : undefined}
          aria-activedescendant={
            open && activeIndex >= 0
              ? `${listId}-option-${activeIndex}`
              : undefined
          }
          onFocus={onFocus}
          onBlur={onBlur}
          onMouseDown={onMouseDown}
          onClick={() => {
            if (open) closeMenu()
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

              moveActive(event.key === 'ArrowDown' ? 1 : -1)
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

          <span
            className="ui-select-arrow bp-fancy-select-arrow vca-select-caret"
            aria-hidden
          >
            <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
              <path
                d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z"
                fill="currentColor"
              />
            </svg>
          </span>
        </button>
      )}

      {open && pos
        ? createPortal(
            <div
              ref={menuRef}
              id={listId}
              className={[
                'ui-select-menu',
                'bp-fancy-select-menu',
                pos.openUp ? 'is-up' : 'is-down',
                menuMotion === 'reveal' ? 'is-reveal-motion' : '',
              ].filter(Boolean).join(' ')}
              role="listbox"
              aria-label={ariaLabel}
              style={{
                position: 'fixed',
                top: pos.openUp ? undefined : pos.top,
                bottom: pos.openUp
                  ? window.innerHeight - pos.top
                  : undefined,
                left: Math.max(
                  8,
                  Math.min(
                    pos.left,
                    window.innerWidth - Math.max(pos.width, menuMinWidth ?? pos.width) - 8,
                  ),
                ),
                width: Math.max(pos.width, menuMinWidth ?? pos.width),
                maxHeight: pos.maxHeight,
                zIndex: menuZIndex,
              }}
            >
              {searchable && !searchInTrigger ? (
                <div className="ui-select-search-wrap">
                  <input
                    ref={searchRef}
                    type="search"
                    className="ui-select-search"
                    value={search}
                    placeholder={searchPlaceholder}
                    aria-label={`Search ${ariaLabel ?? 'options'}`}
                    onChange={(event) => {
                      const next = event.target.value
                      setSearch(next)
                      onSearchChange?.(next)
                    }}
                    onKeyDown={(event) => {
                      if (event.key === 'ArrowDown') {
                        event.preventDefault()
                        moveActive(1)
                        return
                      }

                      if (event.key === 'ArrowUp') {
                        event.preventDefault()
                        moveActive(-1)
                        return
                      }

                      if (event.key === 'Enter') {
                        event.preventDefault()
                        const option = filteredOptions[activeIndex]
                        if (option && !option.disabled) pick(option.value)
                      }
                    }}
                  />
                </div>
              ) : null}

              {menuColumns?.length ? (
                <div
                  className="ui-select-columns-header"
                  style={{ gridTemplateColumns: columnTemplate }}
                  aria-hidden
                >
                  {menuColumns.map((column, index) => (
                    <span
                      key={`${column.header}-${index}`}
                      data-column-tone={column.tone}
                    >
                      {column.header}
                    </span>
                  ))}
                </div>
              ) : null}

              <div
                className="ui-select-menu-inner bp-fancy-select-menu-inner"
                style={{
                  maxHeight: Math.max(
                    80,
                    pos.maxHeight - (searchable && !searchInTrigger ? 40 : 8),
                  ),
                }}
              >
                {filteredOptions.length > 0 ? (
                  filteredOptions.map((option, index) => {
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
                        ]
                          .filter(Boolean)
                          .join(' ')}
                        onMouseEnter={() => setActiveIndex(index)}
                        onClick={() => {
                          if (option.disabled) return
                          pick(option.value)
                        }}
                        style={menuColumns?.length ? { gridTemplateColumns: columnTemplate } : undefined}
                      >
                        {menuColumns?.length ? (
                          (option.columns ?? [option.label]).map((value, columnIndex) => (
                            <span
                              className="ui-select-option-cell"
                              data-column-tone={menuColumns[columnIndex]?.tone}
                              key={`${option.value}-${columnIndex}`}
                            >
                              {value}
                            </span>
                          ))
                        ) : (
                          <span>{option.label}</span>
                        )}
                        {isSelected ? (
                          <span
                            className="ui-select-check bp-fancy-select-check"
                            aria-hidden
                          >
                            ✓
                          </span>
                        ) : null}
                      </button>
                    )
                  })
                ) : (
                  <div className="ui-select-empty">No matching options</div>
                )}
              </div>
            </div>,
            document.body,
          )
        : null}
    </div>
  )
}


export type UiSimpleDropdownProps = Omit<
  UiSelectProps,
  'searchInTrigger' | 'menuColumns'
>

/**
 * Canonical BluePOS "Simple Dropdown".
 * Search lives in the trigger field when searchable; the popup never adds a
 * second search box.
 */
export function UiSimpleDropdown({
  searchable = true,
  className,
  ...props
}: UiSimpleDropdownProps) {
  return (
    <UiSelect
      {...props}
      className={['ui-simple-dropdown', className ?? ''].filter(Boolean).join(' ')}
      searchable={searchable}
      searchInTrigger={searchable}
    />
  )
}

export type UiTableDropdownProps = Omit<
  UiSelectProps,
  'searchInTrigger'
> & {
  menuColumns: UiSelectMenuColumn[]
}

/**
 * Canonical BluePOS "Table Dropdown".
 * The trigger is the search field; popup rows use headed columns.
 */
export function UiTableDropdown({
  searchable = true,
  menuColumns,
  className,
  ...props
}: UiTableDropdownProps) {
  return (
    <UiSelect
      {...props}
      className={['ui-table-dropdown', className ?? ''].filter(Boolean).join(' ')}
      searchable={searchable}
      searchInTrigger
      menuColumns={menuColumns}
    />
  )
}
