import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiFetch } from '../api/client'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiDatePicker } from '../components/ui/UiDatePicker'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { UiSkeleton } from '../components/ui/UiSkeleton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useAuth } from '../features/auth/AuthProvider'
import './DailyBusinessActivityPage.css'
import './DailyReportSheets.css'

type ExpiryPurchaseRow = {
  purchase_ulid: string
  purchase_number: string
  purchase_line_ulid: string | null
  purchase_date: string | null
  product: { ulid: string; product_number: string | null; name: string }
  batch_number: string | null
  expiry_date: string
  days_to_expiry: number
  purchased_base_quantity: string
  remaining_quantity: null
}
type ExpiryPurchaseResult = {
  data: ExpiryPurchaseRow[]
  meta: {
    report_type: 'purchase_expiry_reference_not_remaining_stock'
    report_date: string
    horizon_days: number
    include_expired: boolean
    warehouse: { ulid: string; name: string }
    current_page: number; last_page: number; per_page: number; total: number
    remaining_stock_available: false
    limitation: string
  }
}
const headings = ['Expiry Date','Batch #','Purchase #','Product #','Product','Purchased Base Qty','Remaining Stock','Days to Expiry']
const cells = (row: ExpiryPurchaseRow) => [
  row.expiry_date, row.batch_number ?? '—', row.purchase_number,
  row.product.product_number ?? '', row.product.name, row.purchased_base_quantity,
  'Unavailable', String(row.days_to_expiry),
]
const dateLocal = () => {
  const d = new Date()
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0,10)
}
const escapeHtml = (s: string) => s.replace(/&/g,'&amp;').replace(/</g,'&lt;')
  .replace(/>/g,'&gt;').replace(/"/g,'&quot;')
const csv = (s: string) => '"' + (/^\s*[=+\-@\t\r]/.test(s) ? "'" : '') + s.replace(/"/g,'""') + '"'
function exportPage(format: 'csv' | 'txt' | 'html', data: ExpiryPurchaseRow[]) {
  const rows = [headings, ...data.map(cells)]
  const text = format === 'csv' ? rows.map(line => line.map(csv).join(',')).join('\r\n')
    : format === 'txt' ? rows.map(line => line.join('\t')).join('\n')
    : '<!doctype html><meta charset="utf-8"><table><tbody>' +
      rows.map(line => '<tr>' + line.map(v => '<td>' + escapeHtml(v) + '</td>').join('') + '</tr>').join('') +
      '</tbody></table>'
  const url = URL.createObjectURL(new Blob([text], {type:format==='csv'?'text/csv;charset=utf-8':'text/plain;charset=utf-8'}))
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = 'purchase-expiry-reference-current-page.' + format
  anchor.click()
  URL.revokeObjectURL(url)
}
export function PurchaseExpiryReferenceReportPage() {
  const {session} = useAuth()
  const {closeActiveTab} = useWorkspace()
  const [reportDate,setReportDate] = useState(dateLocal())
  const [horizon,setHorizon] = useState('365')
  const [includeExpired,setIncludeExpired] = useState(false)
  const [search,setSearch] = useState('')
  const [selectedSearch,setSelectedSearch] = useState('')
  const [page,setPage] = useState(1)
  const [preview,setPreview] = useState(false)
  const [zoom,setZoom] = useState(100)
  const paper = useRef<HTMLElement>(null)
  const query = useQuery({
    queryKey:[session?.tenant.ulid,session?.branch.ulid,session?.warehouse.ulid,
      'reports','purchase-expiry-reference',reportDate,horizon,includeExpired,selectedSearch,page],
    queryFn:() => apiFetch<ExpiryPurchaseResult>('/api/reports/products/purchase-expiry-reference?' + new URLSearchParams({
      report_date:reportDate,horizon_days:horizon,include_expired:includeExpired?'1':'0',
      q:selectedSearch,page:String(page),per_page:'100',
    }),{busy:'none'}),
    enabled:preview && Boolean(reportDate),
  })
  const result = query.data
  if(!preview) return <section className="dba-page">
    <header><h1>Purchase Expiry Reference</h1>
      <p>Posted purchase lines with batch and expiry dates. This is not an available or remaining-stock report.</p>
    </header>
    <div className="dba-options">
      <label>Expiry Reference Date
        <UiDatePicker type="date" value={reportDate} onChange={e=>setReportDate(e.target.value)}/>
      </label>
      <label>Lookahead
        <UiSelect searchable={false} value={horizon}
          options={[30,90,180,365,730,1825,7300].map(days=>({value:String(days),label:days===7300?'20 years':days+' days'}))}
          onChange={setHorizon}/>
      </label>
      <label>Search Product / Batch #
        <input value={search} maxLength={180} onChange={e=>setSearch(e.target.value)}/>
      </label>
      <label><input type="checkbox" checked={includeExpired} onChange={e=>setIncludeExpired(e.target.checked)}/>
        Include already expired purchase lines
      </label>
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
      <UiButton variant="info" label="View Report" disabled={!reportDate}
        onClick={()=>{setPage(1);setSelectedSearch(search.trim());setPreview(true)}}/>
    </div>
  </section>

  return <section className="dba-page dba-preview">
    <ReportViewerToolbar ready={Boolean(result)} busy={query.isFetching}
      search={search} onSearchChange={setSearch}
      onFind={()=>{const term=search.trim().toLowerCase();if(term)Array.from(paper.current?.querySelectorAll('td')??[])
        .find(cell=>cell.textContent?.toLowerCase().includes(term))?.scrollIntoView({block:'center'})}}
      zoom={zoom} onZoomChange={setZoom}
      onPrint={()=>window.print()}
      onCopy={async()=>{if(paper.current)await navigator.clipboard.writeText(paper.current.innerText)}}
      onExport={format=>exportPage(format,result?.data??[])}
      onRefresh={()=>void query.refetch()}
      onEditOptions={()=>setPreview(false)}
      onClose={closeActiveTab}/>
    {query.isLoading&&<UiSkeleton label="Loading purchase expiry references…"/>}
    {query.isError&&<p role="alert">Unable to generate purchase expiry reference. Date range or source size may be unsupported.</p>}
    {result&&<article className="dba-paper daily-report-sheet" ref={paper} style={{zoom:zoom+'%'}}>
      <header><h1>PURCHASE EXPIRY REFERENCE — NOT REMAINING STOCK</h1>
        <p>{result.meta.warehouse.name} · Reference {result.meta.report_date} · Next {result.meta.horizon_days} days</p>
        <p>{result.meta.total} purchase lines · Page {result.meta.current_page} of {result.meta.last_page}</p>
      </header>
      <table><thead><tr>{headings.map(h=><th key={h}>{h}</th>)}</tr></thead>
        <tbody>{result.data.map((row,index)=><tr key={row.purchase_line_ulid??row.purchase_ulid+':'+index}>
          {cells(row).map((cell,i)=><td key={i}>{cell}</td>)}
        </tr>)}
          {result.data.length===0&&<tr><td colSpan={headings.length}>No purchase expiry references matched.</td></tr>}
        </tbody>
      </table>
      <p>{result.meta.limitation}</p>
      <p>Product-level remaining inventory cannot be assigned to purchase batches until a lot allocation layer is implemented. Export contains current page only.</p>
    </article>}
    {result&&<div className="dba-actions">
      <UiButton label="Previous" disabled={page<=1||query.isFetching} onClick={()=>setPage(p=>Math.max(1,p-1))}/>
      <span>Page {result.meta.current_page} / {result.meta.last_page}</span>
      <UiButton label="Next" disabled={page>=result.meta.last_page||query.isFetching}
        onClick={()=>setPage(p=>p+1)}/>
    </div>}
  </section>
}
