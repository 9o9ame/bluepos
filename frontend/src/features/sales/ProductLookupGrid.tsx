import { useEffect, useLayoutEffect, useRef, useState, type RefObject } from 'react'
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
  selectedUlid,
  mode = 'sale',
  onRequestClose,
}: {
  rows: Product[]
  total: number
  priceType: SalePriceType
  onSelect: (product: Product) => void
  portalAnchorRef?: RefObject<HTMLElement | null>
  themed?: boolean
  selectedUlid?: string | null
  mode?: 'sale' | 'purchase'
  onRequestClose?: () => void
}) {
  const gridRef = useRef<HTMLDivElement | null>(null)
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

  useEffect(() => {
    if (!onRequestClose) return

    function onPointerDown(event: MouseEvent) {
      const target = event.target as Node
      if (gridRef.current?.contains(target)) return
      if (portalAnchorRef?.current?.contains(target)) return
      onRequestClose()
    }

    window.addEventListener('mousedown', onPointerDown)
    return () => window.removeEventListener('mousedown', onPointerDown)
  }, [onRequestClose, portalAnchorRef])

  const grid = (
    <div
      ref={gridRef}
      className={[
        'sales-pos-product-results-grid',
        mode === 'purchase' ? 'is-purchase' : '',
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
        {mode === 'purchase' ? (
          <>
            <span>ID</span>
            <span>Description</span>
            <span>In Stock</span>
            <span>UOM</span>
            <span>Packing</span>
            <span>Cost Price</span>
            <span>Selling</span>
            <span>Category</span>
            <span>Company</span>
            <span>Location</span>
          </>
        ) : (
          <>
            <span>ID</span>
            <span>Description</span>
            <span>In Stock</span>
            <span>Unit Price</span>
            <span>Base Rate</span>
            <span>Cost</span>
            <span>Unit</span>
            <span>Location</span>
          </>
        )}
      </div>

      <div className="sales-pos-product-grid-body">
        {rows.slice(0, 12).map((row) => (
          <button
            type="button"
            className={[
              'sales-pos-product-grid-row',
              selectedUlid === row.ulid ? 'is-selected' : '',
            ].filter(Boolean).join(' ')}
            key={row.ulid}
            aria-selected={selectedUlid === row.ulid}
            onClick={() => onSelect(row)}
          >
            {mode === 'purchase' ? (
              <>
                <span>{row.product_number}</span>
                <strong>{productLookupDescription(row)}</strong>
                <span>
                  {row.sales_lookup
                    ? Number.parseFloat(row.sales_lookup.in_stock).toFixed(3)
                    : '—'}
                </span>
                <span>{row.base_unit?.symbol ?? row.base_unit?.code ?? '—'}</span>
                <span>
                  {row.secondary_unit
                    ? `${row.secondary_conversion_factor ?? '1'} ${row.secondary_unit.symbol ?? row.secondary_unit.code}`
                    : '—'}
                </span>
                <span>{row.purchase_rate ?? row.sales_lookup?.average_cost ?? '—'}</span>
                <span>{productLookupPrice(row, 'retail')}</span>
                <span>{row.category?.name ?? '—'}</span>
                <span>{row.brand?.name ?? '—'}</span>
                <span>{row.rack_location || '—'}</span>
              </>
            ) : (
              <>
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
              </>
            )}
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
