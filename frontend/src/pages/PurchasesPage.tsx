import { FormEvent, useMemo, useState } from 'react'
import { Plus, RefreshCw, Save, Send, Trash2, X } from 'lucide-react'
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

  return (
    <div className="pos-invoice" style={{ gridTemplateColumns: '1fr' }}>
      <div className="pos-invoice-main">
        <div className="inner-tabs">
          <button type="button" className="inner-tab is-active">Purchase Invoice</button>
          <span style={{ marginLeft: 'auto', fontWeight: 800, fontSize: 14, padding: '4px 10px' }}>
            {documentNumber || 'New Draft'} · {status.toUpperCase()}
          </span>
        </div>

        <div className="purchase-header">
          <div className="grid gap-1">
            <div className="dense-row is-2">
              <label>Doc#</label>
              <input className="desktop-input" value={documentNumber || 'Auto'} disabled />
              <label>Date</label>
              <input
                className="desktop-input"
                type="date"
                value={invoiceDate}
                disabled={readOnly || (Boolean(invoiceUlid) && !canEdit)}
                onChange={(e) => setInvoiceDate(e.target.value)}
              />
            </div>
            <div className="dense-row is-2">
              <label>Supplier</label>
              <select
                className="desktop-select"
                value={supplierUlid}
                disabled={readOnly || (!invoiceUlid ? !canCreate : !canEdit)}
                onChange={(e) => setSupplierUlid(e.target.value)}
              >
                <option value="">Select supplier…</option>
                {suppliers.map((s) => (
                  <option key={s.ulid} value={s.ulid}>{s.code} — {s.name}</option>
                ))}
              </select>
              <label>Supp. Inv#</label>
              <input
                className="desktop-input"
                value={supplierInvoiceNumber}
                disabled={readOnly || (Boolean(invoiceUlid) && !canEdit)}
                onChange={(e) => setSupplierInvoiceNumber(e.target.value)}
              />
            </div>
            <div className="dense-row is-2">
              <label>Warehouse</label>
              <select
                className="desktop-select"
                value={warehouseUlid}
                disabled={readOnly || (!invoiceUlid ? !canCreate : !canEdit)}
                onChange={(e) => setWarehouseUlid(e.target.value)}
              >
                <option value="">Select warehouse…</option>
                {warehouses.map((w) => (
                  <option key={w.ulid} value={w.ulid}>{w.code} — {w.name}</option>
                ))}
              </select>
              <label>Due</label>
              <input
                className="desktop-input"
                type="date"
                value={dueDate}
                disabled={readOnly || (Boolean(invoiceUlid) && !canEdit)}
                onChange={(e) => setDueDate(e.target.value)}
              />
            </div>
            <div className="dense-row">
              <label>Notes</label>
              <textarea
                className="desktop-textarea"
                rows={2}
                value={notes}
                disabled={readOnly || (Boolean(invoiceUlid) && !canEdit)}
                onChange={(e) => setNotes(e.target.value)}
              />
            </div>
          </div>

          <div className="amt-stack">
            <div className="amt-box is-cyan"><span>Subtotal</span><input disabled value={money(subtotal || previewSubtotal)} readOnly /></div>
            <div className="amt-box is-red"><span>Discount</span><input disabled value={money(discountAmount)} readOnly /></div>
            <div className="amt-box is-red"><span>Tax</span><input disabled value={money(taxAmount)} readOnly /></div>
            <div className="amt-box is-green">
              <span>Freight</span>
              <input
                value={freightAmount}
                disabled={readOnly || (Boolean(invoiceUlid) && !canEdit)}
                onChange={(e) => setFreightAmount(e.target.value)}
              />
            </div>
            <div className="amt-box is-green">
              <span>Other</span>
              <input
                value={otherCharges}
                disabled={readOnly || (Boolean(invoiceUlid) && !canEdit)}
                onChange={(e) => setOtherCharges(e.target.value)}
              />
            </div>
            <div className="amt-box is-yellow"><span>Grand Total</span><input disabled value={money(grandTotal)} readOnly /></div>
          </div>
        </div>

        {!readOnly ? (
          <div className="f1-row" style={{ position: 'relative' }}>
            <input
              className="f1-search"
              placeholder="Search product # / SKU / name / barcode…"
              value={productQuery}
              onChange={(e) => setProductQuery(e.target.value)}
              aria-label="Product lookup"
            />
            <div className="f1-hint">Add line</div>
            {productLookup.data?.data?.length ? (
              <div
                style={{
                  position: 'absolute',
                  left: 0,
                  right: 80,
                  top: '100%',
                  zIndex: 20,
                  background: '#fff',
                  border: '1px solid #94a3b8',
                  maxHeight: 200,
                  overflow: 'auto',
                }}
              >
                {productLookup.data.data.map((product) => (
                  <button
                    key={product.ulid}
                    type="button"
                    className="block w-full text-left px-2 py-1 text-[12px] hover:bg-sky-100"
                    onClick={() => addProduct(product)}
                  >
                    {product.product_number} · {product.name}
                    {product.sku ? ` · ${product.sku}` : ''}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        ) : null}

        {error ? <div className="text-[12px] text-red-700 px-2 py-1">{error}</div> : null}

        <div className="invoice-grid">
          <PosDataGrid
            columns={[
              {
                key: 'product',
                header: 'Product',
                render: (row) => row.product_label,
              },
              { key: 'unit_label', header: 'Unit', width: 70 },
              {
                key: 'quantity',
                header: 'Qty',
                width: 80,
                align: 'right',
                render: (row) =>
                  readOnly ? (
                    row.quantity
                  ) : (
                    <input
                      className="desktop-input col-yellow"
                      value={row.quantity}
                      onChange={(e) =>
                        setLines((prev) =>
                          prev.map((l) => (l.key === row.key ? { ...l, quantity: e.target.value } : l)),
                        )
                      }
                    />
                  ),
              },
              {
                key: 'conversion_factor',
                header: 'Factor',
                width: 70,
                align: 'right',
                render: (row) =>
                  readOnly ? (
                    row.conversion_factor
                  ) : (
                    <input
                      className="desktop-input"
                      value={row.conversion_factor}
                      onChange={(e) =>
                        setLines((prev) =>
                          prev.map((l) =>
                            l.key === row.key ? { ...l, conversion_factor: e.target.value } : l,
                          ),
                        )
                      }
                    />
                  ),
              },
              {
                key: 'base_quantity',
                header: 'Base Qty',
                width: 80,
                align: 'right',
                render: (row) =>
                  row.base_quantity ??
                  (((Number(row.quantity) || 0) * (Number(row.conversion_factor) || 0)).toFixed(6)),
              },
              {
                key: 'unit_cost',
                header: 'Unit Cost',
                width: 90,
                align: 'right',
                render: (row) =>
                  readOnly ? (
                    money(row.unit_cost)
                  ) : (
                    <input
                      className="desktop-input col-yellow"
                      value={row.unit_cost}
                      onChange={(e) =>
                        setLines((prev) =>
                          prev.map((l) => (l.key === row.key ? { ...l, unit_cost: e.target.value } : l)),
                        )
                      }
                    />
                  ),
              },
              {
                key: 'discount_amount',
                header: 'Discount',
                width: 80,
                align: 'right',
                render: (row) =>
                  readOnly ? (
                    money(row.discount_amount)
                  ) : (
                    <input
                      className="desktop-input"
                      value={row.discount_amount}
                      onChange={(e) =>
                        setLines((prev) =>
                          prev.map((l) =>
                            l.key === row.key ? { ...l, discount_amount: e.target.value } : l,
                          ),
                        )
                      }
                    />
                  ),
              },
              {
                key: 'tax_amount',
                header: 'Tax',
                width: 70,
                align: 'right',
                render: (row) =>
                  readOnly ? (
                    money(row.tax_amount)
                  ) : (
                    <input
                      className="desktop-input"
                      value={row.tax_amount}
                      onChange={(e) =>
                        setLines((prev) =>
                          prev.map((l) => (l.key === row.key ? { ...l, tax_amount: e.target.value } : l)),
                        )
                      }
                    />
                  ),
              },
              {
                key: 'line_total',
                header: 'Line Total',
                width: 90,
                align: 'right',
                render: (row) =>
                  money(
                    row.line_total ??
                      (
                        (Number(row.quantity) || 0) * (Number(row.unit_cost) || 0) -
                        (Number(row.discount_amount) || 0) +
                        (Number(row.tax_amount) || 0)
                      ),
                  ),
              },
              {
                key: 'batch_number',
                header: 'Batch',
                width: 90,
                render: (row) =>
                  readOnly ? (
                    row.batch_number || '—'
                  ) : (
                    <input
                      className="desktop-input"
                      value={row.batch_number}
                      placeholder={row.track_batch ? 'Required' : ''}
                      onChange={(e) =>
                        setLines((prev) =>
                          prev.map((l) =>
                            l.key === row.key ? { ...l, batch_number: e.target.value } : l,
                          ),
                        )
                      }
                    />
                  ),
              },
              {
                key: 'expiry_date',
                header: 'Expiry',
                width: 110,
                render: (row) =>
                  readOnly ? (
                    row.expiry_date || '—'
                  ) : (
                    <input
                      className="desktop-input"
                      type="date"
                      value={row.expiry_date}
                      onChange={(e) =>
                        setLines((prev) =>
                          prev.map((l) =>
                            l.key === row.key ? { ...l, expiry_date: e.target.value } : l,
                          ),
                        )
                      }
                    />
                  ),
              },
              {
                key: 'actions',
                header: '',
                width: 40,
                render: (row) =>
                  readOnly || !canEdit ? null : (
                    <button type="button" title="Remove" onClick={() => void removeLine(row)}>
                      <Trash2 size={12} />
                    </button>
                  ),
              },
            ]}
            rows={lines}
            rowKey={(row) => row.key}
            emptyMessage="No lines — search a product above"
          />
        </div>

        <div className="invoice-bottom">
          <span className="text-[11px] text-[var(--text-muted)]">
            {lines.length} line{lines.length === 1 ? '' : 's'}
          </span>
          <div className="flex gap-1">
            <DesktopButton
              icon={<Save size={13} />}
              label="Save Draft"
              shortcut="F9"
              disabled={readOnly || saveMutation.isPending || (invoiceUlid ? !canEdit : !canCreate)}
              onClick={() => void onSave()}
            />
            <DesktopButton
              icon={<Send size={13} />}
              label="Post"
              disabled={readOnly || !canPost || postMutation.isPending}
              onClick={() => void onPost()}
            />
            <DesktopButton
              icon={<RefreshCw size={13} />}
              label="Refresh"
              shortcut="F8"
              disabled={!invoiceUlid}
              onClick={() => invoiceUlid && void openInvoice(invoiceUlid)}
            />
            <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={backToList} />
          </div>
        </div>
      </div>
    </div>
  )
}
