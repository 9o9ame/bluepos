import { useEffect, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react'
import { CheckCircle2, ChevronDown, FileText, RefreshCw, RotateCcw, Save, Search, Trash2, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { fetchSuppliers } from '../api/catalog'
import { fetchWarehouses } from '../api/inventory'
import { fetchPurchase } from '../api/purchases'
import {
  createPurchaseReturn,
  createPurchaseReturnLine,
  deletePurchaseReturnLine,
  fetchPostedPurchases,
  fetchPurchaseReturn,
  fetchPurchaseReturnProductWise,
  fetchPurchaseReturns,
  fetchReturnableLines,
  postPurchaseReturn,
  updatePurchaseReturn,
  updatePurchaseReturnLine,
} from '../api/purchaseReturns'
import { ApiClientError } from '../api/client'
import { askConfirm } from '../feedback/FeedbackProvider'
import { DesktopButton } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useLocalProducts } from '../features/catalog/useLocalCatalog'
import { ProductLookupGrid } from '../features/sales/ProductLookupGrid'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Product } from '../types/catalog'
import type { PurchaseInvoice } from '../types/purchases'
import type { PurchaseReturn, ReturnablePurchaseLine } from '../types/purchaseReturns'
import './PurchaseReturnsPage.css'

type ViewMode = 'entry' | 'search' | 'product'

type GeneralReturnRow = {
  key: string
  line_ulid?: string
  product_ulid: string
  product_number: string
  product_name: string
  unit_code: string
  quantity: string
  unit_cost: string
  discount_amount: string
  tax_amount: string
}

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function qty(value: string): string {
  const n = Number(value)
  return Number.isFinite(n) ? n.toFixed(6) : '0.000000'
}

function money4(value: string | number | null | undefined): string {
  const n = typeof value === 'number' ? value : Number.parseFloat(value ?? '0')
  return Number.isFinite(n) ? n.toFixed(4) : '0.0000'
}

function money(value: string | number | null | undefined): string {
  const n = typeof value === 'number' ? value : Number.parseFloat(value ?? '0')
  return Number.isFinite(n) ? n.toFixed(2) : '0.00'
}

function generalRowKey(): string {
  return `purchase-return-general-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

function generalAmounts(row: GeneralReturnRow) {
  const quantity = Math.max(Number(row.quantity) || 0, 0)
  const unitCost = Math.max(Number(row.unit_cost) || 0, 0)
  const discount = Math.max(Number(row.discount_amount) || 0, 0)
  const tax = Math.max(Number(row.tax_amount) || 0, 0)
  const subtotal = quantity * unitCost
  return {
    subtotal,
    discount,
    tax,
    total: Math.max(subtotal - discount + tax, 0),
  }
}

function defaultProductCost(product: Product): string {
  return money4(product.purchase_rate ?? product.sales_lookup?.average_cost ?? '0')
}

export function PurchaseReturnsPage({
  active = true,
  workspaceSearch,
}: {
  active?: boolean
  workspaceSearch?: string
} = {}) {
  const queryClient = useQueryClient()
  const { session } = useAuth()
  const { closeActiveTab } = useWorkspace()
  const [routeSearchParams, setSearchParams] = useSearchParams()
  const searchParams = useMemo(
    () =>
      workspaceSearch === undefined
        ? routeSearchParams
        : new URLSearchParams(workspaceSearch),
    [routeSearchParams, workspaceSearch],
  )

  const deepLinkHandled = useRef<string | null>(null)
  const purchasePickerRef = useRef<HTMLDivElement | null>(null)
  const generalProductAnchorRef = useRef<HTMLDivElement | null>(null)
  const generalProductEntryRef = useRef<HTMLInputElement | null>(null)

  const canView = useCan('purchase_returns.view')
  const canCreate = useCan('purchase_returns.create')
  const canEdit = useCan('purchase_returns.edit')
  const canPost = useCan('purchase_returns.post')

  const [view, setView] = useState<ViewMode>('entry')
  const [q, setQ] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [supplierFilter, setSupplierFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedListKey, setSelectedListKey] = useState<string | null>(null)
  const [productDateFrom, setProductDateFrom] = useState(today())
  const [productDateTo, setProductDateTo] = useState(today())

  const [document, setDocument] = useState<PurchaseReturn | null>(null)
  const [purchaseUlid, setPurchaseUlid] = useState('')
  const [purchaseLabel, setPurchaseLabel] = useState('')
  const [supplierUlid, setSupplierUlid] = useState('')
  const [warehouseUlid, setWarehouseUlid] = useState(session?.warehouse.ulid ?? '')
  const [returnDate, setReturnDate] = useState(today())
  const [supplierReference, setSupplierReference] = useState('')
  const [reason, setReason] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [purchaseSearch, setPurchaseSearch] = useState('')
  const [purchasePickerOpen, setPurchasePickerOpen] = useState(false)
  const [lineQty, setLineQty] = useState<Record<string, string>>({})

  const [generalRows, setGeneralRows] = useState<GeneralReturnRow[]>([])
  const [generalProductSearch, setGeneralProductSearch] = useState('')
  const [generalProductPickerOpen, setGeneralProductPickerOpen] = useState(false)
  const [generalProductLookupMode, setGeneralProductLookupMode] = useState<'browse' | 'search'>('browse')
  const [generalProductTargetKey, setGeneralProductTargetKey] = useState<string | null>(null)

  const readOnly = document?.status === 'posted'
  const invoiceMode = Boolean(purchaseUlid || document?.return_mode === 'invoice')

  const listQuery = useQuery({
    queryKey: ['purchase-returns', q, statusFilter, supplierFilter, dateFrom, dateTo],
    queryFn: () =>
      fetchPurchaseReturns({
        q: q || undefined,
        status: statusFilter || undefined,
        supplier_ulid: supplierFilter || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        per_page: 100,
      }),
    enabled: canView && view === 'search',
    retry: false,
  })

  const suppliersQuery = useQuery({
    queryKey: ['suppliers'],
    queryFn: fetchSuppliers,
    enabled: canView,
    retry: false,
  })

  const warehousesQuery = useQuery({
    queryKey: ['warehouses'],
    queryFn: fetchWarehouses,
    enabled: canView,
    retry: false,
  })

  const purchaseLookup = useQuery({
    queryKey: ['posted-purchases-lookup', purchaseSearch],
    queryFn: () => fetchPostedPurchases({ q: purchaseSearch || undefined, per_page: 20 }),
    enabled: view === 'entry' && !document?.ulid && purchasePickerOpen,
    retry: false,
  })

  const returnableQuery = useQuery({
    queryKey: ['returnable-lines', purchaseUlid],
    queryFn: () => fetchReturnableLines(purchaseUlid),
    enabled: Boolean(purchaseUlid) && view === 'entry',
    retry: false,
  })


  const productWiseQuery = useQuery({
    queryKey: ['purchase-returns', 'product-wise', productDateFrom, productDateTo],
    queryFn: () =>
      fetchPurchaseReturnProductWise({
        date_from: productDateFrom || undefined,
        date_to: productDateTo || undefined,
        per_page: 100,
      }),
    enabled: canView && view === 'product',
    retry: false,
  })

  const generalProductLookupQuery =
    generalProductLookupMode === 'search' ? generalProductSearch : ''

  const localGeneralProducts = useLocalProducts(
    session,
    generalProductLookupQuery,
    view === 'entry' && !invoiceMode && !readOnly && generalProductPickerOpen,
  )

  const generalProducts = localGeneralProducts.rows.filter(
    (product) => product.is_active && product.status === 'active',
  )

  const rows = listQuery.data?.data ?? []
  const suppliers = useMemo(
    () => (suppliersQuery.data ?? []).filter((row) => row.is_active),
    [suppliersQuery.data],
  )
  const warehouses = warehousesQuery.data ?? []
  const returnable = returnableQuery.data?.data ?? []

  const liveTotals = useMemo(() => {
    if (!invoiceMode) {
      return generalRows.reduce(
        (acc, row) => {
          const amounts = generalAmounts(row)
          acc.subtotal += amounts.subtotal
          acc.discount += amounts.discount
          acc.tax += amounts.tax
          acc.total += amounts.total
          return acc
        },
        { subtotal: 0, discount: 0, tax: 0, total: 0 },
      )
    }

    if (document) {
      return {
        subtotal: Number(document.subtotal) || 0,
        discount: Number(document.discount_amount) || 0,
        tax: Number(document.tax_amount) || 0,
        total: Number(document.grand_total) || 0,
      }
    }

    return { subtotal: 0, discount: 0, tax: 0, total: 0 }
  }, [document, generalRows, invoiceMode])

  const selectedLineCount = invoiceMode
    ? returnable.filter((row) => Number(lineQty[row.purchase_line_ulid] ?? 0) > 0).length
    : generalRows.length

  const selectedSupplierName =
    document?.supplier?.name ??
    suppliers.find((supplier) => supplier.ulid === supplierUlid)?.name ??
    'From purchase'

  function handleError(err: unknown) {
    setError(
      err instanceof ApiClientError || err instanceof Error
        ? err.message
        : 'Purchase return failed.',
    )
  }

  function resetEditor() {
    setDocument(null)
    setPurchaseUlid('')
    setPurchaseLabel('')
    setPurchaseSearch('')
    setSupplierUlid('')
    setWarehouseUlid(session?.warehouse.ulid ?? '')
    setReturnDate(today())
    setSupplierReference('')
    setReason('')
    setNotes('')
    setLineQty({})
    setGeneralRows([])
    setGeneralProductSearch('')
    setGeneralProductPickerOpen(false)
    setGeneralProductLookupMode('browse')
    setGeneralProductTargetKey(null)
    setPurchasePickerOpen(false)
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
    setPurchaseSearch(invoice.original_purchase?.document_number ?? '')
    setSupplierUlid(invoice.supplier?.ulid ?? '')
    setWarehouseUlid(invoice.warehouse?.ulid ?? session?.warehouse.ulid ?? '')
    setReturnDate(invoice.return_date)
    setSupplierReference(invoice.supplier_reference ?? '')
    setReason(invoice.reason ?? '')
    setNotes(invoice.notes ?? '')

    const qtyMap: Record<string, string> = {}
    const nextGeneral: GeneralReturnRow[] = []

    for (const line of invoice.lines ?? []) {
      if (line.original_purchase_line_ulid) {
        qtyMap[line.original_purchase_line_ulid] = line.quantity
      } else if (line.product) {
        nextGeneral.push({
          key: line.ulid,
          line_ulid: line.ulid,
          product_ulid: line.product.ulid,
          product_number: line.product.product_number,
          product_name: line.product.name,
          unit_code: line.unit?.code ?? '—',
          quantity: line.quantity,
          unit_cost: line.unit_cost,
          discount_amount: line.discount_amount,
          tax_amount: line.tax_amount,
        })
      }
    }

    setLineQty(qtyMap)
    setGeneralRows(nextGeneral)
    setPurchasePickerOpen(false)
    setGeneralProductPickerOpen(false)
    setGeneralProductLookupMode('browse')
    setGeneralProductTargetKey(null)
    setError(null)
  }

  function selectPurchase(purchase: PurchaseInvoice) {
    setPurchaseUlid(purchase.ulid)
    setPurchaseLabel(
      `${purchase.document_number}${
        purchase.supplier_invoice_number ? ` · ${purchase.supplier_invoice_number}` : ''
      }`,
    )
    setPurchaseSearch(purchase.document_number)
    setSupplierUlid(purchase.supplier?.ulid ?? '')
    if (purchase.warehouse?.ulid) setWarehouseUlid(purchase.warehouse.ulid)
    setLineQty({})
    setGeneralRows([])
    setGeneralProductSearch('')
    setGeneralProductPickerOpen(false)
    setGeneralProductLookupMode('browse')
    setGeneralProductTargetKey(null)
    setPurchasePickerOpen(false)
    setError(null)
  }

  function focusGeneralField(rowKey: string, field: 'quantity' | 'cost' | 'discount' | 'tax') {
    window.setTimeout(() => {
      const input = globalThis.document.querySelector<HTMLInputElement>(
        `[data-return-row="${rowKey}"] [data-general-field="${field}"]`,
      )
      input?.focus()
      input?.select()
    }, 0)
  }

  function addGeneralProduct(product: Product) {
    if (product.track_batch || product.track_expiry) {
      setError('Batch/expiry tracked products must be returned by selecting their posted Purchase #.')
      setGeneralProductPickerOpen(false)
      return
    }

    const existing = generalRows.find(
      (row) =>
        row.product_ulid === product.ulid &&
        row.key !== generalProductTargetKey,
    )

    if (existing) {
      setGeneralRows((current) =>
        current.map((row) =>
          row.key === existing.key
            ? {
                ...row,
                quantity: ((Number.parseFloat(row.quantity) || 0) + 1).toFixed(6),
              }
            : row,
        ),
      )
      setGeneralProductTargetKey(null)
      setGeneralProductSearch('')
      setGeneralProductLookupMode('browse')
      setGeneralProductPickerOpen(false)
      setError(null)
      focusGeneralField(existing.key, 'quantity')
      return
    }

    const rowKey = generalProductTargetKey ?? generalRowKey()
    const nextRow: GeneralReturnRow = {
      key: rowKey,
      product_ulid: product.ulid,
      product_number: product.product_number,
      product_name: product.name,
      unit_code: product.base_unit?.code ?? '—',
      quantity: '1.000000',
      unit_cost: defaultProductCost(product),
      discount_amount: '0.0000',
      tax_amount: '0.0000',
    }

    if (generalProductTargetKey) {
      setGeneralRows((current) =>
        current.map((row) =>
          row.key === generalProductTargetKey
            ? { ...nextRow, line_ulid: row.line_ulid }
            : row,
        ),
      )
    } else {
      setGeneralRows((current) => [...current, nextRow])
    }

    setGeneralProductSearch('')
    setGeneralProductPickerOpen(false)
    setGeneralProductLookupMode('browse')
    setGeneralProductTargetKey(null)
    setError(null)
    focusGeneralField(rowKey, 'quantity')
  }

  function handleGeneralEnter(event: ReactKeyboardEvent<HTMLInputElement>) {
    if (event.key !== 'Enter') return
    event.preventDefault()
    const row = event.currentTarget.closest('tr')
    if (!row) return

    const inputs = Array.from(
      row.querySelectorAll<HTMLInputElement>('[data-general-editable="true"]:not(:disabled)'),
    )
    const index = inputs.indexOf(event.currentTarget)
    const next = inputs[index + 1]
    if (next) {
      next.focus()
      next.select()
      return
    }

    setGeneralProductTargetKey(null)
    setGeneralProductSearch('')
    setGeneralProductLookupMode('browse')
    setGeneralProductPickerOpen(true)
    window.setTimeout(() => {
      generalProductEntryRef.current?.focus()
      generalProductEntryRef.current?.select()
    }, 0)
  }

  useEffect(() => {
    if (!purchasePickerOpen) return

    function handlePointerDown(event: MouseEvent) {
      const target = event.target as Node
      if (purchasePickerRef.current?.contains(target)) return
      setPurchasePickerOpen(false)
    }

    window.addEventListener('mousedown', handlePointerDown)
    return () => window.removeEventListener('mousedown', handlePointerDown)
  }, [purchasePickerOpen])

  useEffect(() => {
    if (!active) return
    const purchaseParam = searchParams.get('purchase')?.trim() ?? ''
    if (!purchaseParam || !canCreate || deepLinkHandled.current === purchaseParam) return

    void (async () => {
      try {
        const purchase = await fetchPurchase(purchaseParam)
        if (purchase.status !== 'posted') {
          setError('Only posted purchase invoices can be returned.')
          setSearchParams({}, { replace: true })
          return
        }

        deepLinkHandled.current = purchaseParam
        resetEditor()
        selectPurchase(purchase)
        setView('entry')
        setSearchParams({}, { replace: true })
      } catch (err) {
        handleError(err)
        setSearchParams({}, { replace: true })
      }
    })()
  }, [searchParams, canCreate, setSearchParams])

  const openMutation = useMutation({
    mutationFn: async (ulid: string) => fetchPurchaseReturn(ulid),
    onSuccess: (row) => {
      loadDocument(row)
      setView('entry')
    },
  })

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (!warehouseUlid) throw new Error('Select a warehouse.')
      if (!purchaseUlid && !supplierUlid) {
        throw new Error('Select a supplier for a general purchase return, or select a posted Purchase #.')
      }

      let current = document

      if (!current?.ulid) {
        current = await createPurchaseReturn({
          purchase_ulid: purchaseUlid || null,
          supplier_ulid: purchaseUlid ? null : supplierUlid,
          warehouse_ulid: warehouseUlid,
          return_date: returnDate,
          supplier_reference: supplierReference || null,
          reason: reason || null,
          notes: notes || null,
        })
      } else {
        current = await updatePurchaseReturn(current.ulid, {
          warehouse_ulid: warehouseUlid,
          ...(current.return_mode === 'general' && supplierUlid ? { supplier_ulid: supplierUlid } : {}),
          return_date: returnDate,
          supplier_reference: supplierReference || null,
          reason: reason || null,
          notes: notes || null,
        })
      }

      const refreshed = await fetchPurchaseReturn(current.ulid)

      if (purchaseUlid) {
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
      } else {
        const persistedGeneral = (refreshed.lines ?? []).filter(
          (line) => !line.original_purchase_line_ulid,
        )
        const activeLineUlids = new Set(generalRows.map((row) => row.line_ulid).filter(Boolean))

        for (const row of generalRows) {
          if (!/^\d+(?:\.\d{0,6})?$/.test(row.quantity) || Number(row.quantity) <= 0) {
            throw new Error(`Return quantity for ${row.product_name} must be greater than zero and use up to 6 decimals.`)
          }
          if (!/^\d+(?:\.\d{0,4})?$/.test(row.unit_cost) || Number(row.unit_cost) < 0) {
            throw new Error(`Unit cost for ${row.product_name} must use up to 4 decimals.`)
          }
          if (!/^\d+(?:\.\d{0,4})?$/.test(row.discount_amount) || Number(row.discount_amount) < 0) {
            throw new Error(`Discount for ${row.product_name} must use up to 4 decimals.`)
          }
          if (!/^\d+(?:\.\d{0,4})?$/.test(row.tax_amount) || Number(row.tax_amount) < 0) {
            throw new Error(`Tax for ${row.product_name} must use up to 4 decimals.`)
          }

          const payload = {
            product_ulid: row.product_ulid,
            quantity: qty(row.quantity),
            unit_cost: money4(row.unit_cost),
            discount_amount: money4(row.discount_amount),
            tax_amount: money4(row.tax_amount),
          }

          if (row.line_ulid) {
            await updatePurchaseReturnLine(current.ulid, row.line_ulid, payload)
          } else {
            await createPurchaseReturnLine(current.ulid, payload)
          }
        }

        for (const existing of persistedGeneral) {
          if (!activeLineUlids.has(existing.ulid)) {
            await deletePurchaseReturnLine(current.ulid, existing.ulid)
          }
        }
      }

      return fetchPurchaseReturn(current.ulid)
    },
    onSuccess: async (saved) => {
      loadDocument(saved)
      await queryClient.invalidateQueries({ queryKey: ['purchase-returns'] })
      if (purchaseUlid) {
        await queryClient.invalidateQueries({ queryKey: ['returnable-lines', purchaseUlid] })
      }
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
      if (purchaseUlid) {
        await queryClient.invalidateQueries({ queryKey: ['returnable-lines', purchaseUlid] })
      }
      setError(null)
    },
  })

  useWorkspaceHandlers(
    {
      save: () => {
        if (view === 'entry' && !readOnly && (canEdit || canCreate)) {
          void saveMutation.mutateAsync().catch(handleError)
        }
      },
      refresh: () => {
        if (view === 'search') {
          void listQuery.refetch()
        } else if (view === 'product') {
          void productWiseQuery.refetch()
        } else if (view === 'entry') {
          if (document?.ulid) {
            void openMutation.mutateAsync(document.ulid).catch(handleError)
          } else if (purchaseUlid) {
            void returnableQuery.refetch()
          } else {
            void localGeneralProducts.refreshFromServer()
          }
        }
      },
    },
    active,
  )

  function remainingPurchaseQty(row: ReturnablePurchaseLine): string {
    const factor = Number(row.conversion_factor) || 1
    const remainingBase = Number(row.remaining_returnable_base_quantity) || 0
    return (remainingBase / factor).toFixed(6)
  }

  function alreadyReturnedQty(row: ReturnablePurchaseLine): string {
    const factor = Number(row.conversion_factor) || 1
    const returnedBase = Number(row.already_returned_base_quantity) || 0
    return (returnedBase / factor).toFixed(6)
  }

  if (!canView) {
    return (
      <div className="purchase-return-no-access">
        You do not have permission to view Purchase Returns.
      </div>
    )
  }

  return (
    <section className="purchase-return-screen">
      <header className="purchase-return-tabs">
        <button
          type="button"
          className={view === 'entry' ? 'is-active' : ''}
          onClick={() => setView('entry')}
        >
          <RotateCcw size={17} />
          Purchase Return Invoice
        </button>
        <button
          type="button"
          className={view === 'search' ? 'is-active' : ''}
          onClick={() => setView('search')}
        >
          <Search size={17} />
          Search Return Invoices
        </button>
        <button
          type="button"
          className={view === 'product' ? 'is-active' : ''}
          onClick={() => setView('product')}
        >
          <FileText size={17} />
          Product Wise
        </button>
        <strong className="purchase-return-title">Purchase Return</strong>
      </header>

      {view === 'entry' ? (
        <div className="purchase-return-entry">
          <section className="purchase-return-head">
            <fieldset>
              <legend>Purchase Return Invoice Options</legend>

              <div className="purchase-return-fields">
                <div className="purchase-return-option-group is-ret">
                  <label>Ret#:</label>
                  <input value={document?.document_number ?? 'Auto'} readOnly />
                </div>

                <div className="purchase-return-option-group is-date">
                  <label>Date:</label>
                  <input
                    type="date"
                    value={returnDate}
                    disabled={readOnly || (!document?.ulid ? !canCreate : !canEdit)}
                    onChange={(event) => setReturnDate(event.target.value)}
                  />
                </div>

                <div className="purchase-return-option-group is-purchase">
                  <label>Purchase #:</label>
                  <div
                    ref={purchasePickerRef}
                    className={`purchase-return-purchase-search${purchasePickerOpen ? ' is-open' : ''}`}
                  >
                    <div className="purchase-return-purchase-input">
                      <Search size={13} aria-hidden="true" />
                      <input
                        value={purchaseLabel || purchaseSearch}
                        disabled={Boolean(document?.ulid) || readOnly}
                        placeholder="Optional: select posted purchase #"
                        onFocus={() => {
                          if (!document?.ulid && !readOnly) setPurchasePickerOpen(true)
                        }}
                        onChange={(event) => {
                          setPurchaseSearch(event.target.value)
                          setPurchaseLabel('')
                          setPurchaseUlid('')
                          setSupplierUlid('')
                          setLineQty({})
                          setGeneralRows([])
                          setPurchasePickerOpen(true)
                        }}
                        onKeyDown={(event) => {
                          if (event.key === 'Escape') {
                            setPurchasePickerOpen(false)
                            return
                          }

                          if (event.key === 'Enter' && purchasePickerOpen) {
                            const first = purchaseLookup.data?.data?.[0]
                            if (first) {
                              event.preventDefault()
                              selectPurchase(first)
                            }
                          }
                        }}
                      />
                      <button
                        type="button"
                        className="purchase-return-purchase-trigger"
                        disabled={Boolean(document?.ulid) || readOnly}
                        onClick={() => setPurchasePickerOpen((open) => !open)}
                        aria-label="Open posted purchases"
                      >
                        <ChevronDown size={14} />
                      </button>
                    </div>

                    {!document?.ulid && purchasePickerOpen ? (
                      <div className="purchase-return-purchase-results">
                        <div className="purchase-return-purchase-results-head">
                          <span>Purchase #</span>
                          <span>Date</span>
                          <span>Supplier</span>
                          <span>Supplier Ref</span>
                          <span>Total</span>
                        </div>
                        <div className="purchase-return-purchase-results-body">
                          {(purchaseLookup.data?.data ?? []).map((purchase) => (
                            <button
                              key={purchase.ulid}
                              type="button"
                              className={
                                purchaseLabel.startsWith(purchase.document_number)
                                  ? 'is-selected'
                                  : undefined
                              }
                              onClick={() => selectPurchase(purchase)}
                            >
                              <strong>{purchase.document_number}</strong>
                              <span>{purchase.invoice_date}</span>
                              <span>{purchase.supplier?.name ?? '—'}</span>
                              <span>{purchase.supplier_invoice_number ?? '—'}</span>
                              <span>{money(purchase.grand_total)}</span>
                            </button>
                          ))}

                          {purchaseLookup.isError ? (
                            <div className="purchase-return-purchase-results-empty">
                              Could not load posted purchases. Use Refresh and try again.
                            </div>
                          ) : !purchaseLookup.isFetching &&
                            (purchaseLookup.data?.data.length ?? 0) === 0 ? (
                            <div className="purchase-return-purchase-results-empty">
                              No posted purchases match this search.
                            </div>
                          ) : null}

                          {purchaseLookup.isFetching ? (
                            <div className="purchase-return-purchase-results-empty">
                              Searching posted purchases…
                            </div>
                          ) : null}
                        </div>
                        <div className="purchase-return-purchase-results-foot">
                          {(purchaseLookup.data?.meta.total ?? 0)} posted purchase(s)
                        </div>
                      </div>
                    ) : null}
                  </div>
                </div>

                <div className="purchase-return-option-group is-supplier">
                  <label>Supplier:</label>
                  {invoiceMode ? (
                    <input value={selectedSupplierName} readOnly />
                  ) : (
                    <UiSelect
                      aria-label="Supplier"
                      value={supplierUlid}
                      disabled={readOnly || Boolean(document?.ulid && document.return_mode === 'invoice')}
                      className="purchase-return-canonical-select"
                      triggerClassName="purchase-return-canonical-trigger"
                      placeholder="Select supplier"
                      options={[
                        { value: '', label: 'Select supplier' },
                        ...suppliers.map((supplier) => ({
                          value: supplier.ulid,
                          label: `${supplier.code} · ${supplier.name}`,
                        })),
                      ]}
                      onChange={setSupplierUlid}
                    />
                  )}
                </div>

                <div className="purchase-return-option-group is-warehouse">
                  <label>Warehouse:</label>
                  <UiSelect
                    aria-label="Warehouse"
                    value={warehouseUlid}
                    disabled={readOnly || (!document?.ulid ? !canCreate : !canEdit)}
                    className="purchase-return-canonical-select"
                    triggerClassName="purchase-return-canonical-trigger"
                    options={[
                      { value: '', label: 'Select warehouse' },
                      ...warehouses.map((warehouse) => ({
                        value: warehouse.ulid,
                        label: `${warehouse.code} · ${warehouse.name}`,
                      })),
                    ]}
                    onChange={setWarehouseUlid}
                  />
                </div>

                <div className="purchase-return-option-group is-supplier-ref">
                  <label>Supp. Ref:</label>
                  <input
                    value={supplierReference}
                    disabled={readOnly || (!document?.ulid ? !canCreate : !canEdit)}
                    onChange={(event) => setSupplierReference(event.target.value)}
                  />
                </div>

                <div className="purchase-return-option-group is-reason">
                  <label>Reason:</label>
                  <input
                    value={reason}
                    disabled={readOnly || (!document?.ulid ? !canCreate : !canEdit)}
                    onChange={(event) => setReason(event.target.value)}
                  />
                </div>

                <div className="purchase-return-option-group is-remarks">
                  <label>Remarks:</label>
                  <input
                    value={notes}
                    disabled={readOnly || (!document?.ulid ? !canCreate : !canEdit)}
                    onChange={(event) => setNotes(event.target.value)}
                  />
                </div>
              </div>
            </fieldset>

            <fieldset className="purchase-return-amounts">
              <legend>Amount Options</legend>
              <div>
                <span>Amount:</span>
                <strong>{money(readOnly ? document?.subtotal : liveTotals.subtotal)}</strong>
              </div>
              <div>
                <span>Disc (Rs):</span>
                <strong>{money(readOnly ? document?.discount_amount : liveTotals.discount)}</strong>
              </div>
              <div>
                <span>Tax:</span>
                <strong>{money(readOnly ? document?.tax_amount : liveTotals.tax)}</strong>
              </div>
              <div className="is-total">
                <span>Total:</span>
                <strong>{money(readOnly ? document?.grand_total : liveTotals.total)}</strong>
              </div>
              <div className="is-net">
                <span>Net:</span>
                <strong>{money(readOnly ? document?.grand_total : liveTotals.total)}</strong>
              </div>
            </fieldset>
          </section>

          {invoiceMode ? (
            <div className="purchase-return-grid-wrap">
              <table className="purchase-return-grid">
                <thead>
                  <tr>
                    <th>Item / Product Description</th>
                    <th>Purchased Qty</th>
                    <th>Already Returned</th>
                    <th>Remaining</th>
                    <th>Return Qty</th>
                    <th>Unit</th>
                    <th>Factor</th>
                    <th>Unit Cost</th>
                  </tr>
                </thead>
                <tbody>
                  {returnable.map((row) => (
                    <tr key={row.purchase_line_ulid}>
                      <td>
                        <strong>{row.product.product_number}</strong> — {row.product.name}
                      </td>
                      <td className="num">{row.original_quantity}</td>
                      <td className="num">{alreadyReturnedQty(row)}</td>
                      <td className="num">{remainingPurchaseQty(row)}</td>
                      <td className="num is-edit">
                        {readOnly ? (
                          lineQty[row.purchase_line_ulid] ?? '0'
                        ) : (
                          <input
                            inputMode="decimal"
                            value={lineQty[row.purchase_line_ulid] ?? ''}
                            placeholder="0"
                            aria-label={`Return quantity for ${row.product.name}`}
                            aria-invalid={
                              Number(lineQty[row.purchase_line_ulid] ?? 0) >
                              Number(remainingPurchaseQty(row))
                            }
                            title={`Maximum returnable: ${remainingPurchaseQty(row)}`}
                            onChange={(event) => {
                              const value = event.target.value
                              if (value !== '' && !/^\d+(?:\.\d{0,6})?$/.test(value)) return
                              setLineQty((prev) => ({
                                ...prev,
                                [row.purchase_line_ulid]: value,
                              }))
                            }}
                            onBlur={() => {
                              const value = lineQty[row.purchase_line_ulid]
                              if (!value || Number(value) === 0) return
                              setLineQty((prev) => ({
                                ...prev,
                                [row.purchase_line_ulid]: qty(value),
                              }))
                            }}
                          />
                        )}
                      </td>
                      <td>{row.unit?.code ?? '—'}</td>
                      <td className="num">{row.conversion_factor}</td>
                      <td className="num">{money(row.unit_cost)}</td>
                    </tr>
                  ))}

                  {returnableQuery.isFetching ? (
                    <tr>
                      <td colSpan={8} className="purchase-return-empty">
                        Loading returnable purchase lines…
                      </td>
                    </tr>
                  ) : null}

                  {returnableQuery.isError ? (
                    <tr>
                      <td colSpan={8} className="purchase-return-empty">
                        Could not load returnable purchase lines. Use Refresh and try again.
                      </td>
                    </tr>
                  ) : null}

                  {!returnableQuery.isFetching &&
                  !returnableQuery.isError &&
                  returnable.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="purchase-return-empty">
                        {purchaseUlid
                          ? 'This purchase has no remaining returnable quantity.'
                          : 'Select a posted purchase first.'}
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="purchase-return-general">
              <div className="purchase-return-grid-wrap">
                <table className="purchase-return-grid is-general">
                  <thead>
                    <tr>
                      <th>Item / Product Description</th>
                      <th>Quantity</th>
                      <th>Unit Cost</th>
                      <th>AMT</th>
                      <th>Disc (Rs)</th>
                      <th>Tax</th>
                      <th>Amount (Rs)</th>
                      <th aria-label="Actions" />
                    </tr>
                  </thead>
                  <tbody>
                    {generalRows.map((row) => {
                      const amounts = generalAmounts(row)
                      return (
                        <tr key={row.key} data-return-row={row.key}>
                          <td className="purchase-return-general-product-cell">
                            {readOnly ? (
                              <>
                                <strong>{row.product_number}</strong> — {row.product_name}
                                <span className="purchase-return-unit-hint"> · {row.unit_code}</span>
                              </>
                            ) : generalProductTargetKey === row.key ? (
                              <div
                                ref={generalProductAnchorRef}
                                className="purchase-return-product-combo"
                              >
                                <input
                                  autoFocus
                                  value={generalProductSearch}
                                  placeholder="Search product name / barcode / ID"
                                  aria-label={`Change product for ${row.product_name}`}
                                  autoComplete="off"
                                  onFocus={() => {
                                    setGeneralProductLookupMode('browse')
                                    setGeneralProductPickerOpen(true)
                                  }}
                                  onChange={(event) => {
                                    setGeneralProductSearch(event.target.value)
                                    setGeneralProductLookupMode('search')
                                    setGeneralProductPickerOpen(true)
                                  }}
                                  onKeyDown={(event) => {
                                    if (event.key === 'Escape') {
                                      setGeneralProductTargetKey(null)
                                      setGeneralProductSearch('')
                                      setGeneralProductLookupMode('browse')
                                      setGeneralProductPickerOpen(false)
                                      return
                                    }
                                    if (event.key === 'Enter') {
                                      const first = generalProducts[0]
                                      if (first) {
                                        event.preventDefault()
                                        addGeneralProduct(first)
                                      }
                                    }
                                  }}
                                />
                                <button
                                  type="button"
                                  className="purchase-return-product-toggle"
                                  aria-label="Open product dropdown"
                                  aria-expanded={generalProductPickerOpen}
                                  onMouseDown={(event) => event.preventDefault()}
                                  onClick={() => {
                                    setGeneralProductLookupMode('browse')
                                    setGeneralProductPickerOpen((open) => !open)
                                  }}
                                >
                                  <ChevronDown size={13} />
                                </button>
                                {generalProductPickerOpen ? (
                                  <ProductLookupGrid
                                    rows={generalProducts}
                                    total={localGeneralProducts.total}
                                    priceType="retail"
                                    mode="purchase"
                                    themed
                                    portalAnchorRef={generalProductAnchorRef}
                                    selectedUlid={row.product_ulid}
                                    onSelect={addGeneralProduct}
                                    onRequestClose={() => setGeneralProductPickerOpen(false)}
                                  />
                                ) : null}
                              </div>
                            ) : (
                              <button
                                type="button"
                                className="purchase-return-general-product-edit"
                                onClick={() => {
                                  setGeneralProductTargetKey(row.key)
                                  setGeneralProductSearch(row.product_name)
                                  setGeneralProductLookupMode('browse')
                                  setGeneralProductPickerOpen(true)
                                }}
                              >
                                <strong>{row.product_number}</strong> — {row.product_name}
                                <span className="purchase-return-unit-hint"> · {row.unit_code}</span>
                              </button>
                            )}
                          </td>
                          <td className="num is-edit">
                            {readOnly ? row.quantity : (
                              <input
                                data-general-editable="true"
                                data-general-field="quantity"
                                inputMode="decimal"
                                value={row.quantity}
                                onKeyDown={handleGeneralEnter}
                                onChange={(event) => {
                                  const value = event.target.value
                                  if (value !== '' && !/^\d+(?:\.\d{0,6})?$/.test(value)) return
                                  setGeneralRows((current) => current.map((item) =>
                                    item.key === row.key ? { ...item, quantity: value } : item,
                                  ))
                                }}
                                onBlur={() => setGeneralRows((current) => current.map((item) =>
                                  item.key === row.key ? { ...item, quantity: qty(item.quantity) } : item,
                                ))}
                              />
                            )}
                          </td>
                          <td className="num is-edit">
                            {readOnly ? money(row.unit_cost) : (
                              <input
                                data-general-editable="true"
                                data-general-field="cost"
                                inputMode="decimal"
                                value={row.unit_cost}
                                onKeyDown={handleGeneralEnter}
                                onChange={(event) => {
                                  const value = event.target.value
                                  if (value !== '' && !/^\d+(?:\.\d{0,4})?$/.test(value)) return
                                  setGeneralRows((current) => current.map((item) =>
                                    item.key === row.key ? { ...item, unit_cost: value } : item,
                                  ))
                                }}
                              />
                            )}
                          </td>
                          <td className="num">{money(amounts.subtotal)}</td>
                          <td className="num is-edit">
                            {readOnly ? money(row.discount_amount) : (
                              <input
                                data-general-editable="true"
                                data-general-field="discount"
                                inputMode="decimal"
                                value={row.discount_amount}
                                onKeyDown={handleGeneralEnter}
                                onChange={(event) => {
                                  const value = event.target.value
                                  if (value !== '' && !/^\d+(?:\.\d{0,4})?$/.test(value)) return
                                  setGeneralRows((current) => current.map((item) =>
                                    item.key === row.key ? { ...item, discount_amount: value } : item,
                                  ))
                                }}
                              />
                            )}
                          </td>
                          <td className="num is-edit">
                            {readOnly ? money(row.tax_amount) : (
                              <input
                                data-general-editable="true"
                                data-general-field="tax"
                                inputMode="decimal"
                                value={row.tax_amount}
                                onKeyDown={handleGeneralEnter}
                                onChange={(event) => {
                                  const value = event.target.value
                                  if (value !== '' && !/^\d+(?:\.\d{0,4})?$/.test(value)) return
                                  setGeneralRows((current) => current.map((item) =>
                                    item.key === row.key ? { ...item, tax_amount: value } : item,
                                  ))
                                }}
                              />
                            )}
                          </td>
                          <td className="num">{money(amounts.total)}</td>
                          <td className="purchase-return-row-action">
                            {!readOnly ? (
                              <UiButton
                                variant="danger"
                                title={`Remove ${row.product_name}`}
                                onClick={() => {
                                  setGeneralRows((current) => current.filter((item) => item.key !== row.key))
                                  if (generalProductTargetKey === row.key) {
                                    setGeneralProductTargetKey(null)
                                    setGeneralProductPickerOpen(false)
                                  }
                                }}
                              >
                                <X size={12} />
                              </UiButton>
                            ) : null}
                          </td>
                        </tr>
                      )
                    })}

                    {!readOnly ? (
                      <tr className="purchase-return-general-add-row">
                        <td className="purchase-return-general-product-cell">
                          <div
                            ref={generalProductTargetKey === null ? generalProductAnchorRef : undefined}
                            className="purchase-return-product-combo"
                          >
                            <input
                              ref={generalProductEntryRef}
                              value={generalProductTargetKey === null ? generalProductSearch : ''}
                              placeholder="Search product name / barcode / ID"
                              aria-label="Add general purchase return product"
                              autoComplete="off"
                              onFocus={() => {
                                setGeneralProductTargetKey(null)
                                setGeneralProductLookupMode('browse')
                                setGeneralProductPickerOpen(true)
                              }}
                              onChange={(event) => {
                                setGeneralProductTargetKey(null)
                                setGeneralProductSearch(event.target.value)
                                setGeneralProductLookupMode('search')
                                setGeneralProductPickerOpen(true)
                              }}
                              onKeyDown={(event) => {
                                if (event.key === 'Escape') {
                                  setGeneralProductPickerOpen(false)
                                  return
                                }
                                if (event.key === 'Enter') {
                                  const first = generalProducts[0]
                                  if (first) {
                                    event.preventDefault()
                                    addGeneralProduct(first)
                                  }
                                }
                              }}
                            />
                            <button
                              type="button"
                              className="purchase-return-product-toggle"
                              aria-label="Open product dropdown"
                              aria-expanded={generalProductTargetKey === null && generalProductPickerOpen}
                              onMouseDown={(event) => event.preventDefault()}
                              onClick={() => {
                                setGeneralProductTargetKey(null)
                                setGeneralProductLookupMode('browse')
                                setGeneralProductPickerOpen((open) => !open)
                              }}
                            >
                              <ChevronDown size={13} />
                            </button>
                            {generalProductTargetKey === null && generalProductPickerOpen ? (
                              <ProductLookupGrid
                                rows={generalProducts}
                                total={localGeneralProducts.total}
                                priceType="retail"
                                mode="purchase"
                                themed
                                portalAnchorRef={generalProductAnchorRef}
                                onSelect={addGeneralProduct}
                                onRequestClose={() => setGeneralProductPickerOpen(false)}
                              />
                            ) : null}
                          </div>
                        </td>
                        <td colSpan={7} className="purchase-return-general-entry-hint">
                          Add another product directly from this row, or select Purchase # above for invoice-linked return.
                        </td>
                      </tr>
                    ) : null}

                    {readOnly && generalRows.length === 0 ? (
                      <tr>
                        <td colSpan={8} className="purchase-return-empty">
                          This general purchase return has no lines.
                        </td>
                      </tr>
                    ) : null}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {error ? <div className="purchase-return-error">{error}</div> : null}

          <footer className="purchase-return-footer">
            <span>
              {selectedLineCount} return line(s) · {invoiceMode ? 'Invoice return' : 'General return'}
            </span>
            <div className="purchase-return-actions">
              <DesktopButton
                icon={<Save size={13} />}
                label="Save Draft"
                shortcut="F9"
                disabled={
                  readOnly ||
                  saveMutation.isPending ||
                  (document?.ulid ? !canEdit : !canCreate)
                }
                onClick={() => void saveMutation.mutateAsync().catch(handleError)}
              />
              <DesktopButton
                icon={<CheckCircle2 size={13} />}
                label="Post"
                disabled={readOnly || !canPost || postMutation.isPending}
                onClick={() => {
                  void (async () => {
                    if (!(await askConfirm('Post this purchase return and reduce stock?'))) return
                    void postMutation.mutateAsync().catch(handleError)
                  })()
                }}
              />
              <DesktopButton
                icon={<RefreshCw size={13} />}
                label="Refresh"
                shortcut="F8"
                onClick={() => {
                  if (document?.ulid) {
                    void openMutation.mutateAsync(document.ulid).catch(handleError)
                  } else if (purchaseUlid) {
                    void returnableQuery.refetch()
                  } else {
                    void localGeneralProducts.refreshFromServer()
                  }
                }}
              />
              {!readOnly && document?.ulid ? (
                <DesktopButton
                  icon={<Trash2 size={13} />}
                  label="Clear qty"
                  onClick={() => {
                    setLineQty({})
                    setGeneralRows([])
                  }}
                />
              ) : null}
              <DesktopButton
                icon={<X size={13} />}
                label="Close"
                shortcut="Esc"
                onClick={closeActiveTab}
              />
            </div>
          </footer>
        </div>
      ) : null}

      {view === 'search' ? (
        <div className="purchase-return-search-view">
          <div className="purchase-return-search-filters">
            <input
              className="desktop-input"
              placeholder="Search return / purchase / supplier"
              value={q}
              onChange={(event) => setQ(event.target.value)}
            />
            <UiSelect
              aria-label="Return status"
              value={statusFilter}
              options={[
                { value: '', label: 'All statuses' },
                { value: 'draft', label: 'Draft' },
                { value: 'posted', label: 'Posted' },
              ]}
              onChange={setStatusFilter}
            />
            <UiSelect
              aria-label="Supplier filter"
              value={supplierFilter}
              options={[
                { value: '', label: 'All suppliers' },
                ...suppliers.map((supplier) => ({
                  value: supplier.ulid,
                  label: `${supplier.code} · ${supplier.name}`,
                })),
              ]}
              onChange={setSupplierFilter}
            />
            <input
              className="desktop-input"
              type="date"
              value={dateFrom}
              onChange={(event) => setDateFrom(event.target.value)}
            />
            <input
              className="desktop-input"
              type="date"
              value={dateTo}
              onChange={(event) => setDateTo(event.target.value)}
            />
            <DesktopButton
              icon={<RefreshCw size={13} />}
              label="Refresh"
              shortcut="F8"
              onClick={() => void listQuery.refetch()}
            />
          </div>

          {error ? <div className="purchase-return-error">{error}</div> : null}

          <div className="purchase-return-search-grid">
            <PosDataGrid
              columns={[
                {
                  key: 'document_number',
                  header: 'Return #',
                  width: 110,
                  render: (row) => row.document_number,
                },
                {
                  key: 'return_date',
                  header: 'Date',
                  width: 100,
                  render: (row) => row.return_date,
                },
                {
                  key: 'original_purchase',
                  header: 'Purchase #',
                  width: 130,
                  render: (row) => row.original_purchase?.document_number ?? 'GENERAL',
                },
                {
                  key: 'supplier',
                  header: 'Supplier',
                  render: (row) => row.supplier?.name ?? '—',
                },
                {
                  key: 'warehouse',
                  header: 'Warehouse',
                  width: 140,
                  render: (row) => row.warehouse?.name ?? '—',
                },
                {
                  key: 'grand_total',
                  header: 'Total',
                  align: 'right',
                  width: 110,
                  render: (row) => money(row.grand_total),
                },
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
              onActivate={(row) =>
                void openMutation.mutateAsync(row.ulid).catch(handleError)
              }
              emptyMessage={listQuery.isFetching ? 'Loading purchase returns…' : 'No purchase returns yet.'}
            />
          </div>

          <footer className="purchase-return-footer">
            <span>{rows.length} return invoice(s)</span>
            <div className="purchase-return-actions">
              <DesktopButton
                icon={<RotateCcw size={13} />}
                label="New Return"
                disabled={!canCreate}
                onClick={() => {
                  resetEditor()
                  setView('entry')
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
                icon={<X size={13} />}
                label="Close"
                shortcut="Esc"
                onClick={closeActiveTab}
              />
            </div>
          </footer>
        </div>
      ) : null}

      {view === 'product' ? (
        <div className="purchase-return-product-view">
          <div className="purchase-return-product-filters">
            <label>From Date</label>
            <input
              className="desktop-input"
              type="date"
              value={productDateFrom}
              onChange={(event) => setProductDateFrom(event.target.value)}
            />
            <label>To Date</label>
            <input
              className="desktop-input"
              type="date"
              value={productDateTo}
              onChange={(event) => setProductDateTo(event.target.value)}
            />
            <UiButton variant="primary" onClick={() => void productWiseQuery.refetch()}>
              Show
            </UiButton>
          </div>

          {productWiseQuery.isError ? (
            <div className="purchase-return-error">
              Could not load Product Wise Purchase Return data. Use Show or Refresh to try again.
            </div>
          ) : null}

          <div className="purchase-return-product-grid">
            <PosDataGrid
              columns={[
                {
                  key: 'return_number',
                  header: 'Return #',
                  width: 110,
                  render: (row) => row.return_number,
                },
                {
                  key: 'return_date',
                  header: 'Date',
                  width: 100,
                  render: (row) => row.return_date,
                },
                {
                  key: 'supplier',
                  header: 'Supplier',
                  render: (row) => row.supplier?.name ?? '—',
                },
                {
                  key: 'purchase',
                  header: 'Purchase #',
                  width: 125,
                  render: (row) => row.purchase?.document_number ?? 'GENERAL',
                },
                {
                  key: 'product',
                  header: 'Product',
                  render: (row) => `${row.product.product_number} — ${row.product.name}`,
                },
                {
                  key: 'category',
                  header: 'Category',
                  width: 130,
                  render: (row) => row.category?.name ?? '—',
                },
                {
                  key: 'unit',
                  header: 'Unit',
                  width: 80,
                  render: (row) => row.unit?.code ?? '—',
                },
                {
                  key: 'quantity_out',
                  header: 'Qty (-)',
                  width: 100,
                  align: 'right',
                  render: (row) => row.quantity_out,
                },
                {
                  key: 'amount',
                  header: 'Amount',
                  width: 115,
                  align: 'right',
                  render: (row) => money(row.amount),
                },
              ]}
              rows={productWiseQuery.data?.data ?? []}
              rowKey={(row) => row.ulid}
              onActivate={(row) =>
                void openMutation.mutateAsync(row.return_ulid).catch(handleError)
              }
              emptyMessage={
                productWiseQuery.isFetching
                  ? 'Loading posted Purchase Return product activity…'
                  : 'No posted Purchase Return product activity in this period.'
              }
            />
          </div>
        </div>
      ) : null}
    </section>
  )
}
