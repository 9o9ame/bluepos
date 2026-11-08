import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiFetch } from '../api/client'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiDateRangePicker } from '../components/ui/UiDateRangePicker'
import { UiButton } from '../components/ui/UiButton'
import { UiSkeleton } from '../components/ui/UiSkeleton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './DailyBusinessActivityPage.css'
import './DailyReportSheets.css'

type GridRow = {
  product: { ulid: string; product_number: string; sku: string | null; name: string }
  opening: string; debit: string; credit: string; closing: string
}
type GridResult = {
  data: GridRow[]
  meta: {
    warehouse: { ulid: string; name: string }
    from_date: string; to_date: string
    current_page: number; last_page: number; total: number
    totals: { opening: string; debit: string; credit: string; closing: string }
  }
}
const columns = ['Product / Item', 'Opening', 'Debit Qty', 'Credit Qty', 'Closing']
const today = () => {
  const now = new Date()
  return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}
const cells = (row: GridRow) => [
  (row.product.sku ?? row.product.product_number) + ' · ' + row.product.name,
  row.opening, row.debit, row.credit, row.closing,
]
const escapeHtml = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;')
  .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
const csvCell = (s: string) => '"' + (/^\s*[=+\-@\t\r]/.test(s) ? "'" : '') + s.replace(/"/g, '""') + '"'
function download(rows: string[][], kind: 'txt' | 'html' | 'csv') {
  const result = kind === 'csv' ? rows.map(line => line.map(csvCell).join(',')).join('\r\n')
    : kind === 'txt' ? rows.map(line => line.join('\t')).join('\n')
      : '<!doctype html><meta charset="utf-8"><table><tbody>' + rows.map(line =>
        '<tr>' + line.map(v => '<td>' + escapeHtml(v) + '</td>').join('') + '</tr>').join('') +
        '</tbody></table>'
  const objectUrl = URL.createObjectURL(new Blob([result], {type:'text/plain;charset=utf-8'}))
  const anchor = document.createElement('a')
  anchor.href = objectUrl
  anchor.download = 'bluepos-product-gl-current-page.' + kind
  anchor.click()
  URL.revokeObjectURL(objectUrl)
}
export function ProductGlGridPage() {
  const { closeActiveTab } = useWorkspace()
  const [fromDate, setFromDate] = useState(today())
  const [toDate, setToDate] = useState(today())
  const [search, setSearch] = useState('')
  const [appliedSearch, setAppliedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [preview, setPreview] = useState(false)
  const [zoom, setZoom] = useState(100)
  const paper = useRef<HTMLElement>(null)
  const query = useQuery({
    queryKey: ['reports', 'product-gl-grid', fromDate, toDate, appliedSearch, page],
    queryFn: () => apiFetch<GridResult>('/api/reports/products/gl-grid?' + new URLSearchParams({
      from_date: fromDate, to_date: toDate, q: appliedSearch, page: String(page), per_page: '100',
    }), {busy:'none'}),
    enabled: preview && Boolean(fromDate && toDate && fromDate <= toDate),
  })
  const report = query.data
  const totals = report?.meta.totals

  if (!preview) return <section className="dba-page">
    <header><h1>Product GL (Grid)</h1><p>Opening, debit, credit and closing quantities for the selected date range.</p></header>
    <div className="dba-options">
      <label>Product or SKU
        <input value={search} maxLength={180} onChange={e => setSearch(e.target.value)} placeholder="Optional search"/>
      </label>
      <label>Date range
        <UiDateRangePicker
          fromInputProps={{type:'date',value:fromDate,onChange:e=>setFromDate(e.target.value)}}
          toInputProps={{type:'date',value:toDate,onChange:e=>setToDate(e.target.value)}}/>
      </label>
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
      <UiButton variant="info" label="View Report" disabled={!fromDate || !toDate || fromDate > toDate}
        onClick={() => { setAppliedSearch(search.trim()); setPage(1); setPreview(true) }}/>
    </div>
  </section>

  return <section className="dba-page dba-preview">
    <ReportViewerToolbar ready={Boolean(report)} busy={query.isFetching}
      search={search} onSearchChange={setSearch}
      onFind={() => {const text=search.toLowerCase().trim();if(text)Array.from(paper.current?.querySelectorAll('td')??[])
        .find(x=>x.textContent?.toLowerCase().includes(text))?.scrollIntoView({block:'center'})}}
      zoom={zoom} onZoomChange={setZoom}
      onPrint={() => window.print()}
      onCopy={async () => {if(paper.current)await navigator.clipboard.writeText(paper.current.innerText)}}
      onExport={type => download([columns, ... (report?.data ?? []).map(cells)], type)}
      onRefresh={() => void query.refetch()}
      onEditOptions={() => setPreview(false)}
      onClose={closeActiveTab}/>
    {query.isLoading && <UiSkeleton label="Loading product GL…"/>}
    {query.isError && <p role="alert">Unable to load product GL. Check dates and permissions.</p>}
    {report && <article ref={paper} className="dba-paper daily-report-sheet" style={{zoom:zoom+'%'}}>
      <header>
        <h1>PRODUCT GL (GRID)</h1>
        <p>{report.meta.warehouse.name} · {report.meta.from_date} to {report.meta.to_date}</p>
        <p>{report.meta.total} products · Page {report.meta.current_page} / {report.meta.last_page}</p>
      </header>
      <table><thead><tr>{columns.map(x=><th key={x}>{x}</th>)}</tr></thead>
      <tbody>{report.data.map(row=><tr key={row.product.ulid}>{cells(row).map((value,i)=><td key={i}>{value}</td>)}</tr>)}
        {report.data.length===0 && <tr><td colSpan={5}>No movements in selected scope.</td></tr>}
      </tbody>
      </table>
      {totals && <p>All matching products — Opening: {totals.opening} · Debit: {totals.debit} · Credit: {totals.credit} · Closing: {totals.closing}</p>}
      <p>Debit/credit are signed inward/outward movement buckets. Export contains only the displayed page.</p>
    </article>}
    {report && <div className="dba-actions">
      <UiButton label="Previous" disabled={page <= 1 || query.isFetching} onClick={() => setPage(p=>Math.max(1,p-1))}/>
      <span>Page {report.meta.current_page} of {report.meta.last_page}</span>
      <UiButton label="Next" disabled={page >= report.meta.last_page || query.isFetching} onClick={() => setPage(p=>p+1)}/>
    </div>}
  </section>
}
