import { useCallback, useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { createPortal } from 'react-dom'
import { GripVertical, Lock, Unlock, X } from 'lucide-react'
import type { ResolvedGridColumn } from './columnCatalog'
import './ColumnCustomizationPanel.css'

type Props = {
  open: boolean
  title?: string
  hiddenColumns: ResolvedGridColumn[]
  visibleColumns: ResolvedGridColumn[]
  onClose: () => void
  onShow: (key: string) => void
  onHide: (key: string) => void
  onToggleLock: (key: string) => void
  onMove: (fromKey: string, toKey: string) => void
  onReset: () => void
  onSaveRoleDefault?: () => void
  canSaveRoleDefault?: boolean
}

const PANEL_W = 300
const PANEL_H = 460

export function ColumnCustomizationPanel({
  open,
  title = 'Customization',
  hiddenColumns,
  visibleColumns,
  onClose,
  onShow,
  onHide,
  onToggleLock,
  onMove,
  onReset,
  onSaveRoleDefault,
  canSaveRoleDefault = false,
}: Props) {
  const panelRef = useRef<HTMLElement | null>(null)
  const dragOffset = useRef({ x: 0, y: 0 })
  const [pos, setPos] = useState(() => ({
    x: Math.max(24, window.innerWidth - PANEL_W - 40),
    y: Math.max(72, Math.round(window.innerHeight * 0.16)),
  }))
  const [dragging, setDragging] = useState(false)

  useEffect(() => {
    if (!open) return
    setPos({
      x: Math.max(24, window.innerWidth - PANEL_W - 40),
      y: Math.max(72, Math.round(window.innerHeight * 0.16)),
    })
  }, [open])

  const onPointerMove = useCallback((event: PointerEvent) => {
    const maxX = Math.max(8, window.innerWidth - PANEL_W - 8)
    const maxY = Math.max(8, window.innerHeight - 120)
    setPos({
      x: Math.min(maxX, Math.max(8, event.clientX - dragOffset.current.x)),
      y: Math.min(maxY, Math.max(8, event.clientY - dragOffset.current.y)),
    })
  }, [])

  const stopDrag = useCallback(() => {
    setDragging(false)
    window.removeEventListener('pointermove', onPointerMove)
    window.removeEventListener('pointerup', stopDrag)
  }, [onPointerMove])

  function startDrag(event: ReactPointerEvent) {
    if ((event.target as HTMLElement).closest('button')) return
    event.preventDefault()
    const rect = panelRef.current?.getBoundingClientRect()
    dragOffset.current = {
      x: event.clientX - (rect?.left ?? pos.x),
      y: event.clientY - (rect?.top ?? pos.y),
    }
    setDragging(true)
    window.addEventListener('pointermove', onPointerMove)
    window.addEventListener('pointerup', stopDrag)
  }

  useEffect(() => () => stopDrag(), [stopDrag])

  if (!open || typeof document === 'undefined') return null

  return createPortal(
    <aside
      ref={panelRef}
      className={`bp-col-customization${dragging ? ' is-dragging' : ''}`}
      role="dialog"
      aria-label={title}
      style={{ left: pos.x, top: pos.y, width: PANEL_W, maxHeight: PANEL_H }}
    >
      <div className="bp-col-customization-titlebar" onPointerDown={startDrag}>
        <span className="bp-col-customization-grip" aria-hidden>
          <GripVertical size={14} />
        </span>
        <span className="bp-col-customization-title">{title}</span>
        <button type="button" aria-label="Close customization" onClick={onClose}>
          <X size={14} />
        </button>
      </div>

      <div className="bp-col-customization-body">
        <div className="bp-col-customization-section-label is-hidden">Hidden — click to show</div>
        <div className="bp-col-customization-list">
          {hiddenColumns.length === 0 ? (
            <div className="bp-col-customization-empty">All columns are visible</div>
          ) : (
            hiddenColumns.map((col) => (
              <button
                key={col.key}
                type="button"
                className="bp-col-chip"
                draggable
                onDragStart={(event) => {
                  event.dataTransfer.setData('text/bp-col', col.key)
                  event.dataTransfer.effectAllowed = 'move'
                }}
                onClick={() => onShow(col.key)}
                title={`Show ${col.label || col.key}`}
              >
                <span>{col.label || col.key}</span>
              </button>
            ))
          )}
        </div>

        <div className="bp-col-customization-section-label is-visible">Visible — drag to reorder</div>
        <div className="bp-col-customization-list is-visible">
          {visibleColumns.map((col) => (
            <div
              key={col.key}
              className={`bp-col-customization-row${col.locked ? ' is-locked' : ''}`}
              draggable={!col.locked}
              onDragStart={(event) => {
                if (col.locked) return
                event.dataTransfer.setData('text/bp-col', col.key)
                event.dataTransfer.effectAllowed = 'move'
              }}
              onDragOver={(event) => {
                if (col.locked) return
                event.preventDefault()
              }}
              onDrop={(event) => {
                event.preventDefault()
                const from = event.dataTransfer.getData('text/bp-col')
                if (from) onMove(from, col.key)
              }}
            >
              <span className="bp-col-customization-label">{col.label || col.key}</span>
              <span className="bp-col-customization-actions">
                {col.lockable !== false ? (
                  <button
                    type="button"
                    title={col.locked ? 'Unlock column' : 'Lock column'}
                    onClick={() => onToggleLock(col.key)}
                  >
                    {col.locked ? <Lock size={12} /> : <Unlock size={12} />}
                  </button>
                ) : null}
                {!col.locked ? (
                  <button type="button" className="is-hide" title="Hide column" onClick={() => onHide(col.key)}>
                    Hide
                  </button>
                ) : null}
              </span>
            </div>
          ))}
        </div>
      </div>

      <div className="bp-col-customization-foot">
        <button type="button" onClick={onReset}>
          Reset
        </button>
        {canSaveRoleDefault && onSaveRoleDefault ? (
          <button type="button" className="is-role" onClick={() => void onSaveRoleDefault()}>
            Save for role
          </button>
        ) : null}
      </div>
    </aside>,
    document.body,
  )
}
