import { FormEvent, useMemo, useState } from 'react'
import {
  Barcode,
  Binoculars,
  Check,
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
  LayoutGrid,
  Minus,
  Package,
  Plus,
  Printer,
  Receipt,
  RefreshCw,
  Save,
  Send,
  Table2,
  X,
  XCircle,
} from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchProducts, fetchSuppliers } from '../api/catalog'
import { ApiClientError } from '../api/client'
import { fetchWarehouses } from '../api/inventory'
import {
  createPurchase,
  createPurchaseLine,
  deletePurchaseLine,
  fetchPurchase,
  fetchPurchases,
  postPurchase,
  updatePurchase,
  updatePurchaseLine,
} from '../api/purchases'
import { DesktopButton, DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Product } from '../types/catalog'
import type { PurchaseInvoice, PurchaseInvoiceLine } from '../types/purchases'

type DraftLine = {
  key: string
  ulid?: string
  product_ulid: string
  product_label: string
  unit_ulid: string
  unit_label: string
  quantity: string
  conversion_factor: string
  unit_cost: string
  discount_amount: string
  tax_amount: string
  supplier_product_code: string
  batch_number: string
  expiry_date: string
  track_batch: boolean
  track_expiry: boolean
  line_total?: string
  base_quantity?: string
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function money(value: string | number | null | undefined) {
  const n = Number(value ?? 0)
  return Number.isFinite(n) ? n.toFixed(4) : '0.0000'
}

function lineFromServer(line: PurchaseInvoiceLine): DraftLine {
  return {
    key: line.ulid,
    ulid: line.ulid,
    product_ulid: line.product?.ulid ?? '',
    product_label: line.product
      ? `${line.product.product_number} · ${line.product.name}`
      : '',
    unit_ulid: line.unit?.ulid ?? '',
    unit_label: line.unit?.code ?? '',
    quantity: line.quantity,
    conversion_factor: line.conversion_factor,
    unit_cost: line.unit_cost,
    discount_amount: line.discount_amount,
    tax_amount: line.tax_amount,
    supplier_product_code: line.supplier_product_code ?? '',
    batch_number: line.batch_number ?? '',
    expiry_date: line.expiry_date ?? '',
    track_batch: Boolean(line.product?.track_batch),
    track_expiry: Boolean(line.product?.track_expiry),
    line_total: line.line_total,
    base_quantity: line.base_quantity,
  }
}

function resolveProductSelection(product: Product, query: string): {
  unit_ulid: string
  unit_label: string
  conversion_factor: string
} {
  const q = query.trim().toLowerCase()
  const barcodeHit = product.barcodes?.find(
    (b) => b.is_active && b.barcode.toLowerCase() === q && b.unit?.ulid,
  )
  if (barcodeHit?.unit) {
    return {
      unit_ulid: barcodeHit.unit.ulid,
      unit_label: barcodeHit.unit.code,
      conversion_factor: barcodeHit.conversion_factor || '1.00000000',
    }
  }
  const base = product.base_unit
  return {
    unit_ulid: base?.ulid ?? '',
    unit_label: base?.code ?? '',
    conversion_factor: '1.00000000',
  }
}

export function PurchasesPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
  const { session } = useAuth()

  const canCreate = useCan('purchases.create')
  const canEdit = useCan('purchases.edit')
  const canPost = useCan('purchases.post')

  const [mode, setMode] = useState<'list' | 'editor'>('list')
  const [q, setQ] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [supplierFilter, setSupplierFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedListKey, setSelectedListKey] = useState<string | null>(null)

  const [invoiceUlid, setInvoiceUlid] = useState<string | null>(null)
  const [supplierUlid, setSupplierUlid] = useState('')
  const [warehouseUlid, setWarehouseUlid] = useState(session?.warehouse.ulid ?? '')
  const [invoiceDate, setInvoiceDate] = useState(todayIso())
  const [dueDate, setDueDate] = useState('')
  const [supplierInvoiceNumber, setSupplierInvoiceNumber] = useState('')
  const [freightAmount, setFreightAmount] = useState('0.0000')
  const [otherCharges, setOtherCharges] = useState('0.0000')
  const [notes, setNotes] = useState('')
  const [documentNumber, setDocumentNumber] = useState('')
  const [status, setStatus] = useState<'draft' | 'posted' | 'cancelled'>('draft')
  const [subtotal, setSubtotal] = useState('0.0000')
  const [discountAmount, setDiscountAmount] = useState('0.0000')
  const [taxAmount, setTaxAmount] = useState('0.0000')
  const [grandTotal, setGrandTotal] = useState('0.0000')
  const [lines, setLines] = useState<DraftLine[]>([])
  const [productQuery, setProductQuery] = useState('')
  const [error, setError] = useState<string | null>(null)

  const readOnly = status === 'posted' || status === 'cancelled'

  const listQuery = useQuery({
    queryKey: ['purchases', q, statusFilter, supplierFilter, dateFrom, dateTo],
    queryFn: () =>
      fetchPurchases({
        q: q || undefined,
        status: statusFilter || undefined,
        supplier_ulid: supplierFilter || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        per_page: 50,
      }),
    enabled: mode === 'list',
  })

  const suppliersQuery = useQuery({
    queryKey: ['suppliers'],
    queryFn: fetchSuppliers,
  })

  const warehousesQuery = useQuery({
    queryKey: ['warehouses'],
    queryFn: fetchWarehouses,
  })

  const productLookup = useQuery({
    queryKey: ['purchase-product-lookup', productQuery],
    queryFn: () => fetchProducts({ q: productQuery, per_page: 15 }),
    enabled: mode === 'editor' && !readOnly && productQuery.trim().length >= 1,
  })

  const listRows = listQuery.data?.data ?? []
  const suppliers = (suppliersQuery.data ?? []).filter((s) => s.is_active)
  const warehouses = (warehousesQuery.data ?? []).filter((w) => w.status === 'active')

  function applyInvoice(invoice: PurchaseInvoice) {
    setInvoiceUlid(invoice.ulid)
    setDocumentNumber(invoice.document_number)
    setStatus(invoice.status)
    setSupplierUlid(invoice.supplier?.ulid ?? '')
    setWarehouseUlid(invoice.warehouse?.ulid ?? session?.warehouse.ulid ?? '')
    setInvoiceDate(invoice.invoice_date)
    setDueDate(invoice.due_date ?? '')
    setSupplierInvoiceNumber(invoice.supplier_invoice_number ?? '')
    setFreightAmount(invoice.freight_amount)
    setOtherCharges(invoice.other_charges)
    setNotes(invoice.notes ?? '')
    setSubtotal(invoice.subtotal)
    setDiscountAmount(invoice.discount_amount)
    setTaxAmount(invoice.tax_amount)
    setGrandTotal(invoice.grand_total)
    setLines((invoice.lines ?? []).map(lineFromServer))
  }

  function resetEditor() {
    setInvoiceUlid(null)
    setDocumentNumber('')
    setStatus('draft')
    setSupplierUlid('')
    setWarehouseUlid(session?.warehouse.ulid ?? '')
    setInvoiceDate(todayIso())
    setDueDate('')
    setSupplierInvoiceNumber('')
    setFreightAmount('0.0000')
    setOtherCharges('0.0000')
    setNotes('')
    setSubtotal('0.0000')
    setDiscountAmount('0.0000')
    setTaxAmount('0.0000')
    setGrandTotal('0.0000')
    setLines([])
    setProductQuery('')
    setError(null)
  }

  async function openInvoice(ulid: string) {
    setError(null)
    try {
      const invoice = await fetchPurchase(ulid)
      applyInvoice(invoice)
      setMode('editor')
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to open purchase.')
    }
  }

  function startNew() {
    resetEditor()
    setMode('editor')
  }

  function backToList() {
    setMode('list')
    setError(null)
    void queryClient.invalidateQueries({ queryKey: ['purchases'] })
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (!supplierUlid || !warehouseUlid) {
        throw new Error('Supplier and warehouse are required.')
      }
      const header = {
        supplier_ulid: supplierUlid,
        warehouse_ulid: warehouseUlid,
        invoice_date: invoiceDate,
        due_date: dueDate || null,
        supplier_invoice_number: supplierInvoiceNumber || null,
        freight_amount: freightAmount || '0',
        other_charges: otherCharges || '0',
        notes: notes || null,
      }

      let invoice = invoiceUlid
        ? await updatePurchase(invoiceUlid, header)
        : await createPurchase(header)

      for (const line of lines) {
        if (!line.product_ulid || !line.unit_ulid) continue
        const payload = {
          product_ulid: line.product_ulid,
          unit_ulid: line.unit_ulid,
          quantity: line.quantity || '0',
          conversion_factor: line.conversion_factor || '1',
          unit_cost: line.unit_cost || '0',
          discount_amount: line.discount_amount || '0',
          tax_amount: line.tax_amount || '0',
          supplier_product_code: line.supplier_product_code || null,
          batch_number: line.batch_number || null,
          expiry_date: line.expiry_date || null,
        }
        if (line.ulid) {
          await updatePurchaseLine(invoice.ulid, line.ulid, payload)
        } else {
          await createPurchaseLine(invoice.ulid, payload)
        }
      }

      invoice = await fetchPurchase(invoice.ulid)
      return invoice
    },
    onSuccess: (invoice) => {
      applyInvoice(invoice)
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['purchases'] })
    },
  })

  const postMutation = useMutation({
    mutationFn: async () => {
      if (!invoiceUlid) {
        const saved = await saveMutation.mutateAsync()
        return postPurchase(saved.ulid)
      }
      if (canEdit && status === 'draft') {
        await saveMutation.mutateAsync()
      }
      return postPurchase(invoiceUlid)
    },
    onSuccess: (invoice) => {
      applyInvoice(invoice)
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['purchases'] })
    },
  })

  async function onSave(event?: FormEvent) {
    event?.preventDefault()
    if (readOnly || (invoiceUlid ? !canEdit : !canCreate)) return
    setError(null)
    try {
      await saveMutation.mutateAsync()
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : err instanceof Error ? err.message : 'Unable to save.')
    }
  }

  async function onPost() {
    if (!canPost || readOnly) return
    if (!window.confirm('Post this purchase? Stock will be updated and the document becomes immutable.')) return
    setError(null)
    try {
      await postMutation.mutateAsync()
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to post purchase.')
    }
  }

  function addProduct(product: Product) {
    const unit = resolveProductSelection(product, productQuery)
    if (!unit.unit_ulid) {
      setError('Selected product has no base unit.')
      return
    }
    setLines((prev) => [
      ...prev,
      {
        key: `new-${Date.now()}-${prev.length}`,
        product_ulid: product.ulid,
        product_label: `${product.product_number} · ${product.name}`,
        unit_ulid: unit.unit_ulid,
        unit_label: unit.unit_label,
        quantity: '1.000000',
        conversion_factor: unit.conversion_factor,
        unit_cost: '0.0000',
        discount_amount: '0.0000',
        tax_amount: '0.0000',
        supplier_product_code: '',
        batch_number: '',
        expiry_date: '',
        track_batch: product.track_batch,
        track_expiry: product.track_expiry,
      },
    ])
    setProductQuery('')
  }

  async function removeLine(line: DraftLine) {
    if (readOnly || !canEdit) return
    if (line.ulid && invoiceUlid) {
      try {
        await deletePurchaseLine(invoiceUlid, line.ulid)
        const refreshed = await fetchPurchase(invoiceUlid)
        applyInvoice(refreshed)
      } catch (err) {
        setError(err instanceof ApiClientError ? err.message : 'Unable to remove line.')
      }
      return
    }
    setLines((prev) => prev.filter((row) => row.key !== line.key))
  }

  useWorkspaceHandlers({
    save: () => void onSave(),
    refresh: () => {
      if (mode === 'list') void listQuery.refetch()
      else if (invoiceUlid) void openInvoice(invoiceUlid)
    },
  })

  const previewSubtotal = useMemo(() => {
    return lines.reduce((sum, line) => {
      const qty = Number(line.quantity) || 0
      const cost = Number(line.unit_cost) || 0
      return sum + qty * cost
    }, 0)
  }, [lines])

  if (mode === 'list') {
    return (
      <DesktopPanel
        title="Purchase Invoices"
        toolbar={
          <>
            <DesktopButton icon={<Plus size={13} />} label="New" disabled={!canCreate} onClick={startNew} />
            <DesktopButton
              icon={<Save size={13} />}
              label="Open"
              disabled={!selectedListKey}
              onClick={() => selectedListKey && void openInvoice(selectedListKey)}
            />
            <DesktopButton
              icon={<RefreshCw size={13} />}
              label="Refresh"
              shortcut="F8"
              onClick={() => void listQuery.refetch()}
            />
            <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
          </>
        }
      >
        <div className="flex flex-wrap gap-2 mb-2 text-[11px]">
          <input
            className="desktop-input"
            style={{ width: 180 }}
            placeholder="Search…"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
          <input
            className="desktop-input"
            type="date"
            value={dateFrom}
            onChange={(e) => setDateFrom(e.target.value)}
            title="From"
          />
          <input
            className="desktop-input"
            type="date"
            value={dateTo}
            onChange={(e) => setDateTo(e.target.value)}
            title="To"
          />
          <select
            className="desktop-select"
            value={supplierFilter}
            onChange={(e) => setSupplierFilter(e.target.value)}
          >
            <option value="">All suppliers</option>
            {suppliers.map((s) => (
              <option key={s.ulid} value={s.ulid}>{s.code} — {s.name}</option>
            ))}
          </select>
          <select
            className="desktop-select"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
          >
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="posted">Posted</option>
          </select>
        </div>
        {error ? <div className="text-[12px] text-red-700 mb-2">{error}</div> : null}
        <PosDataGrid
          columns={[
            { key: 'document_number', header: 'Document #', width: 110 },
            { key: 'invoice_date', header: 'Date', width: 100 },
            {
              key: 'supplier',
              header: 'Supplier',
              render: (row) => row.supplier?.name ?? '—',
            },
            {
              key: 'supplier_invoice_number',
              header: 'Supplier Invoice #',
              width: 130,
              render: (row) => row.supplier_invoice_number ?? '—',
            },
            {
              key: 'warehouse',
              header: 'Warehouse',
              width: 120,
              render: (row) => row.warehouse?.name ?? '—',
            },
            {
              key: 'grand_total',
              header: 'Total',
              align: 'right',
              width: 100,
              render: (row) => money(row.grand_total),
            },
            {
              key: 'status',
              header: 'Status',
              width: 90,
              render: (row) => row.status.toUpperCase(),
            },
          ]}
          rows={listRows}
          rowKey={(row) => row.ulid}
          selectedKey={selectedListKey}
          onSelect={(row) => setSelectedListKey(row.ulid)}
          onActivate={(row) => void openInvoice(row.ulid)}
          emptyMessage={listQuery.isLoading ? 'Loading…' : 'No purchase invoices'}
        />
      </DesktopPanel>
    )
  }

  const editable = !readOnly && (invoiceUlid ? canEdit : canCreate)
  const previewDiscountPercent = previewSubtotal > 0
    ? ((Number(discountAmount || 0) / previewSubtotal) * 100).toFixed(2)
    : '0.00'

  return (
    <div className="purchase-reference-screen">
      <div className="purchase-reference-subtabs">
        <button type="button" className="purchase-reference-subtab is-active">
          <span className="purchase-reference-tab-icon is-cyan"><Table2 /></span>
          <span>Purchase Invoice</span>
        </button>
        <button type="button" className="purchase-reference-subtab" onClick={backToList}>
          <span className="purchase-reference-tab-icon is-blue"><Binoculars /></span>
          <span>Search</span>
        </button>
        <button type="button" className="purchase-reference-subtab" disabled>
          <span className="purchase-reference-tab-icon is-multi"><LayoutGrid /></span>
          <span>(0,Due:0) Pending Purchases</span>
        </button>
        <button type="button" className="purchase-reference-subtab" disabled>
          <span className="purchase-reference-tab-icon is-orange"><Receipt /></span>
          <span>Other Expenses</span>
        </button>
        <button type="button" className="purchase-reference-subtab" disabled>
          <span className="purchase-reference-tab-icon is-green"><Package /></span>
          <span>Product Wise</span>
        </button>
        <div className="purchase-reference-title">Purchase Invoice</div>
      </div>

      <div className="purchase-reference-top">
        <fieldset className="purchase-reference-options">
          <legend>Purchase Invoice Options</legend>

          <div className="purchase-reference-option-line">
            <label>Inv#:</label>
            <input className="purchase-reference-inv" value={documentNumber || 'Auto'} disabled />
            <label>Date:</label>
            <input
              className="purchase-reference-date"
              type="date"
              value={invoiceDate}
              disabled={!editable}
              onChange={(e) => setInvoiceDate(e.target.value)}
            />
            <select className="purchase-reference-credit" value="credit" disabled>
              <option value="credit">CREDIT</option>
            </select>
            <label>Inv #:</label>
            <input
              className="purchase-reference-supplier-inv"
              value={supplierInvoiceNumber}
              disabled={!editable}
              onChange={(e) => setSupplierInvoiceNumber(e.target.value)}
            />
          </div>

          <div className="purchase-reference-from-line">
            <label>From:</label>
            <select
              value={supplierUlid}
              disabled={!editable}
              onChange={(e) => setSupplierUlid(e.target.value)}
            >
              <option value="">Select supplier...</option>
              {suppliers.map((supplier) => (
                <option key={supplier.ulid} value={supplier.ulid}>
                  {supplier.code} — {supplier.name}
                </option>
              ))}
            </select>
            <button type="button" className="purchase-reference-mini" disabled title="Use Supplier master">▼</button>
            <button type="button" className="purchase-reference-mini" disabled title="Use Supplier master">+</button>
          </div>

          <div className="purchase-reference-rem-line">
            <label>Rem:</label>
            <textarea
              value={notes}
              disabled={!editable}
              onChange={(e) => setNotes(e.target.value)}
              rows={2}
            />
            <div className="purchase-reference-order">
              <label>Order No:</label>
              <input disabled />
            </div>
          </div>

          <div className="purchase-reference-warehouse-line">
            <label>Warehouse:</label>
            <select
              value={warehouseUlid}
              disabled={!editable}
              onChange={(e) => setWarehouseUlid(e.target.value)}
            >
              <option value="">Select warehouse...</option>
              {warehouses.map((warehouse) => (
                <option key={warehouse.ulid} value={warehouse.ulid}>
                  {warehouse.code} — {warehouse.name}
                </option>
              ))}
            </select>
            <label>Due:</label>
            <input
              type="date"
              value={dueDate}
              disabled={!editable}
              onChange={(e) => setDueDate(e.target.value)}
            />
            {status !== 'draft' ? (
              <span className="purchase-reference-status">Status: {status.toUpperCase()}</span>
            ) : null}
          </div>
        </fieldset>

        <div className="purchase-reference-center">
          <div className="purchase-reference-checks">
            <label><input type="checkbox" disabled /> Payment Due</label>
            <label><input type="checkbox" disabled /> On Hold</label>
          </div>

          <div className="purchase-reference-center-panels">
            <div className="purchase-reference-balance">
              <div className="purchase-reference-balance-head">
                <button type="button" disabled>Refresh</button>
              </div>
              <div className="purchase-reference-balance-row is-prev">
                <span>Previous</span><strong>0</strong>
              </div>
              <div className="purchase-reference-balance-row is-this">
                <span>This Bill</span><strong>{money(grandTotal || previewSubtotal)}</strong>
              </div>
              <div className="purchase-reference-balance-row is-total">
                <span>Total Balance</span><strong>{money(grandTotal || previewSubtotal)}</strong>
              </div>
            </div>

            <div className="purchase-reference-discount">
              <div><span>Disc.(C)</span><strong>{money(discountAmount)}</strong></div>
              <div><span>Disc.(%)</span><strong>{previewDiscountPercent}</strong></div>
              <div><span>Sales Tax (%)</span><strong>0</strong></div>
            </div>
          </div>
        </div>

        <fieldset className="purchase-reference-amount">
          <legend>
            <span>Amount Options</span>
            <span className="purchase-reference-amount-tools">
              <button type="button" disabled>Add</button>
              <button type="button" disabled title="Get">Get</button>
              <button type="button" disabled>Import</button>
            </span>
          </legend>

          <div className="purchase-reference-amount-grid">
            <label>Amount(Rs):</label>
            <strong className="is-cyan">{money(subtotal || previewSubtotal)}</strong>
            <label className="is-red">Disc.:</label>
            <strong>{money(discountAmount)}</strong>
            <em>{previewDiscountPercent}%</em>

            <label>Others:</label>
            <input
              className="is-green"
              value={otherCharges}
              disabled={!editable}
              onChange={(e) => setOtherCharges(e.target.value)}
            />
            <label className="is-red">Tax:</label>
            <strong>{money(taxAmount)}</strong>
            <input className="is-tax-extra" value="0" disabled />

            <label>Freight:</label>
            <input
              className="is-green-soft"
              value={freightAmount}
              disabled={!editable}
              onChange={(e) => setFreightAmount(e.target.value)}
            />
            <span /><span /><span />

            <label className="is-net-label">Net Payable:</label>
            <strong className="is-yellow">{money(grandTotal || previewSubtotal)}</strong>
          </div>
        </fieldset>
      </div>

      {!readOnly ? (
        <div className="purchase-f1-row">
          <div className="purchase-f1-search">
            <input
              value={productQuery}
              onChange={(e) => setProductQuery(e.target.value)}
              placeholder="Search product # / SKU / name / barcode..."
              aria-label="Product lookup"
            />
            {productLookup.data?.data?.length ? (
              <div className="purchase-reference-results">
                {productLookup.data.data.map((product) => (
                  <button key={product.ulid} type="button" onClick={() => addProduct(product)}>
                    {product.product_number} · {product.name}{product.sku ? ` · ${product.sku}` : ''}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
          <div className="purchase-f1-label">F1 to Add New</div>
          <div className="purchase-f1-spacer" />
          <button type="button" className="purchase-f1-chk" disabled>Chk</button>
        </div>
      ) : (
        <div className="purchase-reference-posted-strip">
          Status: {status.toUpperCase()}
        </div>
      )}

      {error ? <div className="purchase-reference-error">{error}</div> : null}

      <div className="purchase-reference-grid-wrap">
        <table className="purchase-reference-grid">
          <thead>
            <tr>
              <th className="col-sel" />
              <th className="col-product">ITEM / PRODUCT DESCRIPTION</th>
              <th className="col-stock">In Stock</th>
              <th className="col-qty">Quantity</th>
              <th className="col-price">Price (C)</th>
              <th className="col-disc">Disc-Rs</th>
              <th className="col-desc">DESC.</th>
              <th className="col-disc2">Disc</th>
              <th className="col-disrs">Dis-Rs</th>
              <th className="col-dispct">Dis %</th>
              <th className="col-tax">Tax</th>
              <th className="col-taxamt">Tax Amt</th>
              <th className="col-at">A.T</th>
              <th className="col-atamt">AT Amt</th>
              <th className="col-amt">AMT</th>
              <th className="col-batch">Batch</th>
              <th className="col-expiry">Expiry</th>
              <th className="col-amount">Amount</th>
              <th className="col-margin">Margin</th>
              <th className="col-sale">Sale Rate (N)</th>
              <th className="col-p">P</th>
              <th className="col-del">-</th>
            </tr>
          </thead>
          <tbody>
            {lines.length === 0 ? (
              <tr className="is-empty">
                <td>*</td>
                <td />
                <td /><td className="cell-yellow" /><td className="cell-yellow" />
                <td /><td /><td /><td /><td className="cell-yellow" />
                <td className="cell-yellow" /><td /><td /><td />
                <td className="cell-yellow" /><td /><td /><td />
                <td className="cell-cyan" /><td className="cell-cyan" /><td /><td />
              </tr>
            ) : lines.map((row) => {
              const qty = Number(row.quantity) || 0
              const factor = Number(row.conversion_factor) || 0
              const lineAmount = Number(
                row.line_total ??
                  (qty * (Number(row.unit_cost) || 0) -
                    (Number(row.discount_amount) || 0) +
                    (Number(row.tax_amount) || 0)),
              ) || 0
              const baseQty = row.base_quantity ?? (qty * factor).toFixed(6)
              const discountPercent =
                qty > 0 && Number(row.unit_cost) > 0
                  ? ((Number(row.discount_amount || 0) / (qty * Number(row.unit_cost))) * 100).toFixed(2)
                  : '0.00'

              return (
                <tr key={row.key}>
                  <td className="col-sel">*</td>
                  <td className="col-product">{row.product_label}</td>
                  <td className="is-num">—</td>
                  <td className="is-num cell-yellow">
                    {readOnly ? (
                      row.quantity
                    ) : (
                      <input
                        value={row.quantity}
                        onChange={(e) =>
                          setLines((prev) =>
                            prev.map((line) =>
                              line.key === row.key ? { ...line, quantity: e.target.value } : line,
                            ),
                          )
                        }
                      />
                    )}
                  </td>
                  <td className="is-num cell-yellow">
                    {readOnly ? (
                      money(row.unit_cost)
                    ) : (
                      <input
                        value={row.unit_cost}
                        onChange={(e) =>
                          setLines((prev) =>
                            prev.map((line) =>
                              line.key === row.key ? { ...line, unit_cost: e.target.value } : line,
                            ),
                          )
                        }
                      />
                    )}
                  </td>
                  <td className="is-num">
                    {readOnly ? (
                      money(row.discount_amount)
                    ) : (
                      <input
                        value={row.discount_amount}
                        onChange={(e) =>
                          setLines((prev) =>
                            prev.map((line) =>
                              line.key === row.key
                                ? { ...line, discount_amount: e.target.value }
                                : line,
                            ),
                          )
                        }
                      />
                    )}
                  </td>
                  <td className="is-center">{row.unit_label}</td>
                  <td className="is-num" />
                  <td className="is-num" />
                  <td className="is-num cell-yellow">{discountPercent}</td>
                  <td className="is-num cell-yellow">0</td>
                  <td className="is-num">
                    {readOnly ? (
                      money(row.tax_amount)
                    ) : (
                      <input
                        value={row.tax_amount}
                        onChange={(e) =>
                          setLines((prev) =>
                            prev.map((line) =>
                              line.key === row.key ? { ...line, tax_amount: e.target.value } : line,
                            ),
                          )
                        }
                      />
                    )}
                  </td>
                  <td className="is-num">{row.conversion_factor}</td>
                  <td className="is-num">{baseQty}</td>
                  <td className="is-num cell-yellow">{money(lineAmount)}</td>
                  <td>
                    {readOnly ? (
                      row.batch_number || ''
                    ) : (
                      <input
                        value={row.batch_number}
                        placeholder={row.track_batch ? 'Req' : ''}
                        onChange={(e) =>
                          setLines((prev) =>
                            prev.map((line) =>
                              line.key === row.key
                                ? { ...line, batch_number: e.target.value }
                                : line,
                            ),
                          )
                        }
                      />
                    )}
                  </td>
                  <td>
                    {readOnly ? (
                      row.expiry_date || ''
                    ) : (
                      <input
                        type="date"
                        value={row.expiry_date}
                        onChange={(e) =>
                          setLines((prev) =>
                            prev.map((line) =>
                              line.key === row.key
                                ? { ...line, expiry_date: e.target.value }
                                : line,
                            ),
                          )
                        }
                      />
                    )}
                  </td>
                  <td className="is-num">{money(lineAmount)}</td>
                  <td className="cell-cyan" />
                  <td className="cell-cyan" />
                  <td className="is-center col-p">
                    <span className="purchase-reference-p-icon" title="P" aria-hidden>
                      <Package size={14} />
                    </span>
                  </td>
                  <td className="is-center col-del">
                    {readOnly || !canEdit ? null : (
                      <button type="button" title="Remove" onClick={() => void removeLine(row)}>
                        <XCircle size={16} />
                      </button>
                    )}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="purchase-reference-totals">
        <span />
        <strong>{lines.length}</strong>
        <strong>
          {lines.reduce((sum, row) => sum + (Number(row.quantity) || 0), 0).toFixed(0)}
        </strong>
        <span />
        <strong>{money(discountAmount)}</strong>
        <strong>{money(taxAmount)}</strong>
        <strong>{money(grandTotal || previewSubtotal)}</strong>
      </div>

      <div className="purchase-reference-nav">
        <button type="button" disabled aria-label="First"><ChevronsLeft size={12} /></button>
        <button type="button" disabled aria-label="Prev"><ChevronLeft size={12} /></button>
        <span>Record {lines.length ? 1 : 0} of {lines.length}</span>
        <button type="button" disabled aria-label="Next"><ChevronRight size={12} /></button>
        <button type="button" disabled aria-label="Last"><ChevronsRight size={12} /></button>
        <button type="button" disabled aria-label="Add"><Plus size={11} /></button>
        <button type="button" disabled aria-label="Remove"><Minus size={11} /></button>
        <button type="button" disabled aria-label="Ok"><Check size={11} /></button>
        <button type="button" disabled aria-label="Cancel"><X size={11} /></button>
      </div>

      <div className="purchase-reference-actions">
        <button type="button" className="purchase-reference-delete" disabled>
          <span>Delete</span>
          <span className="purchase-reference-action-icon is-delete"><XCircle /></span>
        </button>

        <div className="purchase-reference-actions-center">
          <button
            type="button"
            className="purchase-reference-btn-save"
            disabled={readOnly || saveMutation.isPending || (invoiceUlid ? !canEdit : !canCreate)}
            onClick={() => void onSave()}
          >
            <span>Save</span>
            <span className="purchase-reference-action-icon is-save"><Save /></span>
          </button>
          <button
            type="button"
            className="purchase-reference-btn-post"
            disabled={readOnly || !canPost || postMutation.isPending}
            onClick={() => void onPost()}
          >
            <span>Post</span>
            <span className="purchase-reference-action-icon is-post"><Send /></span>
          </button>
          <button type="button" className="purchase-reference-btn-print" disabled>
            <span>Print</span>
            <span className="purchase-reference-action-icon is-print"><Printer /></span>
          </button>
          <button
            type="button"
            className="purchase-reference-btn-refresh"
            disabled={!invoiceUlid}
            onClick={() => invoiceUlid && void openInvoice(invoiceUlid)}
          >
            <span>Refresh</span>
            <span className="purchase-reference-action-icon is-refresh"><RefreshCw /></span>
          </button>
          <button type="button" className="purchase-reference-btn-close" onClick={backToList}>
            <span>Close</span>
            <span className="purchase-reference-action-icon is-close"><XCircle /></span>
          </button>
        </div>

        <div className="purchase-reference-actions-right">
          <button type="button" disabled>
            <span>Save &amp;<br />Barcode</span>
            <span className="purchase-reference-action-icon is-barcode"><Barcode /></span>
          </button>
          <button type="button" disabled>
            <span>Save &amp;<br />Print</span>
            <span className="purchase-reference-action-icon is-print"><Printer /></span>
          </button>
        </div>
      </div>
    </div>
  )
}
