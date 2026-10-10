import { FileSearch, Plus, RefreshCw, Save, XCircle } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { fetchProduct, fetchProducts, fetchSuppliers } from '../api/catalog'
import {
  createPurchaseOrder,
  fetchPurchaseOrder,
  fetchPurchaseOrders,
} from '../api/purchases'
import { DesktopButton, DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { useCan } from '../features/auth/useCan'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import type { Product } from '../types/catalog'
import type { PurchaseOrder } from '../types/purchases'
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
  quantity: string
  unit_price: string
  discount_percent: string
  discount_amount: string
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
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

  return {
    gross: fixedMoney(gross),
    discount: fixedMoney(Math.min(Math.max(discount, 0), Math.max(gross, 0))),
    total: fixedMoney(gross - Math.min(Math.max(discount, 0), Math.max(gross, 0))),
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
  const [lines, setLines] = useState<DraftLine[]>([])
  const [savedOrder, setSavedOrder] = useState<PurchaseOrder | null>(null)
  const [openedOrder, setOpenedOrder] = useState<PurchaseOrder | null>(null)
  const productSearchRef = useRef<HTMLInputElement | null>(null)

  const activeOrder = openedOrder ?? savedOrder
  const readOnly = Boolean(activeOrder)

  const suppliersQuery = useQuery({
    queryKey: ['suppliers', 'purchase-order'],
    queryFn: fetchSuppliers,
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
    queryKey: ['purchase-orders', searchText],
    queryFn: () =>
      fetchPurchaseOrders({
        q: searchText.trim() || undefined,
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
          columns: [supplier.code, supplier.name],
        })),
    [suppliersQuery.data],
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

  function startNew() {
    setSupplierUlid('')
    setOrderDate(todayIso())
    setNotes('')
    setProductQuery('')
    setLines([])
    setSavedOrder(null)
    setOpenedOrder(null)
    setTab('entry')
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

  const saveMutation = useMutation({
    mutationFn: () => {
      if (!supplierUlid) {
        throw new Error('Select a supplier before saving the purchase order.')
      }
      if (lines.length === 0) {
        throw new Error('Add at least one product before saving the purchase order.')
      }

      return createPurchaseOrder({
        supplier_ulid: supplierUlid,
        order_date: orderDate,
        notes: notes.trim() || null,
        items: lines.map((line) => ({
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
          Purchase Order
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
          <div className="purchase-order-header">
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

            <label className="is-wide">
              <span>Supplier / From</span>
              <UiSelect
                value={activeOrder?.supplier?.ulid ?? supplierUlid}
                options={supplierOptions}
                disabled={readOnly}
                placeholder="Select supplier"
                searchPlaceholder="Search supplier"
                menuColumns={[
                  { header: 'Code', width: '110px' },
                  { header: 'Supplier' },
                ]}
                menuMinWidth={360}
                onChange={setSupplierUlid}
                aria-label="Supplier"
              />
            </label>

            <label className="is-wide">
              <span>Remarks</span>
              <input
                value={activeOrder?.notes ?? notes}
                disabled={readOnly}
                placeholder="Order remarks"
                onChange={(event) => setNotes(event.target.value)}
              />
            </label>
          </div>

          <div className="purchase-order-lines-wrap">
            <table className="purchase-order-lines">
              <thead>
                <tr>
                  <th>Product Description</th>
                  <th>Unit</th>
                  <th className="is-num">Qty</th>
                  <th className="is-num">Price</th>
                  <th className="is-num">Disc %</th>
                  <th className="is-num">Disc Rs</th>
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
                        <td>{line.unit?.code ?? '—'}</td>
                        <td className="is-num">{line.quantity}</td>
                        <td className="is-num">{line.unit_price}</td>
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
                          <td>{line.product_number} — {line.product_name}</td>
                          <td>
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
                          </td>
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
                        placeholder="Search product / barcode"
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
                        <div className="purchase-order-product-results" role="listbox">
                          <div className="purchase-order-product-results-head">
                            <span>ID</span>
                            <span>Description</span>
                            <span>In Stock</span>
                            <span>Avg Cost</span>
                            <span>Unit</span>
                          </div>
                          {productsQuery.data.data.slice(0, 10).map((product) => (
                            <button
                              type="button"
                              key={product.ulid}
                              onClick={() => void addProduct(product)}
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
                        </div>
                      ) : null}
                    </td>
                    <td>—</td>
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
                    <td colSpan={8} className="purchase-order-empty">
                      No purchase order lines found.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>

          <div className="purchase-order-footer">
            <div className="purchase-order-status-note">
              Purchase Order is non-posting. Stock and accounting are affected only by
              the linked Purchase Invoice posting flow.
            </div>
            <div className="purchase-order-totals">
              <div>
                <span>Subtotal</span>
                <strong>
                  {activeOrder?.subtotal ?? fixedMoney(totals.subtotal)}
                </strong>
              </div>
              <div>
                <span>Discount</span>
                <strong>
                  {activeOrder?.discount_amount ?? fixedMoney(totals.discount)}
                </strong>
              </div>
              <div className="is-grand">
                <span>Net Order</span>
                <strong>
                  {activeOrder?.grand_total ?? fixedMoney(totals.total)}
                </strong>
              </div>
            </div>
          </div>
        </div>
      ) : (
        <div className="purchase-order-search">
          <div className="purchase-order-search-bar">
            <input
              className="desktop-input"
              placeholder="PO # or supplier"
              value={searchText}
              onChange={(event) => setSearchText(event.target.value)}
            />
            <UiButton variant="info" onClick={() => void ordersQuery.refetch()}>
              Search
            </UiButton>
          </div>

          <PosDataGrid
            columns={[
              {
                key: 'number',
                header: 'PO #',
                width: 150,
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
                header: 'Supplier / From',
                render: (row) => row.supplier?.name ?? '—',
              },
              {
                key: 'status',
                header: 'Status',
                width: 110,
                render: (row) => row.status,
              },
              {
                key: 'total',
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
