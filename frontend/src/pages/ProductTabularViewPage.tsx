import { useDeferredValue, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, RefreshCw, Save, X } from 'lucide-react'
import { fetchProducts, saveProductPrices, updateProduct } from '../api/catalog'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect, type UiSelectOption } from '../components/ui/UiSelect'
import { useCan } from '../features/auth/useCan'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import type { Product } from '../types/catalog'
import './ProductTabularViewPage.css'

type BulkField = 'reorder_level' | 'wholesale_price' | 'minimum_sale_price' | 'retail_price'
type PriceBulkField = Exclude<BulkField, 'reorder_level'>
type PriceType = 'retail' | 'wholesale' | 'minimum_sale'
type ProductDraft = {
  product?: { reorder_level?: string | null }
  prices?: Partial<Record<PriceType, string>>
}

const BULK_FIELD_OPTIONS: UiSelectOption[] = [
  {
    value: 'discount_percent',
    label: 'Discount (%)',
    disabled: true,
    title: 'BluePOS does not currently have a product-level percentage discount field.',
  },
  {
    value: 'discount_amount',
    label: 'Discount (Rs)',
    disabled: true,
    title: 'BluePOS does not currently have a product-level amount discount field.',
  },
  { value: 'reorder_level', label: 'Re Order Level' },
  { value: 'wholesale_price', label: 'Trade Price' },
  { value: 'minimum_sale_price', label: 'Sale Man Price' },
  { value: 'retail_price', label: 'Sale Price' },
  {
    value: 'purchase_price',
    label: 'Purchase Price',
    disabled: true,
    title: 'Purchase cost comes from inventory valuation and is not directly editable here.',
  },
]

const SET_TEXT_PRESETS: UiSelectOption[] = [
  {
    value: 'last_price_plus_10',
    label: '=LastPrice + (LastPrice * 10.0 / 100)',
    disabled: true,
    title: 'BluePOS does not currently store a LastPrice product field.',
  },
  {
    value: 'trade_price_plus_10',
    label: '=TradePrice + (TradePrice * 10.0 / 100)',
    title: 'For Sale Price: set retail price to Trade Price plus 10%.',
  },
]

const DECIMAL_6 = /^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/
const MONEY_4 = /^(?:0|[1-9]\d*)(?:\.\d{1,4})?$/

function priceTypeForBulkField(field: PriceBulkField): PriceType {
  if (field === 'wholesale_price') return 'wholesale'
  if (field === 'minimum_sale_price') return 'minimum_sale'
  return 'retail'
}

function retailPrice(product: Product): string | null {
  return product.prices?.find((price) => price.is_active && price.price_type === 'retail')?.amount ?? null
}

function formatDecimal(value: string | null | undefined, digits = 2): string {
  if (value === null || value === undefined || value === '') return '—'
  const parsed = Number.parseFloat(value)
  return Number.isFinite(parsed) ? parsed.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: digits }) : value
}

function marginPercent(product: Product): string {
  const cost = Number.parseFloat(product.sales_lookup?.average_cost ?? '')
  const sale = Number.parseFloat(retailPrice(product) ?? '')
  if (!Number.isFinite(cost) || !Number.isFinite(sale) || cost === 0) return '—'
  return (((sale - cost) / cost) * 100).toFixed(2)
}

function csvCell(value: string): string {
  return `"${value.replace(/"/g, '""')}"`
}

export function ProductTabularViewPage() {
  const queryClient = useQueryClient()
  const feedback = useFeedback()
  const { closeActiveTab } = useWorkspace()
  const canEdit = useCan('products.edit')
  const canPrices = useCan('products.manage_prices')
  const canViewStock = useCan('inventory.view')

  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [page, setPage] = useState(1)
  const [activeOnly, setActiveOnly] = useState(false)
  const [withBalance, setWithBalance] = useState(false)
  const [stockLeReorder, setStockLeReorder] = useState(false)
  const [purchaseRateGeSaleRate, setPurchaseRateGeSaleRate] = useState(false)
  const [fieldToUpdate, setFieldToUpdate] = useState<BulkField>('reorder_level')
  const [setText, setSetText] = useState('')
  const [setTextPreset, setSetTextPreset] = useState('')
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [activeKey, setActiveKey] = useState<string | null>(null)
  const [drafts, setDrafts] = useState<Record<string, ProductDraft>>({})

  const productsQuery = useQuery({
    queryKey: [
      'products',
      'tabular-view',
      deferredSearch,
      page,
      activeOnly,
      withBalance,
      stockLeReorder,
      purchaseRateGeSaleRate,
    ],
    queryFn: () =>
      fetchProducts(
        {
          q: deferredSearch.trim() || undefined,
          page,
          per_page: 50,
          sales_lookup: true,
          active_only: activeOnly,
          with_balance: withBalance,
          stock_le_reorder: stockLeReorder,
          purchase_rate_ge_sale_rate: canViewStock && purchaseRateGeSaleRate,
        },
        { busy: 'none' },
      ),
  })

  const rows = productsQuery.data?.data ?? []
  const meta = productsQuery.data?.meta

  const columns = useMemo<PosGridColumn<Product>[]>(() => [
    {
      key: 'selected',
      header: '',
      width: 34,
      align: 'center',
      render: (product) => (
        <input
          type="checkbox"
          checked={selected.has(product.ulid)}
          aria-label={`Select ${product.name}`}
          onClick={(event) => event.stopPropagation()}
          onChange={(event) => {
            setSelected((current) => {
              const next = new Set(current)
              if (event.target.checked) next.add(product.ulid)
              else next.delete(product.ulid)
              return next
            })
          }}
        />
      ),
    },
    {
      key: 'product_number',
      header: 'Product #',
      width: 84,
      render: (product) => product.product_number,
    },
    {
      key: 'code',
      header: 'Code / Barcode',
      width: 130,
      render: (product) => product.primary_barcode ?? product.sku ?? '—',
    },
    {
      key: 'description',
      header: 'Description',
      width: '28%',
      render: (product) => (
        <span className={drafts[product.ulid] ? 'product-tabular-staged' : undefined}>
          {product.name}
        </span>
      ),
    },
    {
      key: 'manufacturer',
      header: 'Manufacturer',
      width: '15%',
      render: (product) => product.brand?.name ?? '—',
    },
    {
      key: 'category',
      header: 'Category',
      width: '15%',
      render: (product) => product.category?.name ?? '—',
    },
    {
      key: 'unit',
      header: 'U.O.M',
      width: 82,
      render: (product) => product.base_unit?.symbol ?? product.base_unit?.code ?? '—',
    },
    {
      key: 'cost',
      header: 'Cost Price',
      width: 96,
      align: 'right',
      render: (product) => canViewStock ? formatDecimal(product.sales_lookup?.average_cost, 4) : '—',
    },
    {
      key: 'margin',
      header: 'Margin %',
      width: 82,
      align: 'right',
      render: (product) => canViewStock ? marginPercent(product) : '—',
    },
    {
      key: 'selling',
      header: 'Selling',
      width: 96,
      align: 'right',
      render: (product) => formatDecimal(retailPrice(product), 4),
    },
    {
      key: 'stock',
      header: 'In Stock',
      width: 96,
      align: 'right',
      render: (product) => {
        const quantity = product.sales_lookup?.in_stock ?? '0'
        const numeric = Number.parseFloat(quantity)
        return (
          <span className={numeric > 0 ? 'product-tabular-stock is-positive' : 'product-tabular-stock is-empty'}>
            {formatDecimal(quantity, 6)}
          </span>
        )
      },
    },
  ], [canViewStock, drafts, selected])

  function resetToFirstPage() {
    setPage(1)
  }

  function applySetText() {
    const targets = selected.size > 0
      ? [...selected]
      : activeKey
        ? [activeKey]
        : []

    if (targets.length === 0) {
      feedback.info('Select at least one product row first.', 'Tabular View')
      return
    }

    if (fieldToUpdate !== 'reorder_level' && !canPrices) {
      feedback.error('You do not have permission to manage product prices.', 'Tabular View')
      return
    }

    const rawValue = setText.trim()
    const isTradeFormula = rawValue === '=TradePrice + (TradePrice * 10.0 / 100)'

    if (isTradeFormula && fieldToUpdate !== 'retail_price') {
      feedback.error('The TradePrice formula can only be used when Field To Update is Sale Price.', 'Tabular View')
      return
    }

    if (!isTradeFormula) {
      const pattern = fieldToUpdate === 'reorder_level' ? DECIMAL_6 : MONEY_4
      const precision = fieldToUpdate === 'reorder_level' ? 6 : 4
      if (!pattern.test(rawValue)) {
        feedback.error(`Enter a non-negative number with up to ${precision} decimal places.`, 'Tabular View')
        return
      }
    }

    const rowByUlid = new Map(rows.map((product) => [product.ulid, product]))
    let skipped = 0

    setDrafts((current) => {
      const next = { ...current }

      for (const ulid of targets) {
        const product = rowByUlid.get(ulid)
        if (!product) {
          skipped += 1
          continue
        }

        if (fieldToUpdate === 'reorder_level') {
          next[ulid] = {
            ...(next[ulid] ?? {}),
            product: {
              ...(next[ulid]?.product ?? {}),
              reorder_level: rawValue,
            },
          }
          continue
        }

        const priceType = priceTypeForBulkField(fieldToUpdate)
        let amount = rawValue

        if (isTradeFormula) {
          const tradePrice = Number.parseFloat(
            product.prices?.find((price) => price.is_active && price.price_type === 'wholesale')?.amount ?? '',
          )
          if (!Number.isFinite(tradePrice)) {
            skipped += 1
            continue
          }
          amount = (tradePrice * 1.1).toFixed(4)
        }

        next[ulid] = {
          ...(next[ulid] ?? {}),
          prices: {
            ...(next[ulid]?.prices ?? {}),
            [priceType]: amount,
          },
        }
      }

      return next
    })

    const staged = targets.length - skipped
    if (staged > 0) {
      feedback.info(
        skipped > 0
          ? `Staged ${staged} product(s); skipped ${skipped} without a Trade Price. Press Save to commit.`
          : `Staged ${staged} product(s). Press Save to commit.`,
        'Tabular View',
      )
    } else {
      feedback.error('No selected product has the data required for this formula.', 'Tabular View')
    }
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const entries = Object.entries(drafts)
      await Promise.all(entries.map(async ([ulid, draft]) => {
        if (draft.product && Object.keys(draft.product).length > 0) {
          await updateProduct(ulid, draft.product as Record<string, unknown>)
        }

        if (draft.prices && Object.keys(draft.prices).length > 0) {
          await Promise.all(
            Object.entries(draft.prices).map(([priceType, amount]) =>
              saveProductPrices(ulid, [{
                price_type: priceType,
                amount,
                is_active: true,
              }]),
            ),
          )
        }
      }))
      return entries.length
    },
    onSuccess: async (count) => {
      setDrafts({})
      await queryClient.invalidateQueries({ queryKey: ['products'] })
      await productsQuery.refetch()
      feedback.success(`Saved changes for ${count} product(s).`, 'Tabular View')
    },
    onError: (error) => {
      feedback.fromApiError(error, 'Unable to save the selected product changes.')
    },
  })

  function exportCurrentPage() {
    if (rows.length === 0) {
      feedback.info('There are no rows to export.', 'Tabular View')
      return
    }

    const lines = [
      ['Product #', 'Code / Barcode', 'Description', 'Manufacturer', 'Category', 'U.O.M', 'Cost Price', 'Margin %', 'Selling', 'In Stock']
        .map(csvCell)
        .join(','),
      ...rows.map((product) => [
        product.product_number,
        product.primary_barcode ?? product.sku ?? '',
        product.name,
        product.brand?.name ?? '',
        product.category?.name ?? '',
        product.base_unit?.symbol ?? product.base_unit?.code ?? '',
        canViewStock ? product.sales_lookup?.average_cost ?? '' : '',
        canViewStock ? marginPercent(product) : '',
        retailPrice(product) ?? '',
        product.sales_lookup?.in_stock ?? '0',
      ].map((value) => csvCell(String(value))).join(',')),
    ]

    const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `bluepos-product-view-page-${page}.csv`
    link.click()
    URL.revokeObjectURL(url)
  }

  const pageStock = rows.reduce(
    (sum, product) => sum + (Number.parseFloat(product.sales_lookup?.in_stock ?? '0') || 0),
    0,
  )

  return (
    <section className="product-tabular-page" aria-label="Product Tabular View">
      <div className="product-tabular-toolbar">
        <div className="product-tabular-primary-row">
          <label className="product-tabular-search">
            <span>Search</span>
            <input
              value={search}
              onChange={(event) => {
                setSearch(event.target.value)
                resetToFirstPage()
              }}
              placeholder="Product, barcode, SKU…"
              autoComplete="off"
            />
          </label>

          <label className="product-tabular-field">
            <span>Field To Update</span>
            <UiSelect
              value={fieldToUpdate}
              options={BULK_FIELD_OPTIONS}
              onChange={(value) => {
                setFieldToUpdate(value as BulkField)
                setSetText('')
                setSetTextPreset('')
              }}
              aria-label="Field to update"
              searchable={false}
              disabled={!canEdit}
            />
          </label>

          <div className="product-tabular-set-text">
            <span>Set Text</span>
            <div className="product-tabular-set-combo">
              <input
                value={setText}
                onChange={(event) => {
                  setSetText(event.target.value)
                  setSetTextPreset('')
                }}
                disabled={!canEdit}
                placeholder={fieldToUpdate === 'reorder_level' ? 'Enter value…' : 'Enter price or formula…'}
                aria-label="Set Text"
              />
              <UiSelect
                value={setTextPreset}
                options={SET_TEXT_PRESETS.map((option) => ({
                  ...option,
                  disabled:
                    option.disabled ||
                    (option.value === 'trade_price_plus_10' && fieldToUpdate !== 'retail_price'),
                }))}
                onChange={(value) => {
                  setSetTextPreset(value)
                  if (value === 'trade_price_plus_10') {
                    setSetText('=TradePrice + (TradePrice * 10.0 / 100)')
                  }
                }}
                placeholder=""
                aria-label="Choose Set Text formula"
                searchable={false}
                disabled={!canEdit}
                className="product-tabular-formula-select"
                triggerClassName="product-tabular-formula-trigger"
              />
            </div>
          </div>

          <div className="product-tabular-update-actions">
            <UiButton
              variant="info"
              icon={<RefreshCw size={15} />}
              label="Refresh"
              onClick={() => void productsQuery.refetch()}
              disabled={productsQuery.isFetching}
            />
            <UiButton
              variant="default"
              label="Set"
              onClick={applySetText}
              disabled={!canEdit || (fieldToUpdate !== 'reorder_level' && !canPrices)}
            />
          </div>

          <div className="product-tabular-actions">
            <UiButton
              variant="success"
              icon={<Save size={16} />}
              label={saveMutation.isPending ? 'Saving…' : 'Save'}
              onClick={() => saveMutation.mutate()}
              disabled={saveMutation.isPending || Object.keys(drafts).length === 0}
            />
            <UiButton
              variant="info"
              icon={<Download size={16} />}
              label="Export"
              onClick={exportCurrentPage}
            />
            <UiButton
              variant="info"
              icon={<X size={16} />}
              label="Close"
              onClick={closeActiveTab}
            />
          </div>
        </div>

        <div className="product-tabular-secondary-row">
          <span className="product-tabular-filter-label">Filters</span>
          <div className="product-tabular-filters" aria-label="Product filters">
            <label>
              <input
                type="checkbox"
                checked={withBalance}
                onChange={(event) => {
                  setWithBalance(event.target.checked)
                  resetToFirstPage()
                }}
              />
              <span>With Balance</span>
            </label>
            <label>
              <input
                type="checkbox"
                checked={activeOnly}
                onChange={(event) => {
                  setActiveOnly(event.target.checked)
                  resetToFirstPage()
                }}
              />
              <span>Active Only</span>
            </label>
            <label>
              <input
                type="checkbox"
                checked={stockLeReorder}
                onChange={(event) => {
                  setStockLeReorder(event.target.checked)
                  resetToFirstPage()
                }}
              />
              <span>Stock &lt;= Reorder</span>
            </label>
            <label title={canViewStock ? undefined : 'Requires inventory.view permission'}>
              <input
                type="checkbox"
                checked={purchaseRateGeSaleRate}
                disabled={!canViewStock}
                onChange={(event) => {
                  setPurchaseRateGeSaleRate(event.target.checked)
                  resetToFirstPage()
                }}
              />
              <span>P.Rate &gt;= S.Rate</span>
            </label>
          </div>
          <span className="product-tabular-selection-summary">
            {selected.size > 0 ? `${selected.size} selected` : activeKey ? '1 active row' : 'No rows selected'}
          </span>
        </div>
      </div>

      <div className="product-tabular-grid">
        <PosDataGrid
          columns={columns}
          rows={rows}
          rowKey={(product) => product.ulid}
          selectedKey={activeKey}
          onSelect={(product) => setActiveKey(product.ulid)}
          emptyMessage={productsQuery.isPending ? 'Loading products…' : 'No products match the selected filters.'}
        />
      </div>

      <footer className="product-tabular-footer">
        <div className="product-tabular-pager">
          <UiButton
            label="First"
            onClick={() => setPage(1)}
            disabled={!meta || page <= 1}
          />
          <UiButton
            label="Previous"
            onClick={() => setPage((current) => Math.max(1, current - 1))}
            disabled={!meta || page <= 1}
          />
          <span>
            Page {meta?.current_page ?? page} of {meta?.last_page ?? 1}
          </span>
          <UiButton
            label="Next"
            onClick={() => setPage((current) => current + 1)}
            disabled={!meta || page >= meta.last_page}
          />
          <UiButton
            label="Last"
            onClick={() => meta && setPage(meta.last_page)}
            disabled={!meta || page >= meta.last_page}
          />
        </div>

        <strong>{meta?.total ?? 0} Items</strong>

        <span className="product-tabular-summary">
          {Object.keys(drafts).length > 0 ? `${Object.keys(drafts).length} staged · ` : ''}
          Page stock: {formatDecimal(String(pageStock), 6)}
        </span>
      </footer>
    </section>
  )
}
