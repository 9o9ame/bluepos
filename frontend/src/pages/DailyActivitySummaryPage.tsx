import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { apiFetch } from '../api/client'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiButton } from '../components/ui/UiButton'
import { UiDateRangePicker } from '../components/ui/UiDateRangePicker'
import { UiSkeleton } from '../components/ui/UiSkeleton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './DailyBusinessActivityPage.css'

type DocumentLine = {
  ulid: string
  product: { ulid: string; product_number: string; name: string }
  quantity: string
  base_quantity: string
  rate: string
  amount: string
}
type DocumentRow = {
  ulid: string
  type: 'sale' | 'purchase' | 'sale_return' | 'purchase_return'
  date: string
  document_number: string
  party: string
  amount: string
  lines: DocumentLine[]
}
type StockRow = {
  product: { ulid: string; name: string; product_number: string }
  opening_quantity: string
  quantity_change: string
  closing_quantity: string
  movements: Record<string, string>
}
type DailyActivity = {
  from: string
  to: string
  business_name: string
  documents: DocumentRow[]
  totals: Record<DocumentRow['type'], string>
  stock: StockRow[]
  basis: string
  limitations: string[]
}
const today = () => {
  const date = new Date()
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}
const format = (value: string) => {
  const [whole, fraction = ''] = value.split('.')
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction ? '.' + fraction : '')
}
const labels: Record<DocumentRow['type'], string> = {
  sale: 'Sale', purchase: 'Purchase',
  sale_return: 'Sale Return', purchase_return: 'Purchase Return',
}

export function DailyActivitySummaryPage() {
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const preview = params.get('view') === 'preview'
  const { openModule, closeActiveTab } = useWorkspace()
  const [from, setFrom] = useState(params.get('from') || today())
  const [to, setTo] = useState(params.get('to') || today())
  const [search, setSearch] = useState('')
  const [zoom, setZoom] = useState(100)
  const paperRef = useRef<HTMLElement>(null)
  const selectedFrom = params.get('from') || ''
  const selectedTo = params.get('to') || ''
  const query = useQuery({
    queryKey: ['daily-activity-summary', selectedFrom, selectedTo],
    queryFn: () => apiFetch<DailyActivity>(
      '/api/reports/daily/activity-summary?' +
      new URLSearchParams({ from: selectedFrom, to: selectedTo }).toString()
    ),
    enabled: preview && Boolean(selectedFrom) && Boolean(selectedTo),
  })

  const exportData = (kind: 'txt' | 'html' | 'csv') => {
    if (!query.data || !paperRef.current) return
    const csvCell = (v: string) => '"' + (/^[\s]*[=+@-]/.test(v) ? "'" : '') + v.replace(/"/g, '""') + '"'
    const content = kind === 'csv'
      ? '\uFEFF' + [
          ['Type', 'Date', 'Document', 'Party', 'Product', 'Quantity', 'Rate', 'Line Amount', 'Document Total'],
          ...query.data.documents.flatMap(row =>
            row.lines.length
              ? row.lines.map(item => [labels[row.type], row.date, row.document_number,
                  row.party, item.product.name, item.quantity, item.rate, item.amount, row.amount])
              : [[labels[row.type], row.date, row.document_number, row.party,
                  '', '', '', '', row.amount]]),
        ].map(line => line.map(csvCell).join(',')).join('\r\n')
      : kind === 'html'
        ? '<!doctype html><html><head><meta charset="utf-8"></head><body>' +
          paperRef.current.innerHTML + '</body></html>'
        : paperRef.current.innerText
    const url = URL.createObjectURL(new Blob([content], {
      type: kind === 'csv' ? 'text/csv;charset=utf-8' : kind === 'html' ? 'text/html;charset=utf-8' : 'text/plain;charset=utf-8',
    }))
    const a = document.createElement('a')
    a.href = url
    a.download = 'daily-activity-summary.' + kind
    a.click()
    URL.revokeObjectURL(url)
  }
  const find = () => {
    const text = search.trim().toLowerCase()
    if (text) Array.from(paperRef.current?.querySelectorAll('td,th,h1,h2') ?? [])
      .find(node => node.textContent?.toLowerCase().includes(text))
      ?.scrollIntoView({ block: 'center' })
  }

  if (!preview) return <section className="dba-page">
    <div className="dba-select-card">
      <h2>Daily Activity Report — Selection Options</h2>
      <div className="dba-fields">
        <div className="dba-dates">
          <label><span>Date range (maximum 31 days)</span>
            <UiDateRangePicker
              fromInputProps={{ type: 'date', value: from, onChange: event => setFrom(event.target.value) }}
              toInputProps={{ type: 'date', value: to, onChange: event => setTo(event.target.value) }}
            />
          </label>
        </div>
      </div>
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab} />
      <UiButton variant="info" label="View Report"
        disabled={!from || !to || from > to}
        onClick={() => openModule('/reports/daily/activity-summary?view=preview&' +
          new URLSearchParams({ from, to }).toString())}
      />
    </div>
  </section>

  return <section className="dba-page dba-preview">
    <ReportViewerToolbar
      ready={Boolean(query.data)} busy={query.isFetching}
      search={search} onSearchChange={setSearch} onFind={find}
      zoom={zoom} onZoomChange={setZoom}
      onPrint={() => window.print()}
      onCopy={async () => { if (paperRef.current) await navigator.clipboard.writeText(paperRef.current.innerText) }}
      onExport={exportData}
      onRefresh={() => void query.refetch()}
      onEditOptions={() => openModule('/reports/daily/activity-summary')}
      onClose={closeActiveTab}
    />
    {query.isLoading && <UiSkeleton label="Loading daily activity report…" />}
    {query.isError && <p role="alert">Unable to load daily activity report.</p>}
    {query.data && <article className="dba-paper" ref={paperRef} style={{ zoom: zoom + '%' }}>
      <header><h1>DAILY ACTIVITY REPORT</h1><p>{query.data.business_name}</p>
        <p>From {query.data.from} to {query.data.to}</p></header>
      <h2>Sale / Purchase Detail for the Date</h2>
      <table>
        <thead><tr><th>Date</th><th>Type</th><th>Document</th><th>Party</th><th>Amount</th></tr></thead>
        <tbody>
          {query.data.documents.map(row => <tr key={row.type + row.ulid}>
            <td>{row.date}</td><td>{labels[row.type]}</td><td>{row.document_number}</td>
            <td>{row.party || '—'}
              {row.lines.length > 0 && <div className="dba-account-meta">
                {row.lines.map((item, index) => <div key={item.ulid || index}>
                  {item.product.product_number} — {item.product.name}:
                  {' '}{format(item.quantity)} × {format(item.rate)} = {format(item.amount)}
                </div>)}
              </div>}
            </td>
            <td className="dba-amount">{format(row.amount)}</td>
          </tr>)}
          {query.data.documents.length === 0 && <tr><td colSpan={5}>No posted documents for this period.</td></tr>}
        </tbody>
      </table>
      <h2>Document Totals</h2>
      <table><thead><tr><th>Sales</th><th>Purchases</th><th>Sale Returns</th><th>Purchase Returns</th></tr></thead>
        <tbody><tr>{(['sale', 'purchase', 'sale_return', 'purchase_return'] as const).map(type =>
          <td className="dba-amount" key={type}>{format(query.data!.totals[type])}</td>)}</tr></tbody>
      </table>
      <h2>Stock Sheet — Period Movement</h2>
      <table>
        <thead><tr><th>Product</th><th>Opening</th><th>Movement type / Quantity</th><th>Net quantity change</th><th>Closing</th></tr></thead>
        <tbody>{query.data.stock.map(row => <tr key={row.product.ulid}>
          <td>{row.product.product_number} — {row.product.name}</td>
          <td className="dba-amount">{format(row.opening_quantity)}</td>
          <td>{Object.entries(row.movements).map(([type, qty]) =>
            <div key={type}>{type.replace(/_/g, ' ')}: {format(qty)}</div>)}</td>
          <td className="dba-amount">{format(row.quantity_change)}</td>
          <td className="dba-amount">{format(row.closing_quantity)}</td>
        </tr>)}
        {query.data.stock.length === 0 && <tr><td colSpan={5}>No stock movements for this period.</td></tr>}
        </tbody>
      </table>
      {query.data.limitations.map(note => <p key={note}>{note}</p>)}
    </article>}
  </section>
}
