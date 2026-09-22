import { useMemo, useState, type KeyboardEvent, type ReactNode } from 'react'

export type PosGridColumn<T> = {
  key: string
  header: string
  width?: number | string
  align?: 'left' | 'right' | 'center'
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
}

export function PosDataGrid<T>({
  columns,
  rows,
  rowKey,
  selectedKey,
  onSelect,
  onActivate,
  emptyMessage = 'No records',
}: PosDataGridProps<T>) {
  const [focusedKey, setFocusedKey] = useState<string | null>(selectedKey ?? null)
  const keys = useMemo(() => rows.map(rowKey), [rows, rowKey])

  function move(delta: number) {
    if (rows.length === 0) {
      return
    }
    const current = focusedKey && keys.includes(focusedKey) ? keys.indexOf(focusedKey) : 0
    const nextIndex = Math.max(0, Math.min(rows.length - 1, current + delta))
    const next = rows[nextIndex]
    setFocusedKey(rowKey(next))
    onSelect?.(next)
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

  return (
    <div className="pos-grid-wrap" tabIndex={0} onKeyDown={onKeyDown} role="grid">
      <table className="pos-grid">
        <thead>
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
        </thead>
        <tbody>
          {rows.map((row) => {
            const key = rowKey(row)
            const selected = key === (selectedKey ?? focusedKey)
            return (
              <tr
                key={key}
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
        </tbody>
      </table>
      {rows.length === 0 ? <div className="pos-grid-empty">{emptyMessage}</div> : null}
    </div>
  )
}
