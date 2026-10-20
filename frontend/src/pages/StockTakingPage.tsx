import { useEffect, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Boxes, Plus, RefreshCw, Save, Send, Trash2, X } from 'lucide-react'
import {
  createStockTake,
  deleteStockTake,
  fetchStockTake,
  fetchStockTakes,
  fetchWarehouses,
  postStockTake,
  saveStockTakeDraft,
} from '../api/inventory'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { PosEntryGrid, type PosEntryGridColumn } from '../components/desktop/PosEntryGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSimpleDropdown } from '../components/ui/UiSelect'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useLocalProducts } from '../features/catalog/useLocalCatalog'
import { ProductLookupGrid } from '../features/sales/ProductLookupGrid'
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

type StockTakeDraftLine = {
  key: string
  product_ulid: string
  product_number: string
  sku: string | null
  product_name: string
  system_quantity: string
  counted_quantity: string
  variance_quantity: string
  notes: string | null
}

function draftLineFromServer(line: StockTakeLine): StockTakeDraftLine | null {
  if (!line.product) return null

  return {
    key: line.product.ulid,
    product_ulid: line.product.ulid,
    product_number: line.product.product_number,
    sku: line.product.sku,
    product_name: line.product.name,
    system_quantity: line.system_quantity,
    counted_quantity: line.counted_quantity,
    variance_quantity: line.variance_quantity,
    notes: line.notes,
  }
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

  const [draftLines, setDraftLines] = useState<StockTakeDraftLine[]>([])
  const [draftDirty, setDraftDirty] = useState(false)
  const [productUlid, setProductUlid] = useState('')
  const [countedQuantity, setCountedQuantity] = useState('0.00')
  const [lineNotes, setLineNotes] = useState('')
  const [selectedDraftKey, setSelectedDraftKey] = useState<string | null>(null)
  const [lineDirty, setLineDirty] = useState(false)
  const [productLookupQuery, setProductLookupQuery] = useState('')
  const [productLookupOpen, setProductLookupOpen] = useState(false)
  const entryRowRef = useRef<HTMLTableRowElement | null>(null)
  const productLookupAnchorRef = useRef<HTMLDivElement | null>(null)
  const productLookupInputRef = useRef<HTMLInputElement | null>(null)

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

  const localProducts = useLocalProducts(
    session,
    productLookupQuery,
    mode === 'editor',
  )

  const document = documentQuery.data ?? null
  const editable = Boolean(document && document.status === 'draft' && canAdjust)

  useEffect(() => {
    if (!document) return

    setCountDate(document.count_date)
    setDocumentNotes(document.notes ?? '')
    setHeaderDirty(false)
    setDraftLines(
      (document.lines ?? [])
        .map(draftLineFromServer)
        .filter((line): line is StockTakeDraftLine => Boolean(line)),
    )
    setDraftDirty(false)
    clearLineForm()
  }, [document?.ulid, document?.count_date, document?.notes, document?.lines])

  useEffect(() => {
    if (!newWarehouseUlid && session?.warehouse.ulid) {
      setNewWarehouseUlid(session.warehouse.ulid)
    }
  }, [newWarehouseUlid, session?.warehouse.ulid])

  function clearLineForm() {
    setProductUlid('')
    setProductLookupQuery('')
    setProductLookupOpen(false)
    setCountedQuantity('0.00')
    setLineNotes('')
    setSelectedDraftKey(null)
    setLineDirty(false)
  }

  function fillLineForm(line: StockTakeDraftLine) {
    setSelectedDraftKey(line.key)
    setProductUlid(line.product_ulid)
    setProductLookupQuery(`${line.product_number} · ${line.product_name}`)
    setProductLookupOpen(false)
    setCountedQuantity(line.counted_quantity)
    setLineNotes(line.notes ?? '')
    setLineDirty(false)
  }

  async function confirmDiscardDraftInputs() {
    if (!headerDirty && !draftDirty && !lineDirty) return true
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

  const saveDraft = useMutation({
    mutationFn: (lines: StockTakeDraftLine[]) =>
      saveStockTakeDraft(documentUlid as string, {
        count_date: countDate,
        notes: documentNotes.trim() || null,
        lines: lines.map((line) => ({
          product_ulid: line.product_ulid,
          counted_quantity: line.counted_quantity,
          notes: line.notes,
        })),
      }),
    onSuccess: async (saved) => {
      setHeaderDirty(false)
      setDraftDirty(false)
      clearLineForm()
      queryClient.setQueryData(['inventory-stock-take', documentUlid], saved)
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock-takes'] })
      feedback.success('Stock Take draft saved.')
      focusEntryField('product')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to save Stock Take draft.'),
  })

  const postDocument = useMutation({
    mutationFn: () => postStockTake(documentUlid as string),
    onSuccess: async () => {
      clearLineForm()
      setHeaderDirty(false)
      setDraftDirty(false)
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

  const lineColumns = useMemo<PosEntryGridColumn[]>(() => [
    { key: 'number', header: 'Product #', width: 90 },
    { key: 'sku', header: 'SKU', width: 90 },
    { key: 'product', header: 'Product', width: '30%' },
    { key: 'system', header: 'System Qty', width: 105, align: 'right' },
    { key: 'counted', header: 'Counted Qty', width: 105, align: 'right' },
    { key: 'variance', header: 'Variance', width: 105, align: 'right' },
    { key: 'notes', header: 'Notes', width: '20%' },
    { key: 'delete', header: '', width: 38, align: 'center' },
  ], [])

  const selectedProduct = localProducts.data?.find((product) => product.ulid === productUlid) ?? null
  const selectedDraftLine = selectedDraftKey
    ? draftLines.find((line) => line.key === selectedDraftKey) ?? null
    : null

  function focusEntryField(field: 'product' | 'counted' | 'notes') {
    window.setTimeout(() => {
      if (field === 'product') {
        productLookupInputRef.current?.focus()
        return
      }

      const input = entryRowRef.current?.querySelector<HTMLInputElement>(
        `[data-stock-field="${field}"]`,
      )
      input?.focus()
      input?.select()
    }, 0)
  }

  function buildEntryDraftLine(): StockTakeDraftLine | null {
    if (!productUlid) {
      feedback.info('Select a product.', 'Stock Taking')
      focusEntryField('product')
      return null
    }

    if (!/^(?:0|[1-9]\d*)(?:\.\d{0,6})?$/.test(countedQuantity.trim())) {
      feedback.info('Enter a valid counted quantity.', 'Stock Taking')
      focusEntryField('counted')
      return null
    }

    const product = localProducts.data?.find((row) => row.ulid === productUlid)
    if (!product) {
      feedback.info('Selected product is no longer available in the local catalogue.', 'Stock Taking')
      focusEntryField('product')
      return null
    }

    const existing =
      draftLines.find((line) => line.key === selectedDraftKey) ??
      draftLines.find((line) => line.product_ulid === product.ulid) ??
      null
    const system = existing?.system_quantity ?? '—'
    const systemNumber = Number.parseFloat(system)
    const countedNumber = Number.parseFloat(countedQuantity)
    const variance =
      Number.isFinite(systemNumber) && Number.isFinite(countedNumber)
        ? (countedNumber - systemNumber).toFixed(6)
        : '—'

    return {
      key: product.ulid,
      product_ulid: product.ulid,
      product_number: product.product_number,
      sku: product.sku,
      product_name: product.name,
      system_quantity: system,
      counted_quantity: countedQuantity.trim(),
      variance_quantity: variance,
      notes: lineNotes.trim() || null,
    }
  }

  function mergeEntryIntoDraft(source: StockTakeDraftLine[]) {
    const entry = buildEntryDraftLine()
    if (!entry) return null

    const targetKey = selectedDraftKey ?? entry.key
    const existingIndex = source.findIndex(
      (line) => line.key === targetKey || line.product_ulid === entry.product_ulid,
    )

    if (existingIndex >= 0) {
      const next = [...source]
      next[existingIndex] = entry
      return next
    }

    return [...source, entry]
  }

  function commitEntryLine() {
    if (!editable) return false

    const next = mergeEntryIntoDraft(draftLines)
    if (!next) return false

    setDraftLines(next)
    setDraftDirty(true)
    clearLineForm()
    focusEntryField('product')
    return true
  }

  function handleEntryFieldEnter(
    event: ReactKeyboardEvent<HTMLInputElement>,
    next: 'notes' | 'commit',
  ) {
    if (event.key !== 'Enter') return
    event.preventDefault()

    if (next === 'notes') {
      focusEntryField('notes')
      return
    }

    commitEntryLine()
  }

  function saveCurrentDraft() {
    let lines = draftLines

    if (lineDirty) {
      const merged = mergeEntryIntoDraft(draftLines)
      if (!merged) return
      lines = merged
      setDraftLines(merged)
    }

    saveDraft.mutate(lines)
  }

  useEffect(() => {
    if (!editable || !lineDirty) return

    function handleOutsideEntryRow(event: MouseEvent) {
      const target = event.target
      if (!(target instanceof Element)) return
      if (entryRowRef.current?.contains(target)) return
      if (target.closest('.ui-select-menu')) return
      if (!target.closest('.stock-taking-line-card')) return
      if (target.closest('.stock-taking-entry-grid tbody tr:not(.is-entry-row)')) return

      commitEntryLine()
    }

    window.addEventListener('mousedown', handleOutsideEntryRow)
    return () => window.removeEventListener('mousedown', handleOutsideEntryRow)
  }, [
    countedQuantity,
    draftLines,
    editable,
    lineDirty,
    lineNotes,
    productUlid,
    selectedDraftKey,
  ])

  if (mode === 'list') {
    const selectedDocument = (listQuery.data ?? []).find((row) => row.ulid === selectedListKey) ?? null

    return (
      <section className="stock-taking-page">
        <div className="stock-taking-toolbar">
          <div className="stock-taking-toolbar-left">
            <UiSimpleDropdown
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
            <UiSimpleDropdown
              className="stock-taking-status-filter"
              value={statusFilter}
              options={[
                { value: '', label: 'All statuses' },
                { value: 'draft', label: 'Draft' },
                { value: 'posted', label: 'Posted' },
              ]}
              onChange={setStatusFilter}
              aria-label="Stock Taking status filter"
            />
            <UiSimpleDropdown
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

          <label className="stock-taking-toolbar-field stock-taking-toolbar-warehouse">
            <span>Warehouse</span>
            <input
              value={document?.warehouse ? `${document.warehouse.code} — ${document.warehouse.name}` : ''}
              readOnly
            />
          </label>

          <label className="stock-taking-toolbar-field stock-taking-toolbar-date">
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
        </div>

        <div className="stock-taking-toolbar-right">
          <UiButton
            variant="success"
            onClick={saveCurrentDraft}
            disabled={!editable || (!headerDirty && !draftDirty && !lineDirty) || saveDraft.isPending}
          >
            <Save size={15} /> Save
          </UiButton>
          <UiButton
            variant="success"
            onClick={async () => {
              if (!document || !canPost || document.status !== 'draft') return
              if (headerDirty || draftDirty || lineDirty) {
                feedback.info('Save the Stock Take draft before posting.', 'Stock Taking')
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

      <div className="stock-taking-line-card">
        <PosEntryGrid
          columns={lineColumns}
          className="stock-taking-entry-grid"
          ariaLabel="Stock Taking lines"
        >
          {draftLines.map((line) => (
            <tr
              key={line.key}
              className={selectedDraftKey === line.key ? 'is-selected' : undefined}
              onClick={() => fillLineForm(line)}
              onDoubleClick={() => {
                fillLineForm(line)
                focusEntryField('counted')
              }}
            >
              <td>{line.product_number}</td>
              <td>{line.sku ?? '—'}</td>
              <td>{line.product_name}</td>
              <td className="is-num">{line.system_quantity}</td>
              <td className="is-num">{line.counted_quantity}</td>
              <td className="is-num">{line.variance_quantity}</td>
              <td>{line.notes ?? '—'}</td>
              <td className="stock-taking-delete-cell">
                {editable ? (
                  <button
                    type="button"
                    className="stock-taking-delete-row"
                    aria-label="Remove Stock Taking row"
                    onClick={(event) => {
                      event.stopPropagation()
                      setDraftLines((rows) => rows.filter((row) => row.key !== line.key))
                      setDraftDirty(true)
                      if (selectedDraftKey === line.key) clearLineForm()
                    }}
                  >
                    <X size={14} />
                  </button>
                ) : null}
              </td>
            </tr>
          ))}

          <tr ref={entryRowRef} className="is-entry-row">
            <td>{selectedProduct?.product_number ?? selectedDraftLine?.product_number ?? ''}</td>
            <td>{selectedProduct?.sku ?? selectedDraftLine?.sku ?? ''}</td>
            <td>
              <div
                ref={productLookupAnchorRef}
                className={[
                  'ui-select',
                  'ui-table-dropdown',
                  productLookupOpen ? 'is-open' : '',
                ].filter(Boolean).join(' ')}
              >
                <div className="ui-select-trigger ui-select-combobox-trigger">
                  <input
                    ref={productLookupInputRef}
                    type="text"
                    className="ui-select-trigger-search"
                    value={productLookupQuery}
                    disabled={!editable}
                    placeholder={localProducts.isLoading ? 'Loading products…' : 'Select product'}
                    aria-label="Stock Taking product"
                    autoComplete="off"
                    onFocus={() => {
                      if (!editable) return
                      setProductLookupOpen(true)
                    }}
                    onChange={(event) => {
                      setProductLookupQuery(event.target.value)
                      setProductUlid('')
                      setLineDirty(true)
                      setProductLookupOpen(true)
                    }}
                    onKeyDown={(event) => {
                      if (event.key === 'Escape') {
                        setProductLookupOpen(false)
                        return
                      }

                      if (event.key !== 'Enter') return
                      const first = localProducts.rows.find(
                        (product) => product.is_active && product.status === 'active',
                      )
                      if (!first) return

                      event.preventDefault()
                      setProductUlid(first.ulid)
                      setProductLookupQuery(productLabel(first))
                      setProductLookupOpen(false)
                      setLineDirty(true)
                      focusEntryField('counted')
                    }}
                  />

                  <button
                    type="button"
                    className="ui-select-combobox-toggle"
                    disabled={!editable}
                    aria-label={productLookupOpen ? 'Close product dropdown' : 'Open product dropdown'}
                    aria-expanded={productLookupOpen}
                    tabIndex={-1}
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={() => {
                      if (!editable) return
                      setProductLookupOpen((open) => !open)
                      requestAnimationFrame(() => productLookupInputRef.current?.focus())
                    }}
                  >
                    <span className="ui-select-arrow" aria-hidden>
                      <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                        <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                      </svg>
                    </span>
                  </button>
                </div>

                {productLookupOpen ? (
                  <ProductLookupGrid
                    rows={localProducts.rows.filter(
                      (product) => product.is_active && product.status === 'active',
                    )}
                    total={localProducts.total}
                    priceType="retail"
                    themed
                    portalAnchorRef={productLookupAnchorRef}
                    selectedUlid={productUlid || null}
                    onRequestClose={() => setProductLookupOpen(false)}
                    onSelect={(product) => {
                      setProductUlid(product.ulid)
                      setProductLookupQuery(productLabel(product))
                      setProductLookupOpen(false)
                      setLineDirty(true)
                      focusEntryField('counted')
                    }}
                  />
                ) : null}
              </div>
            </td>
            <td className="is-num">{selectedDraftLine?.system_quantity ?? ''}</td>
            <td className="is-num">
              <input
                data-stock-field="counted"
                inputMode="decimal"
                value={countedQuantity}
                disabled={!editable}
                onChange={(event) => {
                  setCountedQuantity(event.target.value)
                  setLineDirty(true)
                }}
                onKeyDown={(event) => handleEntryFieldEnter(event, 'notes')}
                aria-label="Counted quantity"
              />
            </td>
            <td className="is-num">{selectedDraftLine?.variance_quantity ?? ''}</td>
            <td>
              <input
                data-stock-field="notes"
                value={lineNotes}
                maxLength={500}
                disabled={!editable}
                onChange={(event) => {
                  setLineNotes(event.target.value)
                  setLineDirty(true)
                }}
                onKeyDown={(event) => handleEntryFieldEnter(event, 'commit')}
                aria-label="Line notes"
              />
            </td>
            <td />
          </tr>
        </PosEntryGrid>
      </div>

      <footer className="stock-taking-footer">
        <span>{draftLines.length} line(s)</span>
        <span>{document?.warehouse ? `${document.warehouse.code} — ${document.warehouse.name}` : 'No warehouse'}</span>
        {document?.posted_at ? <span>Posted: {new Date(document.posted_at).toLocaleString()}</span> : null}
      </footer>
    </section>
  )
}
