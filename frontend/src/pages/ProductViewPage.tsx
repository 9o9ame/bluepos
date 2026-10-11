import { useDeferredValue, useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Eye, RefreshCw } from 'lucide-react'
import { fetchProducts, fetchProductStock } from '../api/catalog'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { useCan } from '../features/auth/useCan'
import type { Product, ProductBarcode, ProductPrice } from '../types/catalog'
import './ProductViewPage.css'

type ProductViewRow = {
  key: string
  product: Product
  barcode: ProductBarcode | null
}

type ProductViewMeta = {
  current_page: number
  per_page: number
  total: number
  last_page: number
  stock_total?: string | null
  stock_value_total?: string | null
  retail_stock_value_total?: string | null
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

export function ProductViewPage() {
  const canViewInventory = useCan('inventory.view')
  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [page, setPage] = useState(1)
  const [withBalance, setWithBalance] = useState(false)
  const [activeOnly, setActiveOnly] = useState(false)
  const [lessThanReorder, setLessThanReorder] = useState(false)
  const [singleProductRow, setSingleProductRow] = useState(true)
  const [selectedKey, setSelectedKey] = useState<string | null>(null)

  const productsQuery = useQuery({
    queryKey: [
      'products',
      'daily-product-view',
      deferredSearch,
      page,
      withBalance,
      activeOnly,
      lessThanReorder,
    ],
    queryFn: () =>
      fetchProducts(
        {
          q: deferredSearch.trim() || undefined,
          page,
          per_page: 100,
          sales_lookup: true,
          with_balance: withBalance,
          active_only: activeOnly,
          stock_le_reorder: lessThanReorder,
        },
        { busy: 'none' },
      ),
  })

  const products = productsQuery.data?.data ?? []
  const rows = useMemo(
    () => expandedRows(products, singleProductRow),
    [products, singleProductRow],
  )
  const meta = productsQuery.data?.meta as ProductViewMeta | undefined

  useEffect(() => {
    if (rows.length === 0) {
      setSelectedKey(null)
      return
    }

    if (!selectedKey || !rows.some((row) => row.key === selectedKey)) {
      setSelectedKey(rows[0].key)
    }
  }, [rows, selectedKey])

  const selectedRow = rows.find((row) => row.key === selectedKey) ?? null
  const selectedProduct = selectedRow?.product ?? null

  const stockQuery = useQuery({
    queryKey: ['product-stock', 'daily-product-view', selectedProduct?.ulid],
    queryFn: () => fetchProductStock(selectedProduct!.ulid),
    enabled: Boolean(selectedProduct),
  })

  const columns = useMemo<PosGridColumn<ProductViewRow>[]>(() => [
    {
      key: 'product_number',
      header: 'Product #',
      width: 76,
      render: (row) => row.product.product_number,
    },
    {
      key: 'description',
      header: 'Description',
      width: 260,
      render: (row) => {
        const barcode = row.barcode?.barcode ?? row.product.primary_barcode
        return barcode ? `${barcode} * ${row.product.name}` : row.product.name
      },
    },
    {
      key: 'category',
      header: 'Category',
      width: 110,
      render: (row) => row.product.category?.name ?? '—',
    },
    {
      key: 'company',
      header: 'Company',
      width: 110,
      render: (row) => row.product.brand?.name ?? '—',
    },
    {
      key: 'supplier',
      header: 'Supplier',
      width: 125,
      render: (row) => row.product.primary_supplier?.name ?? '—',
    },
    {
      key: 'location',
      header: 'Location',
      width: 90,
      render: (row) => row.product.rack_location ?? '—',
    },
    {
      key: 'trade',
      header: 'Trade Price',
      width: 86,
      align: 'right',
      render: (row) => money(activePrice(row.product, 'wholesale')?.amount),
    },
    {
      key: 'selling',
      header: 'Selling',
      width: 82,
      align: 'right',
      render: (row) => money(activePrice(row.product, 'retail')?.amount),
    },
    {
      key: 'cost',
      header: 'Cost Price',
      width: 88,
      align: 'right',
      render: (row) => canViewInventory ? money(row.product.sales_lookup?.average_cost) : '—',
    },
    {
      key: 'reorder',
      header: 'Re Order',
      width: 76,
      align: 'right',
      render: (row) => quantity(row.product.reorder_level),
    },
    {
      key: 'stock',
      header: 'In Stock',
      width: 82,
      align: 'right',
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
      header: 'Stock Val (Cost)',
      width: 110,
      align: 'right',
      render: (row) => (
        <span className="product-view-stock-cell is-value">
          {canViewInventory ? money(row.product.sales_lookup?.stock_value) : '—'}
        </span>
      ),
    },
    {
      key: 'minimum_sale',
      header: 'Min Sale',
      width: 82,
      align: 'right',
      render: (row) => money(activePrice(row.product, 'minimum_sale')?.amount),
    },
    {
      key: 'stock_value_sale',
      header: 'Stock Val (Sale)',
      width: 112,
      align: 'right',
      render: (row) => (
        <span className="product-view-stock-cell is-value">
          {money(row.product.sales_lookup?.retail_stock_value)}
        </span>
      ),
    },
  ], [canViewInventory])

  const saleRates = (selectedProduct?.prices ?? []).filter((price) => price.is_active)
  const activeWarehouse = stockQuery.data?.active_warehouse

  function resetPage() {
    setPage(1)
    setSelectedKey(null)
  }

  return (
    <section className="product-view-page" aria-label="Product View">
      <div className="product-view-toolbar">
        <UiButton
          variant="info"
          icon={<RefreshCw size={15} />}
          label={productsQuery.isFetching ? 'Refreshing…' : 'Refresh'}
          disabled={productsQuery.isFetching}
          onClick={() => void productsQuery.refetch()}
        />

        <label className="product-view-filter">
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

        <label className="product-view-filter">
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

        <label className="product-view-filter">
          <input
            type="checkbox"
            checked={lessThanReorder}
            onChange={(event) => {
              setLessThanReorder(event.target.checked)
              resetPage()
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
              resetPage()
            }}
            placeholder="Search product, barcode, SKU…"
            autoComplete="off"
          />
        </label>

        <UiButton
          variant="info"
          icon={<Eye size={15} />}
          label="Preview"
          onClick={() => window.print()}
        />
      </div>

      <div className="product-view-workspace">
        <div className="product-view-grid">
          <PosDataGrid
            columns={columns}
            rows={rows}
            rowKey={(row) => row.key}
            selectedKey={selectedKey}
            onSelect={(row) => setSelectedKey(row.key)}
            emptyMessage={productsQuery.isLoading ? 'Loading products…' : 'No products found'}
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
              <span>{activeWarehouse?.name ?? 'Current warehouse'}</span>
              <strong>{quantity(activeWarehouse?.quantity)}</strong>
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
          {!singleProductRow ? <span> · {rows.length} displayed barcode rows</span> : null}
        </div>

        <div className="product-view-totals">
          <span>In Stock <strong>{quantity(meta?.stock_total)}</strong></span>
          <span>Stock Val (Cost) <strong>{canViewInventory ? money(meta?.stock_value_total) : '—'}</strong></span>
          <span>Stock Val (Sale) <strong>{money(meta?.retail_stock_value_total)}</strong></span>
        </div>

        <div className="product-view-pager">
          <UiButton
            variant="default"
            icon={<ChevronLeft size={14} />}
            label="Previous"
            disabled={(meta?.current_page ?? 1) <= 1 || productsQuery.isFetching}
            onClick={() => {
              setPage((current) => Math.max(1, current - 1))
              setSelectedKey(null)
            }}
          />
          <span>Page {meta?.current_page ?? 1} / {meta?.last_page ?? 1}</span>
          <UiButton
            variant="default"
            icon={<ChevronRight size={14} />}
            label="Next"
            disabled={(meta?.current_page ?? 1) >= (meta?.last_page ?? 1) || productsQuery.isFetching}
            onClick={() => {
              setPage((current) => current + 1)
              setSelectedKey(null)
            }}
          />
        </div>
      </footer>
    </section>
  )
}
