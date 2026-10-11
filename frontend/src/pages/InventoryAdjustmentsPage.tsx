import { useDeferredValue, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  FileSearch,
  PackageSearch,
  Plus,
  RefreshCw,
  Save,
  Send,
  Trash2,
  X,
} from 'lucide-react'
import { fetchProducts } from '../api/catalog'
import {
  createInventoryAdjustment,
  createInventoryAdjustmentLine,
  deleteInventoryAdjustment,
  deleteInventoryAdjustmentLine,
  fetchInventoryAdjustment,
  fetchInventoryAdjustmentAccounts,
  fetchInventoryAdjustments,
  postInventoryAdjustment,
  updateInventoryAdjustment,
  updateInventoryAdjustmentLine,
} from '../api/inventory'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import type { Product } from '../types/catalog'
import type {
  InventoryAdjustment,
  InventoryAdjustmentLine,
} from '../types/inventory'
import './InventoryAdjustmentsPage.css'

function localIsoDate(date = new Date()) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function todayIso() {
  return localIsoDate()
}

function monthStartIso() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

function productLabel(product: Product) {
  return `${product.product_number} · ${product.name}`
}

function signedNet(line: InventoryAdjustmentLine): number {
  const plus = Number.parseFloat(line.qty_plus || '0')
  const minus = Number.parseFloat(line.qty_minus || '0')
  const amount = Number.parseFloat(line.amount || '0')
  return plus > 0 ? amount : minus > 0 ? -amount : 0
}

type TabKey = 'invoice' | 'search' | 'product'

type ProductWiseRow = {
  key: string
  document_number: string
  date: string
  account: string
  product: string
  qty_plus: string
  qty_minus: string
  amount: string
  remarks: string | null
}

export function InventoryAdjustmentsPage() {
  const queryClient = useQueryClient()
  const feedback = useFeedback()
  const { session } = useAuth()
  const { closeActiveTab } = useWorkspace()
  const canAdjust = useCan('inventory.adjust')

  const [tab, setTab] = useState<TabKey>('invoice')
  const [documentUlid, setDocumentUlid] = useState<string | null>(null)
  const [adjustmentDate, setAdjustmentDate] = useState(todayIso())
  const [accountUlid, setAccountUlid] = useState('')
  const [documentNotes, setDocumentNotes] = useState('')
  const [headerDirty, setHeaderDirty] = useState(false)

  const [productSearch, setProductSearch] = useState('')
  const deferredProductSearch = useDeferredValue(productSearch)
  const [productUlid, setProductUlid] = useState('')
  const [qtyPlus, setQtyPlus] = useState('')
  const [qtyMinus, setQtyMinus] = useState('')
  const [lineRemarks, setLineRemarks] = useState('')
  const [selectedLineUlid, setSelectedLineUlid] = useState<string | null>(null)
  const [lineDirty, setLineDirty] = useState(false)

  const [fromDate, setFromDate] = useState(monthStartIso())
  const [toDate, setToDate] = useState(todayIso())
  const [statusFilter, setStatusFilter] = useState('')
  const [selectedSearchUlid, setSelectedSearchUlid] = useState<string | null>(null)
  const [productWiseSearch, setProductWiseSearch] = useState('')
  const deferredProductWiseSearch = useDeferredValue(productWiseSearch)

  const accountsQuery = useQuery({
    queryKey: ['inventory-adjustment-accounts'],
    queryFn: fetchInventoryAdjustmentAccounts,
  })

  const documentQuery = useQuery({
    queryKey: ['inventory-adjustment', documentUlid],
    queryFn: () => fetchInventoryAdjustment(documentUlid as string),
    enabled: Boolean(documentUlid),
  })

  const listQuery = useQuery({
    queryKey: ['inventory-adjustments', fromDate, toDate, statusFilter],
    queryFn: () => fetchInventoryAdjustments({
      from_date: fromDate || undefined,
      to_date: toDate || undefined,
      status: statusFilter || undefined,
    }),
  })

  const productsQuery = useQuery({
    queryKey: ['products', 'inventory-adjustments', deferredProductSearch],
    queryFn: () => fetchProducts(
      {
        q: deferredProductSearch.trim() || undefined,
        per_page: 50,
        active_only: true,
        sales_lookup: true,
      },
      { busy: 'none' },
    ),
    enabled: tab === 'invoice',
  })

  const document = documentQuery.data ?? null
  const editable = Boolean(document && document.status === 'draft' && canAdjust)
  const entryEnabled = canAdjust && (!document || document.status === 'draft')

  useEffect(() => {
    if (!document) return
    setAdjustmentDate(document.adjustment_date)
    setAccountUlid(document.account?.ulid ?? '')
    setDocumentNotes(document.notes ?? '')
    setHeaderDirty(false)
  }, [
    document?.ulid,
    document?.adjustment_date,
    document?.account?.ulid,
    document?.notes,
  ])

  const accountOptions = (accountsQuery.data?.data ?? []).map((account) => ({
    value: account.ulid,
    label: `${account.code} — ${account.name}`,
  }))

  const productOptions = (productsQuery.data?.data ?? []).map((product) => ({
    value: product.ulid,
    label: productLabel(product),
  }))

  function clearLineForm() {
    setProductSearch('')
    setProductUlid('')
    setQtyPlus('')
    setQtyMinus('')
    setLineRemarks('')
    setSelectedLineUlid(null)
    setLineDirty(false)
  }

  function newDocument() {
    setDocumentUlid(null)
    setAdjustmentDate(todayIso())
    setAccountUlid('')
    setDocumentNotes('')
    setHeaderDirty(false)
    clearLineForm()
    setTab('invoice')
  }

  function fillLineForm(line: InventoryAdjustmentLine) {
    setSelectedLineUlid(line.ulid)
    setProductUlid(line.product?.ulid ?? '')
    setProductSearch(line.product ? `${line.product.product_number} · ${line.product.name}` : '')
    setQtyPlus(line.qty_plus === '0.000000' ? '' : line.qty_plus)
    setQtyMinus(line.qty_minus === '0.000000' ? '' : line.qty_minus)
    setLineRemarks(line.remarks ?? '')
    setLineDirty(false)
  }

  function openDocument(row: InventoryAdjustment) {
    setDocumentUlid(row.ulid)
    setTab('invoice')
    clearLineForm()
  }

  const saveHeader = useMutation({
    mutationFn: () => {
      if (!accountUlid) {
        throw new Error('Select an adjustment account.')
      }

      const payload = {
        account_ulid: accountUlid,
        adjustment_date: adjustmentDate,
        adjustment_type: 'stock_adjustment' as const,
        notes: documentNotes.trim() || null,
      }

      return documentUlid
        ? updateInventoryAdjustment(documentUlid, payload)
        : createInventoryAdjustment(payload)
    },
    onSuccess: async (saved) => {
      setDocumentUlid(saved.ulid)
      setHeaderDirty(false)
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustment', saved.ulid] })
      feedback.success(documentUlid ? 'Adjustment header saved.' : `Adjustment ${saved.document_number} created.`)
    },
    onError: (error) => {
      if (error instanceof Error && error.message === 'Select an adjustment account.') {
        feedback.info(error.message, 'Adjustment')
        return
      }
      feedback.fromApiError(error, 'Unable to save adjustment.')
    },
  })

  const saveLine = useMutation({
    mutationFn: async () => {
      if (!productUlid) throw new Error('Select a product.')
      if (!qtyPlus.trim() && !qtyMinus.trim()) {
        throw new Error('Enter Qty + or Qty -.')
      }

      let targetDocumentUlid = documentUlid
      let createdDocumentNumber: string | null = null

      if (!targetDocumentUlid) {
        if (!accountUlid) throw new Error('Select an adjustment account.')

        const created = await createInventoryAdjustment({
          account_ulid: accountUlid,
          adjustment_date: adjustmentDate,
          adjustment_type: 'stock_adjustment',
          notes: documentNotes.trim() || null,
        })

        targetDocumentUlid = created.ulid
        createdDocumentNumber = created.document_number
      }

      const payload = {
        product_ulid: productUlid,
        qty_plus: qtyPlus.trim() || '0',
        qty_minus: qtyMinus.trim() || '0',
        notes: lineRemarks.trim() || null,
      }

      const line = selectedLineUlid
        ? await updateInventoryAdjustmentLine(targetDocumentUlid, selectedLineUlid, payload)
        : await createInventoryAdjustmentLine(targetDocumentUlid, payload)

      return {
        line,
        documentUlid: targetDocumentUlid,
        createdDocumentNumber,
      }
    },
    onSuccess: async (result) => {
      const wasUpdate = Boolean(selectedLineUlid)

      if (!documentUlid) {
        setDocumentUlid(result.documentUlid)
        setHeaderDirty(false)
      }

      clearLineForm()
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustment', result.documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })

      feedback.success(
        result.createdDocumentNumber
          ? `Adjustment ${result.createdDocumentNumber} created and line added.`
          : wasUpdate
            ? 'Adjustment line updated.'
            : 'Adjustment line added.',
      )
    },
    onError: (error) => {
      if (error instanceof Error && (
        error.message === 'Select an adjustment account.' ||
        error.message === 'Select a product.' ||
        error.message === 'Enter Qty + or Qty -.'
      )) {
        feedback.info(error.message, 'Adjustment')
        return
      }
      feedback.fromApiError(error, 'Unable to save adjustment line.')
    },
  })

  const removeLine = useMutation({
    mutationFn: (lineUlid: string) => deleteInventoryAdjustmentLine(documentUlid as string, lineUlid),
    onSuccess: async () => {
      clearLineForm()
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustment', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })
      feedback.success('Adjustment line removed.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to remove adjustment line.'),
  })

  const postDocument = useMutation({
    mutationFn: () => postInventoryAdjustment(documentUlid as string),
    onSuccess: async () => {
      clearLineForm()
      setHeaderDirty(false)
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustment', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })
      await queryClient.invalidateQueries({ queryKey: ['products'] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock'] })
      feedback.success('Adjustment posted. Stock movements and accounting are final.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to post adjustment.'),
  })

  const removeDocument = useMutation({
    mutationFn: (ulid: string) => deleteInventoryAdjustment(ulid),
    onSuccess: async () => {
      newDocument()
      setSelectedSearchUlid(null)
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })
      feedback.success('Draft adjustment deleted.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to delete adjustment.'),
  })

  const lineColumns = useMemo<PosGridColumn<InventoryAdjustmentLine>[]>(() => [
    {
      key: 'product',
      header: 'ITEM / PRODUCT DESCRIPTION',
      width: '30%',
      render: (row) => row.product ? `${row.product.product_number} · ${row.product.name}` : '—',
    },
    { key: 'instock', header: 'In Stock', width: 95, align: 'right', render: (row) => row.in_stock },
    { key: 'plus', header: 'Qty +', width: 95, align: 'right', render: (row) => row.qty_plus },
    { key: 'minus', header: 'Qty -', width: 95, align: 'right', render: (row) => row.qty_minus },
    { key: 'after', header: 'After Stock', width: 105, align: 'right', render: (row) => row.after_stock },
    { key: 'price', header: 'Price', width: 100, align: 'right', render: (row) => row.price },
    { key: 'amount', header: 'Amount', width: 110, align: 'right', render: (row) => row.amount },
    { key: 'remarks', header: 'Remarks', render: (row) => row.remarks ?? '—' },
  ], [])

  const searchColumns = useMemo<PosGridColumn<InventoryAdjustment>[]>(() => [
    { key: 'number', header: 'Adj #', width: 120, render: (row) => row.document_number },
    { key: 'date', header: 'Date', width: 110, render: (row) => row.adjustment_date },
    { key: 'warehouse', header: 'Store', width: 180, render: (row) => row.warehouse?.name ?? '—' },
    { key: 'account', header: 'Account Adjusted', width: '28%', render: (row) => row.account ? `${row.account.code} — ${row.account.name}` : '—' },
    { key: 'status', header: 'Status', width: 90, render: (row) => row.status.toUpperCase() },
    { key: 'qty', header: 'Lines', width: 70, align: 'right', render: (row) => String(row.lines?.length ?? 0) },
    { key: 'net', header: 'Net', width: 110, align: 'right', render: (row) => row.net_amount },
  ], [])

  const productWiseRows = useMemo<ProductWiseRow[]>(() => {
    const needle = deferredProductWiseSearch.trim().toLowerCase()

    return (listQuery.data ?? []).flatMap((doc) =>
      (doc.lines ?? [])
        .filter((line) => {
          if (!needle) return true
          const label = `${line.product?.product_number ?? ''} ${line.product?.sku ?? ''} ${line.product?.name ?? ''}`.toLowerCase()
          return label.includes(needle)
        })
        .map((line) => ({
          key: `${doc.ulid}:${line.ulid}`,
          document_number: doc.document_number,
          date: doc.adjustment_date,
          account: doc.account ? `${doc.account.code} — ${doc.account.name}` : '—',
          product: line.product ? `${line.product.product_number} · ${line.product.name}` : '—',
          qty_plus: line.qty_plus,
          qty_minus: line.qty_minus,
          amount: (signedNet(line)).toFixed(4),
          remarks: line.remarks,
        })),
    )
  }, [listQuery.data, deferredProductWiseSearch])

  const productWiseColumns = useMemo<PosGridColumn<ProductWiseRow>[]>(() => [
    { key: 'number', header: 'Adj #', width: 115, render: (row) => row.document_number },
    { key: 'date', header: 'Date', width: 105, render: (row) => row.date },
    { key: 'account', header: 'Account', width: '24%', render: (row) => row.account },
    { key: 'product', header: 'Product', width: '32%', render: (row) => row.product },
    { key: 'plus', header: 'Qty (+)', width: 100, align: 'right', render: (row) => row.qty_plus },
    { key: 'minus', header: 'Qty (-)', width: 100, align: 'right', render: (row) => row.qty_minus },
    { key: 'amount', header: 'Amount', width: 110, align: 'right', render: (row) => row.amount },
    { key: 'remarks', header: 'Remarks', render: (row) => row.remarks ?? '—' },
  ], [])

  const selectedSearchDocument = (listQuery.data ?? []).find((row) => row.ulid === selectedSearchUlid) ?? null

  function previewProductWise() {
    const root = document.documentElement
    const cleanup = () => {
      root.removeAttribute('data-adjustment-product-print')
      window.removeEventListener('afterprint', cleanup)
    }

    root.setAttribute('data-adjustment-product-print', 'true')
    window.addEventListener('afterprint', cleanup, { once: true })
    window.requestAnimationFrame(() => window.print())
  }

  const generatedAt = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date())

  return (
    <section className="inventory-adjustments-page">
      <div className="inventory-adjustments-tabs" role="tablist" aria-label="Adjustment views">
        <button
          type="button"
          className={tab === 'invoice' ? 'is-active' : ''}
          onClick={() => setTab('invoice')}
        >
          <Save size={15} /> Adjustment Invoice
        </button>
        <button
          type="button"
          className={tab === 'search' ? 'is-active' : ''}
          onClick={() => setTab('search')}
        >
          <FileSearch size={15} /> Search
        </button>
        <button
          type="button"
          className={tab === 'product' ? 'is-active' : ''}
          onClick={() => setTab('product')}
        >
          <PackageSearch size={15} /> Product Wise
        </button>
        <strong>Adjustment</strong>
      </div>

      {tab === 'invoice' ? (
        <>
          <div className="inventory-adjustments-header">
            <div className="inventory-adjustments-options">
              <div className="inventory-adjustments-section-title">Invoice Options</div>
              <label>
                <span>Adj#:</span>
                <input value={document?.document_number ?? 'New'} readOnly />
              </label>
              <label>
                <span>Date:</span>
                <input
                  type="date"
                  value={adjustmentDate}
                  disabled={Boolean(document && document.status === 'posted')}
                  onChange={(event) => {
                    setAdjustmentDate(event.target.value)
                    setHeaderDirty(true)
                  }}
                />
              </label>
              <label className="inventory-adjustments-account">
                <span>Account:</span>
                <UiSelect
                  value={accountUlid}
                  options={accountOptions}
                  onChange={(value) => {
                    setAccountUlid(value)
                    setHeaderDirty(true)
                  }}
                  disabled={Boolean(document && document.status === 'posted')}
                  placeholder={accountsQuery.isLoading ? 'Loading accounts…' : 'Select account'}
                  searchPlaceholder="Search accounts…"
                />
              </label>
            </div>

            <div className="inventory-adjustments-amount-options">
              <div className="inventory-adjustments-section-title">Amount Options</div>
              <label>
                <span>Adjustment Type:</span>
                <UiSelect
                  value="stock_adjustment"
                  options={[{ value: 'stock_adjustment', label: 'Stock Adjustment' }]}
                  onChange={() => undefined}
                  searchable={false}
                  disabled
                />
              </label>
              <label>
                <span>Net Amount:</span>
                <input value={document?.net_amount ?? '0.0000'} readOnly />
              </label>
            </div>
          </div>

          <div className="inventory-adjustments-line-entry">
            <label className="inventory-adjustments-product-search">
              <span>ITEM / PRODUCT DESCRIPTION</span>
              <input
                value={productSearch}
                disabled={!entryEnabled}
                placeholder="Product #, SKU, barcode or name"
                onChange={(event) => {
                  setProductSearch(event.target.value)
                  setLineDirty(true)
                }}
              />
            </label>
            <label className="inventory-adjustments-product-select">
              <span>Product</span>
              <UiSelect
                value={productUlid}
                options={productOptions}
                onChange={(value) => {
                  setProductUlid(value)
                  const product = productsQuery.data?.data.find((row) => row.ulid === value)
                  if (product) setProductSearch(productLabel(product))
                  setLineDirty(true)
                }}
                disabled={!entryEnabled}
                placeholder={productsQuery.isFetching ? 'Searching…' : 'Select product'}
                searchPlaceholder="Filter loaded products…"
              />
            </label>
            <label>
              <span>Qty +</span>
              <input
                inputMode="decimal"
                value={qtyPlus}
                disabled={!entryEnabled}
                onChange={(event) => {
                  setQtyPlus(event.target.value)
                  if (event.target.value.trim()) setQtyMinus('')
                  setLineDirty(true)
                }}
                placeholder="0.000000"
              />
            </label>
            <label>
              <span>Qty -</span>
              <input
                inputMode="decimal"
                value={qtyMinus}
                disabled={!entryEnabled}
                onChange={(event) => {
                  setQtyMinus(event.target.value)
                  if (event.target.value.trim()) setQtyPlus('')
                  setLineDirty(true)
                }}
                placeholder="0.000000"
              />
            </label>
            <label className="inventory-adjustments-remarks">
              <span>Remarks</span>
              <input
                value={lineRemarks}
                maxLength={500}
                disabled={!entryEnabled}
                onChange={(event) => {
                  setLineRemarks(event.target.value)
                  setLineDirty(true)
                }}
              />
            </label>
            <div className="inventory-adjustments-line-actions">
              <UiButton
                variant="primary"
                onClick={() => saveLine.mutate()}
                disabled={!entryEnabled || saveLine.isPending}
              >
                {selectedLineUlid ? <Save size={15} /> : <Plus size={15} />}
                {selectedLineUlid ? 'Update' : 'Add'}
              </UiButton>
              <UiButton
                onClick={clearLineForm}
                disabled={!selectedLineUlid && !lineDirty}
              >
                Clear
              </UiButton>
              <UiButton
                variant="danger"
                onClick={async () => {
                  if (!selectedLineUlid) return
                  if (!await feedback.confirm('Remove this adjustment line?')) return
                  removeLine.mutate(selectedLineUlid)
                }}
                disabled={!editable || !selectedLineUlid || removeLine.isPending}
              >
                <Trash2 size={15} /> Remove
              </UiButton>
            </div>
          </div>

          <div className="inventory-adjustments-grid">
            <PosDataGrid
              columns={lineColumns}
              rows={document?.lines ?? []}
              rowKey={(row) => row.ulid}
              selectedKey={selectedLineUlid}
              onSelect={fillLineForm}
              onActivate={fillLineForm}
              emptyMessage={documentUlid ? 'No adjustment lines yet.' : 'Select an account and add the first product.'}
            />
          </div>

          <footer className="inventory-adjustments-footer">
            <div>
              <UiButton
                variant="danger"
                onClick={async () => {
                  if (!document || document.status !== 'draft') return
                  if (!await feedback.confirm(`Delete draft ${document.document_number}?`)) return
                  removeDocument.mutate(document.ulid)
                }}
                disabled={!document || document.status !== 'draft' || !canAdjust}
              >
                <Trash2 size={15} /> Delete
              </UiButton>
            </div>
            <div className="inventory-adjustments-footer-actions">
              <UiButton
                variant="primary"
                onClick={newDocument}
                disabled={!canAdjust}
              >
                <Plus size={15} /> New
              </UiButton>
              <UiButton
                variant="success"
                onClick={() => saveHeader.mutate()}
                disabled={
                  !canAdjust ||
                  Boolean(document && document.status === 'posted') ||
                  (!headerDirty && Boolean(document)) ||
                  saveHeader.isPending
                }
              >
                <Save size={15} /> Save
              </UiButton>
              <UiButton
                variant="success"
                onClick={async () => {
                  if (!document || document.status !== 'draft') return
                  if (headerDirty) {
                    feedback.info('Save the header before posting.', 'Adjustment')
                    return
                  }
                  if (!await feedback.confirm(
                    `Post ${document.document_number}? Live stock and cost will be re-read. Posted adjustments cannot be edited.`,
                  )) return
                  postDocument.mutate()
                }}
                disabled={!document || document.status !== 'draft' || !canAdjust || postDocument.isPending}
              >
                <Send size={15} /> Post
              </UiButton>
              <UiButton
                variant="info"
                onClick={() => {
                  if (documentUlid) void documentQuery.refetch()
                  else newDocument()
                }}
              >
                <RefreshCw size={15} /> Refresh
              </UiButton>
              <UiButton variant="info" onClick={closeActiveTab}>
                <X size={15} /> Close
              </UiButton>
            </div>
          </footer>
        </>
      ) : null}

      {tab === 'search' ? (
        <>
          <div className="inventory-adjustments-report-toolbar">
            <label>
              <span>From:</span>
              <input type="date" value={fromDate} onChange={(event) => setFromDate(event.target.value)} />
            </label>
            <label>
              <span>To:</span>
              <input type="date" value={toDate} onChange={(event) => setToDate(event.target.value)} />
            </label>
            <UiSelect
              className="inventory-adjustments-status-filter"
              value={statusFilter}
              options={[
                { value: '', label: 'All statuses' },
                { value: 'draft', label: 'Draft' },
                { value: 'posted', label: 'Posted' },
              ]}
              onChange={setStatusFilter}
              searchable={false}
            />
            <UiButton variant="info" onClick={() => void listQuery.refetch()}>
              <FileSearch size={15} /> View
            </UiButton>
            <UiButton
              onClick={() => {
                if (selectedSearchDocument) openDocument(selectedSearchDocument)
              }}
              disabled={!selectedSearchDocument}
            >
              Open
            </UiButton>
          </div>
          <div className="inventory-adjustments-report-grid">
            <PosDataGrid
              columns={searchColumns}
              rows={listQuery.data ?? []}
              rowKey={(row) => row.ulid}
              selectedKey={selectedSearchUlid}
              onSelect={(row) => setSelectedSearchUlid(row.ulid)}
              onActivate={openDocument}
              emptyMessage={listQuery.isLoading ? 'Loading adjustments…' : 'No adjustments found.'}
            />
          </div>
        </>
      ) : null}

      {tab === 'product' ? (
        <>
          <header className="inventory-adjustments-print-header" aria-hidden="true">
            <div>
              <h1>Product Wise Adjustments</h1>
              <p>
                {session?.tenant.name ?? 'BluePOS'} · {session?.branch.code ?? '—'} — {session?.branch.name ?? '—'} · {session?.warehouse.name ?? '—'}
              </p>
            </div>
            <div className="inventory-adjustments-print-meta">
              <span>{fromDate} to {toDate}</span>
              {deferredProductWiseSearch.trim() ? <span>Product: {deferredProductWiseSearch.trim()}</span> : null}
              <span>Generated {generatedAt}</span>
            </div>
          </header>

          <div className="inventory-adjustments-report-toolbar">
            <label>
              <span>From Date:</span>
              <input type="date" value={fromDate} onChange={(event) => setFromDate(event.target.value)} />
            </label>
            <label>
              <span>To Date:</span>
              <input type="date" value={toDate} onChange={(event) => setToDate(event.target.value)} />
            </label>
            <label className="inventory-adjustments-product-wise-search">
              <span>Product:</span>
              <input
                value={productWiseSearch}
                onChange={(event) => setProductWiseSearch(event.target.value)}
                placeholder="Search product…"
              />
            </label>
            <UiButton variant="info" onClick={() => void listQuery.refetch()}>
              <PackageSearch size={15} /> Show
            </UiButton>
            <UiButton variant="info" onClick={previewProductWise}>
              Preview
            </UiButton>
          </div>
          <div className="inventory-adjustments-report-grid">
            <PosDataGrid
              columns={productWiseColumns}
              rows={productWiseRows}
              rowKey={(row) => row.key}
              emptyMessage={listQuery.isLoading ? 'Loading adjustment products…' : 'No product-wise adjustment rows found.'}
            />
          </div>
        </>
      ) : null}
    </section>
  )
}
