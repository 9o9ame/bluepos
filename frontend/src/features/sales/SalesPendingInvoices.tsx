import { useDeferredValue, useMemo, useState } from 'react'
import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import { Eye, Printer, RotateCcw, Search } from 'lucide-react'
import { deleteSaleHold, fetchSale, fetchSaleHold, fetchSaleHolds, fetchSales, fetchSalesmen } from '../../api/sales'
import { useCan } from '../auth/useCan'
import { useAuth } from '../auth/AuthProvider'
import { useFeedback } from '../../feedback/FeedbackProvider'
import type { Sale, SaleHold } from '../../types/sales'
import { SalePaymentPanel } from './SalePaymentPanel'
import { previewSaleReceipt, printSaleReceipt } from './saleReceipt'
import { UiSelect } from '../../components/ui/UiSelect'

type Props = {
  onRecallHeld: (hold: SaleHold) => Promise<void> | void
}


function money(value: string | null | undefined): string {
  return (Number.parseFloat(value ?? '0') || 0).toFixed(2)
}

export function SalesPendingInvoices({
  onRecallHeld,
}: Props) {
  const canCollectPayment = useCan('payments.create')
  const { session } = useAuth()
  const feedback = useFeedback()
  const [holdBusyUlid, setHoldBusyUlid] = useState<string | null>(null)
  const [searchText, setSearchText] = useState('')
  const search = useDeferredValue(searchText.trim())
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [salesmanUlid, setSalesmanUlid] = useState('')
  const [openUlid, setOpenUlid] = useState<string | null>(null)

  const holdsQuery = useQuery({
    queryKey: ['sales', 'holds', session?.branch.ulid, session?.warehouse.ulid],
    queryFn: fetchSaleHolds,
    enabled: Boolean(session),
    retry: false,
  })

  const salesmenQuery = useQuery({
    queryKey: ['sales', 'pending', 'salesmen'],
    queryFn: fetchSalesmen,
    retry: false,
  })

  const dueQuery = useInfiniteQuery({
    queryKey: ['sales', 'due', search, dateFrom, dateTo, salesmanUlid],
    initialPageParam: 1,
    queryFn: ({ pageParam }) =>
      fetchSales({
        page: pageParam,
        per_page: 40,
        q: search || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        salesman_ulid: salesmanUlid || undefined,
        status: 'posted',
        due_only: true,
      }),
    getNextPageParam: (lastPage) =>
      lastPage.meta.current_page < lastPage.meta.last_page
        ? lastPage.meta.current_page + 1
        : undefined,
    retry: false,
  })

  const detailQuery = useQuery({
    queryKey: ['sales', 'due', 'detail', openUlid],
    queryFn: () => fetchSale(openUlid as string),
    enabled: openUlid !== null,
    retry: false,
  })

  const rows: Sale[] = dueQuery.data?.pages.flatMap((page) => page.data) ?? []
  const meta = dueQuery.data?.pages[0]?.meta

  const totals = useMemo(
    () =>
      rows.reduce(
        (acc, sale) => {
          acc.total += Number.parseFloat(sale.grand_total) || 0
          acc.paid += Number.parseFloat(sale.paid_amount) || 0
          acc.due += Number.parseFloat(sale.balance_due) || 0
          return acc
        },
        { total: 0, paid: 0, due: 0 },
      ),
    [rows],
  )

  async function refreshAfterPayment() {
    await Promise.all([
      dueQuery.refetch(),
      openUlid ? detailQuery.refetch() : Promise.resolve(),
    ])
  }

  function resetFilters() {
    setSearchText('')
    setDateFrom('')
    setDateTo('')
    setSalesmanUlid('')
  }

  async function recallHold(holdUlid: string) {
    if (holdBusyUlid) return

    setHoldBusyUlid(holdUlid)
    try {
      const hold = await fetchSaleHold(holdUlid)
      await onRecallHeld(hold)
      feedback.success(
        'Held sale recalled. The server recovery copy will remain until the sale is posted or held again.',
        'On Hold',
      )
    } catch (err) {
      feedback.error(
        err instanceof Error ? err.message : 'Unable to recall this held sale.',
        'On Hold',
      )
    } finally {
      setHoldBusyUlid(null)
    }
  }

  async function discardHold(holdUlid: string) {
    if (holdBusyUlid) return

    setHoldBusyUlid(holdUlid)
    try {
      await deleteSaleHold(holdUlid)
      await holdsQuery.refetch()
      feedback.success('Held sale discarded.', 'On Hold')
    } catch (err) {
      feedback.error(
        err instanceof Error ? err.message : 'Unable to discard this held sale.',
        'On Hold',
      )
    } finally {
      setHoldBusyUlid(null)
    }
  }

  function loadMoreDueOnScroll(element: HTMLDivElement) {
    if (!dueQuery.hasNextPage || dueQuery.isFetchingNextPage) return

    const remaining = element.scrollHeight - element.scrollTop - element.clientHeight
    if (remaining < 140) {
      void dueQuery.fetchNextPage()
    }
  }

  return (
    <section className="sales-pending-view" aria-label="Pending and due invoices">
      <section className="sales-hold-section">
        <header className="sales-pending-section-head">
          <div>
            <span className="sales-pending-kicker">PERSISTENT HOLD</span>
            <h3>On Hold</h3>
          </div>
          <strong>{holdsQuery.data?.count ?? 0}</strong>
        </header>

        {(holdsQuery.data?.data.length ?? 0) === 0 ? (
          <div className="sales-pending-empty">
            {holdsQuery.isFetching
              ? 'Loading held sales…'
              : 'No held sales for this branch and warehouse.'}
          </div>
        ) : (
          <div className="sales-pending-grid-wrap">
            <table className="sales-pending-grid">
              <thead>
                <tr>
                  <th>Held at</th>
                  <th>Customer</th>
                  <th className="num">Sale Lines</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                {(holdsQuery.data?.data ?? []).map((entry) => (
                  <tr key={entry.ulid}>
                    <td>{entry.held_at ? new Date(entry.held_at).toLocaleString() : '—'}</td>
                    <td>{entry.customer?.name ?? 'CASH IN HAND'}</td>
                    <td className="num">{entry.sale_line_count}</td>
                    <td className="sales-pending-actions">
                      <button
                        type="button"
                        disabled={holdBusyUlid === entry.ulid}
                        onClick={() => void recallHold(entry.ulid)}
                      >
                        {holdBusyUlid === entry.ulid ? 'Working…' : 'Recall'}
                      </button>
                      <button
                        type="button"
                        className="is-danger"
                        disabled={holdBusyUlid === entry.ulid}
                        onClick={() => void discardHold(entry.ulid)}
                      >
                        Discard
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section className="sales-due-section">
        <header className="sales-pending-section-head">
          <div>
            <span className="sales-pending-kicker">POSTED SALES</span>
            <h3>Due Payments</h3>
          </div>
          <strong>{meta?.total ?? 0}</strong>
        </header>

        <div className="sales-due-toolbar">
          <div className="sales-due-search">
            <Search size={14} aria-hidden="true" />
            <input
              value={searchText}
              onChange={(e) => {
                setSearchText(e.target.value)
              }}
              placeholder="Invoice #, customer, salesman…"
              aria-label="Search due invoices"
            />
          </div>

          <label>
            <span>From</span>
            <input
              type="date"
              value={dateFrom}
              onChange={(e) => {
                setDateFrom(e.target.value)
              }}
            />
          </label>

          <label>
            <span>To</span>
            <input
              type="date"
              value={dateTo}
              onChange={(e) => {
                setDateTo(e.target.value)
              }}
            />
          </label>

          <label>
            <span>Salesman</span>
            <UiSelect
              aria-label="Salesman"
              value={salesmanUlid}
              options={[
                { value: '', label: 'All' },
                ...(salesmenQuery.data ?? []).map((salesman) => ({
                  value: salesman.ulid,
                  label: `${salesman.code} — ${salesman.name}`,
                })),
              ]}
              onChange={setSalesmanUlid}
            />
          </label>

          <button type="button" className="sales-due-reset" onClick={resetFilters}>
            <RotateCcw size={13} />
            Reset
          </button>

          <div className="sales-due-toolbar-summary">
            <div>
              <span>LOADED TOTAL</span>
              <strong>{totals.total.toFixed(2)}</strong>
            </div>
            <div className="is-paid">
              <span>PAID</span>
              <strong>{totals.paid.toFixed(2)}</strong>
            </div>
            <div className="is-due">
              <span>DUE</span>
              <strong>{totals.due.toFixed(2)}</strong>
            </div>
          </div>
        </div>

        <div
          className="sales-pending-grid-wrap sales-due-grid-wrap"
          onScroll={(event) => loadMoreDueOnScroll(event.currentTarget)}
        >
          <table className="sales-pending-grid">
            <thead>
              <tr>
                <th>Invoice #</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Salesman</th>
                <th className="num">Total</th>
                <th className="num">Paid</th>
                <th className="num">Due</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((sale) => (
                <tr
                  key={sale.ulid}
                  className={openUlid === sale.ulid ? 'is-open' : ''}
                  onClick={() =>
                    setOpenUlid((current) => (current === sale.ulid ? null : sale.ulid))
                  }
                >
                  <td className="sales-due-document">{sale.document_number}</td>
                  <td>{sale.sale_date}</td>
                  <td>{sale.customer?.name ?? 'CASH IN HAND'}</td>
                  <td>{sale.salesman?.name ?? '—'}</td>
                  <td className="num">{money(sale.grand_total)}</td>
                  <td className="num is-paid-value">{money(sale.paid_amount)}</td>
                  <td className="num is-due-value">{money(sale.balance_due)}</td>
                </tr>
              ))}

              {rows.length === 0 && !dueQuery.isPending ? (
                <tr>
                  <td colSpan={7} className="sales-pending-empty">
                    No posted invoices with an outstanding balance match these filters.
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>

        <div className="sales-infinite-status" role="status">
          {dueQuery.isFetchingNextPage
            ? 'Loading more due invoices…'
            : dueQuery.hasNextPage
              ? `Scroll for more · ${rows.length} of ${meta?.total ?? rows.length}`
              : rows.length > 0
                ? `All ${rows.length} due invoice(s) loaded`
                : ''}
        </div>

        {openUlid ? (
          <div className="sales-due-detail">
            {detailQuery.isFetching ? (
              <div className="sales-pending-empty">Loading invoice…</div>
            ) : detailQuery.data ? (
              <>
                <header className="sales-due-detail-head">
                  <div>
                    <span className="sales-pending-kicker">OUTSTANDING INVOICE</span>
                    <h3>{detailQuery.data.document_number}</h3>
                    <p>
                      {detailQuery.data.customer?.name ?? 'CASH IN HAND'} ·{' '}
                      {detailQuery.data.sale_date}
                    </p>
                  </div>

                  <div className="sales-pending-actions">
                    <button
                      type="button"
                      onClick={() => previewSaleReceipt(detailQuery.data)}
                    >
                      <Eye size={13} />
                      Preview
                    </button>
                    <button
                      type="button"
                      onClick={() => printSaleReceipt(detailQuery.data)}
                    >
                      <Printer size={13} />
                      Print
                    </button>
                  </div>
                </header>

                <div className="sales-due-detail-summary">
                  <div><span>Total</span><strong>{money(detailQuery.data.grand_total)}</strong></div>
                  <div className="is-paid"><span>Paid</span><strong>{money(detailQuery.data.paid_amount)}</strong></div>
                  <div className="is-due"><span>Due</span><strong>{money(detailQuery.data.balance_due)}</strong></div>
                </div>

                {canCollectPayment ? (
                  <SalePaymentPanel
                    sale={detailQuery.data}
                    outstanding={detailQuery.data.balance_due}
                    onCollected={() => {
                      void refreshAfterPayment()
                    }}
                  />
                ) : (
                  <div className="sales-pending-empty">
                    You can view this balance, but you do not have permission to collect payments.
                  </div>
                )}
              </>
            ) : detailQuery.isError ? (
              <div className="sales-pending-empty">Unable to load invoice details.</div>
            ) : null}
          </div>
        ) : null}
      </section>
    </section>
  )
}
