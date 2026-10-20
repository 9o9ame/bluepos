import { useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type ReactNode, type UIEvent } from 'react'
import { ListLoadingIndicator } from '../ui/ListLoadingIndicator'

export type PosGridColumn<T> = {
  key: string
  header: ReactNode
  width?: number | string
  align?: 'left' | 'right' | 'center'
  filter?: ReactNode
  render?: (row: T) => ReactNode
}

type PosDataGridProps<T> = {
  columns: PosGridColumn<T>[]
  rows: T[]
  rowKey: (row: T) => string
  selectedKey?: string | null
  onSelect?: (row: T) => void
  onActivate?: (row: T) => void
  emptyMessage?: string
  onEndReached?: () => void
  endReachedOffset?: number
  loading?: boolean
  loadingLabel?: string
  virtualize?: boolean
  estimatedRowHeight?: number
  overscan?: number
}

export function PosDataGrid<T>({
  columns,
  rows,
  rowKey,
  selectedKey,
  onSelect,
  onActivate,
  emptyMessage = 'No records',
  onEndReached,
  endReachedOffset = 120,
  loading = false,
  loadingLabel = 'Loading more records…',
  virtualize = false,
  estimatedRowHeight = 22,
  overscan = 8,
}: PosDataGridProps<T>) {
  const wrapRef = useRef<HTMLDivElement | null>(null)
  const theadRef = useRef<HTMLTableSectionElement | null>(null)
  const firstRenderedRowRef = useRef<HTMLTableRowElement | null>(null)
  const [focusedKey, setFocusedKey] = useState<string | null>(selectedKey ?? null)
  const [scrollTop, setScrollTop] = useState(0)
  const [viewportHeight, setViewportHeight] = useState(0)
  const [rowHeight, setRowHeight] = useState(estimatedRowHeight)
  const [headerHeight, setHeaderHeight] = useState(0)
  const keys = useMemo(() => rows.map(rowKey), [rows, rowKey])

  useLayoutEffect(() => {
    if (!virtualize) return

    const wrap = wrapRef.current
    if (!wrap) return

    const measure = () => {
      setViewportHeight(wrap.clientHeight)
      setHeaderHeight(theadRef.current?.getBoundingClientRect().height ?? 0)

      const measuredRowHeight = firstRenderedRowRef.current?.getBoundingClientRect().height
      if (measuredRowHeight && measuredRowHeight > 0) {
        setRowHeight(measuredRowHeight)
      }
    }

    measure()

    const observer = new ResizeObserver(measure)
    observer.observe(wrap)

    return () => observer.disconnect()
  }, [virtualize, rows.length])

  function move(delta: number) {
    if (rows.length === 0) {
      return
    }
    const current = focusedKey && keys.includes(focusedKey) ? keys.indexOf(focusedKey) : 0
    const nextIndex = Math.max(0, Math.min(rows.length - 1, current + delta))
    const next = rows[nextIndex]
    setFocusedKey(rowKey(next))
    onSelect?.(next)

    if (virtualize) {
      const wrap = wrapRef.current
      if (wrap) {
        const rowTop = headerHeight + nextIndex * rowHeight
        const rowBottom = rowTop + rowHeight
        const viewportTop = wrap.scrollTop
        const viewportBottom = viewportTop + wrap.clientHeight

        if (rowTop < viewportTop) {
          wrap.scrollTop = Math.max(0, rowTop)
        } else if (rowBottom > viewportBottom) {
          wrap.scrollTop = Math.max(0, rowBottom - wrap.clientHeight)
        }
      }
    }
  }

  function onScroll(event: UIEvent<HTMLDivElement>) {
    const element = event.currentTarget

    if (virtualize) {
      setScrollTop(element.scrollTop)
    }

    if (!onEndReached) return

    const remaining = element.scrollHeight - element.scrollTop - element.clientHeight

    if (remaining <= endReachedOffset) {
      onEndReached()
    }
  }

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      move(1)
    }
    if (event.key === 'ArrowUp') {
      event.preventDefault()
      move(-1)
    }
    if (event.key === 'Enter' && focusedKey) {
      const row = rows.find((item) => rowKey(item) === focusedKey)
      if (row) {
        onActivate?.(row)
      }
    }
  }

  const hasFilterRow = columns.some((column) => column.filter !== undefined)

  const virtualWindow = useMemo(() => {
    if (!virtualize || rows.length === 0) {
      return {
        start: 0,
        end: rows.length,
        topSpacer: 0,
        bottomSpacer: 0,
      }
    }

    const contentScrollTop = Math.max(0, scrollTop - headerHeight)
    const visibleHeight = Math.max(viewportHeight, rowHeight)
    const start = Math.max(0, Math.floor(contentScrollTop / rowHeight) - overscan)
    const end = Math.min(
      rows.length,
      Math.ceil((contentScrollTop + visibleHeight) / rowHeight) + overscan,
    )

    return {
      start,
      end,
      topSpacer: start * rowHeight,
      bottomSpacer: Math.max(0, (rows.length - end) * rowHeight),
    }
  }, [
    headerHeight,
    overscan,
    rowHeight,
    rows.length,
    scrollTop,
    viewportHeight,
    virtualize,
  ])

  const renderedRows = virtualize
    ? rows.slice(virtualWindow.start, virtualWindow.end)
    : rows

  return (
    <div ref={wrapRef} className="pos-grid-wrap" tabIndex={0} onKeyDown={onKeyDown} onScroll={onScroll} role="grid">
      <table className="pos-grid">
        <thead ref={theadRef}>
          <tr>
            {columns.map((column) => (
              <th
                key={column.key}
                style={column.width ? { width: column.width } : undefined}
                className={column.align === 'right' ? 'is-num' : column.align === 'center' ? 'is-center' : undefined}
              >
                {column.header}
              </th>
            ))}
          </tr>
          {hasFilterRow ? (
            <tr className="pos-grid-filter-row">
              {columns.map((column) => (
                <th key={column.key} className={column.align === 'right' ? 'is-num' : column.align === 'center' ? 'is-center' : undefined}>
                  {column.filter ?? null}
                </th>
              ))}
            </tr>
          ) : null}
        </thead>
        <tbody>
          {virtualize && virtualWindow.topSpacer > 0 ? (
            <tr aria-hidden="true">
              <td
                colSpan={columns.length}
                style={{ height: virtualWindow.topSpacer, padding: 0, border: 0 }}
              />
            </tr>
          ) : null}
          {renderedRows.map((row, renderedIndex) => {
            const key = rowKey(row)
            const selected = key === (selectedKey ?? focusedKey)
            return (
              <tr
                key={key}
                ref={virtualize && renderedIndex === 0 ? firstRenderedRowRef : undefined}
                className={selected ? 'is-selected' : undefined}
                onClick={() => {
                  setFocusedKey(key)
                  onSelect?.(row)
                }}
                onDoubleClick={() => onActivate?.(row)}
              >
                {columns.map((column) => (
                  <td
                    key={column.key}
                    className={column.align === 'right' ? 'is-num' : column.align === 'center' ? 'is-center' : undefined}
                  >
                    {column.render ? column.render(row) : null}
                  </td>
                ))}
              </tr>
            )
          })}
          {virtualize && virtualWindow.bottomSpacer > 0 ? (
            <tr aria-hidden="true">
              <td
                colSpan={columns.length}
                style={{ height: virtualWindow.bottomSpacer, padding: 0, border: 0 }}
              />
            </tr>
          ) : null}
        </tbody>
      </table>
      {rows.length === 0 && !loading ? <div className="pos-grid-empty">{emptyMessage}</div> : null}
      <ListLoadingIndicator visible={loading} label={loadingLabel} />
    </div>
  )
}
