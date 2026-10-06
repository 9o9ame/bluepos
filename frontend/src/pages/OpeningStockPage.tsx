import { useDeferredValue, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Boxes, Plus, RefreshCw, Save, Send, Trash2, X } from 'lucide-react'
import { fetchProducts } from '../api/catalog'
import {
  createOpeningBalance,
  createOpeningBalanceLine,
  deleteOpeningBalance,
  deleteOpeningBalanceLine,
  fetchOpeningBalance,
  fetchOpeningBalances,
  fetchWarehouses,
  postOpeningBalance,
  updateOpeningBalance,
  updateOpeningBalanceLine,
} from '../api/inventory'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import type { Product } from '../types/catalog'
import type { OpeningBalance, OpeningBalanceLine } from '../types/inventory'
import './OpeningStockPage.css'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function productLabel(product: Product) {
  return `${product.product_number} · ${product.name}`
}

export function OpeningStockPage() {
  const queryClient = useQueryClient()
  const feedback = useFeedback()
  const { closeActiveTab } = useWorkspace()
  const { session } = useAuth()

  const canCreate = useCan('inventory.opening_balance.create')
  const canEdit = useCan('inventory.opening_balance.edit')
  const canPost = useCan('inventory.opening_balance.post')

  const [mode, setMode] = useState<'list' | 'editor'>('list')
  const [statusFilter, setStatusFilter] = useState('')
  const [warehouseFilter, setWarehouseFilter] = useState('')
  const [selectedListKey, setSelectedListKey] = useState<string | null>(null)
  const [documentUlid, setDocumentUlid] = useState<string | null>(null)

  const [newWarehouseUlid, setNewWarehouseUlid] = useState(session?.warehouse.ulid ?? '')
  const [documentDate, setDocumentDate] = useState(todayIso())
  const [documentNotes, setDocumentNotes] = useState('')
  const [headerDirty, setHeaderDirty] = useState(false)

  const [productSearch, setProductSearch] = useState('')
  const deferredProductSearch = useDeferredValue(productSearch)
  const [productUlid, setProductUlid] = useState('')
  const [quantity, setQuantity] = useState('')
  const [unitCost, setUnitCost] = useState('')
  const [lineNotes, setLineNotes] = useState('')
  const [selectedLineUlid, setSelectedLineUlid] = useState<string | null>(null)
  const [lineDirty, setLineDirty] = useState(false)

  const warehousesQuery = useQuery({
    queryKey: ['warehouses'],
    queryFn: fetchWarehouses,
  })

  const listQuery = useQuery({
    queryKey: ['inventory-opening-balances', statusFilter, warehouseFilter],
    queryFn: () => fetchOpeningBalances({
      status: statusFilter || undefined,
      warehouse_ulid: warehouseFilter || undefined,
    }),
  })

  const documentQuery = useQuery({
    queryKey: ['inventory-opening-balance', documentUlid],
    queryFn: () => fetchOpeningBalance(documentUlid as string),
    enabled: Boolean(documentUlid),
  })

  const productsQuery = useQuery({
    queryKey: ['products', 'opening-stock', deferredProductSearch],
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
  const editable = Boolean(document && document.status === 'draft' && canEdit)

  useEffect(() => {
    if (!document) return
    setDocumentDate(document.document_date)
    setDocumentNotes(document.notes ?? '')
    setHeaderDirty(false)
  }, [document?.ulid, document?.document_date, document?.notes])

  useEffect(() => {
    if (!newWarehouseUlid && session?.warehouse.ulid) {
      setNewWarehouseUlid(session.warehouse.ulid)
    }
  }, [newWarehouseUlid, session?.warehouse.ulid])

  function clearLineForm() {
    setProductSearch('')
    setProductUlid('')
    setQuantity('')
    setUnitCost('')
    setLineNotes('')
    setSelectedLineUlid(null)
    setLineDirty(false)
  }

  function fillLineForm(line: OpeningBalanceLine) {
    setSelectedLineUlid(line.ulid)
    setProductUlid(line.product?.ulid ?? '')
    setProductSearch(line.product ? `${line.product.product_number} · ${line.product.name}` : '')
    setQuantity(line.quantity)
    setUnitCost(line.unit_cost)
    setLineNotes(line.notes ?? '')
    setLineDirty(false)
  }

  async function confirmDiscardDraftInputs() {
    if (!headerDirty && !lineDirty) return true
    return feedback.confirm('Discard unsaved Opening Stock form changes?')
  }

  async function backToList() {
    if (!await confirmDiscardDraftInputs()) return
    setMode('list')
    setDocumentUlid(null)
    setHeaderDirty(false)
    clearLineForm()
  }

  const createDocument = useMutation({
    mutationFn: () => createOpeningBalance({
      warehouse_ulid: newWarehouseUlid,
      document_date: todayIso(),
    }),
    onSuccess: async (created) => {
      await queryClient.invalidateQueries({ queryKey: ['inventory-opening-balances'] })
      setDocumentUlid(created.ulid)
      setMode('editor')
      feedback.success(`Opening Stock ${created.document_number} created.`)
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to create Opening Stock.'),
  })

  const saveHeader = useMutation({
    mutationFn: () => updateOpeningBalance(documentUlid as string, {
      document_date: documentDate,
      notes: documentNotes.trim() || null,
    }),
    onSuccess: async () => {
      setHeaderDirty(false)
      await queryClient.invalidateQueries({ queryKey: ['inventory-opening-balance', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-opening-balances'] })
      feedback.success('Opening Stock header saved.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to save Opening Stock.'),
  })

  const saveLine = useMutation({
    mutationFn: () => {
      const payload = {
        product_ulid: productUlid,
        quantity: quantity.trim(),
        unit_cost: unitCost.trim(),
        notes: lineNotes.trim() || null,
      }
      return selectedLineUlid
        ? updateOpeningBalanceLine(documentUlid as string, selectedLineUlid, payload)
        : createOpeningBalanceLine(documentUlid as string, payload)
    },
    onSuccess: async () => {
      clearLineForm()
      await queryClient.invalidateQueries({ queryKey: ['inventory-opening-balance', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-opening-balances'] })
      feedback.success(selectedLineUlid ? 'Opening Stock line updated.' : 'Opening Stock line added.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to save Opening Stock line.'),
  })

  const removeLine = useMutation({
    mutationFn: (lineUlid: string) => deleteOpeningBalanceLine(documentUlid as string, lineUlid),
    onSuccess: async () => {
      clearLineForm()
      await queryClient.invalidateQueries({ queryKey: ['inventory-opening-balance', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-opening-balances'] })
      feedback.success('Opening Stock line removed.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to remove Opening Stock line.'),
  })

  const postDocument = useMutation({
    mutationFn: () => postOpeningBalance(documentUlid as string),
    onSuccess: async () => {
      clearLineForm()
      setHeaderDirty(false)
      await queryClient.invalidateQueries({ queryKey: ['inventory-opening-balance', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-opening-balances'] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock'] })
      feedback.success('Opening Stock posted. Stock movements are now final.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to post Opening Stock.'),
  })

  const removeDocument = useMutation({
    mutationFn: (ulid: string) => deleteOpeningBalance(ulid),
    onSuccess: async () => {
      setSelectedListKey(null)
      await queryClient.invalidateQueries({ queryKey: ['inventory-opening-balances'] })
      feedback.success('Draft Opening Stock deleted.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to delete Opening Stock.'),
  })

  const listColumns = useMemo<PosGridColumn<OpeningBalance>[]>(() => [
    { key: 'number', header: 'Document #', width: 120, render: (row) => row.document_number },
    { key: 'date', header: 'Date', width: 110, render: (row) => row.document_date },
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

  const lineColumns = useMemo<PosGridColumn<OpeningBalanceLine>[]>(() => [
    { key: 'number', header: 'Product #', width: 100, render: (row) => row.product?.product_number ?? '—' },
    { key: 'sku', header: 'SKU', width: 100, render: (row) => row.product?.sku ?? '—' },
    { key: 'product', header: 'Product', width: '36%', render: (row) => row.product?.name ?? '—' },
    { key: 'quantity', header: 'Quantity', width: 110, align: 'right', render: (row) => row.quantity },
    { key: 'unit_cost', header: 'Unit Cost', width: 110, align: 'right', render: (row) => row.unit_cost },
    { key: 'total_cost', header: 'Total Cost', width: 120, align: 'right', render: (row) => row.total_cost },
    { key: 'notes', header: 'Notes', render: (row) => row.notes ?? '—' },
  ], [])

  const productOptions = (productsQuery.data?.data ?? []).map((product) => ({
    value: product.ulid,
    label: productLabel(product),
  }))

  if (mode === 'list') {
    const selectedDocument = (listQuery.data ?? []).find((row) => row.ulid === selectedListKey) ?? null

    return (
      <section className="opening-stock-page">
        <div className="opening-stock-toolbar">
          <div className="opening-stock-toolbar-left">
            <UiSelect
              className="opening-stock-new-warehouse"
              value={newWarehouseUlid}
              options={(warehousesQuery.data ?? []).map((warehouse) => ({
                value: warehouse.ulid,
                label: `${warehouse.code} — ${warehouse.name}`,
                disabled: warehouse.status !== 'active',
              }))}
              onChange={setNewWarehouseUlid}
              placeholder="Warehouse"
              aria-label="Warehouse for new Opening Stock"
            />
            <UiButton
              variant="primary"
              onClick={() => createDocument.mutate()}
              disabled={!canCreate || !newWarehouseUlid || createDocument.isPending}
            >
              <Plus size={15} /> New
            </UiButton>
            <UiSelect
              className="opening-stock-status-filter"
              value={statusFilter}
              options={[
                { value: '', label: 'All statuses' },
                { value: 'draft', label: 'Draft' },
                { value: 'posted', label: 'Posted' },
              ]}
              onChange={setStatusFilter}
              searchable={false}
              aria-label="Opening Stock status filter"
            />
            <UiSelect
              className="opening-stock-warehouse-filter"
              value={warehouseFilter}
              options={[
                { value: '', label: 'All warehouses' },
                ...(warehousesQuery.data ?? []).map((warehouse) => ({
                  value: warehouse.ulid,
                  label: `${warehouse.code} — ${warehouse.name}`,
                })),
              ]}
              onChange={setWarehouseFilter}
              aria-label="Opening Stock warehouse filter"
            />
          </div>

          <div className="opening-stock-toolbar-right">
            <UiButton variant="info" onClick={() => void listQuery.refetch()}>
              <RefreshCw size={15} /> Refresh
            </UiButton>
            <UiButton
              onClick={() => {
                if (selectedDocument) {
                  setDocumentUlid(selectedDocument.ulid)
                  setMode('editor')
                }
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
              disabled={!selectedDocument || selectedDocument.status !== 'draft' || !canEdit}
            >
              <Trash2 size={15} /> Delete
            </UiButton>
            <UiButton variant="info" onClick={closeActiveTab}>
              <X size={15} /> Close
            </UiButton>
          </div>
        </div>

        <div className="opening-stock-grid-card">
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
            emptyMessage={listQuery.isLoading ? 'Loading Opening Stock…' : 'No Opening Stock documents found.'}
          />
        </div>
      </section>
    )
  }

  return (
    <section className="opening-stock-page">
      <div className="opening-stock-toolbar">
        <div className="opening-stock-toolbar-left">
          <UiButton variant="info" onClick={() => void backToList()}>
            <ArrowLeft size={15} /> List
          </UiButton>
          <strong className="opening-stock-title">
            <Boxes size={16} />
            {document?.document_number ?? 'Opening Stock'}
          </strong>
          <span className={`opening-stock-status is-${document?.status ?? 'draft'}`}>
            {(document?.status ?? 'draft').toUpperCase()}
          </span>
        </div>

        <div className="opening-stock-toolbar-right">
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
                feedback.info('Save the document header before posting.', 'Opening Stock')
                return
              }
              if (!await feedback.confirm(`Post ${document.document_number}? Posted opening stock cannot be edited.`)) return
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

      <div className="opening-stock-header-card">
        <label>
          <span>Warehouse</span>
          <input
            value={document?.warehouse ? `${document.warehouse.code} — ${document.warehouse.name}` : ''}
            readOnly
          />
        </label>
        <label>
          <span>Date</span>
          <input
            type="date"
            value={documentDate}
            disabled={!editable}
            onChange={(event) => {
              setDocumentDate(event.target.value)
              setHeaderDirty(true)
            }}
          />
        </label>
        <label className="opening-stock-notes-field">
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

      <div className="opening-stock-line-card">
        <div className="opening-stock-line-entry">
          <label className="opening-stock-product-search">
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
          <label className="opening-stock-product-select">
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
            <span>Quantity</span>
            <input
              inputMode="decimal"
              value={quantity}
              disabled={!editable}
              onChange={(event) => {
                setQuantity(event.target.value)
                setLineDirty(true)
              }}
              placeholder="0.000000"
            />
          </label>
          <label>
            <span>Unit Cost</span>
            <input
              inputMode="decimal"
              value={unitCost}
              disabled={!editable}
              onChange={(event) => {
                setUnitCost(event.target.value)
                setLineDirty(true)
              }}
              placeholder="0.0000"
            />
          </label>
          <label className="opening-stock-line-notes">
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
          <div className="opening-stock-line-actions">
            <UiButton
              variant="primary"
              onClick={() => {
                if (!productUlid || !quantity.trim() || !unitCost.trim()) {
                  feedback.info('Select a product and enter quantity and unit cost.', 'Opening Stock')
                  return
                }
                saveLine.mutate()
              }}
              disabled={!editable || saveLine.isPending}
            >
              {selectedLineUlid ? <Save size={15} /> : <Plus size={15} />}
              {selectedLineUlid ? 'Update' : 'Add'}
            </UiButton>
            <UiButton
              onClick={clearLineForm}
              disabled={!editable && !selectedLineUlid}
            >
              Clear
            </UiButton>
            <UiButton
              variant="danger"
              onClick={async () => {
                if (!selectedLineUlid) return
                if (!await feedback.confirm('Remove this Opening Stock line?')) return
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
          emptyMessage={documentQuery.isLoading ? 'Loading lines…' : 'No Opening Stock lines yet.'}
        />
      </div>

      <footer className="opening-stock-footer">
        <span>{document?.lines?.length ?? 0} line(s)</span>
        <span>{document?.warehouse ? `${document.warehouse.code} — ${document.warehouse.name}` : 'No warehouse'}</span>
        {document?.posted_at ? <span>Posted: {new Date(document.posted_at).toLocaleString()}</span> : null}
      </footer>
    </section>
  )
}
