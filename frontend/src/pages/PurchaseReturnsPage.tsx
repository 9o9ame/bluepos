import { useEffect, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react'
import { CheckCircle2, FileText, RefreshCw, RotateCcw, Save, Search, Trash2, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { fetchSuppliers } from '../api/catalog'
import { fetchWarehouses } from '../api/inventory'
import { fetchPurchase } from '../api/purchases'
import {
  amendPurchaseReturn,
  createPurchaseReturn,
  createPurchaseReturnLine,
  deletePurchaseReturnLine,
  fetchNextPurchaseReturnDocumentNumber,
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
import { PosEntryGrid } from '../components/desktop/PosEntryGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSimpleDropdown, UiTableDropdown } from '../components/ui/UiSelect'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useLocalProducts } from '../features/catalog/useLocalCatalog'
import { ProductLookupGrid } from '../features/sales/ProductLookupGrid'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Product } from '../types/catalog'
import type { PurchaseInvoice } from '../types/purchases'
import type { PurchaseReturn } from '../types/purchaseReturns'
import { formatDecimal } from '../utils/formatDecimal'
import './PurchaseReturnsPage.css'

type ViewMode = 'entry' | 'search' | 'product'

type GeneralReturnRow = {
  key: string
  line_ulid?: string
  purchase_line_ulid?: string
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
  return formatDecimal(value)
}

function money(value: string | number | null | undefined): string {
  return formatDecimal(value)
}

function newReturnKey(): string {
  return `purchase-return-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
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
  const amendmentKeyRef = useRef(newReturnKey())
  const generalProductAnchorRef = useRef<HTMLDivElement | null>(null)
  const generalProductEntryRef = useRef<HTMLInputElement | null>(null)

  const canView = useCan('purchase_returns.view')
  const canCreate = useCan('purchase_returns.create')
  const canEdit = useCan('purchase_returns.edit')
  const canAmend = useCan('purchase_returns.amend_posted')
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
  const [returnNumberValue, setReturnNumberValue] = useState<string | null>(null)
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

  const [generalRows, setGeneralRows] = useState<GeneralReturnRow[]>([])
  const [generalProductSearch, setGeneralProductSearch] = useState('')
  const [generalProductPickerOpen, setGeneralProductPickerOpen] = useState(false)
  const [generalProductLookupMode, setGeneralProductLookupMode] = useState<'browse' | 'search'>('browse')
  const [generalProductTargetKey, setGeneralProductTargetKey] = useState<string | null>(null)

  const amendingPosted = document?.status === 'posted' && canAmend
  const readOnly = document?.status === 'posted' && !canAmend
  const canModifyCurrent =
    document?.status === 'posted'
      ? canAmend
      : (document?.ulid ? canEdit : canCreate)
  const invoiceMode = amendingPosted
    ? Boolean(purchaseUlid)
    : Boolean(purchaseUlid || document?.return_mode === 'invoice')

  const nextReturnNumberQuery = useQuery({
    queryKey: ['purchase-return', 'next-document-number', session?.branch.ulid, session?.warehouse.ulid],
    queryFn: fetchNextPurchaseReturnDocumentNumber,
    enabled:
      Boolean(session) &&
      active &&
      view === 'entry' &&
      (canCreate || canAmend),
    retry: false,
  })

  const displayedReturnNumber =
    returnNumberValue ??
    document?.document_number ??
    nextReturnNumberQuery.data?.document_number ??
    'Auto'

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
    enabled: view === 'entry' && (!document?.ulid || amendingPosted),
    retry: false,
  })

  const returnableQuery = useQuery({
    queryKey: ['returnable-lines', purchaseUlid, amendingPosted ? document?.ulid : null],
    queryFn: () => fetchReturnableLines(
      purchaseUlid,
      amendingPosted ? document?.ulid : null,
    ),
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
    view === 'entry' && !readOnly && generalProductPickerOpen,
  )

  const rows = listQuery.data?.data ?? []
  const suppliers = useMemo(
    () => (suppliersQuery.data ?? []).filter((row) => row.is_active),
    [suppliersQuery.data],
  )
  const warehouses = warehousesQuery.data ?? []
  const returnable = returnableQuery.data?.data ?? []

  const generalProducts = localGeneralProducts.rows.filter(
    (product) => product.is_active && product.status === 'active',
  )

  const purchaseOptions = useMemo(() => {
    const rows = purchaseLookup.data?.data ?? []
    const options = rows.map((purchase) => ({
      value: purchase.ulid,
      label: `${purchase.document_number}${purchase.supplier_invoice_number ? ` · ${purchase.supplier_invoice_number}` : ''}`,
      columns: [
        purchase.document_number,
        purchase.invoice_date,
        purchase.supplier?.name ?? '',
        purchase.supplier_invoice_number ?? '',
        money(purchase.grand_total),
      ],
    }))

    if (purchaseUlid && purchaseLabel && !options.some((option) => option.value === purchaseUlid)) {
      options.unshift({
        value: purchaseUlid,
        label: purchaseLabel,
        columns: [purchaseSearch || purchaseLabel, '', '', '', ''],
      })
    }

    return [
      {
        value: '',
        label: 'Optional: select posted purchase #',
        columns: ['', '', '', '', ''],
      },
      ...options,
    ]
  }, [purchaseLabel, purchaseLookup.data?.data, purchaseSearch, purchaseUlid])

  const supplierOptions = useMemo(
    () => [
      { value: '', label: 'Select supplier', columns: ['', 'Select supplier', '', ''] },
      ...suppliers.map((supplier) => ({
        value: supplier.ulid,
        label: supplier.name,
        columns: [
          supplier.code,
          supplier.name,
          supplier.address ?? '',
          supplier.mobile ?? supplier.phone ?? '',
        ],
      })),
    ],
    [suppliers],
  )

  const warehouseOptions = useMemo(
    () => [
      { value: '', label: 'Select warehouse' },
      ...warehouses.map((warehouse) => ({
        value: warehouse.ulid,
        label: `${warehouse.code} · ${warehouse.name}`,
      })),
    ],
    [warehouses],
  )

  const liveTotals = useMemo(
    () =>
      generalRows.reduce(
        (acc, row) => {
          const amounts = generalAmounts(row)
          acc.subtotal += amounts.subtotal
          acc.discount += amounts.discount
          acc.tax += amounts.tax
          acc.total += amounts.total
          return acc
        },
        { subtotal: 0, discount: 0, tax: 0, total: 0 },
      ),
    [generalRows],
  )

  const selectedLineCount = generalRows.length

  function handleError(err: unknown) {
    setError(
      err instanceof ApiClientError || err instanceof Error
        ? err.message
        : 'Purchase return failed.',
    )
  }

  function resetEditor() {
    setDocument(null)
    setReturnNumberValue(null)
    amendmentKeyRef.current = newReturnKey()
    setPurchaseUlid('')
    setPurchaseLabel('')
    setPurchaseSearch('')
    setSupplierUlid('')
    setWarehouseUlid(session?.warehouse.ulid ?? '')
    setReturnDate(today())
    setSupplierReference('')
    setReason('')
    setNotes('')
    setGeneralRows([])
    setGeneralProductSearch('')
    setGeneralProductPickerOpen(false)
    setGeneralProductLookupMode('browse')
    setGeneralProductTargetKey(null)
    setError(null)
    void nextReturnNumberQuery.refetch()
  }

  function loadDocument(invoice: PurchaseReturn) {
    setDocument(invoice)
    setReturnNumberValue(invoice.document_number)
    amendmentKeyRef.current = newReturnKey()
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

    const nextRows: GeneralReturnRow[] = []

    for (const line of invoice.lines ?? []) {
      if (!line.product) continue

      nextRows.push({
        key: line.ulid,
        line_ulid: line.ulid,
        purchase_line_ulid: line.original_purchase_line_ulid ?? undefined,
        product_ulid: line.product.ulid,
        product_number: line.product.product_number,
        product_name: line.product.name,
        unit_code: line.unit?.code ?? '—',
        quantity: formatDecimal(line.quantity),
        unit_cost: formatDecimal(line.unit_cost),
        discount_amount: formatDecimal(line.discount_amount),
        tax_amount: formatDecimal(line.tax_amount),
      })
    }

    setGeneralRows(nextRows)
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
    setGeneralRows([])
    setGeneralProductSearch('')
    setGeneralProductPickerOpen(false)
    setGeneralProductLookupMode('browse')
    setGeneralProductTargetKey(null)
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

  function detachPurchaseSource(nextSupplierUlid?: string) {
    setPurchaseUlid('')
    setPurchaseLabel('')
    setPurchaseSearch('')
    if (nextSupplierUlid !== undefined) {
      setSupplierUlid(nextSupplierUlid)
    }
    setGeneralRows((current) =>
      current.map(({ purchase_line_ulid: _purchaseLineUlid, ...row }) => row),
    )
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
                quantity: formatDecimal((Number.parseFloat(row.quantity) || 0) + 1),
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

    const sourceLine = invoiceMode
      ? returnable.find((row) => row.product.ulid === product.ulid)
      : undefined

    if (invoiceMode && !sourceLine) {
      detachPurchaseSource()
    }

    const rowKey = generalProductTargetKey ?? generalRowKey()
    const nextRow: GeneralReturnRow = {
      key: rowKey,
      purchase_line_ulid: sourceLine?.purchase_line_ulid,
      product_ulid: product.ulid,
      product_number: product.product_number,
      product_name: product.name,
      unit_code: sourceLine?.unit?.code ?? product.base_unit?.code ?? '—',
      quantity: '1.0',
      unit_cost: formatDecimal(sourceLine?.unit_cost ?? defaultProductCost(product)),
      discount_amount: '0.0',
      tax_amount: '0.0',
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

  const returnLookupMutation = useMutation({
    mutationFn: async (requestedNumber: string) => {
      const lookup = await fetchPurchaseReturns({
        q: requestedNumber,
        per_page: 20,
      })

      const exact = lookup.data.find(
        (row) =>
          row.document_number.trim().toLocaleLowerCase() ===
          requestedNumber.trim().toLocaleLowerCase(),
      )

      if (!exact) {
        throw new Error('No Purchase Return with that exact number was found in this branch and warehouse.')
      }

      return fetchPurchaseReturn(exact.ulid)
    },
    onSuccess: (row) => {
      loadDocument(row)
      setView('entry')
    },
    onError: handleError,
  })

  function hasCurrentReturnEntryData(): boolean {
    return Boolean(
      document?.ulid ||
      purchaseUlid ||
      generalRows.length > 0 ||
      supplierReference.trim() ||
      reason.trim() ||
      notes.trim(),
    )
  }

  async function requestReturnLookup(requestedOverride?: string) {
    const requestedNumber = (requestedOverride ?? returnNumberValue ?? '').trim()
    if (!requestedNumber || returnLookupMutation.isPending) return

    const currentNumber = document?.document_number.trim().toLocaleLowerCase() ?? null
    const requestedKey = requestedNumber.toLocaleLowerCase()

    if (hasCurrentReturnEntryData() && currentNumber !== requestedKey) {
      const confirmed = await askConfirm(
        `The current Purchase Return contains data. If you fetch ${requestedNumber}, the current data will be lost.`,
      )
      if (!confirmed) {
        setReturnNumberValue(document?.document_number ?? null)
        return
      }
    }

    returnLookupMutation.mutate(requestedNumber)
  }

  async function requestOpenReturn(row: PurchaseReturn) {
    setReturnNumberValue(row.document_number)

    const currentNumber = document?.document_number.trim().toLocaleLowerCase() ?? null
    const requestedKey = row.document_number.trim().toLocaleLowerCase()

    if (hasCurrentReturnEntryData() && currentNumber !== requestedKey) {
      const confirmed = await askConfirm(
        `The current Purchase Return contains data. If you fetch ${row.document_number}, the current data will be lost.`,
      )
      if (!confirmed) {
        setReturnNumberValue(document?.document_number ?? null)
        return
      }
    }

    openMutation.mutate(row.ulid)
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (!warehouseUlid) throw new Error('Select a warehouse.')
      if (!purchaseUlid && !supplierUlid) {
        throw new Error('Select a supplier for a general purchase return, or select a posted Purchase #.')
      }

      let current = document

      if (current?.status === 'posted') {
        if (!canAmend) {
          throw new Error('You do not have permission to amend posted Purchase Returns.')
        }

        const amendmentLines = generalRows.map((row) => {
          const previous = current?.lines?.find(
            (line) => line.product?.ulid === row.product_ulid,
          )

          return purchaseUlid
            ? {
                purchase_line_ulid: row.purchase_line_ulid,
                quantity: qty(row.quantity),
                discount_amount: Number(row.discount_amount || 0).toFixed(4),
                tax_amount: Number(row.tax_amount || 0).toFixed(4),
                batch_number: previous?.batch_number ?? null,
                expiry_date: previous?.expiry_date ?? null,
                reason: reason || null,
              }
            : {
                product_ulid: row.product_ulid,
                quantity: qty(row.quantity),
                unit_cost: Number(row.unit_cost || 0).toFixed(4),
                discount_amount: Number(row.discount_amount || 0).toFixed(4),
                tax_amount: Number(row.tax_amount || 0).toFixed(4),
                batch_number: previous?.batch_number ?? null,
                expiry_date: previous?.expiry_date ?? null,
                reason: reason || null,
              }
        })

        return amendPurchaseReturn(
          current.ulid,
          {
            revision_reason: 'Posted Purchase Return corrected from Purchase Return screen',
            purchase_ulid: purchaseUlid || null,
            supplier_ulid: purchaseUlid ? null : (supplierUlid || null),
            warehouse_ulid: warehouseUlid,
            return_date: returnDate,
            supplier_reference: supplierReference || null,
            reason: reason || null,
            notes: notes || null,
            lines: amendmentLines,
          },
          amendmentKeyRef.current,
        )
      }

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
        const activePurchaseLineUlids = new Set(
          generalRows.map((row) => row.purchase_line_ulid).filter(Boolean),
        )

        for (const row of generalRows) {
          if (!row.purchase_line_ulid) {
            throw new Error(`Select a product from the selected Purchase # for ${row.product_name}.`)
          }

          const payload = {
            purchase_line_ulid: row.purchase_line_ulid,
            quantity: qty(row.quantity),
            discount_amount: money4(row.discount_amount),
            tax_amount: money4(row.tax_amount),
          }
          const existing = existingByPurchaseLine.get(row.purchase_line_ulid)
          if (existing) {
            await updatePurchaseReturnLine(current.ulid, existing.ulid, payload)
          } else {
            await createPurchaseReturnLine(current.ulid, payload)
          }
        }

        for (const [purchaseLineUlid, existing] of existingByPurchaseLine) {
          if (!activePurchaseLineUlids.has(purchaseLineUlid)) {
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
        const canSaveCurrent =
          document?.status === 'posted'
            ? canAmend
            : (document?.ulid ? canEdit : canCreate)

        if (view === 'entry' && !readOnly && canSaveCurrent) {
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
                  <input
                    value={displayedReturnNumber}
                    readOnly={!canCreate && !canAmend}
                    disabled={returnLookupMutation.isPending || saveMutation.isPending}
                    title="Type an exact Purchase Return number and press Enter to load it"
                    onChange={(event) => setReturnNumberValue(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key !== 'Enter') return
                      event.preventDefault()
                      void requestReturnLookup()
                    }}
                  />
                </div>

                <div className="purchase-return-option-group is-date">
                  <label>Date:</label>
                  <input
                    type="date"
                    value={returnDate}
                    disabled={!canModifyCurrent}
                    onChange={(event) => setReturnDate(event.target.value)}
                  />
                </div>

                <div className="purchase-return-option-group is-purchase">
                  <label>Purchase #:</label>
                  <UiTableDropdown
                    aria-label="Purchase number"
                    value={purchaseUlid}
                    disabled={readOnly || Boolean(document?.ulid && !amendingPosted)}
                    className="purchase-return-canonical-select purchase-return-purchase-select"
                    triggerClassName="purchase-return-canonical-trigger"
                    placeholder="Optional: select posted purchase #"
                    searchPlaceholder="Search posted purchase"
                    options={purchaseOptions}
                    menuColumns={[
                      { header: 'Purchase #', width: '120px' },
                      { header: 'Date', width: '100px' },
                      { header: 'Supplier', width: '210px' },
                      { header: 'Supplier Ref', width: '130px' },
                      { header: 'Total', width: '100px' },
                    ]}
                    menuMinWidth={680}
                    onSearchChange={setPurchaseSearch}
                    onChange={(value) => {
                      if (!value) {
                        setPurchaseUlid('')
                        setPurchaseLabel('')
                        setPurchaseSearch('')
                        setSupplierUlid('')
                                            setGeneralRows([])
                        return
                      }

                      const purchase = purchaseLookup.data?.data.find((row) => row.ulid === value)
                      if (purchase) selectPurchase(purchase)
                    }}
                  />
                </div>

                <div className="purchase-return-option-group is-supplier">
                  <label>Supplier:</label>
                  <UiTableDropdown
                    aria-label="Supplier"
                    value={supplierUlid}
                    disabled={!canModifyCurrent}
                    className="purchase-return-canonical-select"
                    triggerClassName="purchase-return-canonical-trigger"
                    placeholder="Select supplier"
                    searchPlaceholder="Search supplier"
                    options={supplierOptions}
                    menuColumns={[
                      { header: 'Code', width: '100px' },
                      { header: 'Name', width: '220px' },
                      { header: 'Address', width: '240px' },
                      { header: 'Mobile', width: '130px' },
                    ]}
                    menuMinWidth={720}
                    onChange={(value) => {
                      if (purchaseUlid && value !== supplierUlid) {
                        detachPurchaseSource(value)
                        return
                      }
                      setSupplierUlid(value)
                    }}
                  />
                </div>

                <div className="purchase-return-option-group is-warehouse">
                  <label>Warehouse:</label>
                  <UiSimpleDropdown
                    aria-label="Warehouse"
                    value={warehouseUlid}
                    disabled={!canModifyCurrent}
                    className="purchase-return-canonical-select"
                    triggerClassName="purchase-return-canonical-trigger"
                    searchPlaceholder="Search warehouse"
                    options={warehouseOptions}
                    onChange={setWarehouseUlid}
                  />
                </div>

                <div className="purchase-return-option-group is-supplier-ref">
                  <label>Supp. Ref:</label>
                  <input
                    value={supplierReference}
                    disabled={!canModifyCurrent}
                    onChange={(event) => setSupplierReference(event.target.value)}
                  />
                </div>

                <div className="purchase-return-option-group is-reason">
                  <label>Reason:</label>
                  <input
                    value={reason}
                    disabled={!canModifyCurrent}
                    onChange={(event) => setReason(event.target.value)}
                  />
                </div>

                <div className="purchase-return-option-group is-remarks">
                  <label>Remarks:</label>
                  <input
                    value={notes}
                    disabled={!canModifyCurrent}
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

          
            <div className="purchase-return-general">
              <div className="purchase-return-grid-wrap">
                <PosEntryGrid
                  className="purchase-return-custom-table"
                  tableClassName="purchase-return-grid is-general"
                  ariaLabel="General purchase return lines"
                  columns={[
                    { key: 'product', header: 'Item / Product Description', width: '42%' },
                    { key: 'quantity', header: 'Quantity', width: '10%', align: 'right' },
                    { key: 'cost', header: 'Unit Cost', width: '10%', align: 'right' },
                    { key: 'amt', header: 'AMT', width: '10%', align: 'right' },
                    { key: 'discount', header: 'Disc (Rs)', width: '10%', align: 'right' },
                    { key: 'tax', header: 'Tax', width: '8%', align: 'right' },
                    { key: 'amount', header: 'Amount (Rs)', width: '10%', align: 'right' },
                    { key: 'actions', header: '', width: '36px' },
                  ]}
                >
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
                                  onMouseDown={(event) => {
                                    event.preventDefault()
                                    event.stopPropagation()
                                  }}
                                  onClick={() => {
                                    setGeneralProductLookupMode('browse')
                                    setGeneralProductPickerOpen((open) => !open)
                                  }}
                                >
                                  <span aria-hidden>
                                  <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                                    <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                                  </svg>
                                </span>
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
                                  item.key === row.key ? { ...item, quantity: formatDecimal(item.quantity) } : item,
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
                                  if (purchaseUlid) detachPurchaseSource()
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
                      <tr className="purchase-return-general-add-row is-entry-row">
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
                              onMouseDown={(event) => {
                                    event.preventDefault()
                                    event.stopPropagation()
                                  }}
                              onClick={() => {
                                setGeneralProductTargetKey(null)
                                setGeneralProductLookupMode('browse')
                                setGeneralProductPickerOpen((open) => !open)
                              }}
                            >
                              <span aria-hidden>
                                  <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                                    <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                                  </svg>
                                </span>
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
                        <td className="num" />
                        <td className="num" />
                        <td className="num" />
                        <td className="num" />
                        <td className="num" />
                        <td className="num" />
                        <td />
                      </tr>
                    ) : null}

                    {readOnly && generalRows.length === 0 ? (
                      <tr>
                        <td colSpan={8} className="purchase-return-empty">
                          This general purchase return has no lines.
                        </td>
                      </tr>
                    ) : null}
                </PosEntryGrid>
              </div>
            </div>
          {error ? <div className="purchase-return-error">{error}</div> : null}

          <footer className="purchase-return-footer">
            <span>
              {selectedLineCount} return line(s) · {invoiceMode ? 'Invoice return' : 'General return'}
            </span>
            <div className="purchase-return-actions">
              <DesktopButton
                icon={<Save size={13} />}
                label={document?.status === 'posted' ? 'Amend Return' : 'Save Draft'}
                shortcut="F9"
                disabled={
                  readOnly ||
                  saveMutation.isPending ||
                  !canModifyCurrent
                }
                onClick={() => void saveMutation.mutateAsync().catch(handleError)}
              />
              <DesktopButton
                icon={<CheckCircle2 size={13} />}
                label="Post"
                disabled={
                  document?.status === 'posted' ||
                  readOnly ||
                  !canPost ||
                  postMutation.isPending
                }
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
            <UiSimpleDropdown
              aria-label="Return status"
              value={statusFilter}
              className="purchase-return-search-status-select"
              placeholder="All statuses"
              searchPlaceholder="Search status"
              options={[
                { value: '', label: 'All statuses' },
                { value: 'draft', label: 'Draft' },
                { value: 'posted', label: 'Posted' },
              ]}
              onChange={setStatusFilter}
            />
            <UiTableDropdown
              aria-label="Supplier filter"
              value={supplierFilter}
              className="purchase-return-search-supplier-select"
              placeholder="All suppliers"
              searchPlaceholder="Search supplier"
              options={[
                { value: '', label: 'All suppliers', columns: ['', 'All suppliers', '', ''] },
                ...suppliers.map((supplier) => ({
                  value: supplier.ulid,
                  label: `${supplier.code} · ${supplier.name}`,
                  columns: [
                    supplier.code,
                    supplier.name,
                    supplier.address ?? '',
                    supplier.mobile ?? supplier.phone ?? '',
                  ],
                })),
              ]}
              menuColumns={[
                { header: 'Code', width: '100px' },
                { header: 'Name', width: '220px' },
                { header: 'Address', width: '240px' },
                { header: 'Mobile', width: '130px' },
              ]}
              menuMinWidth={720}
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
                  render: (row) => formatDecimal(row.grand_total),
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
              onActivate={(row) => void requestOpenReturn(row)}
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
                  const row = rows.find((item) => item.ulid === selectedListKey)
                  if (row) void requestOpenReturn(row)
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
                  render: (row) => formatDecimal(row.quantity_out),
                },
                {
                  key: 'amount',
                  header: 'Amount',
                  width: 115,
                  align: 'right',
                  render: (row) => formatDecimal(row.amount),
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
