import type { CSSProperties, ReactNode } from 'react'
import './PosEntryGrid.css'

export type PosEntryGridColumn = {
  key: string
  header: ReactNode
  width?: number | string
  align?: 'left' | 'right' | 'center'
  className?: string
}

export type PosEntryGridProps = {
  columns: PosEntryGridColumn[]
  children: ReactNode
  className?: string
  tableClassName?: string
  minWidth?: number | string
  ariaLabel?: string
}

/**
 * Canonical BluePOS "Custom Table" shell for editable transaction lines.
 *
 * Modules own their columns, cells and business behavior. This component owns
 * the shared table geometry and visual contract: header, borders, density,
 * numeric alignment, entry-row surface and editable control sizing.
 */
export function PosEntryGrid({
  columns,
  children,
  className,
  tableClassName,
  minWidth,
  ariaLabel,
}: PosEntryGridProps) {
  const tableStyle: CSSProperties | undefined =
    minWidth === undefined
      ? undefined
      : { minWidth: typeof minWidth === 'number' ? `${minWidth}px` : minWidth }

  return (
    <div
      className={['pos-entry-grid-wrap', className ?? ''].filter(Boolean).join(' ')}
      role="region"
      aria-label={ariaLabel}
    >
      <table
        className={['pos-entry-grid', tableClassName ?? ''].filter(Boolean).join(' ')}
        style={tableStyle}
      >
        <colgroup>
          {columns.map((column) => (
            <col
              key={column.key}
              style={
                column.width === undefined
                  ? undefined
                  : {
                      width:
                        typeof column.width === 'number'
                          ? `${column.width}px`
                          : column.width,
                    }
              }
            />
          ))}
        </colgroup>
        <thead>
          <tr>
            {columns.map((column) => (
              <th
                key={column.key}
                className={[
                  column.align === 'right'
                    ? 'is-num'
                    : column.align === 'center'
                      ? 'is-center'
                      : '',
                  column.className ?? '',
                ]
                  .filter(Boolean)
                  .join(' ')}
              >
                {column.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  )
}
