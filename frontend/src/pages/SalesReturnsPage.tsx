import { useMemo, useRef, useState } from 'react'
import { Banknote, CheckCircle2, ChevronDown, CreditCard, FileText, Landmark, RefreshCw, RotateCcw, Save, Search, Wallet, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiClientError } from '../api/client'
import { fetchSales, fetchSalesmen } from '../api/sales'
import { fetchParties } from '../api/parties'
import {
  createSaleReturn,
  createSaleReturnLine,
  createSaleReturnRefund,
  deleteSaleReturnLine,
  fetchReturnableSaleLines,
  fetchSaleReturn,
  fetchSaleReturnProductWise,
  fetchSaleReturns,
  postSaleReturn,
  updateSaleReturn,
  updateSaleReturnLine,
} from '../api/salesReturns'
import { DesktopButton } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { askConfirm, useFeedback } from '../feedback/FeedbackProvider'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Sale, SalePaymentMethod } from '../types/sales'
import type { SaleReturn } from '../types/salesReturns'
import './SalesReturnsPage.css'

type ViewMode = 'entry' | 'search' | 'product'

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

export function SalesReturnsPage() {
  const queryClient = useQueryClient()
  const feedback = useFeedback()
  const { closeActiveTab } = useWorkspace()
  const canReturn = useCan('sales.return')
  const canRefund = useCan('payments.create')

  const [view, setView] = useState<ViewMode>('entry')
  const [document, setDocument] = useState<SaleReturn | null>(null)
  const [saleUlid, setSaleUlid] = useState('')
  const [saleLabel, setSaleLabel] = useState('')
  const [saleSearch, setSaleSearch] = useState('')
  const [salePickerOpen, setSalePickerOpen] = useState(false)
  const [customerFilterUlid, setCustomerFilterUlid] = useState('')
  const [salesmanFilterUlid, setSalesmanFilterUlid] = useState('')
  const [customerSelectOpen, setCustomerSelectOpen] = useState(false)
  const [salesmanSelectOpen, setSalesmanSelectOpen] = useState(false)
  const [returnDate, setReturnDate] = useState(today())
  const [reason, setReason] = useState('')
  const [notes, setNotes] = useState('')
  const [lineQty, setLineQty] = useState<Record<string, string>>({})
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
  const refundKeyRef = useRef(newRefundKey())
  const readOnly = document?.status === 'posted'

  const customersQuery = useQuery({
    queryKey: ['sales-return', 'customers'],
    queryFn: () => fetchParties('customer'),
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
    queryKey: ['sales', 'return-sale-lookup', saleSearch, customerFilterUlid, salesmanFilterUlid],
    queryFn: () =>
      fetchSales({
        q: saleSearch || undefined,
        customer_ulid: customerFilterUlid || undefined,
        salesman_ulid: salesmanFilterUlid || undefined,
        status: 'posted',
        per_page: 20,
      }),
    enabled: view === 'entry' && !document?.ulid && salePickerOpen,
    retry: false,
  })

  const returnableQuery = useQuery({
    queryKey: ['sales', 'returnable-lines', saleUlid],
    queryFn: () => fetchReturnableSaleLines(saleUlid),
    enabled: view === 'entry' && Boolean(saleUlid),
    retry: false,
  })

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
  }, [returnable, lineQty])

  const currentOutstanding = Number(returnableQuery.data?.sale.previous_balance ?? 0)
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
    setSalePickerOpen(false)
    setCustomerFilterUlid('')
    setSalesmanFilterUlid('')
    setReturnDate(today())
    setReason('')
    setNotes('')
    setLineQty({})
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
    setReturnDate(row.return_date)
    setReason(row.reason ?? '')
    setNotes(row.notes ?? '')
    setCustomerFilterUlid(row.customer?.ulid ?? '')
    setSalesmanFilterUlid(row.salesman?.ulid ?? '')
    setRefundAmount(row.balance_due)
    const nextQty: Record<string, string> = {}
    for (const line of row.lines ?? []) {
      if (line.original_sale_item_ulid) {
        nextQty[line.original_sale_item_ulid] = line.quantity
      }
    }
    setLineQty(nextQty)
    setError(null)
  }

  async function selectSale(sale: Sale) {
    setSaleUlid(sale.ulid)
    setSaleLabel(sale.document_number)
    setSaleSearch('')
    setSalePickerOpen(false)
    setCustomerFilterUlid(sale.customer?.ulid ?? '')
    setSalesmanFilterUlid(sale.salesman?.ulid ?? '')
    setDocument(null)
    setLineQty({})
    setError(null)
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
      if (!saleUlid) throw new Error('Select a posted sale invoice first.')

      let current = document

      if (!current?.ulid) {
        current = await createSaleReturn(
          {
            sale_ulid: saleUlid,
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

      return fetchSaleReturn(current.ulid)
    },
    onSuccess: async (saved) => {
      loadDocument(saved)
      createKeyRef.current = newReturnKey()
      await queryClient.invalidateQueries({ queryKey: ['sales-returns'] })
      await queryClient.invalidateQueries({ queryKey: ['sales', 'returnable-lines', saleUlid] })
      feedback.success('Sales return draft saved.', 'Sales Return')
    },
    onError: handleError,
  })

  const postMutation = useMutation({
    mutationFn: async () => {
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
        <strong>Sales Return</strong>
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
                  <div className={`sales-return-sale-search${salePickerOpen ? ' is-open' : ''}`}>
                    <div className="sales-return-sale-input">
                      <Search size={13} aria-hidden="true" />
                      <input
                        value={saleLabel || saleSearch}
                        disabled={Boolean(document?.ulid) || readOnly}
                        placeholder="Invoice #, customer or salesman"
                        onFocus={() => {
                          if (!document?.ulid && !readOnly) setSalePickerOpen(true)
                        }}
                        onChange={(e) => {
                          setSaleSearch(e.target.value)
                          setSaleLabel('')
                          setSaleUlid('')
                          setLineQty({})
                          setSalePickerOpen(true)
                        }}
                        onKeyDown={(e) => {
                          if (e.key === 'Escape') setSalePickerOpen(false)
                        }}
                      />
                      <button
                        type="button"
                        className="sales-return-sale-trigger"
                        disabled={Boolean(document?.ulid) || readOnly}
                        onClick={() => setSalePickerOpen((open) => !open)}
                        aria-label="Open posted sales"
                      >
                        <ChevronDown size={14} />
                      </button>
                    </div>

                    {!document?.ulid && salePickerOpen ? (
                      <div className="sales-return-sale-results">
                        <div className="sales-return-sale-results-head">
                          <span>Invoice #</span>
                          <span>Date</span>
                          <span>Customer</span>
                          <span>Salesman</span>
                          <span>Total</span>
                          <span>Due</span>
                        </div>
                        <div className="sales-return-sale-results-body">
                          {(saleLookup.data?.data ?? []).map((sale) => (
                            <button
                              key={sale.ulid}
                              type="button"
                              onClick={() => void selectSale(sale)}
                            >
                              <strong>{sale.document_number}</strong>
                              <span>{sale.sale_date}</span>
                              <span>{sale.customer?.name ?? 'CASH IN HAND'}</span>
                              <span>{sale.salesman?.name ?? '—'}</span>
                              <span>{money(sale.grand_total)}</span>
                              <span className={Number(sale.balance_due) > 0 ? 'is-due' : 'is-paid'}>
                                {money(sale.balance_due)}
                              </span>
                            </button>
                          ))}
                          {!saleLookup.isFetching && (saleLookup.data?.data.length ?? 0) === 0 ? (
                            <div className="sales-return-sale-results-empty">
                              No posted sales match this search.
                            </div>
                          ) : null}
                          {saleLookup.isFetching ? (
                            <div className="sales-return-sale-results-empty">Searching posted sales…</div>
                          ) : null}
                        </div>
                        <div className="sales-return-sale-results-foot">
                          {(saleLookup.data?.meta.total ?? 0)} posted invoice(s)
                        </div>
                      </div>
                    ) : null}
                  </div>
                </div>

                <div className="sales-return-option-group is-from">
                  <label>From:</label>
                  <div className={`sales-return-detail-picker is-customer${customerSelectOpen ? ' is-open' : ''}`}>
                    <button
                      type="button"
                      className="sales-return-detail-control"
                      disabled={Boolean(saleUlid) || Boolean(document?.ulid)}
                      onClick={() => {
                        setSalesmanSelectOpen(false)
                        setSalePickerOpen(false)
                        setCustomerSelectOpen((open) => !open)
                      }}
                      aria-expanded={customerSelectOpen}
                      aria-label="Choose customer"
                    >
                      <span>
                        {customerFilterUlid
                          ? (() => {
                              const customer = (customersQuery.data ?? []).find((row) => row.ulid === customerFilterUlid)
                              return customer ? `${customer.code} — ${customer.name}` : 'Selected customer'
                            })()
                          : 'All / CASH IN HAND'}
                      </span>
                      <span className="sales-return-detail-caret" aria-hidden="true">
                        <ChevronDown size={12} strokeWidth={2.75} />
                      </span>
                    </button>

                    <div
                      className={`sales-return-detail-dropdown${customerSelectOpen ? ' is-open' : ''}`}
                      aria-hidden={!customerSelectOpen}
                    >
                      <div className="sales-return-detail-head">
                        <span>Code</span>
                        <span>Name</span>
                        <span>Address</span>
                        <span>Mobile</span>
                      </div>
                      <div className="sales-return-detail-body">
                        <button
                          type="button"
                          className="sales-return-detail-row"
                          onClick={() => {
                            setCustomerFilterUlid('')
                            setSaleUlid('')
                            setSaleLabel('')
                            setLineQty({})
                            setCustomerSelectOpen(false)
                            setSalePickerOpen(true)
                          }}
                        >
                          <span>—</span>
                          <strong>ALL / CASH IN HAND</strong>
                          <span>Walk-in / all customers</span>
                          <span>—</span>
                        </button>
                        {(customersQuery.data ?? []).map((customer) => (
                          <button
                            type="button"
                            className="sales-return-detail-row"
                            key={customer.ulid}
                            onClick={() => {
                              setCustomerFilterUlid(customer.ulid)
                              setSaleUlid('')
                              setSaleLabel('')
                              setLineQty({})
                              setCustomerSelectOpen(false)
                              setSalePickerOpen(true)
                            }}
                          >
                            <span>{customer.code || '—'}</span>
                            <strong>{customer.name}</strong>
                            <span>{customer.address || '—'}</span>
                            <span>{customer.mobile || customer.phone || '—'}</span>
                          </button>
                        ))}
                      </div>
                      <div className="sales-return-detail-foot">
                        {(customersQuery.data?.length ?? 0) + 1} account(s)
                      </div>
                    </div>
                  </div>
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
                  <div className={`sales-return-detail-picker is-salesman${salesmanSelectOpen ? ' is-open' : ''}`}>
                    <button
                      type="button"
                      className="sales-return-detail-control"
                      disabled={Boolean(saleUlid) || Boolean(document?.ulid)}
                      onClick={() => {
                        setCustomerSelectOpen(false)
                        setSalePickerOpen(false)
                        setSalesmanSelectOpen((open) => !open)
                      }}
                      aria-expanded={salesmanSelectOpen}
                      aria-label="Choose salesman"
                    >
                      <span>
                        {salesmanFilterUlid
                          ? (() => {
                              const salesman = (salesmenQuery.data ?? []).find((row) => row.ulid === salesmanFilterUlid)
                              return salesman ? `${salesman.code} — ${salesman.name}` : 'Selected salesman'
                            })()
                          : 'All salesmen'}
                      </span>
                      <span className="sales-return-detail-caret" aria-hidden="true">
                        <ChevronDown size={12} strokeWidth={2.75} />
                      </span>
                    </button>

                    <div
                      className={`sales-return-detail-dropdown${salesmanSelectOpen ? ' is-open' : ''}`}
                      aria-hidden={!salesmanSelectOpen}
                    >
                      <div className="sales-return-detail-head">
                        <span>Code</span>
                        <span>Name</span>
                        <span>Address</span>
                        <span>Mobile</span>
                      </div>
                      <div className="sales-return-detail-body">
                        <button
                          type="button"
                          className="sales-return-detail-row"
                          onClick={() => {
                            setSalesmanFilterUlid('')
                            setSaleUlid('')
                            setSaleLabel('')
                            setLineQty({})
                            setSalesmanSelectOpen(false)
                            setSalePickerOpen(true)
                          }}
                        >
                          <span>—</span>
                          <strong>ALL SALESMEN</strong>
                          <span>—</span>
                          <span>—</span>
                        </button>
                        {(salesmenQuery.data ?? []).map((salesman) => (
                          <button
                            type="button"
                            className="sales-return-detail-row"
                            key={salesman.ulid}
                            onClick={() => {
                              setSalesmanFilterUlid(salesman.ulid)
                              setSaleUlid('')
                              setSaleLabel('')
                              setLineQty({})
                              setSalesmanSelectOpen(false)
                              setSalePickerOpen(true)
                            }}
                          >
                            <span>{salesman.code || '—'}</span>
                            <strong>{salesman.name}</strong>
                            <span>{salesman.address || '—'}</span>
                            <span>{salesman.mobile || '—'}</span>
                          </button>
                        ))}
                      </div>
                      <div className="sales-return-detail-foot">
                        {(salesmenQuery.data?.length ?? 0) + 1} salesman option(s)
                      </div>
                    </div>
                  </div>
                </div>

                <div className="sales-return-fields-right">
                  <button
                    type="button"
                    className="sales-return-header-refresh"
                    title="Refresh selected sale and balances"
                    onClick={() => {
                      setCustomerSelectOpen(false)
                      setSalesmanSelectOpen(false)
                      if (saleUlid) {
                        void returnableQuery.refetch()
                      } else {
                        void saleLookup.refetch()
                      }
                    }}
                  >
                    <RefreshCw size={12} />
                    Refresh
                  </button>

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
                          onChange={(e) =>
                            setLineQty((prev) => ({
                              ...prev,
                              [row.sale_item_ulid]: e.target.value,
                            }))
                          }
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
                {saleUlid && returnable.length === 0 && !returnableQuery.isFetching ? (
                  <tr>
                    <td colSpan={11} className="sales-return-empty">
                      This sale has no remaining returnable quantity.
                    </td>
                  </tr>
                ) : null}
                {!saleUlid ? (
                  <tr>
                    <td colSpan={11} className="sales-return-empty">
                      Select a posted Sales Invoice to begin the return.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>

          {error ? <div className="sales-return-error">{error}</div> : null}

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
                  <button
                    type="button"
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
                  </button>
                </div>
              ) : document?.status === 'posted' ? (
                <small className="sales-return-refund-complete">Refund fully settled.</small>
              ) : (
                <small>Post the Sales Return before refund settlement.</small>
              )}
            </div>

            <div className="sales-return-actions">
              <DesktopButton
                icon={<Save size={13} />}
                label="Save"
                disabled={readOnly || saveMutation.isPending}
                onClick={() => void saveMutation.mutateAsync().catch(handleError)}
              />
              <DesktopButton
                icon={<CheckCircle2 size={13} />}
                label="Post"
                disabled={readOnly || postMutation.isPending}
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
            <button type="button" onClick={() => void historyQuery.refetch()}>
              <Search size={13} /> View
            </button>
          </div>

          <PosDataGrid
            columns={[
              { key: 'document_number', header: 'Inv#', width: 110 },
              { key: 'return_date', header: 'Date', width: 100 },
              {
                key: 'customer',
                header: 'Customer',
                render: (row) => row.customer?.name ?? 'CASH IN HAND',
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
            <button type="button" onClick={() => void productWiseQuery.refetch()}>
              Show
            </button>
          </div>

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
