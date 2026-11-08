import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiFetch } from '../api/client'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { UiSkeleton } from '../components/ui/UiSkeleton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useAuth } from '../features/auth/AuthProvider'
import './DailyBusinessActivityPage.css'
import './DailyReportSheets.css'

type DeadStockRow = {
  product: { ulid: string; product_number: string; sku: string | null; name: string; category: string | null; brand: string | null }
  quantity: string
  last_sale_at: string | null
  stock_value: string | null
}
type Result = {
  data: DeadStockRow[]
  meta: {
    days: number
    cutoff: string
    definition: string
    warehouse: { ulid: string; name: string }
    current_page: number
    last_page: number
    total: number
    per_page: number
  }
}
const headings = ['Product #', 'SKU', 'Product', 'Category', 'Brand', 'In Stock', 'Last Sale', 'Stock Value']
function cells(row: DeadStockRow) {
  return [row.product.product_number, row.product.sku ?? '', row.product.name,
    row.product.category ?? '', row.product.brand ?? '', row.quantity,
    row.last_sale_at?.slice(0, 10) ?? 'Never recorded', row.stock_value ?? 'Restricted']
}
function safeCell(value: string) {
  const escaped = value.replace(/"/g, '""')
  return '"' + (/^[\s]*[=+\-@\t\r]/.test(value) ? "'" : '') + escaped + '"'
}
function html(value: string) {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}
function exportReport(format: 'csv' | 'html' | 'txt', rows: DeadStockRow[]) {
  const cellsData = [headings, ...rows.map(cells)]
  const data = format === 'csv' ? cellsData.map(row => row.map(safeCell).join(',')).join('\r\n')
    : format === 'txt' ? cellsData.map(row => row.join('\t')).join('\n')
      : '<!doctype html><meta charset="utf-8"><table><tbody>' +
        cellsData.map(row => '<tr>' + row.map(value => '<td>' + html(value) + '</td>').join('') + '</tr>').join('') +
        '</tbody></table>'
  const file = new Blob([data], { type: format === 'csv' ? 'text/csv;charset=utf-8' :
    format === 'html' ? 'text/html;charset=utf-8' : 'text/plain;charset=utf-8' })
  const url = URL.createObjectURL(file)
  const link = document.createElement('a')
  link.href = url
  link.download = 'bluepos-dead-stock-page.' + format
  link.click()
  URL.revokeObjectURL(url)
}

export function DeadStockReportPage() {
  const { session } = useAuth()
  const { closeActiveTab } = useWorkspace()
  const [days, setDays] = useState('90')
  const [search, setSearch] = useState('')
  const [querySearch, setQuerySearch] = useState('')
  const [page, setPage] = useState(1)
  const [preview, setPreview] = useState(false)
  const [zoom, setZoom] = useState(100)
  const paper = useRef<HTMLElement>(null)
  const query = useQuery({
    queryKey: [session?.tenant.ulid,session?.branch.ulid,session?.warehouse.ulid,'reports', 'dead-stock', days, querySearch, page],
    queryFn: () => apiFetch<Result>('/api/reports/products/dead-stock?' + new URLSearchParams({
      days, q: querySearch, page: String(page), per_page: '100',
    }), { busy: 'none' }),
    enabled: preview,
  })
  const rows = query.data?.data ?? []
  const meta = query.data?.meta

  if (!preview) return <section className="dba-page">
    <header><h1>Dead Stock Report</h1><p>Products with stock on hand and no outbound posted sales in the selected period.</p></header>
    <div className="dba-options">
      <label>Inactivity period
        <UiSelect searchable={false} value={days}
          options={[30, 60, 90, 180, 365, 730].map(n => ({value: String(n), label: n + ' days'}))}
          onChange={setDays}/>
      </label>
      <label>Product / SKU search
        <input value={search} maxLength={180} onChange={e => setSearch(e.target.value)}
          placeholder="Optional product search"/>
      </label>
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
      <UiButton variant="info" label="View Report" onClick={() => {
        setPage(1); setQuerySearch(search.trim()); setPreview(true)
      }}/>
    </div>
  </section>

  return <section className="dba-page dba-preview">
    <ReportViewerToolbar
      ready={Boolean(meta)} busy={query.isFetching}
      search={search} onSearchChange={setSearch}
      onFind={() => {
        const term = search.toLowerCase().trim()
        if (term) Array.from(paper.current?.querySelectorAll('td') ?? [])
          .find(cell => cell.textContent?.toLowerCase().includes(term))?.scrollIntoView({block:'center'})
      }}
      zoom={zoom} onZoomChange={setZoom}
      onPrint={() => window.print()}
      onCopy={async () => {
        if (paper.current) await navigator.clipboard.writeText(paper.current.innerText)
      }}
      onExport={format => exportReport(format, rows)}
      onRefresh={() => void query.refetch()}
      onEditOptions={() => setPreview(false)}
      onClose={closeActiveTab}
    />
    {query.isLoading && <UiSkeleton label="Loading dead stock…"/>}
    {query.isError && <p role="alert">Unable to load dead stock. Check permissions or filters.</p>}
    {meta && <article className="dba-paper daily-report-sheet" ref={paper} style={{zoom: zoom + '%'}}>
      <header><h1>DEAD STOCK REPORT</h1>
        <p>{meta.warehouse.name} · No sale for {meta.days} days (since {meta.cutoff})</p>
        <p>Positive stock only · {meta.total} matching products · Page {meta.current_page} of {meta.last_page}</p>
      </header>
      <table><thead><tr>{headings.map(heading => <th key={heading}>{heading}</th>)}</tr></thead>
        <tbody>{rows.map(row => <tr key={row.product.ulid}>
          {cells(row).map((value, index) => <td key={index}>{value}</td>)}
        </tr>)}
        {rows.length === 0 && <tr><td colSpan={headings.length}>No matching dead stock.</td></tr>}
        </tbody></table>
      <p>Stock value is hidden without inventory cost permission. Exports contain only the currently displayed page.</p>
    </article>}
    {meta && <div className="dba-actions">
      <UiButton label="Previous" disabled={page <= 1 || query.isFetching}
        onClick={() => setPage(current => Math.max(1, current - 1))}/>
      <span>Page {meta.current_page} / {meta.last_page}</span>
      <UiButton label="Next" disabled={page >= meta.last_page || query.isFetching}
        onClick={() => setPage(current => current + 1)}/>
    </div>}
  </section>
}
