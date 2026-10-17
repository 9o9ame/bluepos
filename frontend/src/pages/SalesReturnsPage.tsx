import { useEffect, useMemo, useRef, useState } from 'react'
import { Banknote, CheckCircle2, CreditCard, FileText, Landmark, RefreshCw, RotateCcw, Save, Search, Wallet, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiClientError } from '../api/client'
import { fetchSales, fetchSalesmen } from '../api/sales'
import { fetchProducts } from '../api/catalog'
import {
  createSaleReturn,
  createSaleReturnLine,
  createSaleReturnRefund,
  deleteSaleReturnLine,
  fetchReturnableSaleLines,
  fetchSaleReturn,
  fetchSaleReturnSourceParties,
  fetchSaleReturnProductWise,
  fetchSaleReturns,
  postSaleReturn,
  updateSaleReturn,
  updateSaleReturnLine,
} from '../api/salesReturns'
import { DesktopButton } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { askConfirm, useFeedback } from '../feedback/FeedbackProvider'
import { useCan } from '../features/auth/useCan'
import { useAuth } from '../features/auth/AuthProvider'
import { useLocalProducts } from '../features/catalog/useLocalCatalog'
import { ProductLookupGrid } from '../features/sales/ProductLookupGrid'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Sale, SalePaymentMethod } from '../types/sales'
import type { Product } from '../types/catalog'
import type { SaleReturn } from '../types/salesReturns'
import './SalesReturnsPage.css'

type ViewMode = 'entry' | 'search' | 'product'

type GeneralReturnRow = {
  key: string
  line_ulid?: string
  product_ulid: string
  product_number: string
  product_name: string
  unit_code: string
  quantity: string
  unit_price: string
  discount_percent: string
  tax_percent: string
}

const REFUND_METHODS: Array<{
  value: SalePaymentMethod
  label: string
  icon: typeof Banknote
}> = [
  { value: 'cash', label: 'Cash', icon: Banknote },
  { value: 'card', label: 'Card', icon: CreditCard },
  { value: 'bank', label: 'Bank', icon: Landmark },
  { value: 'credit', label: 'On account', icon: Wallet },
]

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function qty(value: string): string {
  const n = Number(value)
  return Number.isFinite(n) ? n.toFixed(6) : '0.000000'
}

function money(value: string | number | null | undefined): string {
  const n = typeof value === 'number' ? value : Number.parseFloat(value ?? '0')
  return Number.isFinite(n) ? n.toFixed(2) : '0.00'
}

function newReturnKey(): string {
  return `sale-return-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

function newRefundKey(): string {
  return `sale-return-refund-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

function generalRowKey(): string {
  return `general-return-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

function generalLineAmounts(row: GeneralReturnRow) {
  const quantity = Math.max(Number(row.quantity) || 0, 0)
  const price = Math.max(Number(row.unit_price) || 0, 0)
  const discountPercent = Math.min(Math.max(Number(row.discount_percent) || 0, 0), 100)
  const taxPercent = Math.max(Number(row.tax_percent) || 0, 0)
  const gross = quantity * price
  const discount = gross * discountPercent / 100
  const taxable = Math.max(gross - discount, 0)
  const tax = taxable * taxPercent / 100
  return { gross, discount, tax, total: taxable + tax }
}

function retailPrice(product: Product): string {
  return product.prices?.find((row) => row.is_active && row.price_type === 'retail')?.amount ?? '0.0000'
}

export function SalesReturnsPage() {
  const queryClient = useQueryClient()
  const feedback = useFeedback()
  const { session } = useAuth()
  const { closeActiveTab } = useWorkspace()
  const canReturn = useCan('sales.return')
  const canRefund = useCan('payments.create')

  const [view, setView] = useState<ViewMode>('entry')
  const [document, setDocument] = useState<SaleReturn | null>(null)
  const [saleUlid, setSaleUlid] = useState('')
  const [saleLabel, setSaleLabel] = useState('')
  const [saleSearch, setSaleSearch] = useState('')
  const [fromPartyUlid, setFromPartyUlid] = useState('')
  const [fromPartyType, setFromPartyType] = useState<'customer' | 'account'>('account')
  const [salesmanFilterUlid, setSalesmanFilterUlid] = useState('')
  const [returnDate, setReturnDate] = useState(today())
  const [reason, setReason] = useState('')
  const [notes, setNotes] = useState('')
  const [lineQty, setLineQty] = useState<Record<string, string>>({})
  const [generalRows, setGeneralRows] = useState<GeneralReturnRow[]>([])
  const [generalProductSearch, setGeneralProductSearch] = useState('')
  const [generalProductPickerOpen, setGeneralProductPickerOpen] = useState(false)
  const [generalProductLookupMode, setGeneralProductLookupMode] = useState<'browse' | 'search'>('browse')
  const [generalProductTargetKey, setGeneralProductTargetKey] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [refundAmount, setRefundAmount] = useState('')
  const [refundMethod, setRefundMethod] = useState<SalePaymentMethod>('cash')
  const [refundReference, setRefundReference] = useState('')

  const [historySearch, setHistorySearch] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedHistory, setSelectedHistory] = useState<string | null>(null)

  const [productDateFrom, setProductDateFrom] = useState(today())
  const [productDateTo, setProductDateTo] = useState(today())

  const createKeyRef = useRef(newReturnKey())
  const generalProductAnchorRef = useRef<HTMLDivElement | null>(null)
  const refundKeyRef = useRef(newRefundKey())
  const readOnly = document?.status === 'posted'

  const customersQuery = useQuery({
    queryKey: ['sales-return', 'from-parties'],
    queryFn: fetchSaleReturnSourceParties,
    enabled: view === 'entry' && canReturn,
    retry: false,
  })

  const salesmenQuery = useQuery({
    queryKey: ['sales-return', 'salesmen'],
    queryFn: fetchSalesmen,
    enabled: view === 'entry' && canReturn,
    retry: false,
  })

  const saleLookup = useQuery({
    queryKey: ['sales', 'return-sale-lookup', saleSearch, fromPartyType, fromPartyUlid, salesmanFilterUlid],
    queryFn: () =>
      fetchSales({
        q: saleSearch || undefined,
        customer_ulid: fromPartyType === 'customer' ? fromPartyUlid || undefined : undefined,
        salesman_ulid: salesmanFilterUlid || undefined,
        status: 'posted',
        per_page: 20,
      }),
    enabled: view === 'entry' && !document?.ulid && canReturn,
    retry: false,
  })

  const returnableQuery = useQuery({
    queryKey: ['sales', 'returnable-lines', saleUlid],
    queryFn: () => fetchReturnableSaleLines(saleUlid),
    enabled: view === 'entry' && Boolean(saleUlid),
    retry: false,
  })

  const generalProductLookupQuery =
    generalProductLookupMode === 'search' ? generalProductSearch : ''

  const localGeneralProducts = useLocalProducts(
    session,
    generalProductLookupQuery,
    view === 'entry' && !saleUlid && !readOnly && generalProductPickerOpen,
  )

  const localGeneralProductRows = localGeneralProducts.rows.filter(
    (product) => product.is_active && product.status === 'active',
  )

  const generalProductQuery = useQuery({
    queryKey: ['sales-return', 'general-products-fallback', generalProductLookupQuery],
    queryFn: () => fetchProducts(
      {
        q: generalProductLookupQuery || undefined,
        page: 1,
        per_page: 50,
        sales_lookup: true,
        active_only: true,
      },
      { busy: 'none' },
    ),
    enabled:
      view === 'entry' &&
      !saleUlid &&
      !readOnly &&
      generalProductPickerOpen &&
      !localGeneralProducts.isFetching &&
      localGeneralProductRows.length === 0,
    retry: false,
  })

  const generalProductRows =
    localGeneralProductRows.length > 0
      ? localGeneralProductRows
      : (generalProductQuery.data?.data ?? []).filter(
          (product) => product.is_active && product.status === 'active',
        )

  const generalProductTotal =
    localGeneralProductRows.length > 0
      ? localGeneralProductRows.length
      : (generalProductQuery.data?.meta.total ?? generalProductRows.length)

  useEffect(() => {
    if (saleUlid || document?.ulid || fromPartyUlid) return

    const candidates = (customersQuery.data ?? []).filter(
      (row) => row.type === 'account' || row.type === 'customer',
    )
    const cash = candidates.find(
      (row) => row.type === 'account' && row.name.trim().toLocaleUpperCase() === 'CASH IN HAND',
    ) ?? candidates.find(
      (row) => row.type === 'customer' && row.name.trim().toLocaleUpperCase() === 'CASH IN HAND',
    )

    if (cash) {
      setFromPartyUlid(cash.ulid)
      setFromPartyType(cash.type)
    }
  }, [customersQuery.data, document?.ulid, fromPartyUlid, saleUlid])

  const historyQuery = useQuery({
    queryKey: ['sales-returns', historySearch, dateFrom, dateTo],
    queryFn: () =>
      fetchSaleReturns({
        q: historySearch || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        per_page: 100,
      }),
    enabled: view === 'search' && canReturn,
    retry: false,
  })

  const productWiseQuery = useQuery({
    queryKey: ['sales-returns', 'product-wise', productDateFrom, productDateTo],
    queryFn: () =>
      fetchSaleReturnProductWise({
        date_from: productDateFrom || undefined,
        date_to: productDateTo || undefined,
        per_page: 100,
      }),
    enabled: view === 'product' && canReturn,
    retry: false,
  })

  const returnable = returnableQuery.data?.data ?? []

  const liveTotals = useMemo(() => {
    if (!saleUlid) {
      return generalRows.reduce(
        (acc, row) => {
          const amounts = generalLineAmounts(row)
          acc.subtotal += amounts.gross
          acc.discount += amounts.discount
          acc.tax += amounts.tax
          acc.total += amounts.total
          return acc
        },
        { subtotal: 0, discount: 0, tax: 0, total: 0 },
      )
    }

    return returnable.reduce(
      (acc, row) => {
        const selected = Number(lineQty[row.sale_item_ulid] ?? 0)
        const original = Number(row.original_quantity) || 0
        if (!(selected > 0) || !(original > 0)) return acc

        const ratio = selected / original
        acc.subtotal += (Number(row.gross_amount) || 0) * ratio
        acc.discount += (Number(row.discount_amount) || 0) * ratio
        acc.tax += (Number(row.tax_amount) || 0) * ratio
        acc.total += (Number(row.line_total) || 0) * ratio
        return acc
      },
      { subtotal: 0, discount: 0, tax: 0, total: 0 },
    )
  }, [generalRows, returnable, lineQty, saleUlid])

  const returnEntryState = useMemo(() => {
    if (!saleUlid) {
      let validationError: string | null = null
      let positiveLineCount = 0

      for (const row of generalRows) {
        if (!row.product_ulid) {
          validationError = 'Select a product for every general return row.'
          break
        }
        if (!/^\d+(?:\.\d{0,6})?$/.test(row.quantity) || Number(row.quantity) <= 0) {
          validationError = `Return quantity for ${row.product_name} must be greater than zero and use up to 6 decimals.`
          break
        }
        if (!/^\d+(?:\.\d{0,4})?$/.test(row.unit_price) || Number(row.unit_price) < 0) {
          validationError = `Price for ${row.product_name} must use up to 4 decimal places.`
          break
        }
        if (!/^\d+(?:\.\d{0,8})?$/.test(row.discount_percent)) {
          validationError = `Discount % for ${row.product_name} is invalid.`
          break
        }
        const discount = Number(row.discount_percent)
        if (!Number.isFinite(discount) || discount < 0 || discount > 100) {
          validationError = `Discount % for ${row.product_name} must be between 0 and 100.`
          break
        }
        positiveLineCount += 1
      }

      return {
        positiveLineCount,
        validationError,
        hasReturnQuantity: positiveLineCount > 0,
      }
    }

    let positiveLineCount = 0
    let validationError: string | null = null

    for (const row of returnable) {
      const raw = (lineQty[row.sale_item_ulid] ?? '').trim()
      if (raw === '') continue

      if (!/^\d+(?:\.\d{0,6})?$/.test(raw)) {
        validationError = `Return quantity for ${row.product.name} must use up to 6 decimal places.`
        break
      }

      const selected = Number(raw)
      if (!Number.isFinite(selected) || selected < 0) {
        validationError = `Return quantity for ${row.product.name} is invalid.`
        break
      }

      if (selected === 0) continue

      const remaining = Number(row.remaining_returnable_quantity) || 0
      if (selected > remaining) {
        validationError = `Return quantity for ${row.product.name} cannot exceed ${row.remaining_returnable_quantity}.`
        break
      }

      positiveLineCount += 1
    }

    return {
      positiveLineCount,
      validationError,
      hasReturnQuantity: positiveLineCount > 0,
    }
  }, [generalRows, returnable, lineQty, saleUlid])

  const currentOutstanding = saleUlid ? Number(returnableQuery.data?.sale.previous_balance ?? 0) : 0
  const thisBill = readOnly ? Number(document?.grand_total ?? 0) : liveTotals.total
  const previousBalance =
    document?.status === 'posted' ? currentOutstanding + thisBill : currentOutstanding
  const totalBalance =
    document?.status === 'posted'
      ? currentOutstanding
      : Math.max(0, currentOutstanding - thisBill)

  function handleError(err: unknown) {
    const message =
      err instanceof ApiClientError || err instanceof Error
        ? err.message
        : 'Sales return failed.'
    setError(message)
    feedback.error(message, 'Sales Return')
  }

  function resetEntry() {
    setDocument(null)
    setSaleUlid('')
    setSaleLabel('')
    setSaleSearch('')
    setFromPartyUlid('')
    setFromPartyType('account')
    setSalesmanFilterUlid('')
    setReturnDate(today())
    setReason('')
    setNotes('')
    setLineQty({})
    setGeneralRows([])
    setGeneralProductSearch('')
    setGeneralProductPickerOpen(false)
    setGeneralProductLookupMode('browse')
    setGeneralProductTargetKey(null)
    setError(null)
    setRefundAmount('')
    setRefundMethod('cash')
    setRefundReference('')
    createKeyRef.current = newReturnKey()
    refundKeyRef.current = newRefundKey()
  }

  function loadDocument(row: SaleReturn) {
    setDocument(row)
    setSaleUlid(row.original_sale?.ulid ?? '')
    setSaleLabel(row.original_sale?.document_number ?? '')
    setSaleSearch(row.original_sale?.document_number ?? '')
    setReturnDate(row.return_date)
    setReason(row.reason ?? '')
    setNotes(row.notes ?? '')
    if (row.settlement_account) {
      setFromPartyUlid(row.settlement_account.ulid)
      setFromPartyType('account')
    } else if (row.customer) {
      setFromPartyUlid(row.customer.ulid)
      setFromPartyType('customer')
    } else {
      setFromPartyUlid('')
      setFromPartyType('account')
    }
    setSalesmanFilterUlid(row.salesman?.ulid ?? '')
    setRefundAmount(row.balance_due)
    const nextQty: Record<string, string> = {}
    const nextGeneral: GeneralReturnRow[] = []
    for (const line of row.lines ?? []) {
      if (line.original_sale_item_ulid) {
        nextQty[line.original_sale_item_ulid] = line.quantity
      } else if (line.product) {
        nextGeneral.push({
          key: line.ulid,
          line_ulid: line.ulid,
          product_ulid: line.product.ulid,
          product_number: line.product.product_number,
          product_name: line.product.name,
          unit_code: line.unit?.code ?? '—',
          quantity: line.quantity,
          unit_price: line.unit_price,
          discount_percent: line.discount_percent,
          tax_percent: line.tax_percent,
        })
      }
    }
    setLineQty(nextQty)
    setGeneralRows(nextGeneral)
    setError(null)
  }

  async function selectSale(sale: Sale) {
    setSaleUlid(sale.ulid)
    setSaleLabel(sale.document_number)
    setSaleSearch(sale.document_number)
    if (sale.customer) {
      setFromPartyUlid(sale.customer.ulid)
      setFromPartyType('customer')
    }
    setSalesmanFilterUlid(sale.salesman?.ulid ?? '')
    setDocument(null)
    setLineQty({})
    setGeneralRows([])
    setGeneralProductSearch('')
    setGeneralProductPickerOpen(false)
    setError(null)
  }

  function addGeneralProduct(product: Product) {
    if (generalRows.some((row) => row.product_ulid === product.ulid && row.key !== generalProductTargetKey)) {
      feedback.error(`${product.name} is already on this return.`, 'Sales Return')
      return
    }

    if (generalProductTargetKey) {
      setGeneralRows((rows) => rows.map((row) =>
        row.key === generalProductTargetKey
          ? {
              ...row,
              product_ulid: product.ulid,
              product_number: product.product_number,
              product_name: product.name,
              unit_code: product.base_unit?.code ?? '—',
              unit_price: retailPrice(product),
              discount_percent: '0.00000000',
              tax_percent: product.is_taxable ? product.tax_percent : '0.00000000',
            }
          : row,
      ))
    } else {
      setGeneralRows((rows) => [
        ...rows,
        {
          key: generalRowKey(),
          product_ulid: product.ulid,
          product_number: product.product_number,
          product_name: product.name,
          unit_code: product.base_unit?.code ?? '—',
          quantity: '1.000000',
          unit_price: retailPrice(product),
          discount_percent: '0.00000000',
          tax_percent: product.is_taxable ? product.tax_percent : '0.00000000',
        },
      ])
    }

    setGeneralProductSearch('')
    setGeneralProductPickerOpen(false)
    setGeneralProductLookupMode('browse')
    setGeneralProductTargetKey(null)
  }

  const openMutation = useMutation({
    mutationFn: fetchSaleReturn,
    onSuccess: (row) => {
      loadDocument(row)
      setView('entry')
    },
    onError: handleError,
  })

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (returnEntryState.validationError) {
        throw new Error(returnEntryState.validationError)
      }

      if (!saleUlid && !fromPartyUlid) {
        throw new Error('Select CASH IN HAND, another account, or a customer in From.')
      }

      let current = document

      if (!current?.ulid) {
        current = await createSaleReturn(
          {
            sale_ulid: saleUlid || undefined,
            customer_ulid: !saleUlid && fromPartyType === 'customer' ? fromPartyUlid : undefined,
            account_ulid: !saleUlid && fromPartyType === 'account' ? fromPartyUlid : undefined,
            salesman_ulid: !saleUlid && salesmanFilterUlid ? salesmanFilterUlid : undefined,
            return_date: returnDate,
            reason: reason || null,
            notes: notes || null,
          },
          createKeyRef.current,
        )
      } else {
        current = await updateSaleReturn(current.ulid, {
          return_date: returnDate,
          reason: reason || null,
          notes: notes || null,
        })
      }

      const refreshed = await fetchSaleReturn(current.ulid)

      if (!saleUlid) {
        const existingByProduct = new Map(
          (refreshed.lines ?? [])
            .filter((line) => !line.original_sale_item_ulid && line.product)
            .map((line) => [line.product?.ulid as string, line]),
        )

        for (const row of generalRows) {
          const existing = existingByProduct.get(row.product_ulid)
          const payload = {
            product_ulid: row.product_ulid,
            quantity: qty(row.quantity),
            unit_price: Number(row.unit_price || 0).toFixed(4),
            discount_percent: Number(row.discount_percent || 0).toFixed(8),
            reason: reason || null,
          }

          if (existing) {
            await updateSaleReturnLine(current.ulid, existing.ulid, payload)
          } else {
            await createSaleReturnLine(current.ulid, payload)
          }
        }

        for (const [productUlid, existing] of existingByProduct) {
          if (!generalRows.some((row) => row.product_ulid === productUlid)) {
            await deleteSaleReturnLine(current.ulid, existing.ulid)
          }
        }
      } else {
        const existingBySaleItem = new Map(
          (refreshed.lines ?? [])
            .filter((line) => line.original_sale_item_ulid)
            .map((line) => [line.original_sale_item_ulid as string, line]),
        )

        for (const [saleItemUlid, quantity] of Object.entries(lineQty)) {
          const amount = Number(quantity)
          const existing = existingBySaleItem.get(saleItemUlid)

          if (!Number.isFinite(amount) || amount <= 0) {
            if (existing) {
              await deleteSaleReturnLine(current.ulid, existing.ulid)
            }
            continue
          }

          const payload = {
            sale_item_ulid: saleItemUlid,
            quantity: qty(quantity),
            reason: reason || null,
          }

          if (existing) {
            await updateSaleReturnLine(current.ulid, existing.ulid, payload)
          } else {
            await createSaleReturnLine(current.ulid, payload)
          }
        }

        for (const [saleItemUlid, existing] of existingBySaleItem) {
          if (!(saleItemUlid in lineQty) || Number(lineQty[saleItemUlid]) <= 0) {
            await deleteSaleReturnLine(current.ulid, existing.ulid)
          }
        }
      }

      return fetchSaleReturn(current.ulid)
    },

    onSuccess: async (saved) => {
      loadDocument(saved)
      createKeyRef.current = newReturnKey()
      await queryClient.invalidateQueries({ queryKey: ['sales-returns'] })
      if (saleUlid) {
        await queryClient.invalidateQueries({ queryKey: ['sales', 'returnable-lines', saleUlid] })
      }
      feedback.success('Sales return draft saved.', 'Sales Return')
    },
    onError: handleError,
  })

  const postMutation = useMutation({
    mutationFn: async () => {
      if (!returnEntryState.hasReturnQuantity) {
        throw new Error('Enter at least one return quantity before posting.')
      }
      if (returnEntryState.validationError) {
        throw new Error(returnEntryState.validationError)
      }

      const saved = await saveMutation.mutateAsync()
      return postSaleReturn(saved.ulid)
    },
    onSuccess: async (posted) => {
      loadDocument(posted)
      await queryClient.invalidateQueries({ queryKey: ['sales-returns'] })
      await queryClient.invalidateQueries({ queryKey: ['sales', 'returnable-lines', saleUlid] })
      feedback.success('Sales return posted and stock restored.', 'Sales Return')
    },
    onError: handleError,
  })

  const refundMutation = useMutation({
    mutationFn: async () => {
      if (!document?.ulid || document.status !== 'posted') {
        throw new Error('Post the Sales Return before recording a refund.')
      }

      return createSaleReturnRefund(
        document.ulid,
        {
          amount: refundAmount,
          method: refundMethod,
          reference: refundReference || null,
        },
        refundKeyRef.current,
      )
    },
    onSuccess: async () => {
      refundKeyRef.current = newRefundKey()
      setRefundReference('')
      const refreshed = await fetchSaleReturn(document?.ulid as string)
      loadDocument(refreshed)
      await queryClient.invalidateQueries({ queryKey: ['sales-returns'] })
      await queryClient.invalidateQueries({ queryKey: ['sales'] })
      feedback.success('Sales Return refund recorded.', 'Sales Return')
    },
    onError: handleError,
  })

  useWorkspaceHandlers({
    save: () => {
      if (view === 'entry' && !readOnly && canReturn) {
        void saveMutation.mutateAsync().catch(handleError)
      }
    },
    refresh: () => {
      if (view === 'entry') {
        if (document?.ulid) {
          void openMutation.mutateAsync(document.ulid).catch(handleError)
        } else if (saleUlid) {
          void returnableQuery.refetch()
        }
      } else if (view === 'search') {
        void historyQuery.refetch()
      } else {
        void productWiseQuery.refetch()
      }
    },
  })

  if (!canReturn) {
    return (
      <div className="sales-return-no-access">
        You do not have permission to create or view Sales Returns.
      </div>
    )
  }

  return (
    <section className="sales-return-screen">
      <header className="sales-return-tabs">
        <button
          type="button"
          className={view === 'entry' ? 'is-active' : ''}
          onClick={() => setView('entry')}
        >
          <RotateCcw size={17} />
          Sale Return Invoice
        </button>
        <button
          type="button"
          className={view === 'search' ? 'is-active' : ''}
          onClick={() => setView('search')}
        >
          <Search size={17} />
          Search
        </button>
        <button
          type="button"
          className={view === 'product' ? 'is-active' : ''}
          onClick={() => setView('product')}
        >
          <FileText size={17} />
          Product Wise
        </button>
        <strong className="sales-return-title">Sales Return</strong>
      </header>

      {view === 'entry' ? (
        <div className="sales-return-entry">
          <section className="sales-return-head">
            <fieldset>
              <legend>Return Invoice Options</legend>
              <div className="sales-return-fields">
                <div className="sales-return-fields-left">
                <div className="sales-return-option-group is-ret">
                  <label>Ret#:</label>
                  <input value={document?.document_number ?? 'Auto'} readOnly />
                </div>

                <div className="sales-return-option-group is-date">
                  <label>Date:</label>
                  <input
                    type="date"
                    value={returnDate}
                    disabled={readOnly}
                    onChange={(e) => setReturnDate(e.target.value)}
                  />
                </div>

                <div className="sales-return-option-group is-sales">
                  <label>Sales#:</label>
                  <UiSelect
                    value={saleUlid}
                    className="sales-return-canonical-select sales-return-sales-select"
                    triggerClassName="sales-return-canonical-trigger"
                    disabled={Boolean(document?.ulid) || readOnly}
                    placeholder="Optional Invoice #"
                    searchInTrigger
                    searchable
                    searchPlaceholder="Search invoice #, customer or salesman"
                    aria-label="Sales invoice"
                    menuMinWidth={760}
                    maxMenuHeight={330}
                    menuColumns={[
                      { header: 'Invoice #', width: '112px' },
                      { header: 'Date', width: '92px' },
                      { header: 'Customer', width: 'minmax(190px, 1.3fr)' },
                      { header: 'Salesman', width: 'minmax(160px, 1fr)' },
                      { header: 'Total', width: '90px' },
                      { header: 'Due', width: '90px' },
                    ]}
                    options={[
                      ...(saleUlid &&
                      !(saleLookup.data?.data ?? []).some((sale) => sale.ulid === saleUlid)
                        ? [{
                            value: saleUlid,
                            label: saleLabel || 'Selected invoice',
                            columns: [
                              saleLabel || 'Selected invoice',
                              '—',
                              document?.customer?.name ?? 'CASH IN HAND',
                              document?.salesman?.name ?? '—',
                              money(document?.grand_total ?? 0),
                              money(document?.balance_due ?? 0),
                            ],
                          }]
                        : []),
                      ...(saleLookup.data?.data ?? []).map((sale) => ({
                        value: sale.ulid,
                        label: sale.document_number,
                        columns: [
                          sale.document_number,
                          sale.sale_date,
                          sale.customer?.name ?? 'CASH IN HAND',
                          sale.salesman?.name ?? '—',
                          money(sale.grand_total),
                          money(sale.balance_due),
                        ],
                      })),
                    ]}
                    onSearchChange={(value) => {
                      setSaleSearch(value)
                      if (saleUlid && value !== saleLabel) {
                        setSaleUlid('')
                        setSaleLabel('')
                        setLineQty({})
                        setGeneralRows([])
                      }
                    }}
                    onChange={(value) => {
                      const sale = (saleLookup.data?.data ?? []).find((row) => row.ulid === value)
                      if (sale) void selectSale(sale)
                    }}
                  />
                </div>

                <div className="sales-return-option-group is-from">
                  <label>From:</label>
                  <UiSelect
                    value={fromPartyUlid ? `${fromPartyType}:${fromPartyUlid}` : ''}
                    className="sales-return-canonical-select"
                    triggerClassName="sales-return-canonical-trigger"
                    disabled={Boolean(saleUlid) || Boolean(document?.ulid)}
                    placeholder="Select customer / account"
                    searchable
                    searchPlaceholder="Search customer / account"
                    aria-label="Customer or account"
                    menuMinWidth={520}
                    menuColumns={[
                      { header: 'Code', width: '110px' },
                      { header: 'Name', width: 'minmax(220px, 1fr)' },
                      { header: 'Type', width: '100px' },
                    ]}
                    options={(customersQuery.data ?? []).map((party) => ({
                      value: `${party.type}:${party.ulid}`,
                      label: party.name,
                      columns: [
                        party.code || '—',
                        party.name,
                        party.type === 'account' ? 'Account' : 'Customer',
                      ],
                    }))}
                    onChange={(value) => {
                      const separator = value.indexOf(':')
                      if (separator < 1) return
                      const type = value.slice(0, separator)
                      const ulid = value.slice(separator + 1)
                      if (type !== 'customer' && type !== 'account') return

                      setFromPartyType(type)
                      setFromPartyUlid(ulid)
                      setSaleUlid('')
                      setSaleLabel('')
                      setSaleSearch('')
                      setLineQty({})
                    }}
                  />
                </div>

                <div className="sales-return-option-group is-remarks">
                  <label>Remarks:</label>
                  <input
                    value={notes}
                    disabled={readOnly}
                    onChange={(e) => setNotes(e.target.value)}
                  />
                </div>

                <div className="sales-return-option-group is-salesman">
                  <label>S.Man:</label>
                  <UiSelect
                    value={salesmanFilterUlid}
                    className="sales-return-canonical-select"
                    triggerClassName="sales-return-canonical-trigger"
                    disabled={Boolean(saleUlid) || Boolean(document?.ulid)}
                    placeholder="No salesman"
                    searchable
                    searchPlaceholder="Search salesman"
                    aria-label="Salesman"
                    menuMinWidth={620}
                    menuColumns={[
                      { header: 'Code', width: '100px' },
                      { header: 'Name', width: 'minmax(180px, 1fr)' },
                      { header: 'Address', width: 'minmax(180px, 1fr)' },
                      { header: 'Mobile', width: '130px' },
                    ]}
                    options={[
                      {
                        value: '',
                        label: 'NO SALESMAN',
                        columns: ['—', 'NO SALESMAN', '—', '—'],
                      },
                      ...(salesmenQuery.data ?? []).map((salesman) => ({
                        value: salesman.ulid,
                        label: salesman.name,
                        columns: [
                          salesman.code || '—',
                          salesman.name,
                          salesman.address || '—',
                          salesman.mobile || '—',
                        ],
                      })),
                    ]}
                    onChange={(value) => {
                      setSalesmanFilterUlid(value)
                      setSaleUlid('')
                      setSaleLabel('')
                      setSaleSearch('')
                      setLineQty({})
                    }}
                  />
                </div>
                </div>

                <div className="sales-return-fields-right">
                  <UiButton
                    variant="info"
                    className="sales-return-header-refresh"
                    title="Refresh selected sale and balances"
                    disabled={returnableQuery.isFetching || saleLookup.isFetching}
                    onClick={() => {
                      if (saleUlid) {
                        void returnableQuery.refetch()
                      } else {
                        void saleLookup.refetch()
                      }
                    }}
                  >
                    <RefreshCw size={12} />
                    <span>Refresh</span>
                  </UiButton>

                  <div className="sales-return-balance-chip is-previous">
                    <span>Previous</span>
                    <strong>{money(previousBalance)}</strong>
                  </div>

                  <div className="sales-return-balance-chip is-this-bill">
                    <span>This Bill</span>
                    <strong>{money(thisBill)}</strong>
                  </div>

                  <div className="sales-return-balance-chip is-total-balance">
                    <span>Total Balance</span>
                    <strong>{money(totalBalance)}</strong>
                  </div>
                </div>
              </div>
            </fieldset>

            <fieldset className="sales-return-amounts">
              <legend>Amount Options</legend>
              <div><span>Amount:</span><strong>{money(readOnly ? document?.subtotal : liveTotals.subtotal)}</strong></div>
              <div><span>Disc (Rs):</span><strong>{money(readOnly ? document?.discount_amount : liveTotals.discount)}</strong></div>
              <div><span>Tax:</span><strong>{money(readOnly ? document?.tax_amount : liveTotals.tax)}</strong></div>
              <div className="is-net"><span>Net:</span><strong>{money(readOnly ? document?.grand_total : liveTotals.total)}</strong></div>
            </fieldset>
          </section>

          <div className="sales-return-grid-wrap">
            {saleUlid ? (
              <table className="sales-return-grid">
                <thead>
                  <tr>
                    <th>Item / Product Description</th>
                    <th>Kind</th>
                    <th>Sold Qty</th>
                    <th>Returned</th>
                    <th>Available</th>
                    <th>Return Qty</th>
                    <th>Unit</th>
                    <th>Price</th>
                    <th>Disc</th>
                    <th>Tax</th>
                    <th>Net</th>
                  </tr>
                </thead>
                <tbody>
                  {returnable.map((row) => (
                    <tr key={row.sale_item_ulid}>
                      <td>
                        <strong>{row.product.product_number}</strong> — {row.product.name}
                      </td>
                      <td>{row.line_kind.replace('_', ' ')}</td>
                      <td className="num">{row.original_quantity}</td>
                      <td className="num">{row.already_returned_quantity}</td>
                      <td className="num">{row.remaining_returnable_quantity}</td>
                      <td className="num is-edit">
                        {readOnly ? (
                          lineQty[row.sale_item_ulid] ?? '0'
                        ) : (
                          <input
                            inputMode="decimal"
                            value={lineQty[row.sale_item_ulid] ?? ''}
                            placeholder="0"
                            aria-label={`Return quantity for ${row.product.name}`}
                            aria-invalid={
                              Number(lineQty[row.sale_item_ulid] ?? 0) >
                              Number(row.remaining_returnable_quantity)
                            }
                            title={`Maximum returnable: ${row.remaining_returnable_quantity}`}
                            onChange={(e) => {
                              const value = e.target.value
                              if (value !== '' && !/^\d+(?:\.\d{0,6})?$/.test(value)) return
                              setLineQty((prev) => ({ ...prev, [row.sale_item_ulid]: value }))
                            }}
                            onBlur={() => {
                              const value = lineQty[row.sale_item_ulid]
                              if (!value || Number(value) === 0) return
                              setLineQty((prev) => ({ ...prev, [row.sale_item_ulid]: qty(value) }))
                            }}
                          />
                        )}
                      </td>
                      <td>{row.unit?.code ?? '—'}</td>
                      <td className="num">{money(row.unit_price)}</td>
                      <td className="num">{money(row.discount_amount)}</td>
                      <td className="num">{money(row.tax_amount)}</td>
                      <td className="num">{money(row.line_total)}</td>
                    </tr>
                  ))}
                  {returnableQuery.isFetching ? (
                    <tr><td colSpan={11} className="sales-return-empty">Loading returnable sale lines…</td></tr>
                  ) : null}
                  {returnableQuery.isError ? (
                    <tr><td colSpan={11} className="sales-return-empty">Could not load returnable sale lines. Use Refresh and try again.</td></tr>
                  ) : null}
                  {!returnableQuery.isFetching && !returnableQuery.isError && returnable.length === 0 ? (
                    <tr><td colSpan={11} className="sales-return-empty">This sale has no remaining returnable quantity.</td></tr>
                  ) : null}
                </tbody>
              </table>
            ) : (
              <table className="sales-return-grid is-general">
                <thead>
                  <tr>
                    <th>Item / Product Description</th>
                    <th>Quantity</th>
                    <th>Price (C)</th>
                    <th>AMT</th>
                    <th>Disc%</th>
                    <th>Tax</th>
                    <th>Amount(Rs)</th>
                    <th aria-label="Actions" />
                  </tr>
                </thead>
                <tbody>
                  {generalRows.map((row) => {
                    const amounts = generalLineAmounts(row)
                    return (
                      <tr key={row.key}>
                        <td className="sales-return-general-product-cell">
                          {readOnly ? (
                            <><strong>{row.product_number}</strong> — {row.product_name}</>
                          ) : generalProductTargetKey === row.key ? (
                            <div ref={generalProductAnchorRef} className="sales-product-combo sales-return-product-combo">
                              <input
                                autoFocus
                                className="sales-pos-product-entry"
                                value={generalProductSearch}
                                placeholder="Search product name or number"
                                aria-label={`Change product for ${row.product_name}`}
                                autoComplete="off"
                                onFocus={() => {
                                  setGeneralProductLookupMode('browse')
                                  setGeneralProductPickerOpen(true)
                                }}
                                onChange={(e) => {
                                  setGeneralProductSearch(e.target.value)
                                  setGeneralProductLookupMode('search')
                                  setGeneralProductPickerOpen(true)
                                }}
                                onKeyDown={(e) => {
                                  if (e.key === 'Escape') {
                                    setGeneralProductTargetKey(null)
                                    setGeneralProductSearch('')
                                    setGeneralProductPickerOpen(false)
                                    return
                                  }
                                  if (e.key === 'Enter') {
                                    const first = generalProductRows[0]
                                    if (first) {
                                      e.preventDefault()
                                      addGeneralProduct(first)
                                    }
                                  }
                                }}
                              />
                              <button
                                type="button"
                                className="sales-product-combo-toggle"
                                aria-label="Open product dropdown"
                                aria-expanded={generalProductPickerOpen}
                                onMouseDown={(e) => e.preventDefault()}
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
                                  rows={generalProductRows}
                                  total={generalProductTotal}
                                  priceType="retail"
                                  portalAnchorRef={generalProductAnchorRef}
                                  selectedUlid={row.product_ulid}
                                  onRequestClose={() => setGeneralProductPickerOpen(false)}
                                  onSelect={addGeneralProduct}
                                />
                              ) : null}
                            </div>
                          ) : (
                            <button
                              type="button"
                              className="sales-return-general-product-edit"
                              onClick={() => {
                                setGeneralProductTargetKey(row.key)
                                setGeneralProductSearch(row.product_name)
                                setGeneralProductLookupMode('browse')
                                setGeneralProductPickerOpen(true)
                              }}
                            >
                              <strong>{row.product_number}</strong> — {row.product_name}
                            </button>
                          )}
                        </td>
                        <td className="num is-edit">
                          {readOnly ? row.quantity : (
                            <input
                              inputMode="decimal"
                              value={row.quantity}
                              aria-label={`Return quantity for ${row.product_name}`}
                              onChange={(e) => {
                                const value = e.target.value
                                if (value !== '' && !/^\d+(?:\.\d{0,6})?$/.test(value)) return
                                setGeneralRows((rows) => rows.map((item) => item.key === row.key ? { ...item, quantity: value } : item))
                              }}
                            />
                          )}
                        </td>
                        <td className="num is-edit">
                          {readOnly ? money(row.unit_price) : (
                            <input
                              inputMode="decimal"
                              value={row.unit_price}
                              aria-label={`Return price for ${row.product_name}`}
                              onChange={(e) => {
                                const value = e.target.value
                                if (value !== '' && !/^\d+(?:\.\d{0,4})?$/.test(value)) return
                                setGeneralRows((rows) => rows.map((item) => item.key === row.key ? { ...item, unit_price: value } : item))
                              }}
                            />
                          )}
                        </td>
                        <td className="num">{money(amounts.gross)}</td>
                        <td className="num is-edit">
                          {readOnly ? row.discount_percent : (
                            <input
                              inputMode="decimal"
                              value={row.discount_percent}
                              aria-label={`Discount percent for ${row.product_name}`}
                              onChange={(e) => {
                                const value = e.target.value
                                if (value !== '' && !/^\d+(?:\.\d{0,8})?$/.test(value)) return
                                setGeneralRows((rows) => rows.map((item) => item.key === row.key ? { ...item, discount_percent: value } : item))
                              }}
                            />
                          )}
                        </td>
                        <td className="num">{money(amounts.tax)}</td>
                        <td className="num">{money(amounts.total)}</td>
                        <td className="sales-return-general-delete">
                          {!readOnly ? (
                            <button
                              type="button"
                              aria-label={`Remove ${row.product_name}`}
                              onClick={() => setGeneralRows((rows) => rows.filter((item) => item.key !== row.key))}
                            >
                              <X size={13} />
                            </button>
                          ) : null}
                        </td>
                      </tr>
                    )
                  })}

                  {!readOnly && generalProductTargetKey === null ? (
                    <tr className="sales-return-general-entry">
                      <td className="sales-return-general-product-cell">
                        <div ref={generalProductAnchorRef} className="sales-product-combo sales-return-product-combo">
                          <input
                            className="sales-pos-product-entry"
                            value={generalProductSearch}
                            placeholder="Search product name or number"
                            aria-label="General return product"
                            autoComplete="off"
                            onFocus={() => {
                              setGeneralProductTargetKey(null)
                              setGeneralProductLookupMode('browse')
                              setGeneralProductPickerOpen(true)
                            }}
                            onChange={(e) => {
                              setGeneralProductSearch(e.target.value)
                              setGeneralProductLookupMode('search')
                              setGeneralProductPickerOpen(true)
                            }}
                            onKeyDown={(e) => {
                              if (e.key === 'Escape') {
                                setGeneralProductPickerOpen(false)
                                return
                              }
                              if (e.key === 'Enter') {
                                const first = generalProductRows[0]
                                if (first) {
                                  e.preventDefault()
                                  addGeneralProduct(first)
                                }
                              }
                            }}
                          />
                          <button
                            type="button"
                            className="sales-product-combo-toggle"
                            aria-label="Open product dropdown"
                            aria-expanded={generalProductPickerOpen}
                            onMouseDown={(e) => e.preventDefault()}
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
                              rows={generalProductRows}
                              total={generalProductTotal}
                              priceType="retail"
                              portalAnchorRef={generalProductAnchorRef}
                              onRequestClose={() => setGeneralProductPickerOpen(false)}
                              onSelect={addGeneralProduct}
                            />
                          ) : null}
                        </div>
                      </td>
                      <td colSpan={7} className="sales-return-general-entry-hint">
                        Select products directly for a general return, or choose Sales# above for invoice-linked return.
                      </td>
                    </tr>
                  ) : null}

                  {readOnly && generalRows.length === 0 ? (
                    <tr><td colSpan={8} className="sales-return-empty">This general return has no lines.</td></tr>
                  ) : null}
                </tbody>
              </table>
            )}
          </div>

          {returnEntryState.validationError ? (
            <div className="sales-return-error">{returnEntryState.validationError}</div>
          ) : error ? (
            <div className="sales-return-error">{error}</div>
          ) : null}

          <footer className="sales-return-footer">
            <div className="sales-return-settlement">
              <div className="sales-return-refund-summary">
                <label>Refunded:</label>
                <strong>{money(document?.refund_amount ?? 0)}</strong>
                <label>Balance:</label>
                <strong>{money(readOnly ? document?.balance_due : liveTotals.total)}</strong>
              </div>

              {document?.status === 'posted' && Number(document.balance_due) > 0 ? (
                <div className="sales-return-refund-panel">
                  <div className="sales-return-refund-methods">
                    {REFUND_METHODS.map(({ value, label, icon: Icon }) => (
                      <button
                        key={value}
                        type="button"
                        className={refundMethod === value ? 'is-active' : ''}
                        disabled={!canRefund || refundMutation.isPending}
                        onClick={() => setRefundMethod(value)}
                      >
                        <Icon size={12} />
                        {label}
                      </button>
                    ))}
                  </div>

                  <input
                    className="sales-return-refund-amount"
                    value={refundAmount}
                    inputMode="decimal"
                    disabled={!canRefund || refundMutation.isPending}
                    aria-label="Refund amount"
                    onChange={(e) => setRefundAmount(e.target.value)}
                  />
                  <input
                    className="sales-return-refund-reference"
                    value={refundReference}
                    disabled={!canRefund || refundMutation.isPending}
                    placeholder="Reference"
                    onChange={(e) => setRefundReference(e.target.value)}
                  />
                  <UiButton
                    variant="warning"
                    className="sales-return-refund-submit"
                    disabled={
                      !canRefund ||
                      refundMutation.isPending ||
                      !(Number(refundAmount) > 0) ||
                      Number(refundAmount) > Number(document.balance_due)
                    }
                    onClick={() => refundMutation.mutate()}
                  >
                    {refundMutation.isPending ? 'Refunding…' : 'Refund'}
                  </UiButton>
                </div>
              ) : document?.status === 'posted' ? (
                <small className="sales-return-refund-complete">Refund fully settled.</small>
              ) : (
                <small className="sales-return-settlement-hint">Post the Sales Return before refund settlement.</small>
              )}
            </div>

            <div className="sales-return-actions">
              <DesktopButton
                icon={<Save size={13} />}
                label="Save"
                disabled={
                  readOnly ||
                  !saleUlid ||
                  saveMutation.isPending ||
                  postMutation.isPending ||
                  Boolean(returnEntryState.validationError)
                }
                onClick={() => void saveMutation.mutateAsync().catch(handleError)}
              />
              <DesktopButton
                icon={<CheckCircle2 size={13} />}
                label="Post"
                disabled={
                  readOnly ||
                  !saleUlid ||
                  !returnEntryState.hasReturnQuantity ||
                  saveMutation.isPending ||
                  postMutation.isPending ||
                  Boolean(returnEntryState.validationError)
                }
                onClick={() => {
                  void (async () => {
                    if (!(await askConfirm('Post this Sales Return and add returned stock back to the warehouse?'))) {
                      return
                    }
                    void postMutation.mutateAsync().catch(handleError)
                  })()
                }}
              />
              <DesktopButton
                icon={<RefreshCw size={13} />}
                label="Refresh"
                onClick={() => {
                  if (document?.ulid) {
                    void openMutation.mutateAsync(document.ulid).catch(handleError)
                  } else if (saleUlid) {
                    void returnableQuery.refetch()
                  } else {
                    resetEntry()
                  }
                }}
              />
              <DesktopButton
                icon={<X size={13} />}
                label="Close"
                onClick={closeActiveTab}
              />
            </div>
          </footer>
        </div>
      ) : null}

      {view === 'search' ? (
        <div className="sales-return-search-view">
          <div className="sales-return-filterbar">
            <input
              placeholder="Return #, Sales #, customer, salesman"
              value={historySearch}
              onChange={(e) => setHistorySearch(e.target.value)}
            />
            <label>From</label>
            <input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
            <label>To</label>
            <input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
            <UiButton variant="primary" onClick={() => void historyQuery.refetch()}>
              <Search size={13} />
              <span>View</span>
            </UiButton>
          </div>

          {historyQuery.isError ? (
            <div className="sales-return-error">
              Could not load Sales Return history. Use View or Refresh to try again.
            </div>
          ) : null}

          <PosDataGrid
            columns={[
              { key: 'document_number', header: 'Inv#', width: 110 },
              { key: 'return_date', header: 'Date', width: 100 },
              {
                key: 'customer',
                header: 'Customer',
                render: (row) => row.customer?.name ?? row.settlement_account?.name ?? 'CASH IN HAND',
              },
              {
                key: 'original_sale',
                header: 'Sales#',
                width: 120,
                render: (row) => row.original_sale?.document_number ?? '—',
              },
              {
                key: 'salesman',
                header: 'Sale Person',
                render: (row) => row.salesman?.name ?? '—',
              },
              { key: 'grand_total', header: 'Net', width: 110, align: 'right' },
              { key: 'refund_amount', header: 'Refunded', width: 110, align: 'right' },
              { key: 'balance_due', header: 'Balance', width: 110, align: 'right' },
              {
                key: 'status',
                header: 'Status',
                width: 90,
                render: (row) => row.status.toUpperCase(),
              },
            ]}
            rows={historyQuery.data?.data ?? []}
            rowKey={(row) => row.ulid}
            selectedKey={selectedHistory}
            onSelect={(row) => setSelectedHistory(row.ulid)}
            onActivate={(row) => void openMutation.mutateAsync(row.ulid)}
            emptyMessage="No Sales Returns match these filters."
          />
        </div>
      ) : null}

      {view === 'product' ? (
        <div className="sales-return-product-view">
          <div className="sales-return-filterbar">
            <label>From Date</label>
            <input type="date" value={productDateFrom} onChange={(e) => setProductDateFrom(e.target.value)} />
            <label>To Date</label>
            <input type="date" value={productDateTo} onChange={(e) => setProductDateTo(e.target.value)} />
            <UiButton variant="primary" onClick={() => void productWiseQuery.refetch()}>
              Show
            </UiButton>
          </div>

          {productWiseQuery.isError ? (
            <div className="sales-return-error">
              Could not load Product Wise Sales Return data. Use Show or Refresh to try again.
            </div>
          ) : null}

          <PosDataGrid
            columns={[
              { key: 'return_number', header: 'ID', width: 110 },
              { key: 'return_date', header: 'Date', width: 100 },
              {
                key: 'customer',
                header: 'Vendor / Customer',
                render: (row) => row.customer?.name ?? 'CASH IN HAND',
              },
              {
                key: 'salesman',
                header: 'Sale Man',
                render: (row) => row.salesman?.name ?? '—',
              },
              {
                key: 'product',
                header: 'Product',
                render: (row) => `${row.product.product_number} — ${row.product.name}`,
              },
              {
                key: 'category',
                header: 'Category',
                render: (row) => row.category?.name ?? '—',
              },
              { key: 'quantity_in', header: 'Qty (+)', width: 90, align: 'right' },
              { key: 'quantity_out', header: 'Qty (-)', width: 90, align: 'right' },
              { key: 'amount', header: 'Amount', width: 110, align: 'right' },
            ]}
            rows={productWiseQuery.data?.data ?? []}
            rowKey={(row) => row.ulid}
            emptyMessage="No posted Sales Return product activity in this period."
          />
        </div>
      ) : null}
    </section>
  )
}
