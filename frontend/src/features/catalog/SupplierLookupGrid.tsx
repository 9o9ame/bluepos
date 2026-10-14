import { useEffect, useLayoutEffect, useRef, useState, type RefObject } from 'react'
import { createPortal } from 'react-dom'
import { UI_LAYER } from '../../components/ui/uiLayers'
import type { Supplier } from '../../types/catalog'
import './SupplierLookupGrid.css'

export function SupplierLookupGrid({
  rows,
  total,
  selectedUlid,
  portalAnchorRef,
  onSelect,
  onRequestClose,
}: {
  rows: Supplier[]
  total: number
  selectedUlid?: string | null
  portalAnchorRef: RefObject<HTMLElement | null>
  onSelect: (supplier: Supplier) => void
  onRequestClose: () => void
}) {
  const gridRef = useRef<HTMLDivElement | null>(null)
  const bodyRef = useRef<HTMLDivElement | null>(null)
  const selectedScrolledRef = useRef(false)
  const [portalStyle, setPortalStyle] = useState<{
    top: number
    left: number
    width: number
  } | null>(null)

  useLayoutEffect(() => {
    const update = () => {
      const rect = portalAnchorRef.current?.getBoundingClientRect()
      if (!rect) return

      const width = Math.min(900, Math.max(760, window.innerWidth - rect.left - 16))
      const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))

      setPortalStyle({
        top: rect.bottom - 1,
        left,
        width,
      })
    }

    update()
    window.addEventListener('resize', update)
    window.addEventListener('scroll', update, true)

    return () => {
      window.removeEventListener('resize', update)
      window.removeEventListener('scroll', update, true)
    }
  }, [portalAnchorRef])

  useEffect(() => {
    const requestClose = onRequestClose

    function onPointerDown(event: MouseEvent) {
      const target = event.target as Node
      if (gridRef.current?.contains(target)) return
      if (portalAnchorRef.current?.contains(target)) return
      requestClose()
    }

    window.addEventListener('mousedown', onPointerDown)
    return () => window.removeEventListener('mousedown', onPointerDown)
  }, [onRequestClose, portalAnchorRef])

  useLayoutEffect(() => {
    selectedScrolledRef.current = false
  }, [selectedUlid])

  useLayoutEffect(() => {
    if (!portalStyle || !selectedUlid || selectedScrolledRef.current) return

    const frame = window.requestAnimationFrame(() => {
      const selected = bodyRef.current?.querySelector<HTMLElement>(
        `[data-supplier-ulid="${selectedUlid}"]`,
      )
      selected?.scrollIntoView({ block: 'center' })
      selectedScrolledRef.current = true
    })

    return () => window.cancelAnimationFrame(frame)
  }, [portalStyle, rows, selectedUlid])

  const grid = (
    <div
      ref={gridRef}
      className="supplier-lookup-grid is-themed"
      style={
        portalStyle
          ? {
              position: 'fixed',
              top: portalStyle.top,
              left: portalStyle.left,
              width: portalStyle.width,
              zIndex: UI_LAYER.dropdown,
            }
          : undefined
      }
      role="listbox"
      aria-label="Choose supplier"
    >
      <div className="supplier-lookup-grid-head" aria-hidden="true">
        <span>Code</span>
        <span>Name</span>
        <span>Address</span>
        <span>Mobile</span>
      </div>

      <div ref={bodyRef} className="supplier-lookup-grid-body">
        {rows.map((supplier) => (
          <button
            type="button"
            key={supplier.ulid}
            data-supplier-ulid={supplier.ulid}
            className={[
              'supplier-lookup-grid-row',
              supplier.ulid === selectedUlid ? 'is-selected' : '',
            ].filter(Boolean).join(' ')}
            aria-selected={supplier.ulid === selectedUlid}
            onClick={() => onSelect(supplier)}
          >
            <span>{supplier.code || '—'}</span>
            <strong>{supplier.name}</strong>
            <span>{supplier.address || supplier.billing_address || '—'}</span>
            <span>{supplier.mobile || supplier.phone || '—'}</span>
          </button>
        ))}
      </div>

      <div className="supplier-lookup-grid-foot">
        Showing {rows.length} of {total} Suppliers
      </div>
    </div>
  )

  if (portalStyle && typeof document !== 'undefined') {
    return createPortal(grid, document.body)
  }

  return grid
}
