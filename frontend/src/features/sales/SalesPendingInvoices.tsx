import { useDeferredValue, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Eye, Printer, RotateCcw, Search } from 'lucide-react'
import type { Party } from '../../api/parties'
import { fetchSale, fetchSales, fetchSalesmen } from '../../api/sales'
import { useCan } from '../auth/useCan'
import type { Sale, SaleDraftLine } from '../../types/sales'
import { SalePaymentPanel } from './SalePaymentPanel'
import { previewSaleReceipt, printSaleReceipt } from './saleReceipt'

type HeldCart = {
  id: string
  lines: SaleDraftLine[]
  heldAt: string
  customer: Party | null
}

type Props = {
  heldCarts: HeldCart[]
  onRecallHeld: (id: string) => void
  onDiscardHeld: (id: string) => void
}

function money(value: string | null | undefined): string {
  return (Number.parseFloat(value ?? '0') || 0).toFixed(2)
}

export function SalesPendingInvoices({
  heldCarts,
  onRecallHeld,
  onDiscardHeld,
}: Props) {
  const canCollectPayment = useCan('payments.create')
  const [page, setPage] = useState(1)
  const [searchText, setSearchText] = useState('')
  const search = useDeferredValue(searchText.trim())
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [salesmanUlid, setSalesmanUlid] = useState('')
  const [openUlid, setOpenUlid] = useState<string | null>(null)

  const salesmenQuery = useQuery({
    queryKey: ['sales', 'pending', 'salesmen'],
    queryFn: fetchSalesmen,
    retry: false,
  })

  const dueQuery = useQuery({
    queryKey: ['sales', 'due', page, search, dateFrom, dateTo, salesmanUlid],
    queryFn: () =>
      fetchSales({
        page,
        per_page: 25,
        q: search || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        salesman_ulid: salesmanUlid || undefined,
        status: 'posted',
        due_only: true,
      }),
    retry: false,
  })

  const detailQuery = useQuery({
    queryKey: ['sales', 'due', 'detail', openUlid],
    queryFn: () => fetchSale(openUlid as string),
    enabled: openUlid !== null,
    retry: false,
  })

  const rows: Sale[] = dueQuery.data?.data ?? []
  const meta = dueQuery.data?.meta

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
    setPage(1)
  }

  return (
    <section className="sales-pending-view" aria-label="Pending and due invoices">
      <section className="sales-hold-section">
        <header className="sales-pending-section-head">
          <div>
            <span className="sales-pending-kicker">LOCAL HOLD</span>
            <h3>On Hold</h3>
          </div>
          <strong>{heldCarts.length}</strong>
        </header>

        {heldCarts.length === 0 ? (
          <div className="sales-pending-empty">
            No locally held carts in this session.
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
                {heldCarts.map((entry) => (
                  <tr key={entry.id}>
                    <td>{entry.heldAt}</td>
                    <td>{entry.customer?.name ?? 'CASH IN HAND'}</td>
                    <td className="num">
                      {entry.lines.filter((line) => line.line_kind === 'sale').length}
                    </td>
                    <td className="sales-pending-actions">
                      <button type="button" onClick={() => onRecallHeld(entry.id)}>
                        Recall
                      </button>
                      <button
                        type="button"
                        className="is-danger"
                        onClick={() => onDiscardHeld(entry.id)}
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
                setPage(1)
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
                setPage(1)
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
                setPage(1)
              }}
            />
          </label>

          <label>
            <span>Salesman</span>
            <select
              value={salesmanUlid}
              onChange={(e) => {
                setSalesmanUlid(e.target.value)
                setPage(1)
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

          <button type="button" className="sales-due-reset" onClick={resetFilters}>
            <RotateCcw size={13} />
            Reset
          </button>
        </div>

        <div className="sales-due-summary">
          <div>
            <span>PAGE TOTAL</span>
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

        <div className="sales-pending-grid-wrap">
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

        <div className="sales-due-pager">
          <button
            type="button"
            disabled={page <= 1 || dueQuery.isFetching}
            onClick={() => setPage((current) => Math.max(1, current - 1))}
          >
            ◀ Previous
          </button>
          <span>
            Page {meta?.current_page ?? page} of {meta?.last_page ?? 1}
          </span>
          <button
            type="button"
            disabled={!meta || page >= meta.last_page || dueQuery.isFetching}
            onClick={() => setPage((current) => current + 1)}
          >
            Next ▶
          </button>
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
