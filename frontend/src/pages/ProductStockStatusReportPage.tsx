import { useDeferredValue, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Eye, RefreshCw } from 'lucide-react'
import { fetchProductStockStatusReport } from '../api/inventory'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { useAuth } from '../features/auth/AuthProvider'
import type { ProductStockStatusRow } from '../types/inventory'
import './ProductStockStatusReportPage.css'

function money(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const parsed = Number.parseFloat(value)
  if (!Number.isFinite(parsed)) return value
  return parsed.toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
  })
}

function quantity(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '0'
  const parsed = Number.parseFloat(value)
  if (!Number.isFinite(parsed)) return value
  return parsed.toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 6,
  })
}

export function ProductStockStatusReportPage() {
  const { session } = useAuth()
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [activeOnly, setActiveOnly] = useState(true)
  const [withBalance, setWithBalance] = useState(false)
  const [lowStock, setLowStock] = useState(false)
  const [page, setPage] = useState(1)

  const reportQuery = useQuery({
    queryKey: [
      'reports',
      'product-stock-status',
      deferredSearch,
      activeOnly,
      withBalance,
      lowStock,
      page,
    ],
    queryFn: () => fetchProductStockStatusReport({
      q: deferredSearch.trim() || undefined,
      active_only: activeOnly,
      with_balance: withBalance,
      low_stock: lowStock,
      page,
      per_page: 100,
    }),
  })

  const report = reportQuery.data

  const columns = useMemo<PosGridColumn<ProductStockStatusRow>[]>(() => [
    {
      key: 'product_number',
      header: 'Product #',
      width: 90,
      render: (row) => row.product.product_number,
    },
    {
      key: 'description',
      header: 'Description',
      width: '26%',
      render: (row) => row.product.name,
    },
    {
      key: 'category',
      header: 'Category',
      width: 130,
      render: (row) => row.product.category?.name ?? '—',
    },
    {
      key: 'brand',
      header: 'Brand',
      width: 120,
      render: (row) => row.product.brand?.name ?? '—',
    },
    {
      key: 'reorder',
      header: 'Re Order',
      width: 88,
      align: 'right',
      render: (row) => quantity(row.reorder_level),
    },
    {
      key: 'stock',
      header: 'In Stock',
      width: 92,
      align: 'right',
      render: (row) => quantity(row.quantity),
    },
    {
      key: 'cost',
      header: 'Avg Cost',
      width: 98,
      align: 'right',
      render: (row) => money(row.average_cost),
    },
    {
      key: 'stock_value',
      header: 'Stock Value',
      width: 112,
      align: 'right',
      render: (row) => money(row.stock_value),
    },
    {
      key: 'retail_rate',
      header: 'Retail Rate',
      width: 98,
      align: 'right',
      render: (row) => money(row.retail_rate),
    },
    {
      key: 'retail_value',
      header: 'Retail Value',
      width: 112,
      align: 'right',
      render: (row) => money(row.retail_stock_value),
    },
  ], [])

  function resetPage() {
    setPage(1)
  }

  function previewPrint() {
    const root = window.document.documentElement
    const cleanup = () => {
      root.removeAttribute('data-product-stock-status-print')
      window.removeEventListener('afterprint', cleanup)
    }

    root.setAttribute('data-product-stock-status-print', 'true')
    window.addEventListener('afterprint', cleanup, { once: true })
    window.requestAnimationFrame(() => window.print())
  }

  const generatedAt = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date())

  const filterSummary = [
    activeOnly ? 'Active Only' : null,
    withBalance ? 'Having Balance' : null,
    lowStock ? 'At / Below Reorder' : null,
    deferredSearch.trim() ? `Search: "${deferredSearch.trim()}"` : null,
  ].filter(Boolean).join(' · ')

  return (
    <section className="product-stock-status-page" aria-label="Stock Status Report">
      <header className="product-stock-status-print-header" aria-hidden="true">
        <div>
          <h1>Stock Status</h1>
          <p>
            {session?.tenant.name ?? 'BluePOS'} · {session?.branch.code ?? '—'} — {session?.branch.name ?? '—'} · {report?.meta.warehouse.name ?? session?.warehouse.name ?? '—'}
          </p>
        </div>
        <div className="product-stock-status-print-meta">
          <span>{filterSummary || 'All products'}</span>
          <span>Generated {generatedAt}</span>
        </div>
      </header>

      <div className="product-stock-status-toolbar">
        <UiButton
          variant="info"
          icon={<RefreshCw size={15} />}
          label={reportQuery.isFetching ? 'Refreshing…' : 'Refresh'}
          disabled={reportQuery.isFetching}
          onClick={() => void reportQuery.refetch()}
        />

        <label>
          <input
            type="checkbox"
            checked={activeOnly}
            onChange={(event) => {
              setActiveOnly(event.target.checked)
              resetPage()
            }}
          />
          <span>Active Only</span>
        </label>

        <label>
          <input
            type="checkbox"
            checked={withBalance}
            onChange={(event) => {
              setWithBalance(event.target.checked)
              resetPage()
            }}
          />
          <span>Having Balance</span>
        </label>

        <label>
          <input
            type="checkbox"
            checked={lowStock}
            onChange={(event) => {
              setLowStock(event.target.checked)
              resetPage()
            }}
          />
          <span>At / Below Reorder</span>
        </label>

        <div className="product-stock-status-warehouse">
          Warehouse: <strong>{report?.meta.warehouse.name ?? session?.warehouse.name ?? '—'}</strong>
        </div>

        <input
          className="product-stock-status-search"
          value={search}
          onChange={(event) => {
            setSearch(event.target.value)
            resetPage()
          }}
          placeholder="Search product, SKU or barcode…"
          autoComplete="off"
        />

        <UiButton
          variant="info"
          icon={<Eye size={15} />}
          label="Preview"
          onClick={previewPrint}
        />
      </div>

      <div className="product-stock-status-grid">
        <PosDataGrid
          columns={columns}
          rows={report?.data ?? []}
          rowKey={(row) => row.product.ulid}
          emptyMessage={reportQuery.isLoading ? 'Loading stock status…' : 'No stock rows found.'}
        />
      </div>

      <footer className="product-stock-status-footer">
        <div>
          <strong>{report?.meta.total ?? 0}</strong> products
        </div>

        <div className="product-stock-status-totals">
          <span>In Stock <strong>{quantity(report?.meta.totals.quantity)}</strong></span>
          <span>Stock Value <strong>{money(report?.meta.totals.stock_value)}</strong></span>
          <span>Retail Value <strong>{money(report?.meta.totals.retail_stock_value)}</strong></span>
        </div>

        <div className="product-stock-status-pager">
          <UiButton
            icon={<ChevronLeft size={14} />}
            label="Previous"
            disabled={(report?.meta.current_page ?? 1) <= 1 || reportQuery.isFetching}
            onClick={() => setPage((current) => Math.max(1, current - 1))}
          />
          <span>Page {report?.meta.current_page ?? 1} / {report?.meta.last_page ?? 1}</span>
          <UiButton
            icon={<ChevronRight size={14} />}
            label="Next"
            disabled={(report?.meta.current_page ?? 1) >= (report?.meta.last_page ?? 1) || reportQuery.isFetching}
            onClick={() => setPage((current) => current + 1)}
          />
        </div>
      </footer>
    </section>
  )
}
