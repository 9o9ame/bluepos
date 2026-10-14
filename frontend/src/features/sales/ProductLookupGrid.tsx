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

const PRODUCT_LOOKUP_ROW_HEIGHT = 24
const PRODUCT_LOOKUP_OVERSCAN = 8

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
  onLoadNext,
  onLoadPrevious,
  loadingNext = false,
  loadingPrevious = false,
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
  onLoadNext?: () => void
  onLoadPrevious?: () => void
  loadingNext?: boolean
  loadingPrevious?: boolean
}) {
  const gridRef = useRef<HTMLDivElement | null>(null)
  const bodyRef = useRef<HTMLDivElement | null>(null)
  const selectedScrolledRef = useRef(false)
  const prependSnapshotRef = useRef<{ height: number; top: number } | null>(null)
  const [scrollTop, setScrollTop] = useState(0)
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

  useLayoutEffect(() => {
    const body = bodyRef.current
    const snapshot = prependSnapshotRef.current
    if (!body || !snapshot) return

    body.scrollTop = snapshot.top + (body.scrollHeight - snapshot.height)
    prependSnapshotRef.current = null
  }, [rows.length])

  useLayoutEffect(() => {
    selectedScrolledRef.current = false
  }, [selectedUlid])

  useLayoutEffect(() => {
    if (!selectedUlid || selectedScrolledRef.current) return
    const body = bodyRef.current
    if (!body) return

    const selectedIndex = rows.findIndex((row) => row.ulid === selectedUlid)
    if (selectedIndex < 0) return

    const nextTop = Math.max(
      0,
      selectedIndex * PRODUCT_LOOKUP_ROW_HEIGHT -
        Math.max(0, body.clientHeight - PRODUCT_LOOKUP_ROW_HEIGHT) / 2,
    )
    body.scrollTop = nextTop
    setScrollTop(nextTop)
    selectedScrolledRef.current = true
  }, [rows, selectedUlid])

  const visibleStart = Math.max(
    0,
    Math.floor(scrollTop / PRODUCT_LOOKUP_ROW_HEIGHT) - PRODUCT_LOOKUP_OVERSCAN,
  )
  const visibleCount =
    Math.ceil(250 / PRODUCT_LOOKUP_ROW_HEIGHT) + PRODUCT_LOOKUP_OVERSCAN * 2
  const visibleEnd = Math.min(rows.length, visibleStart + visibleCount)
  const visibleRows = rows.slice(visibleStart, visibleEnd)

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

      <div
        ref={bodyRef}
        className="sales-pos-product-grid-body"
        onScroll={(event) => {
          const element = event.currentTarget
          setScrollTop(element.scrollTop)

          if (
            element.scrollTop <= 28 &&
            onLoadPrevious &&
            !loadingPrevious
          ) {
            prependSnapshotRef.current = {
              height: element.scrollHeight,
              top: element.scrollTop,
            }
            onLoadPrevious()
          }

          if (
            element.scrollHeight - element.scrollTop - element.clientHeight <= 28 &&
            onLoadNext &&
            !loadingNext
          ) {
            onLoadNext()
          }
        }}
      >
        <div
          style={{
            position: 'relative',
            height: rows.length * PRODUCT_LOOKUP_ROW_HEIGHT,
          }}
        >
        {visibleRows.map((row, visibleIndex) => {
          const rowIndex = visibleStart + visibleIndex
          return (
          <button
            type="button"
            className={[
              'sales-pos-product-grid-row',
              selectedUlid === row.ulid ? 'is-selected' : '',
            ].filter(Boolean).join(' ')}
            key={row.ulid}
            data-product-ulid={row.ulid}
            aria-selected={selectedUlid === row.ulid}
            style={{
              position: 'absolute',
              top: rowIndex * PRODUCT_LOOKUP_ROW_HEIGHT,
              left: 0,
              right: 0,
              height: PRODUCT_LOOKUP_ROW_HEIGHT,
            }}
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
          )
        })}
        </div>
      </div>

      <div className="sales-pos-product-grid-foot">
        {loadingPrevious || loadingNext
          ? `Loading… ${rows.length} of ${total} Products`
          : `Showing ${rows.length} of ${total} Products`}
      </div>
    </div>
  )

  if (portalStyle && typeof document !== 'undefined') {
    return createPortal(grid, document.body)
  }

  return grid
}
