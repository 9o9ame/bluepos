import { FileSearch, Play, Plus, RefreshCw, Save, XCircle } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import {
  fetchBrands,
  fetchCategories,
  fetchProduct,
  fetchProducts,
  fetchSuppliers,
} from '../api/catalog'
import {
  createPurchaseOrder,
  fetchPurchaseOrder,
  fetchPurchaseOrders,
  generatePurchaseOrder,
} from '../api/purchases'
import { DesktopButton, DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { PurchaseProductLookup } from '../features/purchases/PurchaseProductLookup'
import { useCan } from '../features/auth/useCan'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import type { Product } from '../types/catalog'
import type {
  PurchaseOrder,
  PurchaseOrderGenerateMode,
  PurchaseOrderGenerateRow,
} from '../types/purchases'
import './PurchaseOrderPage.css'

type UnitOption = {
  ulid: string
  code: string
  factor: string
}

type DraftLine = {
  key: string
  product_ulid: string
  product_number: string
  product_name: string
  unit_ulid: string
  unit_code: string
  unit_options: UnitOption[]
  in_stock: string
  stock_value: string
  consumption: string
  difference: string
  quantity: string
  unit_price: string
  discount_percent: string
  discount_amount: string
}

const ORDER_TYPE_OPTIONS: Array<{
  value: PurchaseOrderGenerateMode
  label: string
  disabled?: boolean
  title?: string
}> = [
  { value: 'last_n_days', label: 'Last N Days Sale' },
  { value: 'between_dates', label: 'Between Dates Sale' },
  { value: 'reorder_level', label: 'Reorder Level' },
  { value: 'min_level', label: 'Min Level' },
  { value: 'max_level', label: 'Max Level' },
  {
    value: 'optimum_level',
    label: 'Optimum Level',
    disabled: true,
    title: 'Current BluePOS product master has no Optimum Level field.',
  },
  { value: 'get_all', label: 'Get All Products' },
]

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function daysAgoIso(days: number) {
  const date = new Date()
  date.setDate(date.getDate() - Math.max(days - 1, 0))
  return date.toISOString().slice(0, 10)
}

function num(value: string | number | null | undefined): number {
  const parsed = Number(value ?? 0)
  return Number.isFinite(parsed) ? parsed : 0
}

function fixedMoney(value: number): string {
  return Math.max(value, 0).toFixed(4)
}

function buildUnitOptions(product: Product): UnitOption[] {
  const map = new Map<string, UnitOption>()

  const push = (ulid: string | undefined, code: string | undefined, factor: string) => {
    if (!ulid || !code || map.has(ulid)) return
    map.set(ulid, { ulid, code, factor })
  }

  push(product.base_unit?.ulid, product.base_unit?.code, '1.00000000')
  push(
    product.secondary_unit?.ulid,
    product.secondary_unit?.code,
    product.secondary_conversion_factor ?? '1.00000000',
  )

  for (const barcode of product.barcodes ?? []) {
    if (!barcode.is_active || !barcode.unit?.ulid) continue
    push(
      barcode.unit.ulid,
      barcode.unit.code,
      barcode.conversion_factor || '1.00000000',
    )
  }

  return [...map.values()]
}

function linePreview(line: DraftLine) {
  const gross = num(line.quantity) * num(line.unit_price)
  const pct = num(line.discount_percent)
  const explicit = num(line.discount_amount)
  const discount = pct > 0 ? (gross * pct) / 100 : explicit
  const safeDiscount = Math.min(Math.max(discount, 0), Math.max(gross, 0))

  return {
    gross: fixedMoney(gross),
    discount: fixedMoney(safeDiscount),
    total: fixedMoney(gross - safeDiscount),
  }
}

function generatedLine(row: PurchaseOrderGenerateRow): DraftLine | null {
  if (!row.unit) return null

  return {
    key: crypto.randomUUID(),
    product_ulid: row.product.ulid,
    product_number: row.product.product_number,
    product_name: row.product.name,
    unit_ulid: row.unit.ulid,
    unit_code: row.unit.code,
    unit_options: [
      {
        ulid: row.unit.ulid,
        code: row.unit.code,
        factor: '1.00000000',
      },
    ],
    in_stock: row.in_stock,
    stock_value: row.stock_value,
    consumption: row.consumption,
    difference: row.difference,
    quantity: row.suggested_quantity,
    unit_price: row.unit_price,
    discount_percent: '0',
    discount_amount: '0.0000',
  }
}

export function PurchaseOrderPage() {
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const canCreate = useCan('purchases.create')

  const [tab, setTab] = useState<'entry' | 'search'>('entry')
  const [supplierUlid, setSupplierUlid] = useState('')
  const [orderDate, setOrderDate] = useState(todayIso())
  const [notes, setNotes] = useState('')
  const [productQuery, setProductQuery] = useState('')
  const [searchText, setSearchText] = useState('')
  const [searchFrom, setSearchFrom] = useState(daysAgoIso(30))
  const [searchTo, setSearchTo] = useState(todayIso())
  const [lines, setLines] = useState<DraftLine[]>([])
  const [savedOrder, setSavedOrder] = useState<PurchaseOrder | null>(null)
  const [openedOrder, setOpenedOrder] = useState<PurchaseOrder | null>(null)

  const [orderType, setOrderType] = useState<PurchaseOrderGenerateMode>('get_all')
  const [lastDays, setLastDays] = useState('10')
  const [dateFrom, setDateFrom] = useState(daysAgoIso(10))
  const [dateTo, setDateTo] = useState(todayIso())
  const [includeNonSold, setIncludeNonSold] = useState(false)
  const [categoryUlid, setCategoryUlid] = useState('')
  const [brandUlid, setBrandUlid] = useState('')

  const productSearchRef = useRef<HTMLInputElement | null>(null)

  const activeOrder = openedOrder ?? savedOrder
  const readOnly = Boolean(activeOrder)

  const suppliersQuery = useQuery({
    queryKey: ['suppliers', 'purchase-order'],
    queryFn: fetchSuppliers,
    retry: false,
  })

  const categoriesQuery = useQuery({
    queryKey: ['categories', 'purchase-order'],
    queryFn: fetchCategories,
    retry: false,
  })

  const brandsQuery = useQuery({
    queryKey: ['brands', 'purchase-order'],
    queryFn: fetchBrands,
    retry: false,
  })

  const productsQuery = useQuery({
    queryKey: ['products', 'purchase-order', productQuery],
    queryFn: () =>
      fetchProducts(
        {
          q: productQuery.trim() || undefined,
          page: 1,
          per_page: 15,
          active_only: true,
          sales_lookup: true,
        },
        { busy: 'none' },
      ),
    enabled: !readOnly && productQuery.trim().length > 0,
  })

  const ordersQuery = useQuery({
    queryKey: ['purchase-orders', searchText, searchFrom, searchTo],
    queryFn: () =>
      fetchPurchaseOrders({
        q: searchText.trim() || undefined,
        date_from: searchFrom || undefined,
        date_to: searchTo || undefined,
        per_page: 50,
      }),
    enabled: tab === 'search',
    retry: false,
  })

  const supplierOptions = useMemo(
    () =>
      (suppliersQuery.data ?? [])
        .filter((supplier) => supplier.is_active)
        .map((supplier) => ({
          value: supplier.ulid,
          label: `${supplier.code} — ${supplier.name}`,
          columns: [
            supplier.code,
            supplier.name,
            supplier.address ?? '',
            supplier.mobile ?? supplier.phone ?? '',
          ],
        })),
    [suppliersQuery.data],
  )

  const categoryOptions = useMemo(
    () => [
      { value: '', label: 'All Categories' },
      ...(categoriesQuery.data ?? [])
        .filter((category) => category.is_active)
        .map((category) => ({
          value: category.ulid,
          label: category.name,
          columns: [category.code, category.name],
        })),
    ],
    [categoriesQuery.data],
  )

  const brandOptions = useMemo(
    () => [
      { value: '', label: 'All Companies / Brands' },
      ...(brandsQuery.data ?? [])
        .filter((brand) => brand.is_active)
        .map((brand) => ({
          value: brand.ulid,
          label: brand.name,
          columns: [brand.code, brand.name],
        })),
    ],
    [brandsQuery.data],
  )

  const totals = useMemo(() => {
    return lines.reduce(
      (acc, line) => {
        const preview = linePreview(line)
        acc.subtotal += num(preview.gross)
        acc.discount += num(preview.discount)
        acc.total += num(preview.total)
        return acc
      },
      { subtotal: 0, discount: 0, total: 0 },
    )
  }, [lines])

  const netAmount = activeOrder?.grand_total ?? fixedMoney(totals.total)

  function startNew() {
    setSupplierUlid('')
    setOrderDate(todayIso())
    setNotes('')
    setProductQuery('')
    setLines([])
    setSavedOrder(null)
    setOpenedOrder(null)
    setTab('entry')
    setOrderType('get_all')
    setLastDays('10')
    setDateFrom(daysAgoIso(10))
    setDateTo(todayIso())
    setIncludeNonSold(false)
    setCategoryUlid('')
    setBrandUlid('')
  }

  function patchLine(key: string, patch: Partial<DraftLine>) {
    setLines((current) =>
      current.map((line) => (line.key === key ? { ...line, ...patch } : line)),
    )
  }

  async function addProduct(productRow: Product) {
    try {
      const product = await fetchProduct(productRow.ulid)
      const unitOptions = buildUnitOptions(product)
      const unit = unitOptions[0]

      if (!unit) {
        feedback.error('This product has no configured purchase unit.', 'Purchase Order')
        return
      }

      setLines((current) => [
        ...current,
        {
          key: crypto.randomUUID(),
          product_ulid: product.ulid,
          product_number: product.product_number,
          product_name: product.name,
          unit_ulid: unit.ulid,
          unit_code: unit.code,
          unit_options: unitOptions,
          in_stock: productRow.sales_lookup?.in_stock ?? '0.000000',
          stock_value: '0.0000',
          consumption: '0.000000',
          difference: '0.000000',
          quantity: '1.000000',
          unit_price: productRow.sales_lookup?.average_cost ?? '0.0000',
          discount_percent: '0',
          discount_amount: '0.0000',
        },
      ])
      setProductQuery('')
    } catch (error) {
      feedback.error(
        error instanceof Error ? error.message : 'Unable to add product.',
        'Purchase Order',
      )
    }
  }

  const generateMutation = useMutation({
    mutationFn: () =>
      generatePurchaseOrder({
        mode: orderType,
        days: orderType === 'last_n_days' ? Number(lastDays) : undefined,
        date_from: orderType === 'between_dates' ? dateFrom : undefined,
        date_to: orderType === 'between_dates' ? dateTo : undefined,
        supplier_ulid: supplierUlid || undefined,
        category_ulid: categoryUlid || undefined,
        brand_ulid: brandUlid || undefined,
        include_non_sold: includeNonSold,
      }),
    onSuccess: (payload) => {
      const next = payload.data
        .map(generatedLine)
        .filter((line): line is DraftLine => line !== null)
      setLines(next)
      feedback.success(
        `Generated ${next.length} purchase order line${next.length === 1 ? '' : 's'} from server planning data.`,
        'Purchase Order',
      )
    },
    onError: (error) => {
      feedback.error(
        error instanceof Error ? error.message : 'Unable to generate purchase order.',
        'Purchase Order',
      )
    },
  })

  const saveMutation = useMutation({
    mutationFn: () => {
      if (!supplierUlid) {
        throw new Error('Select a supplier before saving the purchase order.')
      }
      if (lines.length === 0) {
        throw new Error('Add or generate at least one product before saving the purchase order.')
      }

      const orderable = lines.filter((line) => num(line.quantity) > 0)
      if (orderable.length === 0) {
        throw new Error('At least one line must have an order quantity greater than zero.')
      }

      return createPurchaseOrder({
        supplier_ulid: supplierUlid,
        order_date: orderDate,
        notes: notes.trim() || null,
        items: orderable.map((line) => ({
          product_ulid: line.product_ulid,
          unit_ulid: line.unit_ulid,
          quantity: line.quantity,
          unit_price: line.unit_price,
          discount_percent: num(line.discount_percent) > 0
            ? line.discount_percent
            : '0',
          discount_amount: num(line.discount_percent) > 0
            ? '0'
            : line.discount_amount,
        })),
      })
    },
    onSuccess: (order) => {
      setSavedOrder(order)
      setOpenedOrder(null)
      feedback.success(
        `Purchase Order ${order.document_number} saved. No stock or accounting entry was posted.`,
        'Purchase Order',
      )
    },
    onError: (error) => {
      feedback.error(
        error instanceof Error ? error.message : 'Unable to save purchase order.',
        'Purchase Order',
      )
    },
  })

  const openMutation = useMutation({
    mutationFn: fetchPurchaseOrder,
    onSuccess: (order) => {
      setOpenedOrder(order)
      setSavedOrder(null)
      setSupplierUlid(order.supplier?.ulid ?? '')
      setOrderDate(order.order_date)
      setNotes(order.notes ?? '')
      setProductQuery('')
      setTab('entry')
    },
    onError: (error) => {
      feedback.error(
        error instanceof Error ? error.message : 'Unable to open purchase order.',
        'Purchase Order',
      )
    },
  })

  const displayedLines = activeOrder?.items ?? []

  return (
    <DesktopPanel
      className="purchase-order-page"
      title="Purchase Order"
      toolbar={
        <>
          <DesktopButton icon={<Plus size={15} />} label="New" onClick={startNew} />
          <DesktopButton
            icon={<Save size={15} />}
            label="Save Order"
            variant="success"
            disabled={!canCreate || readOnly || saveMutation.isPending}
            onClick={() => saveMutation.mutate()}
          />
          <DesktopButton
            icon={<RefreshCw size={15} />}
            label="Refresh"
            variant="info"
            onClick={() => {
              if (tab === 'search') void ordersQuery.refetch()
              else startNew()
            }}
          />
          <DesktopButton
            icon={<FileSearch size={15} />}
            label="Search"
            variant="info"
            onClick={() => setTab('search')}
          />
          <DesktopButton
            icon={<XCircle size={15} />}
            label="Close"
            variant="info"
            onClick={closeActiveTab}
          />
        </>
      }
      flush
    >
      <div className="purchase-order-tabs" aria-label="Purchase order views">
        <button
          type="button"
          className={tab === 'entry' ? 'is-active' : ''}
          onClick={() => setTab('entry')}
        >
          Orders Entry
        </button>
        <button
          type="button"
          className={tab === 'search' ? 'is-active' : ''}
          onClick={() => setTab('search')}
        >
          Search
        </button>
      </div>

      {tab === 'entry' ? (
        <div className="purchase-order-entry">
          <div className="purchase-order-top">
            <section className="purchase-order-options">
              <div className="purchase-order-section-title">Purchase Order Options</div>

              <div className="purchase-order-option-grid">
                <label>
                  <span>PO #</span>
                  <input
                    value={activeOrder?.document_number ?? 'Auto'}
                    disabled
                    aria-label="Purchase order number"
                  />
                </label>

                <label>
                  <span>Order Date</span>
                  <input
                    type="date"
                    value={activeOrder?.order_date ?? orderDate}
                    disabled={readOnly}
                    onChange={(event) => setOrderDate(event.target.value)}
                  />
                </label>

                <label className="is-order-type">
                  <span>Order Type</span>
                  <UiSelect
                    value={orderType}
                    disabled={readOnly}
                    searchable={false}
                    options={ORDER_TYPE_OPTIONS}
                    onChange={(value) => setOrderType(value as PurchaseOrderGenerateMode)}
                    aria-label="Order type"
                  />
                </label>

                <label className="purchase-order-check">
                  <input type="checkbox" disabled checked={false} readOnly />
                  <span title="Reference behavior is not mapped to the current BluePOS unit model.">
                    Apply to Pack
                  </span>
                </label>

                <label className="purchase-order-check">
                  <input
                    type="checkbox"
                    checked={includeNonSold}
                    disabled={readOnly || !['last_n_days', 'between_dates'].includes(orderType)}
                    onChange={(event) => setIncludeNonSold(event.target.checked)}
                  />
                  <span>Non Sold Product Also</span>
                </label>

                <label>
                  <span>Days</span>
                  <input
                    type="number"
                    min="1"
                    value={lastDays}
                    disabled={readOnly || orderType !== 'last_n_days'}
                    onChange={(event) => setLastDays(event.target.value)}
                  />
                </label>

                <label>
                  <span>From</span>
                  <input
                    type="date"
                    value={dateFrom}
                    disabled={readOnly || orderType !== 'between_dates'}
                    onChange={(event) => setDateFrom(event.target.value)}
                  />
                </label>

                <label>
                  <span>To</span>
                  <input
                    type="date"
                    value={dateTo}
                    disabled={readOnly || orderType !== 'between_dates'}
                    onChange={(event) => setDateTo(event.target.value)}
                  />
                </label>

                <label className="is-wide">
                  <span>From / Supplier</span>
                  <UiSelect
                    value={activeOrder?.supplier?.ulid ?? supplierUlid}
                    options={supplierOptions}
                    disabled={readOnly}
                    placeholder="Select supplier / distributor"
                    searchPlaceholder="Search supplier"
                    menuColumns={[
                      { header: 'Code', width: '100px' },
                      { header: 'Name', width: '220px' },
                      { header: 'Address', width: '240px' },
                      { header: 'Mobile', width: '130px' },
                    ]}
                    menuMinWidth={720}
                    onChange={setSupplierUlid}
                    aria-label="Supplier"
                  />
                </label>

                <label>
                  <span>Category</span>
                  <UiSelect
                    value={categoryUlid}
                    options={categoryOptions}
                    disabled={readOnly}
                    onChange={setCategoryUlid}
                    aria-label="Category filter"
                  />
                </label>

                <label>
                  <span>Company</span>
                  <UiSelect
                    value={brandUlid}
                    options={brandOptions}
                    disabled={readOnly}
                    onChange={setBrandUlid}
                    aria-label="Company or brand filter"
                  />
                </label>

              </div>
            </section>

            <div className="purchase-order-generate">
              <UiButton
                variant="success"
                disabled={readOnly || generateMutation.isPending}
                onClick={() => generateMutation.mutate()}
              >
                <Play size={16} />
                Generate
              </UiButton>
            </div>

            <label className="purchase-order-remarks">
              <span>Remarks</span>
              <textarea
                value={activeOrder?.notes ?? notes}
                disabled={readOnly}
                rows={5}
                onChange={(event) => setNotes(event.target.value)}
              />
            </label>

            <aside className="purchase-order-amount-options">
              <div className="purchase-order-section-title">Amount Options</div>
              <div>
                <span>Net</span>
                <strong>{netAmount}</strong>
              </div>
              <div>
                <span>Paid</span>
                <strong>0.0000</strong>
              </div>
              <div>
                <span>Diff</span>
                <strong>{netAmount}</strong>
              </div>
            </aside>
          </div>

          <div className="purchase-order-lines-wrap">
            <table className="purchase-order-lines">
              <thead>
                <tr>
                  <th>Item / Product Description</th>
                  <th className="is-num">In Stock</th>
                  <th className="is-num">Stock Val</th>
                  <th className="is-num">Consumption</th>
                  <th className="is-num">Difference</th>
                  <th className="is-num">Quantity</th>
                  <th className="is-num">Price</th>
                  <th className="is-num">Amt</th>
                  <th className="is-num">Dis %</th>
                  <th className="is-num">Dis-Rs</th>
                  <th className="is-num">Amount</th>
                  <th aria-label="Actions" />
                </tr>
              </thead>
              <tbody>
                {readOnly
                  ? displayedLines.map((line) => (
                      <tr key={line.ulid}>
                        <td>
                          {line.product
                            ? `${line.product.product_number} — ${line.product.name}`
                            : '—'}
                        </td>
                        <td className="is-num">—</td>
                        <td className="is-num">—</td>
                        <td className="is-num">—</td>
                        <td className="is-num">—</td>
                        <td className="is-num">{line.quantity}</td>
                        <td className="is-num">{line.unit_price}</td>
                        <td className="is-num">{line.gross_amount}</td>
                        <td className="is-num">{line.discount_percent}</td>
                        <td className="is-num">{line.discount_amount}</td>
                        <td className="is-num">{line.line_total}</td>
                        <td />
                      </tr>
                    ))
                  : lines.map((line) => {
                      const preview = linePreview(line)
                      return (
                        <tr key={line.key}>
                          <td>
                            <div className="purchase-order-product-name">
                              <strong>{line.product_number} — {line.product_name}</strong>
                              <UiSelect
                                className="purchase-order-unit"
                                value={line.unit_ulid}
                                searchable={false}
                                options={line.unit_options.map((unit) => ({
                                  value: unit.ulid,
                                  label: unit.code,
                                }))}
                                onChange={(value) => {
                                  const unit = line.unit_options.find((row) => row.ulid === value)
                                  patchLine(line.key, {
                                    unit_ulid: value,
                                    unit_code: unit?.code ?? line.unit_code,
                                  })
                                }}
                                aria-label={`Unit for ${line.product_name}`}
                              />
                            </div>
                          </td>
                          <td className="is-num">{line.in_stock}</td>
                          <td className="is-num">{line.stock_value}</td>
                          <td className="is-num">{line.consumption}</td>
                          <td className="is-num">{line.difference}</td>
                          <td className="is-num">
                            <input
                              value={line.quantity}
                              aria-label={`Quantity for ${line.product_name}`}
                              onChange={(event) =>
                                patchLine(line.key, { quantity: event.target.value })
                              }
                            />
                          </td>
                          <td className="is-num">
                            <input
                              value={line.unit_price}
                              aria-label={`Price for ${line.product_name}`}
                              onChange={(event) =>
                                patchLine(line.key, { unit_price: event.target.value })
                              }
                            />
                          </td>
                          <td className="is-num">{preview.gross}</td>
                          <td className="is-num">
                            <input
                              value={line.discount_percent}
                              aria-label={`Discount percent for ${line.product_name}`}
                              onChange={(event) =>
                                patchLine(line.key, {
                                  discount_percent: event.target.value,
                                  discount_amount: num(event.target.value) > 0
                                    ? '0.0000'
                                    : line.discount_amount,
                                })
                              }
                            />
                          </td>
                          <td className="is-num">
                            <input
                              value={line.discount_amount}
                              aria-label={`Discount amount for ${line.product_name}`}
                              onChange={(event) =>
                                patchLine(line.key, {
                                  discount_amount: event.target.value,
                                  discount_percent: num(event.target.value) > 0
                                    ? '0'
                                    : line.discount_percent,
                                })
                              }
                            />
                          </td>
                          <td className="is-num">{preview.total}</td>
                          <td>
                            <UiButton
                              variant="danger"
                              aria-label={`Remove ${line.product_name}`}
                              onClick={() =>
                                setLines((current) =>
                                  current.filter((row) => row.key !== line.key),
                                )
                              }
                            >
                              <XCircle size={14} />
                            </UiButton>
                          </td>
                        </tr>
                      )
                    })}

                {!readOnly ? (
                  <tr className="purchase-order-entry-row">
                    <td className="purchase-order-product-cell">
                      <input
                        ref={productSearchRef}
                        className="purchase-order-product-input"
                        value={productQuery}
                        placeholder="Search / select product"
                        autoComplete="off"
                        onChange={(event) => setProductQuery(event.target.value)}
                        onKeyDown={(event) => {
                          if (event.key !== 'Enter') return
                          const first = productsQuery.data?.data?.[0]
                          if (!first) return
                          event.preventDefault()
                          void addProduct(first)
                        }}
                      />

                      {productQuery.trim() && productsQuery.data?.data?.length ? (
                        <PurchaseProductLookup
                          rows={productsQuery.data.data}
                          anchorRef={productSearchRef}
                          onSelect={(product) => void addProduct(product)}
                        />
                      ) : null}
                    </td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td className="is-num">—</td>
                    <td />
                  </tr>
                ) : null}

                {readOnly && displayedLines.length === 0 ? (
                  <tr>
                    <td colSpan={12} className="purchase-order-empty">
                      No purchase order lines found.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>

          <div className="purchase-order-footer">
            <div className="purchase-order-status-note">
              Generated planning values are informational until Save. Purchase Order remains
              non-posting; stock/accounting change only through Purchase Invoice posting.
            </div>
            <div className="purchase-order-totals">
              <div>
                <span>Subtotal</span>
                <strong>{activeOrder?.subtotal ?? fixedMoney(totals.subtotal)}</strong>
              </div>
              <div>
                <span>Discount</span>
                <strong>{activeOrder?.discount_amount ?? fixedMoney(totals.discount)}</strong>
              </div>
              <div className="is-grand">
                <span>Net Order</span>
                <strong>{netAmount}</strong>
              </div>
            </div>
          </div>
        </div>
      ) : (
        <div className="purchase-order-search">
          <div className="purchase-order-search-bar">
            <input
              className="desktop-input"
              placeholder="PO # or distributor"
              value={searchText}
              onChange={(event) => setSearchText(event.target.value)}
            />
            <label>
              <span>From</span>
              <input
                type="date"
                value={searchFrom}
                onChange={(event) => setSearchFrom(event.target.value)}
              />
            </label>
            <label>
              <span>To</span>
              <input
                type="date"
                value={searchTo}
                onChange={(event) => setSearchTo(event.target.value)}
              />
            </label>
            <UiButton variant="info" onClick={() => void ordersQuery.refetch()}>
              View
            </UiButton>
          </div>

          <PosDataGrid
            columns={[
              {
                key: 'number',
                header: 'PO #',
                width: 160,
                render: (row) => row.document_number,
              },
              {
                key: 'date',
                header: 'Order Date',
                width: 120,
                render: (row) => row.order_date,
              },
              {
                key: 'supplier',
                header: 'Distributor',
                render: (row) => row.supplier?.name ?? '—',
              },
              {
                key: 'status',
                header: 'Status',
                width: 110,
                render: (row) => row.status,
              },
              {
                key: 'net',
                header: 'Net',
                width: 150,
                align: 'right',
                render: (row) => row.grand_total,
              },
            ]}
            rows={ordersQuery.data?.data ?? []}
            rowKey={(row) => row.ulid}
            onActivate={(row) => openMutation.mutate(row.ulid)}
            emptyMessage="No purchase orders found."
          />
        </div>
      )}
    </DesktopPanel>
  )
}
