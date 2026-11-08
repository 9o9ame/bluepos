import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiFetch } from '../api/client'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiDateRangePicker } from '../components/ui/UiDateRangePicker'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { UiSkeleton } from '../components/ui/UiSkeleton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useAuth } from '../features/auth/AuthProvider'
import './DailyBusinessActivityPage.css'
import './DailyReportSheets.css'

type Mode = 'quantity' | 'document_cost' | 'sales_stock'
type Activity = {
  product: { ulid: string; product_number: string; sku: string | null; name: string; category: string | null }
  opening: string; purchases: string; purchase_returns: string; sales: string
  sale_returns: string; other_movements: string; closing: string
  purchase_cost: string | null; purchase_return_cost: string | null
  sale_cost: string | null; sale_return_cost: string | null; closing_cost: string | null
}
type Report = {
  data: Activity[]
  meta: {
    from_date: string; to_date: string
    warehouse: { ulid: string; name: string }
    current_page: number; last_page: number; total: number
    totals: Omit<Activity,'product'>
    definition: string; cost_basis: string
  }
}
const dateLocal = () => {
  const d = new Date()
  return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,10)
}
const fields = ['opening','purchases','purchase_returns','sales','sale_returns','other_movements','closing'] as const
const titlesByMode: Record<Mode, string[]> = {
  quantity: ['Product','Category','Opening','Purchases','P. Returns','Sales','S. Returns','Other','Closing'],
  document_cost: ['Product','Category','PSJ Qty','PSJ Stock Cost','PRJ Qty','PRJ Stock Cost',
    'SLJ Qty','SLJ Stock Cost','SRJ Qty','SRJ Stock Cost','Closing Qty','Closing Stock Cost'],
  sales_stock: ['Product','Category','Opening Qty','Purchases Qty','P. Return Qty',
    'Sales Qty','S. Return Qty','Other Qty','Closing Qty','Closing Stock Cost'],
}
const cells = (row: Activity, mode: Mode) => {
  const label = (row.product.sku ?? row.product.product_number) + ' · ' + row.product.name
  const base = [label, row.product.category ?? '']
  if (mode === 'document_cost') return [...base,
    row.purchases, row.purchase_cost ?? 'Unavailable',
    row.purchase_returns, row.purchase_return_cost ?? 'Unavailable',
    row.sales, row.sale_cost ?? 'Unavailable',
    row.sale_returns, row.sale_return_cost ?? 'Unavailable',
    row.closing, row.closing_cost ?? 'Unavailable']
  if (mode === 'sales_stock') return [...base,
    row.opening,row.purchases,row.purchase_returns,row.sales,
    row.sale_returns,row.other_movements,row.closing,row.closing_cost ?? 'Unavailable']
  return [...base,...fields.map(field=>row[field])]
}
function csvCell(value:string) {
  return '"' + (/^\s*[=+\-@\t\r]/.test(value)?"'":'') + value.replace(/"/g,'""') + '"'
}
function download(format:'txt'|'html'|'csv', rows:Activity[], mode:Mode) {
  const table = [titlesByMode[mode],...rows.map(row=>cells(row,mode))]
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
  const { session } = useAuth()
  const {closeActiveTab}=useWorkspace()
  const [mode,setMode]=useState<Mode>('quantity')
  const [from,setFrom]=useState(dateLocal())
  const [to,setTo]=useState(dateLocal())
  const [search,setSearch]=useState('')
  const [selectedSearch,setSelectedSearch]=useState('')
  const [page,setPage]=useState(1)
  const [preview,setPreview]=useState(false)
  const [zoom,setZoom]=useState(100)
  const paper=useRef<HTMLElement>(null)
  const query=useQuery({
    queryKey:[session?.tenant.ulid,session?.branch.ulid,session?.warehouse.ulid,'reports','stock-activity',from,to,selectedSearch,page],
    queryFn:()=>apiFetch<Report>('/api/reports/products/stock-activity?'+new URLSearchParams({
      from_date:from,to_date:to,q:selectedSearch,page:String(page),per_page:'100',
    }),{busy:'none'}),
    enabled:preview&&!!from&&!!to&&from<=to,
  })
  const report=query.data
  if(!preview)return <section className="dba-page">
    <header><h1>Stock Activity Between Dates</h1><p>Posted stock movement reconciliation, by product.</p></header>
    <div className="dba-options">
      <label>Report Format
        <UiSelect searchable={false} value={mode} onChange={value=>setMode(value as Mode)}
          options={[{value:'quantity',label:'Stock Quantity Between Dates'},
            {value:'document_cost',label:'Document Wise Stock (Movement Cost)'},
            {value:'sales_stock',label:'Sales & Stock Statement'}]}/>
      </label>
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
      onExport={format=>download(format,report?.data??[],mode)}
      onRefresh={()=>void query.refetch()}
      onEditOptions={()=>setPreview(false)}
      onClose={closeActiveTab}/>
    {query.isLoading&&<UiSkeleton label="Loading stock activity…"/>}
    {query.isError&&<p role="alert">Stock activity could not be generated.</p>}
    {report&&<article className="dba-paper daily-report-sheet" ref={paper} style={{zoom:zoom+'%'}}>
      <header><h1>{mode === 'document_cost' ? 'DOCUMENT WISE STOCK (COST)' : mode === 'sales_stock'
        ? 'SALES AND STOCK STATEMENT' : 'STOCK ACTIVITY BETWEEN DATES'}</h1>
        <p>{report.meta.warehouse.name} · {report.meta.from_date} to {report.meta.to_date}</p>
        <p>{report.meta.total} products · Page {report.meta.current_page} of {report.meta.last_page}</p>
      </header>
      <table><thead><tr>{titlesByMode[mode].map(title=><th key={title}>{title}</th>)}</tr></thead>
        <tbody>{report.data.map(row=><tr key={row.product.ulid}>{cells(row,mode).map((cell,i)=><td key={i}>{cell}</td>)}</tr>)}
          {report.data.length===0&&<tr><td colSpan={titlesByMode[mode].length}>No posted movements.</td></tr>
        </tbody>
      </table>
      <p>All matching products: {fields.map(field=><span key={field}>{field.replace(/_/g,' ')}: {report.meta.totals[field]} · </span>)}</p>
      <p>{report.meta.definition} {mode !== 'quantity' ? report.meta.cost_basis : ''} Export contains current page only.</p>
    </article>}
    {report&&<div className="dba-actions">
      <UiButton label="Previous" disabled={page<=1||query.isFetching} onClick={()=>setPage(p=>Math.max(1,p-1))}/>
      <span>Page {report.meta.current_page} / {report.meta.last_page}</span>
      <UiButton label="Next" disabled={page>=report.meta.last_page||query.isFetching} onClick={()=>setPage(p=>p+1)}/>
    </div>}
  </section>
}
