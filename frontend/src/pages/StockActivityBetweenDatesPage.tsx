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

type Activity = {
  product: { ulid: string; product_number: string; sku: string | null; name: string; category: string | null }
  opening: string; purchases: string; purchase_returns: string; sales: string
  sale_returns: string; other_movements: string; closing: string
}
type Report = {
  data: Activity[]
  meta: {
    from_date: string; to_date: string
    warehouse: { ulid: string; name: string }
    current_page: number; last_page: number; total: number
    totals: Omit<Activity,'product'>
    definition: string
  }
}
const dateLocal = () => {
  const d = new Date()
  return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,10)
}
const fields = ['opening','purchases','purchase_returns','sales','sale_returns','other_movements','closing'] as const
const titles = ['Product','Category','Opening','Purchases','P. Returns','Sales','S. Returns','Other','Closing']
const cells = (row: Activity) => [
  (row.product.sku ?? row.product.product_number) + ' · ' + row.product.name, row.product.category ?? '',
  ...fields.map(field=>row[field]),
]
function csvCell(value:string) {
  return '"' + (/^\s*[=+\-@\t\r]/.test(value)?"'":'') + value.replace(/"/g,'""') + '"'
}
function download(format:'txt'|'html'|'csv', rows:Activity[]) {
  const table = [titles,...rows.map(cells)]
  const value = format==='csv'?table.map(row=>row.map(csvCell).join(',')).join('\r\n')
    : format==='txt'?table.map(row=>row.join('\t')).join('\n')
      : '<!doctype html><meta charset="utf-8"><table><tbody>'+
        table.map(row=>'<tr>'+row.map(cell=>'<td>'+
          cell.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</td>').join('')+'</tr>').join('')+
        '</tbody></table>'
  const url=URL.createObjectURL(new Blob([value],{type:'text/plain;charset=utf-8'}))
  const a=document.createElement('a')
  a.href=url
  a.download='stock-activity-current-page.'+format
  a.click()
  URL.revokeObjectURL(url)
}
export function StockActivityBetweenDatesPage() {
  const {closeActiveTab}=useWorkspace()
  const [from,setFrom]=useState(dateLocal())
  const [to,setTo]=useState(dateLocal())
  const [search,setSearch]=useState('')
  const [selectedSearch,setSelectedSearch]=useState('')
  const [page,setPage]=useState(1)
  const [preview,setPreview]=useState(false)
  const [zoom,setZoom]=useState(100)
  const paper=useRef<HTMLElement>(null)
  const query=useQuery({
    queryKey:['reports','stock-activity',from,to,selectedSearch,page],
    queryFn:()=>apiFetch<Report>('/api/reports/products/stock-activity?'+new URLSearchParams({
      from_date:from,to_date:to,q:selectedSearch,page:String(page),per_page:'100',
    }),{busy:'none'}),
    enabled:preview&&!!from&&!!to&&from<=to,
  })
  const report=query.data
  if(!preview)return <section className="dba-page">
    <header><h1>Stock Activity Between Dates</h1><p>Posted stock movement reconciliation, by product.</p></header>
    <div className="dba-options">
      <label>Search Product / SKU <input value={search} maxLength={180} onChange={e=>setSearch(e.target.value)}/></label>
      <label>Date range
        <UiDateRangePicker
          fromInputProps={{type:'date',value:from,onChange:e=>setFrom(e.target.value)}}
          toInputProps={{type:'date',value:to,onChange:e=>setTo(e.target.value)}}/>
      </label>
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
      <UiButton variant="info" label="View Report" disabled={!from||!to||from>to}
        onClick={()=>{setSelectedSearch(search.trim());setPage(1);setPreview(true)}}/>
    </div>
  </section>
  return <section className="dba-page dba-preview">
    <ReportViewerToolbar ready={!!report} busy={query.isFetching}
      search={search} onSearchChange={setSearch}
      onFind={()=>{const needle=search.trim().toLowerCase();if(needle)Array.from(paper.current?.querySelectorAll('td')??[])
        .find(td=>td.textContent?.toLowerCase().includes(needle))?.scrollIntoView({block:'center'})}}
      zoom={zoom} onZoomChange={setZoom}
      onPrint={()=>window.print()}
      onCopy={async()=>{if(paper.current)await navigator.clipboard.writeText(paper.current.innerText)}}
      onExport={format=>download(format,report?.data??[])}
      onRefresh={()=>void query.refetch()}
      onEditOptions={()=>setPreview(false)}
      onClose={closeActiveTab}/>
    {query.isLoading&&<UiSkeleton label="Loading stock activity…"/>}
    {query.isError&&<p role="alert">Stock activity could not be generated.</p>}
    {report&&<article className="dba-paper daily-report-sheet" ref={paper} style={{zoom:zoom+'%'}}>
      <header><h1>STOCK ACTIVITY BETWEEN DATES</h1>
        <p>{report.meta.warehouse.name} · {report.meta.from_date} to {report.meta.to_date}</p>
        <p>{report.meta.total} products · Page {report.meta.current_page} of {report.meta.last_page}</p>
      </header>
      <table><thead><tr>{titles.map(title=><th key={title}>{title}</th>)}</tr></thead>
        <tbody>{report.data.map(row=><tr key={row.product.ulid}>{cells(row).map((cell,i)=><td key={i}>{cell}</td>)}</tr>)}
          {report.data.length===0&&<tr><td colSpan={titles.length}>No posted movements.</td></tr>}
        </tbody>
      </table>
      <p>All matching products: {fields.map(field=><span key={field}>{field.replace(/_/g,' ')}: {report.meta.totals[field]} · </span>)}</p>
      <p>{report.meta.definition} Export contains current page only.</p>
    </article>}
    {report&&<div className="dba-actions">
      <UiButton label="Previous" disabled={page<=1||query.isFetching} onClick={()=>setPage(p=>Math.max(1,p-1))}/>
      <span>Page {report.meta.current_page} / {report.meta.last_page}</span>
      <UiButton label="Next" disabled={page>=report.meta.last_page||query.isFetching} onClick={()=>setPage(p=>p+1)}/>
    </div>}
  </section>
}
