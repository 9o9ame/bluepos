import { useDeferredValue, useEffect, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronLeft, ChevronRight, Eye, RefreshCw } from 'lucide-react'
import { fetchProducts } from '../api/catalog'
import { fetchProductLedgerReport } from '../api/inventory'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { ProductLookupGrid } from '../features/sales/ProductLookupGrid'
import { useAuth } from '../features/auth/AuthProvider'
import type { Product } from '../types/catalog'
import type { ProductLedgerRow } from '../types/inventory'
import './ProductLedgerReportPage.css'

function localIsoDate(date = new Date()) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function monthStartIso() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

function qty(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '0'
  const parsed = Number.parseFloat(value)
  if (!Number.isFinite(parsed)) return value
  return parsed.toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 6,
  })
}

function money(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const parsed = Number.parseFloat(value)
  if (!Number.isFinite(parsed)) return value
  return parsed.toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
  })
}

function dateTime(value: string | null): string {
  if (!value) return '—'
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(parsed)
}

function movementLabel(value: string): string {
  return value
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
}

function productLabel(product: Product) {
  return `${product.product_number} · ${product.name}`
}

export function ProductLedgerReportPage() {
  const { session } = useAuth()

  const [productSearch, setProductSearch] = useState('')
  const [productLookupQuery, setProductLookupQuery] = useState('')
  const deferredProductSearch = useDeferredValue(productLookupQuery)
  const [productUlid, setProductUlid] = useState('')
  const [pickerOpen, setPickerOpen] = useState(false)
  const [fromDate, setFromDate] = useState(monthStartIso())
  const [toDate, setToDate] = useState(localIsoDate())
  const [page, setPage] = useState(1)

  const productAnchorRef = useRef<HTMLDivElement | null>(null)
  const productInputRef = useRef<HTMLInputElement | null>(null)

  const productsQuery = useQuery({
    queryKey: ['reports', 'product-ledger-products', deferredProductSearch],
    queryFn: () => fetchProducts(
      {
        q: deferredProductSearch.trim() || undefined,
        per_page: 50,
        active_only: true,
        sales_lookup: true,
      },
      { busy: 'none' },
    ),
  })

  const ledgerQuery = useQuery({
    queryKey: ['reports', 'product-ledger', productUlid, fromDate, toDate, page],
    queryFn: () => fetchProductLedgerReport({
      product_ulid: productUlid,
      from_date: fromDate,
      to_date: toDate,
      page,
      per_page: 100,
    }),
    enabled: Boolean(productUlid && fromDate && toDate && fromDate <= toDate),
  })

  useEffect(() => {
    if (!pickerOpen) return

    const handleOutsidePointer = (event: MouseEvent) => {
      const target = event.target
      if (!(target instanceof Node)) return
      if (productAnchorRef.current?.contains(target)) return
      if (target instanceof Element && target.closest('.sales-pos-product-results-grid')) return
      setPickerOpen(false)
    }

    window.document.addEventListener('mousedown', handleOutsidePointer)
    return () => window.document.removeEventListener('mousedown', handleOutsidePointer)
  }, [pickerOpen])

  const selectedProduct =
    ledgerQuery.data?.product
      ? {
          ulid: ledgerQuery.data.product.ulid,
          product_number: ledgerQuery.data.product.product_number,
          sku: ledgerQuery.data.product.sku,
          name: ledgerQuery.data.product.name,
        }
      : productsQuery.data?.data.find((row) => row.ulid === productUlid) ?? null

  const columns = useMemo<PosGridColumn<ProductLedgerRow>[]>(() => [
    {
      key: 'date',
      header: 'Date / Time',
      width: 155,
      render: (row) => dateTime(row.occurred_at),
    },
    {
      key: 'movement',
      header: 'Movement',
      width: 120,
      render: (row) => movementLabel(row.movement_type),
    },
    {
      key: 'reference_type',
      header: 'Reference',
      width: 145,
      render: (row) => movementLabel(row.reference_type),
    },
    {
      key: 'reference',
      header: 'Document',
      width: 210,
      render: (row) => row.reference_ulid ?? '—',
    },
    {
      key: 'in',
      header: 'Qty In',
      width: 95,
      align: 'right',
      render: (row) => qty(row.quantity_in),
    },
    {
      key: 'out',
      header: 'Qty Out',
      width: 95,
      align: 'right',
      render: (row) => qty(row.quantity_out),
    },
    {
      key: 'running',
      header: 'Balance',
      width: 100,
      align: 'right',
      render: (row) => qty(row.running_quantity),
    },
    {
      key: 'unit_cost',
      header: 'Unit Cost',
      width: 100,
      align: 'right',
      render: (row) => money(row.unit_cost),
    },
    {
      key: 'total_cost',
      header: 'Total Cost',
      width: 110,
      align: 'right',
      render: (row) => money(row.total_cost),
    },
    {
      key: 'notes',
      header: 'Remarks',
      width: '24%',
      render: (row) => row.notes ?? '—',
    },
  ], [])

  function selectProduct(product: Product) {
    setProductUlid(product.ulid)
    setProductSearch(productLabel(product))
    setProductLookupQuery(product.name)
    setPickerOpen(false)
    setPage(1)
  }

  function previewPrint() {
    const root = window.document.documentElement
    const cleanup = () => {
      root.removeAttribute('data-product-ledger-print')
      window.removeEventListener('afterprint', cleanup)
    }

    root.setAttribute('data-product-ledger-print', 'true')
    window.addEventListener('afterprint', cleanup, { once: true })
    window.requestAnimationFrame(() => window.print())
  }

  const report = ledgerQuery.data
  const generatedAt = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date())

  return (
    <section className="product-ledger-page" aria-label="Product Ledger Report">
      <header className="product-ledger-print-header" aria-hidden="true">
        <div>
          <h1>Product Ledger</h1>
          <p>
            {session?.tenant.name ?? 'BluePOS'} · {session?.branch.code ?? '—'} — {session?.branch.name ?? '—'} · {report?.warehouse.name ?? session?.warehouse.name ?? '—'}
          </p>
          <p>
            {report ? `${report.product.product_number} · ${report.product.name}` : productSearch || 'No product selected'}
          </p>
        </div>
        <div className="product-ledger-print-meta">
          <span>{fromDate} to {toDate}</span>
          <span>Opening: {qty(report?.opening_quantity)}</span>
          <span>Generated {generatedAt}</span>
        </div>
      </header>

      <div className="product-ledger-toolbar">
        <div ref={productAnchorRef} className="product-ledger-product-picker">
          <input
            ref={productInputRef}
            value={productSearch}
            autoComplete="off"
            placeholder="Search product name, number, SKU or barcode"
            onFocus={() => {
              if (productSearch.trim() || (productsQuery.data?.data.length ?? 0) > 0) {
                setPickerOpen(true)
              }
            }}
            onChange={(event) => {
              setProductSearch(event.target.value)
              setProductLookupQuery(event.target.value)
              setProductUlid('')
              setPickerOpen(true)
              setPage(1)
            }}
            onKeyDown={(event) => {
              if (event.key === 'ArrowDown') {
                event.preventDefault()
                setPickerOpen(true)
                return
              }

              if (event.key !== 'Enter') return
              const first = productsQuery.data?.data[0]
              if (!first) return

              event.preventDefault()
              selectProduct(first)
            }}
          />
          <button
            type="button"
            className="product-ledger-product-arrow"
            aria-label="Open product list"
            onClick={() => {
              setPickerOpen((open) => !open)
              window.setTimeout(() => productInputRef.current?.focus(), 0)
            }}
          >
            <ChevronDown size={14} />
          </button>

          {pickerOpen && (productsQuery.data?.data.length ?? 0) > 0 ? (
            <ProductLookupGrid
              rows={productsQuery.data?.data ?? []}
              total={productsQuery.data?.meta.total ?? 0}
              priceType="default"
              onSelect={selectProduct}
              portalAnchorRef={productAnchorRef}
              themed
              selectedUlid={productUlid}
            />
          ) : null}
        </div>

        <label>
          <span>From:</span>
          <input
            type="date"
            value={fromDate}
            onChange={(event) => {
              setFromDate(event.target.value)
              setPage(1)
            }}
          />
        </label>

        <label>
          <span>To:</span>
          <input
            type="date"
            value={toDate}
            onChange={(event) => {
              setToDate(event.target.value)
              setPage(1)
            }}
          />
        </label>

        <UiButton
          variant="info"
          icon={<RefreshCw size={15} />}
          label={ledgerQuery.isFetching ? 'Refreshing…' : 'Refresh'}
          disabled={!productUlid || ledgerQuery.isFetching}
          onClick={() => void ledgerQuery.refetch()}
        />

        <UiButton
          variant="info"
          icon={<Eye size={15} />}
          label="Preview"
          disabled={!productUlid}
          onClick={previewPrint}
        />
      </div>

      <div className="product-ledger-summary">
        <span>Product <strong>{selectedProduct ? `${selectedProduct.product_number} · ${selectedProduct.name}` : '—'}</strong></span>
        <span>Warehouse <strong>{report?.warehouse.name ?? session?.warehouse.name ?? '—'}</strong></span>
        <span>Opening <strong>{qty(report?.opening_quantity)}</strong></span>
        <span>Qty In <strong>{qty(report?.meta.totals.quantity_in)}</strong></span>
        <span>Qty Out <strong>{qty(report?.meta.totals.quantity_out)}</strong></span>
        <span>Closing <strong>{qty(report?.meta.closing_quantity)}</strong></span>
      </div>

      <div className="product-ledger-grid">
        <PosDataGrid
          columns={columns}
          rows={report?.data ?? []}
          rowKey={(row) => row.ulid}
          emptyMessage={
            !productUlid
              ? 'Select a product to view its ledger.'
              : ledgerQuery.isLoading
                ? 'Loading product ledger…'
                : 'No stock movements found for this period.'
          }
        />
      </div>

      <footer className="product-ledger-footer">
        <div>{report?.meta.total ?? 0} movements</div>
        <div className="product-ledger-pager">
          <UiButton
            icon={<ChevronLeft size={14} />}
            label="Previous"
            disabled={(report?.meta.current_page ?? 1) <= 1 || ledgerQuery.isFetching}
            onClick={() => setPage((current) => Math.max(1, current - 1))}
          />
          <span>Page {report?.meta.current_page ?? 1} / {report?.meta.last_page ?? 1}</span>
          <UiButton
            icon={<ChevronRight size={14} />}
            label="Next"
            disabled={(report?.meta.current_page ?? 1) >= (report?.meta.last_page ?? 1) || ledgerQuery.isFetching}
            onClick={() => setPage((current) => current + 1)}
          />
        </div>
      </footer>
    </section>
  )
}
