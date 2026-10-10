import { useLayoutEffect, useState, type RefObject } from 'react'
import { createPortal } from 'react-dom'
import { UI_LAYER } from '../../components/ui/uiLayers'
import type { Product } from '../../types/catalog'
import type { SalePriceType } from '../../types/sales'
import './ProductLookupGrid.css'

function productLookupPrice(product: Product, priceType: SalePriceType): string {
  const type = priceType === 'wholesale' ? 'wholesale' : 'retail'
  return product.prices?.find(
    (price) => price.is_active && price.price_type === type,
  )?.amount ?? '—'
}

function productBaseRate(product: Product): string {
  return product.prices?.find(
    (price) => price.is_active && price.price_type === 'minimum_sale',
  )?.amount ?? '—'
}

function productLookupDescription(product: Product): string {
  const barcode =
    product.primary_barcode ??
    product.barcodes?.find((row) => row.is_primary && row.is_active)?.barcode ??
    product.barcodes?.find((row) => row.is_active)?.barcode ??
    ''

  return [barcode, product.name, product.category?.name]
    .filter(Boolean)
    .join(' * ')
}

export function ProductLookupGrid({
  rows,
  total,
  priceType,
  onSelect,
  portalAnchorRef,
  themed = false,
}: {
  rows: Product[]
  total: number
  priceType: SalePriceType
  onSelect: (product: Product) => void
  portalAnchorRef?: RefObject<HTMLElement | null>
  themed?: boolean
}) {
  const [portalStyle, setPortalStyle] = useState<{
    top: number
    left: number
    width: number
  } | null>(null)

  useLayoutEffect(() => {
    if (!portalAnchorRef?.current) {
      setPortalStyle(null)
      return
    }

    const update = () => {
      const rect = portalAnchorRef.current?.getBoundingClientRect()
      if (!rect) return

      const width = Math.min(1080, Math.max(700, window.innerWidth - rect.left - 16))
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

  const grid = (
    <div
      className={[
        'sales-pos-product-results-grid',
        portalStyle ? 'is-portal' : '',
        themed ? 'is-themed' : '',
      ].filter(Boolean).join(' ')}
      style={
        portalStyle
          ? {
              position: 'fixed',
              top: portalStyle.top,
              left: portalStyle.left,
              width: portalStyle.width,
              maxWidth: 'none',
              zIndex: UI_LAYER.dropdown,
            }
          : undefined
      }
      role="listbox"
      aria-label="Choose product"
    >
      <div className="sales-pos-product-grid-head" aria-hidden="true">
        <span>ID</span>
        <span>Description</span>
        <span>In Stock</span>
        <span>Unit Price</span>
        <span>Base Rate</span>
        <span>Cost</span>
        <span>Unit</span>
        <span>Location</span>
      </div>

      <div className="sales-pos-product-grid-body">
        {rows.slice(0, 12).map((row) => (
          <button
            type="button"
            className="sales-pos-product-grid-row"
            key={row.ulid}
            onClick={() => onSelect(row)}
          >
            <span>{row.product_number}</span>
            <strong>{productLookupDescription(row)}</strong>
            <span>
              {row.sales_lookup
                ? Number.parseFloat(row.sales_lookup.in_stock).toFixed(3)
                : '—'}
            </span>
            <span>{productLookupPrice(row, priceType)}</span>
            <span>{productBaseRate(row)}</span>
            <span>{row.sales_lookup?.average_cost ?? '—'}</span>
            <span>{row.base_unit?.symbol ?? row.base_unit?.code ?? '—'}</span>
            <span>{row.rack_location || '—'}</span>
          </button>
        ))}
      </div>

      <div className="sales-pos-product-grid-foot">
        Showing {Math.min(rows.length, 12)} of {total} Products
      </div>
    </div>
  )

  if (portalStyle && typeof document !== 'undefined') {
    return createPortal(grid, document.body)
  }

  return grid
}
