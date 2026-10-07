import { useDeferredValue, useMemo, useState } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import { RotateCcw, Search } from 'lucide-react'
import { fetchSaleProductWise } from '../../api/sales'
import { useCan } from '../auth/useCan'
import { useAuth } from '../auth/AuthProvider'

function money(value: string): string {
  return (Number.parseFloat(value) || 0).toFixed(2)
}

function quantity(value: string): string {
  return (Number.parseFloat(value) || 0).toFixed(3)
}

function lineKindLabel(kind: 'sale' | 'free_packaging' | 'free_scheme'): string {
  if (kind === 'free_packaging') return 'Free Packaging'
  if (kind === 'free_scheme') return 'Free Scheme'
  return 'Sale'
}

export function SalesProductWiseSummary() {
  const canView = useCan('sales.view')
  const { session } = useAuth()

  const [searchText, setSearchText] = useState('')
  const search = useDeferredValue(searchText.trim())
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  const query = useInfiniteQuery({
    queryKey: [
      'sales',
      'product-wise',
      session?.branch.ulid,
      session?.warehouse.ulid,
      search,
      dateFrom,
      dateTo,
    ],
    initialPageParam: 1,
    queryFn: ({ pageParam }) =>
      fetchSaleProductWise({
        page: pageParam,
        per_page: 50,
        q: search || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
      }),
    getNextPageParam: (lastPage) =>
      lastPage.meta.current_page < lastPage.meta.last_page
        ? lastPage.meta.current_page + 1
        : undefined,
    enabled: Boolean(session) && canView,
    retry: false,
  })

  const rows = query.data?.pages.flatMap((page) => page.data) ?? []
  const meta = query.data?.pages[0]?.meta

  const loadedAmount = useMemo(
    () =>
      rows.reduce(
        (sum, row) => sum + (Number.parseFloat(row.amount) || 0),
        0,
      ),
    [rows],
  )

  function resetFilters() {
    setSearchText('')
    setDateFrom('')
    setDateTo('')
  }

  function loadMoreOnScroll(element: HTMLDivElement) {
    if (!query.hasNextPage || query.isFetchingNextPage) return

    const remaining =
      element.scrollHeight - element.scrollTop - element.clientHeight

    if (remaining < 140) {
      void query.fetchNextPage()
    }
  }

  if (!canView) {
    return (
      <section className="sales-pending-view" aria-label="Product Wise Summary">
        <div className="sales-pending-empty">
          You do not have permission to view Sales product activity.
        </div>
      </section>
    )
  }

  return (
    <section className="sales-pending-view" aria-label="Product Wise Summary">
      <section className="sales-due-section">
        <header className="sales-pending-section-head">
          <div>
            <span className="sales-pending-kicker">POSTED SALES</span>
            <h3>Product Wise Summary</h3>
          </div>
          <strong>{meta?.total ?? 0}</strong>
        </header>

        <div className="sales-due-toolbar">
          <div className="sales-due-search">
            <Search size={14} aria-hidden="true" />
            <input
              value={searchText}
              onChange={(event) => setSearchText(event.target.value)}
              placeholder="Invoice, product, category, customer, salesman…"
              aria-label="Search product-wise Sales"
            />
          </div>

          <label>
            <span>From</span>
            <input
              type="date"
              value={dateFrom}
              onChange={(event) => setDateFrom(event.target.value)}
            />
          </label>

          <label>
            <span>To</span>
            <input
              type="date"
              value={dateTo}
              onChange={(event) => setDateTo(event.target.value)}
            />
          </label>

          <button type="button" className="sales-due-reset" onClick={resetFilters}>
            <RotateCcw size={13} />
            Reset
          </button>

          <div className="sales-due-toolbar-summary">
            <div>
              <span>LOADED AMOUNT</span>
              <strong>{loadedAmount.toFixed(2)}</strong>
            </div>
          </div>
        </div>

        {query.isError ? (
          <div className="sales-pending-empty">
            Unable to load Product Wise Summary.
          </div>
        ) : null}

        <div
          className="sales-pending-grid-wrap sales-due-grid-wrap"
          onScroll={(event) => loadMoreOnScroll(event.currentTarget)}
        >
          <table className="sales-pending-grid">
            <thead>
              <tr>
                <th>Invoice</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Salesman</th>
                <th>Product</th>
                <th>Category</th>
                <th>Unit</th>
                <th>Type</th>
                <th className="num">Qty (-)</th>
                <th className="num">Amount</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.ulid}>
                  <td>{row.sale_number}</td>
                  <td>{row.sale_date}</td>
                  <td>{row.customer?.name ?? 'CASH IN HAND'}</td>
                  <td>{row.salesman?.name ?? '—'}</td>
                  <td>
                    {row.product.product_number} — {row.product.name}
                  </td>
                  <td>{row.category?.name ?? '—'}</td>
                  <td>{row.unit?.code ?? '—'}</td>
                  <td>{lineKindLabel(row.line_kind)}</td>
                  <td className="num">{quantity(row.quantity_out)}</td>
                  <td className="num">{money(row.amount)}</td>
                </tr>
              ))}

              {rows.length === 0 && !query.isPending && !query.isError ? (
                <tr>
                  <td colSpan={10} className="sales-pending-empty">
                    No posted Sales product activity matches these filters.
                  </td>
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>

        <div className="sales-infinite-status" role="status">
          {query.isFetchingNextPage
            ? 'Loading more product activity…'
            : query.hasNextPage
              ? `Scroll for more · ${rows.length} of ${meta?.total ?? rows.length}`
              : rows.length > 0
                ? `All ${rows.length} line(s) loaded`
                : ''}
        </div>
      </section>
    </section>
  )
}
