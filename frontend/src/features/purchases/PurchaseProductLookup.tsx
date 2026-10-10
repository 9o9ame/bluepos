import { useLayoutEffect, useState, type RefObject } from 'react'
import { createPortal } from 'react-dom'
import { UI_LAYER } from '../../components/ui/uiLayers'
import type { Product } from '../../types/catalog'

export function PurchaseProductLookup({
  rows,
  anchorRef,
  onSelect,
}: {
  rows: Product[]
  anchorRef: RefObject<HTMLElement | null>
  onSelect: (product: Product) => void
}) {
  const [position, setPosition] = useState<{
    top: number
    left: number
    width: number
  } | null>(null)

  useLayoutEffect(() => {
    const update = () => {
      const rect = anchorRef.current?.getBoundingClientRect()
      if (!rect) return

      const width = Math.min(
        860,
        Math.max(640, window.innerWidth - rect.left - 16),
      )
      const left = Math.max(
        8,
        Math.min(rect.left, window.innerWidth - width - 8),
      )

      setPosition({
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
  }, [anchorRef])

  if (!position || typeof document === 'undefined') return null

  return createPortal(
    <div
      className="purchase-order-product-results"
      role="listbox"
      aria-label="Choose purchase order product"
      style={{
        position: 'fixed',
        top: position.top,
        left: position.left,
        width: position.width,
        zIndex: UI_LAYER.dropdown,
      }}
    >
      <div className="purchase-order-product-results-head" aria-hidden>
        <span>ID</span>
        <span>Description</span>
        <span>In Stock</span>
        <span>Avg Cost</span>
        <span>Unit</span>
      </div>

      {rows.slice(0, 10).map((product) => (
        <button
          type="button"
          key={product.ulid}
          role="option"
          onClick={() => onSelect(product)}
        >
          <span>{product.product_number}</span>
          <strong>{product.name}</strong>
          <span>
            {product.sales_lookup
              ? Number(product.sales_lookup.in_stock).toFixed(3)
              : '—'}
          </span>
          <span>{product.sales_lookup?.average_cost ?? '—'}</span>
          <span>
            {product.base_unit?.symbol ?? product.base_unit?.code ?? '—'}
          </span>
        </button>
      ))}
    </div>,
    document.body,
  )
}
