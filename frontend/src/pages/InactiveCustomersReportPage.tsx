import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiFetch } from '../api/client'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiButton } from '../components/ui/UiButton'
import { UiSkeleton } from '../components/ui/UiSkeleton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useAuth } from '../features/auth/AuthProvider'
import './DailyBusinessActivityPage.css'
import './DailyReportSheets.css'

type CustomerRow={
  ulid:string;code:string|null;name:string;area:string|null
  address:string|null;mobile:string|null;phone:string|null
  last_sale_date:string|null;days_inactive:number|null
}
type Result={data:CustomerRow[];meta:{
  as_of:string;min_days:number;max_days:number;include_never:boolean
  branch_ulid:string;current_page:number;last_page:number;total:number;definition:string
}}
const today=()=>{const d=new Date();return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,10)}
const columns=['Code','Customer','Area','Address','Phone','Last Sale','Inactive Days']
const fields=(r:CustomerRow)=>[r.code??'',r.name,r.area??'',r.address??'',r.phone??r.mobile??'',
  r.last_sale_date??'Never recorded',r.days_inactive===null?'—':String(r.days_inactive)]
const csv=(s:string)=>'"'+(/^\s*[=+\-@\t\r]/.test(s)?"'":'')+s.replace(/"/g,'""')+'"'
const html=(s:string)=>s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')
function exportRows(format:'txt'|'csv'|'html',rows:CustomerRow[]){
  const table=[columns,...rows.map(fields)]
  const text=format==='csv'?table.map(row=>row.map(csv).join(',')).join('\r\n')
    :format==='txt'?table.map(row=>row.join('\t')).join('\n')
      :'<!doctype html><meta charset="utf-8"><table><tbody>'+
        table.map(row=>'<tr>'+row.map(cell=>'<td>'+html(cell)+'</td>').join('')+'</tr>').join('')+
        '</tbody></table>'
  const url=URL.createObjectURL(new Blob([text],{type:'text/plain;charset=utf-8'}))
  const link=document.createElement('a');link.href=url;link.download='inactive-customers-current-page.'+format;link.click()
  URL.revokeObjectURL(url)
}
export function InactiveCustomersReportPage(){
  const { session } = useAuth()
  const {closeActiveTab}=useWorkspace()
  const [asOf,setAsOf]=useState(today())
  const [minDays,setMinDays]=useState(1)
  const [maxDays,setMaxDays]=useState(30)
  const [includeNever,setIncludeNever]=useState(false)
  const [search,setSearch]=useState('')
  const [selected,setSelected]=useState('')
  const [page,setPage]=useState(1)
  const [preview,setPreview]=useState(false)
  const [zoom,setZoom]=useState(100)
  const paper=useRef<HTMLElement>(null)
  const query=useQuery({
    queryKey:[session?.tenant.ulid,session?.branch.ulid,session?.warehouse.ulid,'reports','inactive-customers',asOf,minDays,maxDays,includeNever,selected,page],
    queryFn:()=>apiFetch<Result>('/api/reports/accounts/inactive-customers?'+new URLSearchParams({
      as_of:asOf,min_days:String(minDays),max_days:String(maxDays),
      include_never:includeNever?'1':'0',q:selected,page:String(page),per_page:'100',
    }),{busy:'none'}),
    enabled:preview&&!!asOf&&minDays>=1&&maxDays>=minDays&&maxDays<=3650,
  })
  const report=query.data
  if(!preview)return <section className="dba-page">
    <header><h1>Inactive Customers Report</h1><p>Customers whose last posted sale falls within an inactivity range.</p></header>
    <div className="dba-options">
      <label>As Of <input type="date" value={asOf} onChange={e=>setAsOf(e.target.value)}/></label>
      <label>Inactive From (days)
        <input type="number" min={1} max={3650} value={minDays} onChange={e=>setMinDays(Number(e.target.value))}/>
      </label>
      <label>Inactive To (days)
        <input type="number" min={1} max={3650} value={maxDays} onChange={e=>setMaxDays(Number(e.target.value))}/>
      </label>
      <label>Search <input value={search} onChange={e=>setSearch(e.target.value)} maxLength={180}/></label>
      <label><input type="checkbox" checked={includeNever} onChange={e=>setIncludeNever(e.target.checked)}/>
        Include customers without recorded sales
      </label>
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
      <UiButton variant="info" label="View Report"
        disabled={!asOf||minDays<1||maxDays<minDays||maxDays>3650}
        onClick={()=>{setPage(1);setSelected(search.trim());setPreview(true)}}/>
    </div>
  </section>
  return <section className="dba-page dba-preview">
    <ReportViewerToolbar ready={!!report} busy={query.isFetching}
      search={search} onSearchChange={setSearch}
      onFind={()=>{let needle=search.trim().toLowerCase();if(needle)Array.from(paper.current?.querySelectorAll('td')??[])
        .find(td=>td.textContent?.toLowerCase().includes(needle))?.scrollIntoView({block:'center'})}}
      zoom={zoom} onZoomChange={setZoom}
      onPrint={()=>window.print()}
      onCopy={async()=>{if(paper.current)await navigator.clipboard.writeText(paper.current.innerText)}}
      onExport={format=>exportRows(format,report?.data??[])}
      onRefresh={()=>void query.refetch()}
      onEditOptions={()=>setPreview(false)}
      onClose={closeActiveTab}/>
    {query.isLoading&&<UiSkeleton label="Loading inactive customers…"/>}
    {query.isError&&<p role="alert">Unable to load customer activity. Check permissions or range.</p>}
    {report&&<article ref={paper} className="dba-paper daily-report-sheet" style={{zoom:zoom+'%'}}>
      <header><h1>INACTIVE CUSTOMERS REPORT</h1>
        <p>As of {report.meta.as_of} · Inactive {report.meta.min_days} to {report.meta.max_days} days</p>
        <p>{report.meta.total} customers · Page {report.meta.current_page} of {report.meta.last_page}</p>
      </header>
      <table><thead><tr>{columns.map(x=><th key={x}>{x}</th>)}</tr></thead>
        <tbody>{report.data.map(row=><tr key={row.ulid}>{fields(row).map((cell,i)=><td key={i}>{cell}</td>)}</tr>)}
          {report.data.length===0&&<tr><td colSpan={columns.length}>No customers matched.</td></tr>}</tbody>
      </table>
      <p>{report.meta.definition} Export contains current page only.</p>
    </article>}
    {report&&<div className="dba-actions">
      <UiButton label="Previous" disabled={page<=1||query.isFetching} onClick={()=>setPage(p=>Math.max(1,p-1))}/>
      <span>Page {report.meta.current_page} / {report.meta.last_page}</span>
      <UiButton label="Next" disabled={page>=report.meta.last_page||query.isFetching} onClick={()=>setPage(p=>p+1)}/>
    </div>}
  </section>
}
