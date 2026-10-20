import { useEffect, useMemo, useState } from 'react'
import { ArrowDown, ArrowUp, ChevronsUpDown, Eye, RefreshCw } from 'lucide-react'
import { fetchProducts } from '../api/catalog'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import {
  productViewCacheKey,
  readProductViewSnapshot,
  writeProductViewSnapshot,
  type ProductViewSnapshotMeta,
} from '../features/productView/productViewCache'
import type { Product, ProductBarcode, ProductPrice } from '../types/catalog'
import './ProductViewPage.css'

type ProductViewRow = {
  key: string
  product: Product
  barcode: ProductBarcode | null
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

function quantity(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') return '0'
  const parsed = Number.parseFloat(value)
  if (!Number.isFinite(parsed)) return value
  return parsed.toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 6,
  })
}

function activePrice(product: Product, type: ProductPrice['price_type']): ProductPrice | undefined {
  return product.prices?.find((price) => price.is_active && price.price_type === type)
}

function pricePeriod(price: ProductPrice): string {
  if (price.effective_from && price.effective_to) {
    return `${price.effective_from} – ${price.effective_to}`
  }
  if (price.effective_from) return `From ${price.effective_from}`
  if (price.effective_to) return `Until ${price.effective_to}`
  return 'Current'
}

function productDescription(row: ProductViewRow): string {
  const barcode = row.barcode?.barcode ?? row.product.primary_barcode
  return barcode ? `${barcode} * ${row.product.name}` : row.product.name
}

function expandedRows(products: Product[], singleProductRow: boolean): ProductViewRow[] {
  return products.flatMap((product) => {
    const activeBarcodes = product.barcodes?.filter((barcode) => barcode.is_active) ?? []

    if (singleProductRow || activeBarcodes.length <= 1) {
      const primary =
        activeBarcodes.find((barcode) => barcode.is_primary) ??
        activeBarcodes[0] ??
        null

      return [{
        key: `${product.ulid}:${primary?.ulid ?? 'product'}`,
        product,
        barcode: primary,
      }]
    }

    return activeBarcodes.map((barcode) => ({
      key: `${product.ulid}:${barcode.ulid}`,
      product,
      barcode,
    }))
  })
}

function sortValue(
  row: ProductViewRow,
  key: string,
  canViewInventory: boolean,
): string | number {
  switch (key) {
    case 'product_number':
      return row.product.product_number
    case 'description':
      return productDescription(row)
    case 'category':
      return row.product.category?.name ?? ''
    case 'company':
      return row.product.brand?.name ?? ''
    case 'supplier':
      return row.product.primary_supplier?.name ?? ''
    case 'location':
      return row.product.rack_location ?? ''
    case 'trade':
      return Number.parseFloat(activePrice(row.product, 'wholesale')?.amount ?? '0')
    case 'selling':
      return Number.parseFloat(activePrice(row.product, 'retail')?.amount ?? '0')
    case 'cost':
      return canViewInventory
        ? Number.parseFloat(row.product.sales_lookup?.average_cost ?? '0')
        : 0
    case 'reorder':
      return Number.parseFloat(row.product.reorder_level ?? '0')
    case 'stock':
      return Number.parseFloat(row.product.sales_lookup?.in_stock ?? '0')
    case 'stock_value_cost':
      return canViewInventory
        ? Number.parseFloat(row.product.sales_lookup?.stock_value ?? '0')
        : 0
    case 'minimum_sale':
      return Number.parseFloat(activePrice(row.product, 'minimum_sale')?.amount ?? '0')
    case 'stock_value_sale':
      return Number.parseFloat(row.product.sales_lookup?.retail_stock_value ?? '0')
    default:
      return ''
  }
}

export function ProductViewPage() {
  const { session } = useAuth()
  const canViewInventory = useCan('inventory.view')
  const [search, setSearch] = useState('')
  const [withBalance, setWithBalance] = useState(false)
  const [activeOnly, setActiveOnly] = useState(false)
  const [lessThanReorder, setLessThanReorder] = useState(false)
  const [singleProductRow, setSingleProductRow] = useState(true)
  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [columnFilters, setColumnFilters] = useState<Record<string, string>>({})
  const [sortState, setSortState] = useState<{ key: string; direction: 'asc' | 'desc' } | null>(null)
  const [products, setProducts] = useState<Product[]>([])
  const [meta, setMeta] = useState<ProductViewSnapshotMeta | undefined>(undefined)
  const [isSyncing, setIsSyncing] = useState(false)

  const cacheKey = session
    ? productViewCacheKey(
        session.tenant.ulid,
        session.membership.ulid,
        session.branch.ulid,
        session.warehouse.ulid,
        canViewInventory,
      )
    : null

  useEffect(() => {
    if (!cacheKey) return

    let cancelled = false

    void (async () => {
      const cached = await readProductViewSnapshot(cacheKey).catch(() => null)

      if (cancelled) return

      if (cached) {
        setProducts(cached.products)
        setMeta(cached.meta)
        return
      }

      setIsSyncing(true)

      try {
        const result = await fetchProducts(
          {
            lookup_all: true,
            sales_lookup: true,
          },
          { busy: 'none' },
        )

        if (cancelled) return

        const nextMeta = result.meta as ProductViewSnapshotMeta
        setProducts(result.data)
        setMeta(nextMeta)

        await writeProductViewSnapshot({
          key: cacheKey,
          products: result.data,
          meta: nextMeta,
          saved_at: new Date().toISOString(),
        }).catch(() => undefined)
      } finally {
        if (!cancelled) setIsSyncing(false)
      }
    })()

    return () => {
      cancelled = true
    }
  }, [cacheKey])

  async function refreshProducts() {
    if (!cacheKey || isSyncing) return

    setIsSyncing(true)

    try {
      const result = await fetchProducts(
        {
          lookup_all: true,
          sales_lookup: true,
        },
        { busy: 'none' },
      )

      const nextMeta = result.meta as ProductViewSnapshotMeta
      setProducts(result.data)
      setMeta(nextMeta)
      setSelectedKey(null)

      await writeProductViewSnapshot({
        key: cacheKey,
        products: result.data,
        meta: nextMeta,
        saved_at: new Date().toISOString(),
      }).catch(() => undefined)
    } finally {
      setIsSyncing(false)
    }
  }

  const rows = useMemo(
    () => expandedRows(products, singleProductRow),
    [products, singleProductRow],
  )
  const visibleRows = useMemo(() => {
    const globalTerm = search.trim().toLocaleLowerCase()
    const activeFilters = Object.entries(columnFilters)
      .map(([key, value]) => [key, value.trim().toLocaleLowerCase()] as const)
      .filter(([, value]) => value.length > 0)

    const filteredRows = rows.filter((row) => {
      if (withBalance && Number.parseFloat(row.product.sales_lookup?.in_stock ?? '0') === 0) {
        return false
      }

      if (activeOnly && !row.product.is_active) {
        return false
      }

      if (lessThanReorder) {
        const reorder = row.product.reorder_level
        if (reorder === null || reorder === undefined || reorder === '') return false

        const stock = Number.parseFloat(row.product.sales_lookup?.in_stock ?? '0')
        const reorderValue = Number.parseFloat(reorder)

        if (!Number.isFinite(reorderValue) || stock > reorderValue) return false
      }

      if (globalTerm) {
        const barcodes = row.product.barcodes?.map((barcode) => barcode.barcode).join(' ') ?? ''
        const globalValue = [
          row.product.product_number,
          row.product.sku ?? '',
          row.product.name,
          barcodes,
        ].join(' ').toLocaleLowerCase()

        if (!globalValue.includes(globalTerm)) return false
      }

      return activeFilters.every(([key, filter]) => {
      let value = ''

      switch (key) {
        case 'product_number':
          value = row.product.product_number
          break
        case 'description':
          value = productDescription(row)
          break
        case 'category':
          value = row.product.category?.name ?? ''
          break
        case 'company':
          value = row.product.brand?.name ?? ''
          break
        case 'supplier':
          value = row.product.primary_supplier?.name ?? ''
          break
        case 'location':
          value = row.product.rack_location ?? ''
          break
        case 'trade':
          value = money(activePrice(row.product, 'wholesale')?.amount)
          break
        case 'selling':
          value = money(activePrice(row.product, 'retail')?.amount)
          break
        case 'cost':
          value = canViewInventory ? money(row.product.sales_lookup?.average_cost) : ''
          break
        case 'reorder':
          value = quantity(row.product.reorder_level)
          break
        case 'stock':
          value = quantity(row.product.sales_lookup?.in_stock)
          break
        case 'stock_value_cost':
          value = canViewInventory ? money(row.product.sales_lookup?.stock_value) : ''
          break
        case 'minimum_sale':
          value = money(activePrice(row.product, 'minimum_sale')?.amount)
          break
        case 'stock_value_sale':
          value = money(row.product.sales_lookup?.retail_stock_value)
          break
        default:
          value = ''
      }

        return value.toLocaleLowerCase().includes(filter)
      })
    })

    if (!sortState) return filteredRows

    const sortedRows = [...filteredRows]
    const direction = sortState.direction === 'asc' ? 1 : -1

    sortedRows.sort((left, right) => {
      const leftValue = sortValue(left, sortState.key, canViewInventory)
      const rightValue = sortValue(right, sortState.key, canViewInventory)

      if (typeof leftValue === 'number' && typeof rightValue === 'number') {
        return (leftValue - rightValue) * direction
      }

      return String(leftValue).localeCompare(String(rightValue), undefined, {
        numeric: true,
        sensitivity: 'base',
      }) * direction
    })

    return sortedRows
  }, [
    activeOnly,
    canViewInventory,
    columnFilters,
    lessThanReorder,
    rows,
    search,
    sortState,
    withBalance,
  ])

  useEffect(() => {
    if (visibleRows.length === 0) {
      setSelectedKey(null)
      return
    }

    if (!selectedKey || !visibleRows.some((row) => row.key === selectedKey)) {
      setSelectedKey(visibleRows[0].key)
    }
  }, [selectedKey, visibleRows])

  const selectedRow = visibleRows.find((row) => row.key === selectedKey) ?? null
  const selectedProduct = selectedRow?.product ?? null

  function setColumnFilter(key: string, value: string) {
    setColumnFilters((current) => ({ ...current, [key]: value }))
    setSelectedKey(null)
  }

  function toggleSort(key: string) {
    setSortState((current) => {
      if (!current || current.key !== key) {
        return { key, direction: 'asc' }
      }

      if (current.direction === 'asc') {
        return { key, direction: 'desc' }
      }

      return null
    })
  }

  function sortHeader(key: string, label: string) {
    const direction = sortState?.key === key ? sortState.direction : null
    const icon = direction === 'asc'
      ? <ArrowUp size={11} />
      : direction === 'desc'
        ? <ArrowDown size={11} />
        : <ChevronsUpDown size={11} />

    return (
      <button
        type="button"
        className="product-view-sort-header"
        onClick={() => toggleSort(key)}
        aria-label={`Sort ${label} ${direction ?? 'normal'}`}
        title={direction === 'asc' ? 'Ascending' : direction === 'desc' ? 'Descending' : 'Normal'}
      >
        <span>{label}</span>
        {icon}
      </button>
    )
  }

  function filterInput(key: string, label: string) {
    return (
      <input
        className="product-view-column-filter"
        value={columnFilters[key] ?? ''}
        onChange={(event) => setColumnFilter(key, event.target.value)}
        aria-label={`Filter ${label}`}
        title={`Filter ${label}`}
        autoComplete="off"
      />
    )
  }

  const columns = useMemo<PosGridColumn<ProductViewRow>[]>(() => [
    {
      key: 'product_number',
      header: sortHeader('product_number', 'Product #'),
      width: '5%',
      filter: filterInput('product_number', 'Product #'),
      render: (row) => row.product.product_number,
    },
    {
      key: 'description',
      header: sortHeader('description', 'Description'),
      width: '20.5%',
      filter: filterInput('description', 'Description'),
      render: (row) => {
        const description = productDescription(row)
        return (
          <span className="product-view-description-cell" title={description}>
            {description}
          </span>
        )
      },
    },
    {
      key: 'category',
      header: sortHeader('category', 'Category'),
      width: '7.5%',
      filter: filterInput('category', 'Category'),
      render: (row) => row.product.category?.name ?? '—',
    },
    {
      key: 'company',
      header: sortHeader('company', 'Company'),
      width: '7.5%',
      filter: filterInput('company', 'Company'),
      render: (row) => row.product.brand?.name ?? '—',
    },
    {
      key: 'supplier',
      header: sortHeader('supplier', 'Supplier'),
      width: '7.5%',
      filter: filterInput('supplier', 'Supplier'),
      render: (row) => row.product.primary_supplier?.name ?? '—',
    },
    {
      key: 'location',
      header: sortHeader('location', 'Location'),
      width: '5.5%',
      filter: filterInput('location', 'Location'),
      render: (row) => row.product.rack_location ?? '—',
    },
    {
      key: 'trade',
      header: sortHeader('trade', 'Trade Price'),
      width: '5.5%',
      align: 'right',
      filter: filterInput('trade', 'Trade Price'),
      render: (row) => money(activePrice(row.product, 'wholesale')?.amount),
    },
    {
      key: 'selling',
      header: sortHeader('selling', 'Selling'),
      width: '5.5%',
      align: 'right',
      filter: filterInput('selling', 'Selling'),
      render: (row) => money(activePrice(row.product, 'retail')?.amount),
    },
    {
      key: 'cost',
      header: sortHeader('cost', 'Cost Price'),
      width: '5.5%',
      align: 'right',
      filter: filterInput('cost', 'Cost Price'),
      render: (row) => canViewInventory ? money(row.product.sales_lookup?.average_cost) : '—',
    },
    {
      key: 'reorder',
      header: sortHeader('reorder', 'Re Order'),
      width: '5%',
      align: 'right',
      filter: filterInput('reorder', 'Re Order'),
      render: (row) => quantity(row.product.reorder_level),
    },
    {
      key: 'stock',
      header: sortHeader('stock', 'In Stock'),
      width: '5%',
      align: 'right',
      filter: filterInput('stock', 'In Stock'),
      render: (row) => {
        const amount = Number.parseFloat(row.product.sales_lookup?.in_stock ?? '0')
        return (
          <span className={amount > 0 ? 'product-view-stock-cell is-positive' : 'product-view-stock-cell is-empty'}>
            {quantity(row.product.sales_lookup?.in_stock)}
          </span>
        )
      },
    },
    {
      key: 'stock_value_cost',
      header: sortHeader('stock_value_cost', 'Stock Val (Cost)'),
      width: '7%',
      align: 'right',
      filter: filterInput('stock_value_cost', 'Stock Val (Cost)'),
      render: (row) => (
        <span className="product-view-stock-cell is-value">
          {canViewInventory ? money(row.product.sales_lookup?.stock_value) : '—'}
        </span>
      ),
    },
    {
      key: 'minimum_sale',
      header: sortHeader('minimum_sale', 'Min Sale'),
      width: '5.5%',
      align: 'right',
      filter: filterInput('minimum_sale', 'Min Sale'),
      render: (row) => money(activePrice(row.product, 'minimum_sale')?.amount),
    },
    {
      key: 'stock_value_sale',
      header: sortHeader('stock_value_sale', 'Stock Val (Sale)'),
      width: '7.5%',
      align: 'right',
      filter: filterInput('stock_value_sale', 'Stock Val (Sale)'),
      render: (row) => (
        <span className="product-view-stock-cell is-value">
          {money(row.product.sales_lookup?.retail_stock_value)}
        </span>
      ),
    },
  ], [canViewInventory, columnFilters, sortState])

  const saleRates = (selectedProduct?.prices ?? []).filter((price) => price.is_active)

  function resetView() {
    setSelectedKey(null)
  }

  function previewPrint() {
    const root = document.documentElement
    const cleanup = () => {
      root.removeAttribute('data-product-view-print')
      window.removeEventListener('afterprint', cleanup)
    }

    root.setAttribute('data-product-view-print', 'true')
    window.addEventListener('afterprint', cleanup, { once: true })

    window.requestAnimationFrame(() => {
      window.print()
    })
  }

  const filterSummary = [
    withBalance ? 'Having Balance' : null,
    activeOnly ? 'Active Only' : null,
    lessThanReorder ? 'Less than Reorder' : null,
    singleProductRow ? 'Single row per product' : 'Barcode rows',
    search.trim() ? `Search: "${search.trim()}"` : null,
  ].filter(Boolean).join(' · ')

  const generatedAt = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date())

  return (
    <section className="product-view-page" aria-label="Product View">
      <header className="product-view-print-header" aria-hidden="true">
        <div>
          <h1>Product View</h1>
          <p>
            {session?.tenant.name ?? 'BluePOS'} · {session?.branch.code ?? '—'} — {session?.branch.name ?? '—'} · {session?.warehouse.name ?? '—'}
          </p>
        </div>
        <div className="product-view-print-meta">
          <span>{filterSummary || 'All products'}</span>
          <span>Generated {generatedAt}</span>
        </div>
      </header>

      <div className="product-view-toolbar">
        <UiButton
          variant="info"
          icon={<RefreshCw size={15} />}
          label={isSyncing ? 'Refreshing…' : 'Refresh'}
          disabled={isSyncing}
          onClick={() => void refreshProducts()}
        />

        <label className="product-view-filter">
          <input
            type="checkbox"
            checked={withBalance}
            onChange={(event) => {
              setWithBalance(event.target.checked)
              resetView()
            }}
          />
          <span>Having Balance</span>
        </label>

        <label className="product-view-filter">
          <input
            type="checkbox"
            checked={activeOnly}
            onChange={(event) => {
              setActiveOnly(event.target.checked)
              resetView()
            }}
          />
          <span>Active Only</span>
        </label>

        <label className="product-view-filter">
          <input
            type="checkbox"
            checked={lessThanReorder}
            onChange={(event) => {
              setLessThanReorder(event.target.checked)
              resetView()
            }}
          />
          <span>Less than Reorder</span>
        </label>

        <label className="product-view-filter">
          <input
            type="checkbox"
            checked={singleProductRow}
            onChange={(event) => {
              setSingleProductRow(event.target.checked)
              setSelectedKey(null)
            }}
          />
          <span>Show Product Single Time if Having More than 1 Barcode</span>
        </label>

        <label className="product-view-search">
          <span className="sr-only">Search products</span>
          <input
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              resetView()
            }}
            placeholder="Search product, barcode, SKU…"
            autoComplete="off"
          />
        </label>

        <UiButton
          variant="info"
          icon={<Eye size={15} />}
          label="Preview"
          onClick={previewPrint}
        />
      </div>

      <div className="product-view-workspace">
        <div className="product-view-grid">
          <PosDataGrid
            columns={columns}
            rows={visibleRows}
            rowKey={(row) => row.key}
            selectedKey={selectedKey}
            onSelect={(row) => setSelectedKey(row.key)}
            emptyMessage="No products found"
          />
        </div>

        <aside className="product-view-side" aria-label="Selected product details">
          <section className="product-view-side-card">
            <h3>Sale Rate Variations</h3>
            <div className="product-view-mini-grid">
              <div className="product-view-mini-head">Period / Type</div>
              <div className="product-view-mini-head is-num">Rate</div>
              {saleRates.length > 0 ? saleRates.map((price) => (
                <div className="product-view-mini-row" key={price.ulid}>
                  <span>
                    {price.price_type.replace('_', ' ')} · {pricePeriod(price)}
                  </span>
                  <strong>{money(price.amount)}</strong>
                </div>
              )) : (
                <div className="product-view-mini-empty">No selected product rates</div>
              )}
            </div>
          </section>

          <section className="product-view-side-card">
            <h3>Store Stock</h3>
            <div className="product-view-store">
              <span>Active warehouse</span>
              <strong>{quantity(selectedProduct?.sales_lookup?.in_stock)}</strong>
            </div>
          </section>

          <section className="product-view-side-card">
            <h3>Purchase Rate Variations</h3>
            <div className="product-view-mini-grid">
              <div className="product-view-mini-head">Period</div>
              <div className="product-view-mini-head is-num">Rate</div>
              {selectedProduct && canViewInventory && selectedProduct.sales_lookup?.average_cost ? (
                <div className="product-view-mini-row">
                  <span>Current average cost</span>
                  <strong>{money(selectedProduct.sales_lookup.average_cost)}</strong>
                </div>
              ) : (
                <div className="product-view-mini-empty">No purchase rate history available</div>
              )}
            </div>
          </section>
        </aside>
      </div>

      <footer className="product-view-footer">
        <div className="product-view-counts">
          <strong>{meta?.total ?? 0}</strong> products
          {Object.values(columnFilters).some((value) => value.trim()) ? <span> · {visibleRows.length} matching loaded rows</span> : null}
          {!singleProductRow ? <span> · {visibleRows.length} displayed barcode rows</span> : null}
        </div>

        <div className="product-view-totals">
          <span>In Stock <strong>{quantity(meta?.stock_total)}</strong></span>
          <span>Stock Val (Cost) <strong>{canViewInventory ? money(meta?.stock_value_total) : '—'}</strong></span>
          <span>Stock Val (Sale) <strong>{money(meta?.retail_stock_value_total)}</strong></span>
        </div>

        <div className="product-view-pager">
          <span>{products.length} products cached locally</span>
        </div>
      </footer>
    </section>
  )
}
