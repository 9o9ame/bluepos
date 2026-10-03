import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchSale, fetchSales } from '../../api/sales'
import { printSaleReceipt } from './saleReceipt'
import type { Sale } from '../../types/sales'

/**
 * Posted invoice history. Read-only: a posted sale never changes here —
 * corrections are returns/voids handled in later phases, never edits.
 */
export function SalesInvoiceHistory() {
  const [page, setPage] = useState(1)
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [openUlid, setOpenUlid] = useState<string | null>(null)

  const listQuery = useQuery({
    queryKey: ['sales', 'history', page, dateFrom, dateTo],
    queryFn: () =>
      fetchSales({
        page,
        per_page: 25,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
      }),
    retry: false,
  })

  const detailQuery = useQuery({
    queryKey: ['sales', 'detail', openUlid],
    queryFn: () => fetchSale(openUlid as string),
    enabled: openUlid !== null,
    retry: false,
  })

  const rows: Sale[] = listQuery.data?.data ?? []
  const meta = listQuery.data?.meta

  return (
    <div className="sales-history">
      <div className="sales-history-filters">
        <label>
          From <input type="date" value={dateFrom} onChange={(e) => { setDateFrom(e.target.value); setPage(1) }} />
        </label>
        <label>
          To <input type="date" value={dateTo} onChange={(e) => { setDateTo(e.target.value); setPage(1) }} />
        </label>
        <button
          type="button"
          onClick={() => { setDateFrom(''); setDateTo(''); setPage(1) }}
        >
          Clear
        </button>
        <span className="sales-history-count">
          {meta ? `${meta.total} invoice(s)` : 'Loading…'}
        </span>
      </div>

      <div className="sales-history-body">
        <table className="sales-history-grid">
          <thead>
            <tr>
              <th>Invoice #</th>
              <th>Date</th>
              <th>Customer</th>
              <th>Branch</th>
              <th>Status</th>
              <th className="num">Total</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((sale) => (
              <tr
                key={sale.ulid}
                className={openUlid === sale.ulid ? 'is-open' : ''}
                onClick={() => setOpenUlid(sale.ulid === openUlid ? null : sale.ulid)}
              >
                <td>{sale.document_number}</td>
                <td>{sale.sale_date}</td>
                <td>{sale.customer?.name ?? '—'}</td>
                <td>{sale.branch?.code ?? '—'}</td>
                <td>{sale.status}</td>
                <td className="num">{sale.grand_total}</td>
              </tr>
            ))}
            {rows.length === 0 && !listQuery.isPending ? (
              <tr>
                <td colSpan={6} className="sales-history-empty">No invoices found</td>
              </tr>
            ) : null}
          </tbody>
        </table>

        <div className="sales-history-pager">
          <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)}>◀</button>
          <span>Page {meta?.current_page ?? page} of {meta?.last_page ?? 1}</span>
          <button type="button" disabled={!meta || page >= meta.last_page} onClick={() => setPage(page + 1)}>▶</button>
        </div>

        {openUlid && detailQuery.data ? (
          <div className="sales-history-detail">
            <h3>
              {detailQuery.data.document_number} — {detailQuery.data.grand_total}
            </h3>
            <button
              type="button"
              onClick={() => printSaleReceipt(detailQuery.data)}
            >
              Print receipt
            </button>
            <p>
              {detailQuery.data.sale_date} · {detailQuery.data.customer?.name ?? 'Walk-in'} ·{' '}
              {detailQuery.data.warehouse?.name ?? ''} · {detailQuery.data.status}
            </p>
            <table className="sales-history-grid">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Kind</th>
                  <th className="num">Qty</th>
                  <th className="num">Price</th>
                  <th className="num">Total</th>
                </tr>
              </thead>
              <tbody>
                {detailQuery.data.items.map((item, index) => (
                  <tr key={index}>
                    <td>{item.product ? `${item.product.product_number} — ${item.product.name}` : '—'}</td>
                    <td>{item.line_kind}</td>
                    <td className="num">{item.quantity}</td>
                    <td className="num">{item.unit_price}</td>
                    <td className="num">{item.line_total}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {detailQuery.data.payments?.length ? (
              <table className="sales-history-grid">
                <thead>
                  <tr>
                    <th>Payment</th>
                    <th>Reference</th>
                    <th className="num">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {detailQuery.data.payments.map((payment) => (
                    <tr key={payment.ulid}>
                      <td>{payment.method}</td>
                      <td>{payment.reference ?? '—'}</td>
                      <td className="num">{payment.amount}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            ) : null}
          </div>
        ) : null}
      </div>
    </div>
  )
}
