import { useMemo, useState } from 'react'
import { CheckCircle2, Plus, RefreshCw, Save, Trash2, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchSuppliers } from '../api/catalog'
import { fetchWarehouses } from '../api/inventory'
import {
  createPurchaseReturn,
  createPurchaseReturnLine,
  deletePurchaseReturnLine,
  fetchPostedPurchases,
  fetchPurchaseReturn,
  fetchPurchaseReturns,
  fetchReturnableLines,
  postPurchaseReturn,
  updatePurchaseReturn,
  updatePurchaseReturnLine,
} from '../api/purchaseReturns'
import { ApiClientError } from '../api/client'
import { DesktopButton, DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { PurchaseReturn, ReturnablePurchaseLine } from '../types/purchaseReturns'

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function qty(value: string): string {
  const n = Number(value)
  return Number.isFinite(n) ? n.toFixed(6) : '0.000000'
}

export function PurchaseReturnsPage() {
  const queryClient = useQueryClient()
  const { session } = useAuth()
  const { closeActiveTab } = useWorkspace()
  const canView = useCan('purchase_returns.view')
  const canCreate = useCan('purchase_returns.create')
  const canEdit = useCan('purchase_returns.edit')
  const canPost = useCan('purchase_returns.post')

  const [mode, setMode] = useState<'list' | 'editor'>('list')
  const [q, setQ] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [supplierFilter, setSupplierFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedListKey, setSelectedListKey] = useState<string | null>(null)

  const [document, setDocument] = useState<PurchaseReturn | null>(null)
  const [purchaseUlid, setPurchaseUlid] = useState('')
  const [purchaseLabel, setPurchaseLabel] = useState('')
  const [warehouseUlid, setWarehouseUlid] = useState(session?.warehouse.ulid ?? '')
  const [returnDate, setReturnDate] = useState(today())
  const [supplierReference, setSupplierReference] = useState('')
  const [reason, setReason] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [purchaseSearch, setPurchaseSearch] = useState('')
  const [lineQty, setLineQty] = useState<Record<string, string>>({})

  const readOnly = document?.status === 'posted'

  const listQuery = useQuery({
    queryKey: ['purchase-returns', q, statusFilter, supplierFilter, dateFrom, dateTo],
    queryFn: () =>
      fetchPurchaseReturns({
        q: q || undefined,
        status: statusFilter || undefined,
        supplier_ulid: supplierFilter || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        per_page: 50,
      }),
    enabled: canView && mode === 'list',
  })

  const suppliersQuery = useQuery({
    queryKey: ['suppliers'],
    queryFn: fetchSuppliers,
    enabled: canView,
  })

  const warehousesQuery = useQuery({
    queryKey: ['warehouses'],
    queryFn: fetchWarehouses,
    enabled: canView,
  })

  const purchaseLookup = useQuery({
    queryKey: ['posted-purchases-lookup', purchaseSearch],
    queryFn: () => fetchPostedPurchases({ q: purchaseSearch, per_page: 20 }),
    enabled: mode === 'editor' && !document?.ulid && purchaseSearch.trim().length >= 1,
  })

  const returnableQuery = useQuery({
    queryKey: ['returnable-lines', purchaseUlid],
    queryFn: () => fetchReturnableLines(purchaseUlid),
    enabled: Boolean(purchaseUlid) && mode === 'editor',
  })

  const rows = listQuery.data?.data ?? []
  const suppliers = useMemo(
    () => (suppliersQuery.data ?? []).filter((row) => row.is_active),
    [suppliersQuery.data],
  )
  const warehouses = warehousesQuery.data ?? []
  const returnable = returnableQuery.data?.data ?? []

  function handleError(err: unknown) {
    setError(err instanceof ApiClientError || err instanceof Error ? err.message : 'Purchase return failed.')
  }

  function resetEditor() {
    setDocument(null)
    setPurchaseUlid('')
    setPurchaseLabel('')
    setWarehouseUlid(session?.warehouse.ulid ?? '')
    setReturnDate(today())
    setSupplierReference('')
    setReason('')
    setNotes('')
    setLineQty({})
    setPurchaseSearch('')
    setError(null)
  }

  function loadDocument(invoice: PurchaseReturn) {
    setDocument(invoice)
    setPurchaseUlid(invoice.original_purchase?.ulid ?? '')
    setPurchaseLabel(
      invoice.original_purchase
        ? `${invoice.original_purchase.document_number}${
            invoice.original_purchase.supplier_invoice_number
              ? ` · ${invoice.original_purchase.supplier_invoice_number}`
              : ''
          }`
        : '',
    )
    setWarehouseUlid(invoice.warehouse?.ulid ?? session?.warehouse.ulid ?? '')
    setReturnDate(invoice.return_date)
    setSupplierReference(invoice.supplier_reference ?? '')
    setReason(invoice.reason ?? '')
    setNotes(invoice.notes ?? '')
    const qtyMap: Record<string, string> = {}
    for (const line of invoice.lines ?? []) {
      if (line.original_purchase_line_ulid) {
        qtyMap[line.original_purchase_line_ulid] = line.quantity
      }
    }
    setLineQty(qtyMap)
    setError(null)
  }

  const openMutation = useMutation({
    mutationFn: async (ulid: string) => fetchPurchaseReturn(ulid),
    onSuccess: (row) => {
      loadDocument(row)
      setMode('editor')
    },
  })

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (!purchaseUlid) throw new Error('Select a posted purchase invoice.')
      if (!warehouseUlid) throw new Error('Select a warehouse.')

      let current = document
      if (!current?.ulid) {
        current = await createPurchaseReturn({
          purchase_ulid: purchaseUlid,
          warehouse_ulid: warehouseUlid,
          return_date: returnDate,
          supplier_reference: supplierReference || null,
          reason: reason || null,
          notes: notes || null,
        })
      } else {
        current = await updatePurchaseReturn(current.ulid, {
          warehouse_ulid: warehouseUlid,
          return_date: returnDate,
          supplier_reference: supplierReference || null,
          reason: reason || null,
          notes: notes || null,
        })
      }

      const refreshed = await fetchPurchaseReturn(current.ulid)
      const existingByPurchaseLine = new Map(
        (refreshed.lines ?? [])
          .filter((line) => line.original_purchase_line_ulid)
          .map((line) => [line.original_purchase_line_ulid as string, line]),
      )

      for (const [purchaseLineUlid, quantity] of Object.entries(lineQty)) {
        const amount = Number(quantity)
        if (!Number.isFinite(amount) || amount <= 0) {
          const existing = existingByPurchaseLine.get(purchaseLineUlid)
          if (existing) await deletePurchaseReturnLine(current.ulid, existing.ulid)
          continue
        }
        const payload = {
          purchase_line_ulid: purchaseLineUlid,
          quantity: qty(quantity),
        }
        const existing = existingByPurchaseLine.get(purchaseLineUlid)
        if (existing) {
          await updatePurchaseReturnLine(current.ulid, existing.ulid, payload)
        } else {
          await createPurchaseReturnLine(current.ulid, payload)
        }
      }

      for (const [purchaseLineUlid, existing] of existingByPurchaseLine) {
        if (!(purchaseLineUlid in lineQty) || Number(lineQty[purchaseLineUlid]) <= 0) {
          await deletePurchaseReturnLine(current.ulid, existing.ulid)
        }
      }

      return fetchPurchaseReturn(current.ulid)
    },
    onSuccess: async (saved) => {
      loadDocument(saved)
      await queryClient.invalidateQueries({ queryKey: ['purchase-returns'] })
      await queryClient.invalidateQueries({ queryKey: ['returnable-lines', purchaseUlid] })
      setError(null)
    },
  })

  const postMutation = useMutation({
    mutationFn: async () => {
      const saved = await saveMutation.mutateAsync()
      return postPurchaseReturn(saved.ulid)
    },
    onSuccess: async (saved) => {
      loadDocument(saved)
      await queryClient.invalidateQueries({ queryKey: ['purchase-returns'] })
      await queryClient.invalidateQueries({ queryKey: ['returnable-lines', purchaseUlid] })
      setError(null)
    },
  })

  useWorkspaceHandlers({
    save: () => {
      if (mode === 'editor' && !readOnly && (canEdit || canCreate)) {
        void saveMutation.mutateAsync().catch(handleError)
      }
    },
    refresh: () => {
      if (mode === 'list') void listQuery.refetch()
      else if (document?.ulid) void openMutation.mutateAsync(document.ulid).catch(handleError)
    },
  })

  function remainingPurchaseQty(row: ReturnablePurchaseLine): string {
    const factor = Number(row.conversion_factor) || 1
    const remainingBase = Number(row.remaining_returnable_base_quantity) || 0
    return (remainingBase / factor).toFixed(6)
  }

  if (!canView) {
    return (
      <DesktopPanel title="Purchase Returns">
        <p className="p-3 text-sm text-[var(--text-muted)]">You do not have permission to view purchase returns.</p>
      </DesktopPanel>
    )
  }

  if (mode === 'list') {
    return (
      <DesktopPanel
        title="Purchase Returns"
        toolbar={
          <>
            <DesktopButton
              icon={<Plus size={13} />}
              label="New"
              disabled={!canCreate}
              onClick={() => {
                resetEditor()
                setMode('editor')
              }}
            />
            <DesktopButton
              icon={<Save size={13} />}
              label="Open"
              disabled={!selectedListKey}
              onClick={() => {
                if (!selectedListKey) return
                void openMutation.mutateAsync(selectedListKey).catch(handleError)
              }}
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
        <div className="dense-row is-2 mb-2" style={{ gridTemplateColumns: '1fr 140px 180px 120px 120px' }}>
          <input
            className="desktop-input"
            placeholder="Search return / purchase / supplier"
            value={q}
            onChange={(event) => setQ(event.target.value)}
          />
          <select className="desktop-select" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}>
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="posted">Posted</option>
          </select>
          <select className="desktop-select" value={supplierFilter} onChange={(event) => setSupplierFilter(event.target.value)}>
            <option value="">All suppliers</option>
            {suppliers.map((supplier) => (
              <option key={supplier.ulid} value={supplier.ulid}>
                {supplier.code} · {supplier.name}
              </option>
            ))}
          </select>
          <input className="desktop-input" type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} />
          <input className="desktop-input" type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} />
        </div>
        {error ? <div className="mb-2 text-sm text-[var(--danger)]">{error}</div> : null}
        <PosDataGrid
          columns={[
            { key: 'document_number', header: 'Return #', width: 110 },
            { key: 'return_date', header: 'Date', width: 100 },
            {
              key: 'original_purchase',
              header: 'Original Purchase #',
              width: 140,
              render: (row) => row.original_purchase?.document_number ?? '—',
            },
            {
              key: 'supplier',
              header: 'Supplier',
              render: (row) => row.supplier?.name ?? '—',
            },
            {
              key: 'warehouse',
              header: 'Warehouse',
              width: 120,
              render: (row) => row.warehouse?.name ?? '—',
            },
            { key: 'grand_total', header: 'Total', align: 'right', width: 110 },
            {
              key: 'status',
              header: 'Status',
              width: 90,
              render: (row) => row.status.toUpperCase(),
            },
          ]}
          rows={rows}
          rowKey={(row) => row.ulid}
          selectedKey={selectedListKey}
          onSelect={(row) => setSelectedListKey(row.ulid)}
          onActivate={(row) => void openMutation.mutateAsync(row.ulid).catch(handleError)}
          emptyMessage="No purchase returns yet."
        />
      </DesktopPanel>
    )
  }

  return (
    <div className="pos-invoice" style={{ gridTemplateColumns: '1fr' }}>
      <div className="pos-invoice-main">
        <div className="inner-tabs">
          <button type="button" className="inner-tab is-active">Purchase Return</button>
          <span style={{ marginLeft: 'auto', fontWeight: 800, fontSize: 16, padding: '4px 10px' }}>
            {document?.document_number ?? 'New'} · {(document?.status ?? 'draft').toUpperCase()}
          </span>
        </div>

        <div className="purchase-header">
          <div className="grid gap-1">
            <div className="dense-row is-2">
              <label>Return #</label>
              <input className="desktop-input" disabled value={document?.document_number ?? 'Auto'} readOnly />
              <label>Date</label>
              <input
                className="desktop-input"
                type="date"
                disabled={readOnly || !canEdit}
                value={returnDate}
                onChange={(event) => setReturnDate(event.target.value)}
              />
            </div>
            <div className="dense-row is-2">
              <label>Purchase</label>
              <input
                className="desktop-input"
                disabled={Boolean(document?.ulid) || readOnly}
                value={purchaseLabel || purchaseSearch}
                placeholder="Search posted purchase #"
                onChange={(event) => {
                  setPurchaseSearch(event.target.value)
                  setPurchaseLabel('')
                  setPurchaseUlid('')
                }}
              />
              <label>Supplier</label>
              <input className="desktop-input" disabled value={document?.supplier?.name ?? 'From purchase'} readOnly />
            </div>
            <div className="dense-row is-2">
              <label>Warehouse</label>
              <select
                className="desktop-select"
                disabled={readOnly || (!document?.ulid ? !canCreate : !canEdit)}
                value={warehouseUlid}
                onChange={(event) => setWarehouseUlid(event.target.value)}
              >
                <option value="">Select warehouse</option>
                {warehouses.map((warehouse) => (
                  <option key={warehouse.ulid} value={warehouse.ulid}>
                    {warehouse.code} · {warehouse.name}
                  </option>
                ))}
              </select>
              <label>Supp. Ref</label>
              <input
                className="desktop-input"
                disabled={readOnly || !canEdit}
                value={supplierReference}
                onChange={(event) => setSupplierReference(event.target.value)}
              />
            </div>
            <div className="dense-row">
              <label>Reason</label>
              <input
                className="desktop-input"
                disabled={readOnly || !canEdit}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
              />
            </div>
            <div className="dense-row">
              <label>Notes</label>
              <textarea
                className="desktop-textarea"
                rows={2}
                disabled={readOnly || !canEdit}
                value={notes}
                onChange={(event) => setNotes(event.target.value)}
              />
            </div>
          </div>

          <div className="amt-stack">
            <div className="amt-box is-cyan"><span>Subtotal</span><input disabled value={document?.subtotal ?? '0.0000'} readOnly /></div>
            <div className="amt-box is-red"><span>Discount</span><input disabled value={document?.discount_amount ?? '0.0000'} readOnly /></div>
            <div className="amt-box is-red"><span>Tax</span><input disabled value={document?.tax_amount ?? '0.0000'} readOnly /></div>
            <div className="amt-box is-yellow"><span>Grand Total</span><input disabled value={document?.grand_total ?? '0.0000'} readOnly /></div>
          </div>
        </div>

        {!document?.ulid && purchaseLookup.data?.data?.length ? (
          <div className="mb-2 max-h-36 overflow-auto border border-[var(--border)] bg-white text-[11px]">
            {purchaseLookup.data.data.map((purchase) => (
              <button
                key={purchase.ulid}
                type="button"
                className="block w-full px-2 py-1 text-left hover:bg-[var(--row-hover)]"
                onClick={() => {
                  setPurchaseUlid(purchase.ulid)
                  setPurchaseLabel(
                    `${purchase.document_number}${
                      purchase.supplier_invoice_number ? ` · ${purchase.supplier_invoice_number}` : ''
                    }`,
                  )
                  setPurchaseSearch('')
                  if (purchase.warehouse?.ulid) setWarehouseUlid(purchase.warehouse.ulid)
                }}
              >
                {purchase.document_number} · {purchase.supplier?.name ?? 'Supplier'} · {purchase.grand_total}
              </button>
            ))}
          </div>
        ) : null}

        <div className="invoice-grid">
          <PosDataGrid
            columns={[
              {
                key: 'product',
                header: 'Product',
                render: (row) =>
                  row.product ? `${row.product.product_number} · ${row.product.name}` : '—',
              },
              {
                key: 'original_quantity',
                header: 'Purchased Qty',
                width: 100,
                align: 'right',
              },
              {
                key: 'already_returned_base_quantity',
                header: 'Already Returned (base)',
                width: 130,
                align: 'right',
              },
              {
                key: 'remaining',
                header: 'Remaining',
                width: 90,
                align: 'right',
                render: (row) => remainingPurchaseQty(row),
              },
              {
                key: 'return_qty',
                header: 'Return Qty',
                width: 100,
                align: 'right',
                render: (row) =>
                  readOnly ? (
                    lineQty[row.purchase_line_ulid] ?? '0'
                  ) : (
                    <input
                      className="desktop-input"
                      value={lineQty[row.purchase_line_ulid] ?? ''}
                      placeholder="0"
                      onChange={(event) =>
                        setLineQty((prev) => ({
                          ...prev,
                          [row.purchase_line_ulid]: event.target.value,
                        }))
                      }
                    />
                  ),
              },
              {
                key: 'unit',
                header: 'Unit',
                width: 70,
                render: (row) => row.unit?.code ?? '—',
              },
              { key: 'conversion_factor', header: 'Factor', width: 80, align: 'right' },
              { key: 'unit_cost', header: 'Unit Cost', width: 90, align: 'right' },
            ]}
            rows={returnable}
            rowKey={(row) => row.purchase_line_ulid}
            emptyMessage={purchaseUlid ? 'No returnable lines.' : 'Select a posted purchase first.'}
          />
        </div>

        {error ? <div className="mt-2 text-sm text-[var(--danger)]">{error}</div> : null}

        <div className="invoice-bottom">
          <span className="text-[11px] text-[var(--text-muted)]">
            {returnable.filter((row) => Number(lineQty[row.purchase_line_ulid] ?? 0) > 0).length} return line(s)
          </span>
          <div className="flex gap-1">
            <DesktopButton
              icon={<Save size={13} />}
              label="Save Draft"
              shortcut="F9"
              disabled={readOnly || saveMutation.isPending || (document?.ulid ? !canEdit : !canCreate)}
              onClick={() => void saveMutation.mutateAsync().catch(handleError)}
            />
            <DesktopButton
              icon={<CheckCircle2 size={13} />}
              label="Post"
              disabled={readOnly || !canPost || postMutation.isPending}
              onClick={() => {
                if (!window.confirm('Post this purchase return and reduce stock?')) return
                void postMutation.mutateAsync().catch(handleError)
              }}
            />
            <DesktopButton
              icon={<RefreshCw size={13} />}
              label="Refresh"
              shortcut="F8"
              disabled={!document?.ulid}
              onClick={() => document?.ulid && void openMutation.mutateAsync(document.ulid).catch(handleError)}
            />
            <DesktopButton
              icon={<X size={13} />}
              label="Close"
              shortcut="Esc"
              onClick={() => {
                setMode('list')
                setError(null)
                void listQuery.refetch()
              }}
            />
            {!readOnly && document?.ulid ? (
              <DesktopButton
                icon={<Trash2 size={13} />}
                label="Clear qty"
                onClick={() => setLineQty({})}
              />
            ) : null}
          </div>
        </div>
      </div>
    </div>
  )
}
