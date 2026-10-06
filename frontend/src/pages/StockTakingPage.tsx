import { useDeferredValue, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Boxes, Plus, RefreshCw, Save, Send, Trash2, X } from 'lucide-react'
import { fetchProducts } from '../api/catalog'
import {
  createStockTake,
  createStockTakeLine,
  deleteStockTake,
  deleteStockTakeLine,
  fetchStockTake,
  fetchStockTakes,
  fetchWarehouses,
  postStockTake,
  updateStockTake,
  updateStockTakeLine,
} from '../api/inventory'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import type { Product } from '../types/catalog'
import type { StockTake, StockTakeLine } from '../types/inventory'
import './StockTakingPage.css'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function productLabel(product: Product) {
  return `${product.product_number} · ${product.name}`
}

export function StockTakingPage() {
  const queryClient = useQueryClient()
  const feedback = useFeedback()
  const { closeActiveTab } = useWorkspace()
  const { session } = useAuth()

  const canAdjust = useCan('inventory.adjust')
  const canPost = useCan('inventory.stock_take.approve')

  const [mode, setMode] = useState<'list' | 'editor'>('list')
  const [statusFilter, setStatusFilter] = useState('')
  const [warehouseFilter, setWarehouseFilter] = useState('')
  const [selectedListKey, setSelectedListKey] = useState<string | null>(null)
  const [documentUlid, setDocumentUlid] = useState<string | null>(null)

  const [newWarehouseUlid, setNewWarehouseUlid] = useState(session?.warehouse.ulid ?? '')
  const [countDate, setCountDate] = useState(todayIso())
  const [documentNotes, setDocumentNotes] = useState('')
  const [headerDirty, setHeaderDirty] = useState(false)

  const [productSearch, setProductSearch] = useState('')
  const deferredProductSearch = useDeferredValue(productSearch)
  const [productUlid, setProductUlid] = useState('')
  const [countedQuantity, setCountedQuantity] = useState('')
  const [lineNotes, setLineNotes] = useState('')
  const [selectedLineUlid, setSelectedLineUlid] = useState<string | null>(null)
  const [lineDirty, setLineDirty] = useState(false)

  const warehousesQuery = useQuery({
    queryKey: ['warehouses'],
    queryFn: fetchWarehouses,
  })

  const listQuery = useQuery({
    queryKey: ['inventory-stock-takes', statusFilter, warehouseFilter],
    queryFn: () => fetchStockTakes({
      status: statusFilter || undefined,
      warehouse_ulid: warehouseFilter || undefined,
    }),
  })

  const documentQuery = useQuery({
    queryKey: ['inventory-stock-take', documentUlid],
    queryFn: () => fetchStockTake(documentUlid as string),
    enabled: Boolean(documentUlid),
  })

  const productsQuery = useQuery({
    queryKey: ['products', 'stock-taking', deferredProductSearch],
    queryFn: () => fetchProducts(
      {
        q: deferredProductSearch.trim() || undefined,
        per_page: 50,
        active_only: true,
      },
      { busy: 'none' },
    ),
    enabled: mode === 'editor',
  })

  const document = documentQuery.data ?? null
  const editable = Boolean(document && document.status === 'draft' && canAdjust)

  useEffect(() => {
    if (!document) return
    setCountDate(document.count_date)
    setDocumentNotes(document.notes ?? '')
    setHeaderDirty(false)
  }, [document?.ulid, document?.count_date, document?.notes])

  useEffect(() => {
    if (!newWarehouseUlid && session?.warehouse.ulid) {
      setNewWarehouseUlid(session.warehouse.ulid)
    }
  }, [newWarehouseUlid, session?.warehouse.ulid])

  function clearLineForm() {
    setProductSearch('')
    setProductUlid('')
    setCountedQuantity('')
    setLineNotes('')
    setSelectedLineUlid(null)
    setLineDirty(false)
  }

  function fillLineForm(line: StockTakeLine) {
    setSelectedLineUlid(line.ulid)
    setProductUlid(line.product?.ulid ?? '')
    setProductSearch(line.product ? `${line.product.product_number} · ${line.product.name}` : '')
    setCountedQuantity(line.counted_quantity)
    setLineNotes(line.notes ?? '')
    setLineDirty(false)
  }

  async function confirmDiscardDraftInputs() {
    if (!headerDirty && !lineDirty) return true
    return feedback.confirm('Discard unsaved Stock Taking form changes?')
  }

  async function backToList() {
    if (!await confirmDiscardDraftInputs()) return
    setMode('list')
    setDocumentUlid(null)
    setHeaderDirty(false)
    clearLineForm()
  }

  const createDocument = useMutation({
    mutationFn: () => createStockTake({
      warehouse_ulid: newWarehouseUlid,
      count_date: todayIso(),
    }),
    onSuccess: async (created) => {
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock-takes'] })
      setDocumentUlid(created.ulid)
      setMode('editor')
      feedback.success(`Stock Take ${created.document_number} created.`)
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to create Stock Take.'),
  })

  const saveHeader = useMutation({
    mutationFn: () => updateStockTake(documentUlid as string, {
      count_date: countDate,
      notes: documentNotes.trim() || null,
    }),
    onSuccess: async () => {
      setHeaderDirty(false)
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock-take', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock-takes'] })
      feedback.success('Stock Take header saved.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to save Stock Take.'),
  })

  const saveLine = useMutation({
    mutationFn: () => {
      const payload = {
        product_ulid: productUlid,
        counted_quantity: countedQuantity.trim(),
        notes: lineNotes.trim() || null,
      }
      return selectedLineUlid
        ? updateStockTakeLine(documentUlid as string, selectedLineUlid, payload)
        : createStockTakeLine(documentUlid as string, payload)
    },
    onSuccess: async () => {
      const wasUpdate = Boolean(selectedLineUlid)
      clearLineForm()
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock-take', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock-takes'] })
      feedback.success(wasUpdate ? 'Stock Take line updated.' : 'Stock Take line added.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to save Stock Take line.'),
  })

  const removeLine = useMutation({
    mutationFn: (lineUlid: string) => deleteStockTakeLine(documentUlid as string, lineUlid),
    onSuccess: async () => {
      clearLineForm()
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock-take', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock-takes'] })
      feedback.success('Stock Take line removed.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to remove Stock Take line.'),
  })

  const postDocument = useMutation({
    mutationFn: () => postStockTake(documentUlid as string),
    onSuccess: async () => {
      clearLineForm()
      setHeaderDirty(false)
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock-take', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock-takes'] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock'] })
      feedback.success('Stock Take posted. Inventory variance movements are now final.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to post Stock Take.'),
  })

  const removeDocument = useMutation({
    mutationFn: (ulid: string) => deleteStockTake(ulid),
    onSuccess: async () => {
      setSelectedListKey(null)
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock-takes'] })
      feedback.success('Draft Stock Take deleted.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to delete Stock Take.'),
  })

  const listColumns = useMemo<PosGridColumn<StockTake>[]>(() => [
    { key: 'number', header: 'Document #', width: 120, render: (row) => row.document_number },
    { key: 'date', header: 'Count Date', width: 110, render: (row) => row.count_date },
    {
      key: 'warehouse',
      header: 'Warehouse',
      width: '28%',
      render: (row) => row.warehouse ? `${row.warehouse.code} — ${row.warehouse.name}` : '—',
    },
    { key: 'status', header: 'Status', width: 90, render: (row) => row.status.toUpperCase() },
    { key: 'lines', header: 'Lines', width: 70, align: 'right', render: (row) => String(row.lines?.length ?? 0) },
    { key: 'notes', header: 'Notes', render: (row) => row.notes ?? '—' },
  ], [])

  const lineColumns = useMemo<PosGridColumn<StockTakeLine>[]>(() => [
    { key: 'number', header: 'Product #', width: 100, render: (row) => row.product?.product_number ?? '—' },
    { key: 'sku', header: 'SKU', width: 100, render: (row) => row.product?.sku ?? '—' },
    { key: 'product', header: 'Product', width: '34%', render: (row) => row.product?.name ?? '—' },
    { key: 'system', header: 'System Qty', width: 120, align: 'right', render: (row) => row.system_quantity },
    { key: 'counted', header: 'Counted Qty', width: 120, align: 'right', render: (row) => row.counted_quantity },
    { key: 'variance', header: 'Variance', width: 120, align: 'right', render: (row) => row.variance_quantity },
    { key: 'notes', header: 'Notes', render: (row) => row.notes ?? '—' },
  ], [])

  const productOptions = (productsQuery.data?.data ?? []).map((product) => ({
    value: product.ulid,
    label: productLabel(product),
  }))

  if (mode === 'list') {
    const selectedDocument = (listQuery.data ?? []).find((row) => row.ulid === selectedListKey) ?? null

    return (
      <section className="stock-taking-page">
        <div className="stock-taking-toolbar">
          <div className="stock-taking-toolbar-left">
            <UiSelect
              className="stock-taking-new-warehouse"
              value={newWarehouseUlid}
              options={(warehousesQuery.data ?? []).map((warehouse) => ({
                value: warehouse.ulid,
                label: `${warehouse.code} — ${warehouse.name}`,
                disabled: warehouse.status !== 'active',
              }))}
              onChange={setNewWarehouseUlid}
              placeholder="Warehouse"
              aria-label="Warehouse for new Stock Take"
            />
            <UiButton
              variant="primary"
              onClick={() => createDocument.mutate()}
              disabled={!canAdjust || !newWarehouseUlid || createDocument.isPending}
            >
              <Plus size={15} /> New
            </UiButton>
            <UiSelect
              className="stock-taking-status-filter"
              value={statusFilter}
              options={[
                { value: '', label: 'All statuses' },
                { value: 'draft', label: 'Draft' },
                { value: 'posted', label: 'Posted' },
              ]}
              onChange={setStatusFilter}
              searchable={false}
              aria-label="Stock Taking status filter"
            />
            <UiSelect
              className="stock-taking-warehouse-filter"
              value={warehouseFilter}
              options={[
                { value: '', label: 'All warehouses' },
                ...(warehousesQuery.data ?? []).map((warehouse) => ({
                  value: warehouse.ulid,
                  label: `${warehouse.code} — ${warehouse.name}`,
                })),
              ]}
              onChange={setWarehouseFilter}
              aria-label="Stock Taking warehouse filter"
            />
          </div>

          <div className="stock-taking-toolbar-right">
            <UiButton variant="info" onClick={() => void listQuery.refetch()}>
              <RefreshCw size={15} /> Refresh
            </UiButton>
            <UiButton
              onClick={() => {
                if (!selectedDocument) return
                setDocumentUlid(selectedDocument.ulid)
                setMode('editor')
              }}
              disabled={!selectedDocument}
            >
              Open
            </UiButton>
            <UiButton
              variant="danger"
              onClick={async () => {
                if (!selectedDocument || selectedDocument.status !== 'draft') return
                if (!await feedback.confirm(`Delete draft ${selectedDocument.document_number}?`)) return
                removeDocument.mutate(selectedDocument.ulid)
              }}
              disabled={!selectedDocument || selectedDocument.status !== 'draft' || !canAdjust}
            >
              <Trash2 size={15} /> Delete
            </UiButton>
            <UiButton variant="info" onClick={closeActiveTab}>
              <X size={15} /> Close
            </UiButton>
          </div>
        </div>

        <div className="stock-taking-grid-card">
          <PosDataGrid
            columns={listColumns}
            rows={listQuery.data ?? []}
            rowKey={(row) => row.ulid}
            selectedKey={selectedListKey}
            onSelect={(row) => setSelectedListKey(row.ulid)}
            onActivate={(row) => {
              setDocumentUlid(row.ulid)
              setMode('editor')
            }}
            emptyMessage={listQuery.isLoading ? 'Loading Stock Takes…' : 'No Stock Taking documents found.'}
          />
        </div>
      </section>
    )
  }

  return (
    <section className="stock-taking-page">
      <div className="stock-taking-toolbar">
        <div className="stock-taking-toolbar-left">
          <UiButton variant="info" onClick={() => void backToList()}>
            <ArrowLeft size={15} /> List
          </UiButton>
          <strong className="stock-taking-title">
            <Boxes size={16} />
            {document?.document_number ?? 'Stock Taking'}
          </strong>
          <span className={`stock-taking-status is-${document?.status ?? 'draft'}`}>
            {(document?.status ?? 'draft').toUpperCase()}
          </span>
        </div>

        <div className="stock-taking-toolbar-right">
          <UiButton
            variant="success"
            onClick={() => saveHeader.mutate()}
            disabled={!editable || !headerDirty || saveHeader.isPending}
          >
            <Save size={15} /> Save
          </UiButton>
          <UiButton
            variant="success"
            onClick={async () => {
              if (!document || !canPost || document.status !== 'draft') return
              if (headerDirty) {
                feedback.info('Save the document header before posting.', 'Stock Taking')
                return
              }
              if (!await feedback.confirm(
                `Post ${document.document_number}? The server will re-read live stock and post the final variance. Posted stock takes cannot be edited.`,
              )) return
              postDocument.mutate()
            }}
            disabled={!document || document.status !== 'draft' || !canPost || postDocument.isPending}
          >
            <Send size={15} /> Post
          </UiButton>
          <UiButton variant="info" onClick={() => void documentQuery.refetch()}>
            <RefreshCw size={15} /> Refresh
          </UiButton>
          <UiButton variant="info" onClick={async () => {
            if (!await confirmDiscardDraftInputs()) return
            closeActiveTab()
          }}>
            <X size={15} /> Close
          </UiButton>
        </div>
      </div>

      <div className="stock-taking-header-card">
        <label>
          <span>Warehouse</span>
          <input
            value={document?.warehouse ? `${document.warehouse.code} — ${document.warehouse.name}` : ''}
            readOnly
          />
        </label>
        <label>
          <span>Count Date</span>
          <input
            type="date"
            value={countDate}
            disabled={!editable}
            onChange={(event) => {
              setCountDate(event.target.value)
              setHeaderDirty(true)
            }}
          />
        </label>
        <label className="stock-taking-notes-field">
          <span>Notes</span>
          <input
            value={documentNotes}
            maxLength={500}
            disabled={!editable}
            onChange={(event) => {
              setDocumentNotes(event.target.value)
              setHeaderDirty(true)
            }}
          />
        </label>
      </div>

      <div className="stock-taking-line-card">
        <div className="stock-taking-line-entry">
          <label className="stock-taking-product-search">
            <span>Search product</span>
            <input
              value={productSearch}
              disabled={!editable}
              placeholder="Product #, SKU, barcode or name"
              onChange={(event) => {
                setProductSearch(event.target.value)
                setLineDirty(true)
              }}
            />
          </label>
          <label className="stock-taking-product-select">
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
              disabled={!editable}
              placeholder={productsQuery.isFetching ? 'Searching…' : 'Select product'}
              searchPlaceholder="Filter loaded products…"
            />
          </label>
          <label>
            <span>Counted Qty</span>
            <input
              inputMode="decimal"
              value={countedQuantity}
              disabled={!editable}
              onChange={(event) => {
                setCountedQuantity(event.target.value)
                setLineDirty(true)
              }}
              placeholder="0.000000"
            />
          </label>
          <label className="stock-taking-line-notes">
            <span>Line Notes</span>
            <input
              value={lineNotes}
              maxLength={500}
              disabled={!editable}
              onChange={(event) => {
                setLineNotes(event.target.value)
                setLineDirty(true)
              }}
            />
          </label>
          <div className="stock-taking-line-actions">
            <UiButton
              variant="primary"
              onClick={() => {
                if (!productUlid || !countedQuantity.trim()) {
                  feedback.info('Select a product and enter the counted quantity.', 'Stock Taking')
                  return
                }
                saveLine.mutate()
              }}
              disabled={!editable || saveLine.isPending}
            >
              {selectedLineUlid ? <Save size={15} /> : <Plus size={15} />}
              {selectedLineUlid ? 'Update' : 'Add'}
            </UiButton>
            <UiButton onClick={clearLineForm} disabled={!editable && !selectedLineUlid}>
              Clear
            </UiButton>
            <UiButton
              variant="danger"
              onClick={async () => {
                if (!selectedLineUlid) return
                if (!await feedback.confirm('Remove this Stock Take line?')) return
                removeLine.mutate(selectedLineUlid)
              }}
              disabled={!editable || !selectedLineUlid || removeLine.isPending}
            >
              <Trash2 size={15} /> Remove
            </UiButton>
          </div>
        </div>

        <PosDataGrid
          columns={lineColumns}
          rows={document?.lines ?? []}
          rowKey={(row) => row.ulid}
          selectedKey={selectedLineUlid}
          onSelect={fillLineForm}
          onActivate={fillLineForm}
          emptyMessage={documentQuery.isLoading ? 'Loading lines…' : 'No Stock Take lines yet.'}
        />
      </div>

      <footer className="stock-taking-footer">
        <span>{document?.lines?.length ?? 0} line(s)</span>
        <span>{document?.warehouse ? `${document.warehouse.code} — ${document.warehouse.name}` : 'No warehouse'}</span>
        {document?.posted_at ? <span>Posted: {new Date(document.posted_at).toLocaleString()}</span> : null}
      </footer>
    </section>
  )
}
