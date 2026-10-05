import { useDeferredValue, useMemo, useState } from 'react'
import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { Eye, Printer, RotateCcw, Search, X } from 'lucide-react'
import { fetchSale, fetchSales, fetchSalesmen } from '../../api/sales'
import { previewSaleReceipt, printSaleReceipt } from './saleReceipt'
import type { Sale } from '../../types/sales'

type StatusFilter = '' | 'posted' | 'void'

function money(value: string | null | undefined): string {
  return (Number.parseFloat(value ?? '0') || 0).toFixed(2)
}

function paymentLabel(sale: Sale): string {
  const due = Number.parseFloat(sale.balance_due) || 0
  const paid = Number.parseFloat(sale.paid_amount) || 0

  if (due <= 0.00005) return 'PAID'
  if (paid > 0.00005) return 'PARTIAL'
  return 'DUE'
}

/**
 * Posted invoice history. Read-only: posted financial records are never edited
 * here. Corrections remain separate return/void flows.
 */
export function SalesInvoiceHistory() {
  const [searchText, setSearchText] = useState('')
  const deferredSearch = useDeferredValue(searchText.trim())
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [salesmanUlid, setSalesmanUlid] = useState('')
  const [status, setStatus] = useState<StatusFilter>('posted')
  const [openUlid, setOpenUlid] = useState<string | null>(null)

  const salesmenQuery = useQuery({
    queryKey: ['sales', 'history', 'salesmen'],
    queryFn: fetchSalesmen,
    retry: false,
  })

  const listQuery = useInfiniteQuery({
    queryKey: [
      'sales',
      'history',
      deferredSearch,
      dateFrom,
      dateTo,
      salesmanUlid,
      status,
    ],
    initialPageParam: 1,
    queryFn: ({ pageParam }) =>
      fetchSales({
        page: pageParam,
        per_page: 40,
        q: deferredSearch || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        salesman_ulid: salesmanUlid || undefined,
        status: status || undefined,
      }),
    getNextPageParam: (lastPage) =>
      lastPage.meta.current_page < lastPage.meta.last_page
        ? lastPage.meta.current_page + 1
        : undefined,
    retry: false,
  })

  const detailQuery = useQuery({
    queryKey: ['sales', 'detail', openUlid],
    queryFn: () => fetchSale(openUlid as string),
    enabled: openUlid !== null,
    retry: false,
  })

  const rows: Sale[] = listQuery.data?.pages.flatMap((page) => page.data) ?? []
  const meta = listQuery.data?.pages[0]?.meta

  const summary = useMemo(() => {
    return rows.reduce(
      (acc, sale) => {
        acc.total += Number.parseFloat(sale.grand_total) || 0
        acc.paid += Number.parseFloat(sale.paid_amount) || 0
        acc.due += Number.parseFloat(sale.balance_due) || 0
        return acc
      },
      { total: 0, paid: 0, due: 0 },
    )
  }, [rows])

  function resetFilters() {
    setSearchText('')
    setDateFrom('')
    setDateTo('')
    setSalesmanUlid('')
    setStatus('posted')
  }

  function openSale(saleUlid: string) {
    setOpenUlid((current) => (current === saleUlid ? null : saleUlid))
  }

  function loadMoreOnScroll(element: HTMLDivElement) {
    if (!listQuery.hasNextPage || listQuery.isFetchingNextPage) return

    const remaining = element.scrollHeight - element.scrollTop - element.clientHeight
    if (remaining < 140) {
      void listQuery.fetchNextPage()
    }
  }

  return (
    <section className="sales-history sales-posted-history" aria-label="Posted invoices">
      <div className="sales-history-summary">
        <div>
          <span>PAGE TOTAL</span>
          <strong>{summary.total.toFixed(2)}</strong>
        </div>
        <div className="is-paid">
          <span>PAID</span>
          <strong>{summary.paid.toFixed(2)}</strong>
        </div>
        <div className="is-due">
          <span>DUE</span>
          <strong>{summary.due.toFixed(2)}</strong>
        </div>
      </div>

      <header className="sales-history-toolbar">
        <div className="sales-history-search">
          <Search size={15} aria-hidden="true" />
          <input
            value={searchText}
            onChange={(e) => {
              setSearchText(e.target.value)
            }}
            placeholder="Invoice #, customer, salesman…"
            aria-label="Search posted invoices"
          />
          {searchText ? (
            <button
              type="button"
              className="sales-history-search-clear"
              onClick={() => {
                setSearchText('')
                }}
              aria-label="Clear search"
            >
              <X size={13} />
            </button>
          ) : null}
        </div>

        <label className="sales-history-filter">
          <span>From</span>
          <input
            type="date"
            value={dateFrom}
            onChange={(e) => {
              setDateFrom(e.target.value)
            }}
          />
        </label>

        <label className="sales-history-filter">
          <span>To</span>
          <input
            type="date"
            value={dateTo}
            onChange={(e) => {
              setDateTo(e.target.value)
            }}
          />
        </label>

        <label className="sales-history-filter">
          <span>Salesman</span>
          <select
            value={salesmanUlid}
            onChange={(e) => {
              setSalesmanUlid(e.target.value)
            }}
          >
            <option value="">All</option>
            {(salesmenQuery.data ?? []).map((salesman) => (
              <option key={salesman.ulid} value={salesman.ulid}>
                {salesman.code} — {salesman.name}
              </option>
            ))}
          </select>
        </label>

        <label className="sales-history-filter">
          <span>Status</span>
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value as StatusFilter)
            }}
          >
            <option value="">All</option>
            <option value="posted">Posted</option>
            <option value="void">Void</option>
          </select>
        </label>

        <button
          type="button"
          className="sales-history-reset"
          onClick={resetFilters}
          title="Reset filters"
        >
          <RotateCcw size={14} />
          Reset
        </button>

        <div className="sales-history-count">
          {listQuery.isFetching
            ? 'Searching…'
            : meta
              ? `${meta.total} invoice(s)`
              : '0 invoice(s)'}
        </div>
      </header>

      <div className="sales-history-body">
        <div
          className="sales-history-grid-wrap"
          onScroll={(event) => loadMoreOnScroll(event.currentTarget)}
        >
          <table className="sales-history-grid">
            <thead>
              <tr>
                <th>Invoice #</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Salesman</th>
                <th>Warehouse</th>
                <th>Status</th>
                <th>Payment</th>
                <th className="num">Total</th>
                <th className="num">Paid</th>
                <th className="num">Due</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((sale) => {
                const payment = paymentLabel(sale)

                return (
                  <tr
                    key={sale.ulid}
                    className={openUlid === sale.ulid ? 'is-open' : ''}
                    onClick={() => openSale(sale.ulid)}
                    onDoubleClick={() => setOpenUlid(sale.ulid)}
                  >
                    <td className="sales-history-document">{sale.document_number}</td>
                    <td>{sale.sale_date}</td>
                    <td>{sale.customer?.name ?? 'CASH IN HAND'}</td>
                    <td>{sale.salesman?.name ?? '—'}</td>
                    <td>{sale.warehouse?.code ?? '—'}</td>
                    <td>
                      <span className={`sales-history-status is-${sale.status}`}>
                        {sale.status.toUpperCase()}
                      </span>
                    </td>
                    <td>
                      <span className={`sales-history-payment is-${payment.toLowerCase()}`}>
                        {payment}
                      </span>
                    </td>
                    <td className="num">{money(sale.grand_total)}</td>
                    <td className="num is-paid-value">{money(sale.paid_amount)}</td>
                    <td className="num is-due-value">{money(sale.balance_due)}</td>
                  </tr>
                )
              })}

              {rows.length === 0 && !listQuery.isPending ? (
                <tr>
                  <td colSpan={10} className="sales-history-empty">
                    No posted invoices match these filters.
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>

        <div className="sales-infinite-status" role="status">
          {listQuery.isFetchingNextPage
            ? 'Loading more invoices…'
            : listQuery.hasNextPage
              ? `Scroll for more · ${rows.length} of ${meta?.total ?? rows.length}`
              : rows.length > 0
                ? `All ${rows.length} invoice(s) loaded`
                : ''}
        </div>

        {openUlid ? (
          <article className="sales-history-detail">
            {detailQuery.isFetching ? (
              <div className="sales-history-detail-loading">Loading invoice…</div>
            ) : detailQuery.data ? (
              <>
                <header className="sales-history-detail-head">
                  <div>
                    <span className="sales-history-detail-kicker">POSTED INVOICE</span>
                    <h3>{detailQuery.data.document_number}</h3>
                    <p>
                      {detailQuery.data.sale_date} ·{' '}
                      {detailQuery.data.customer?.name ?? 'CASH IN HAND'} ·{' '}
                      {detailQuery.data.salesman?.name ?? 'No salesman'}
                    </p>
                  </div>

                  <div className="sales-history-detail-actions">
                    <button
                      type="button"
                      onClick={() => previewSaleReceipt(detailQuery.data)}
                    >
                      <Eye size={14} />
                      Preview
                    </button>
                    <button
                      type="button"
                      onClick={() => printSaleReceipt(detailQuery.data)}
                    >
                      <Printer size={14} />
                      Print
                    </button>
                    <button type="button" onClick={() => setOpenUlid(null)}>
                      <X size={14} />
                      Close
                    </button>
                  </div>
                </header>

                <div className="sales-history-detail-totals">
                  <div><span>Subtotal</span><strong>{money(detailQuery.data.subtotal)}</strong></div>
                  <div><span>Discount</span><strong>{money(detailQuery.data.discount_amount)}</strong></div>
                  <div><span>Tax</span><strong>{money(detailQuery.data.tax_amount)}</strong></div>
                  <div><span>Total</span><strong>{money(detailQuery.data.grand_total)}</strong></div>
                  <div className="is-paid"><span>Paid</span><strong>{money(detailQuery.data.paid_amount)}</strong></div>
                  <div className="is-due"><span>Due</span><strong>{money(detailQuery.data.balance_due)}</strong></div>
                </div>

                <div className="sales-history-detail-grid-wrap">
                  <table className="sales-history-grid is-detail-grid">
                    <thead>
                      <tr>
                        <th>Product</th>
                        <th>Kind</th>
                        <th>Unit</th>
                        <th className="num">Qty</th>
                        <th className="num">Price</th>
                        <th className="num">Disc.</th>
                        <th className="num">Tax</th>
                        <th className="num">Net</th>
                      </tr>
                    </thead>
                    <tbody>
                      {detailQuery.data.items.map((item) => (
                        <tr key={item.ulid}>
                          <td>
                            {item.product
                              ? `${item.product.product_number} — ${item.product.name}`
                              : '—'}
                          </td>
                          <td>{item.line_kind}</td>
                          <td>{item.unit?.code ?? '—'}</td>
                          <td className="num">{item.quantity}</td>
                          <td className="num">{money(item.unit_price)}</td>
                          <td className="num">{money(item.discount_amount)}</td>
                          <td className="num">{money(item.tax_amount)}</td>
                          <td className="num">{money(item.line_total)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                <section className="sales-history-payments">
                  <h4>Payments</h4>
                  {detailQuery.data.payments?.length ? (
                    <table className="sales-history-grid is-payments-grid">
                      <thead>
                        <tr>
                          <th>Method</th>
                          <th>Reference</th>
                          <th>Collected</th>
                          <th className="num">Amount</th>
                        </tr>
                      </thead>
                      <tbody>
                        {detailQuery.data.payments.map((payment) => (
                          <tr key={payment.ulid}>
                            <td>{payment.method.toUpperCase()}</td>
                            <td>{payment.reference ?? '—'}</td>
                            <td>
                              {payment.created_at
                                ? new Date(payment.created_at).toLocaleString()
                                : '—'}
                            </td>
                            <td className="num">{money(payment.amount)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  ) : (
                    <p className="sales-history-no-payments">
                      No payment has been collected for this invoice.
                    </p>
                  )}
                </section>

                {detailQuery.data.notes ? (
                  <div className="sales-history-notes">
                    <strong>Remarks:</strong> {detailQuery.data.notes}
                  </div>
                ) : null}
              </>
            ) : detailQuery.isError ? (
              <div className="sales-history-empty">Unable to load invoice details.</div>
            ) : null}
          </article>
        ) : null}
      </div>
    </section>
  )
}
