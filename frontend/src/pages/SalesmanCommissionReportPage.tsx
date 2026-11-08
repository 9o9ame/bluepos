import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiFetch } from '../api/client'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiDateRangePicker } from '../components/ui/UiDateRangePicker'
import { UiButton } from '../components/ui/UiButton'
import { UiSkeleton } from '../components/ui/UiSkeleton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useAuth } from '../features/auth/AuthProvider'
import './DailyBusinessActivityPage.css'
import './DailyReportSheets.css'

type CommissionRow = {
  document_ulid:string;line_ulid:string|null;document_number:string;document_type:string;date:string
  salesman:{ulid:string|null;name:string|null}|null
  customer:string|null
  product:{ulid:string;product_number:string|null;name:string|null}
  base_quantity:string
  line_amount_ex_tax_before_invoice_discount:string
  cost_amount:string|null
  commission_percent:string|null
  commission_per_base_unit:string|null
  commission_amount:string|null
  commission_unavailable_reason:string
}
type Result={data:CommissionRow[];meta:{
  from_date:string;to_date:string;warehouse:{ulid:string;name:string}
  total:number;current_page:number;last_page:number;per_page:number
  commission_calculation_available:boolean;limitations:string[]
  selected_salesman_ulid:string|null
  totals:{base_quantity:string;line_amount_ex_tax_before_invoice_discount:string}
}}
const dateLocal=()=>{const d=new Date();return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,10)}
const headers=['Sale #','Date','Type','Salesman','Customer','Product #','Product','Sale Qty',
  'Line Sale (Ex Tax)*','Cost','Comm %','Com / Base Unit','Commission']
const cells=(row:CommissionRow)=>[
  row.document_number,row.date,row.document_type,row.salesman?.name??'—',row.customer??'—',
  row.product.product_number??'',row.product.name??'',row.base_quantity,
  row.line_amount_ex_tax_before_invoice_discount,row.cost_amount??'Unavailable',
  row.commission_percent??'Unconfigured',row.commission_per_base_unit??'Unconfigured',
  row.commission_amount??'Unavailable',
]
const html=(s:string)=>s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')
const csv=(s:string)=>'"'+(/^\s*[=+\-@\t\r]/.test(s)?"'":'')+s.replace(/"/g,'""')+'"'
function download(kind:'csv'|'txt'|'html',rows:CommissionRow[]){
  const all=[headers,...rows.map(cells)]
  const data=kind==='csv'?all.map(row=>row.map(csv).join(',')).join('\r\n')
    :kind==='txt'?all.map(row=>row.join('\t')).join('\n')
      :'<!doctype html><meta charset="utf-8"><table><tbody>'+
       all.map(row=>'<tr>'+row.map(value=>'<td>'+html(value)+'</td>').join('')+'</tr>').join('')+'</tbody></table>'
  const url=URL.createObjectURL(new Blob([data],{type:'text/plain;charset=utf-8'}))
  const anchor=document.createElement('a');anchor.href=url;anchor.download='salesman-sales-register-page.'+kind
  anchor.click();URL.revokeObjectURL(url)
}
export function SalesmanCommissionReportPage(){
  const {session}=useAuth()
  const {closeActiveTab}=useWorkspace()
  const [from,setFrom]=useState(dateLocal())
  const [to,setTo]=useState(dateLocal())
  const [page,setPage]=useState(1)
  const [preview,setPreview]=useState(false)
  const [search,setSearch]=useState('')
  const [zoom,setZoom]=useState(100)
  const paper=useRef<HTMLElement>(null)
  const query=useQuery({
    queryKey:[session?.tenant.ulid,session?.branch.ulid,session?.warehouse.ulid,
      'reports','salesman-commission',from,to,page],
    queryFn:()=>apiFetch<Result>('/api/reports/sales/salesman-commission?'+new URLSearchParams({
      from_date:from,to_date:to,page:String(page),per_page:'100',
    }),{busy:'none'}),
    enabled:preview&&Boolean(from&&to&&from<=to),
  })
  const report=query.data
  if(!preview)return <section className="dba-page">
    <header><h1>Salesman Commissions</h1>
      <p>Posted sales and returns by assigned salesman. Actual commissions require configured terms.</p></header>
    <div className="dba-options">
      <label>Selection
        <UiDateRangePicker
          fromInputProps={{type:'date',value:from,onChange:e=>setFrom(e.target.value)}}
          toInputProps={{type:'date',value:to,onChange:e=>setTo(e.target.value)}}/>
      </label>
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
      <UiButton variant="info" label="View" disabled={!from||!to||from>to}
        onClick={()=>{setPage(1);setPreview(true)}}/>
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
      onExport={kind=>download(kind,report?.data??[])}
      onRefresh={()=>void query.refetch()}
      onEditOptions={()=>setPreview(false)}
      onClose={closeActiveTab}/>
    {query.isLoading&&<UiSkeleton label="Loading assigned sales…"/>}
    {query.isError&&<p role="alert">Unable to load salesman sales. Limit date range to 31 days.</p>}
    {report&&<article className="dba-paper daily-report-sheet" ref={paper} style={{zoom:zoom+'%'}}>
      <header><h1>SALESMAN COMMISSIONS — SOURCE REGISTER</h1>
        <p>{report.meta.warehouse.name} · {report.meta.from_date} to {report.meta.to_date}</p>
        <p>{report.meta.total} posted source lines · Page {report.meta.current_page} / {report.meta.last_page}</p>
      </header>
      <table><thead><tr>{headers.map(title=><th key={title}>{title}</th>)}</tr></thead>
        <tbody>{report.data.map((row,index)=><tr key={row.document_ulid+':'+(row.line_ulid??String(index))}>
          {cells(row).map((cell,j)=><td key={j}>{cell}</td>)}
        </tr>)}
          {report.data.length===0&&<tr><td colSpan={headers.length}>No assigned posted sales matched.</td></tr>}</tbody>
      </table>
      <p>All matching posted lines — Signed Qty: {report.meta.totals.base_quantity} · Signed Line Sale (Ex Tax): {report.meta.totals.line_amount_ex_tax_before_invoice_discount}</p>
      <p>* Line amount excludes line tax but does not allocate invoice-level discounts. Not a payable commission basis.</p>
      {report.meta.limitations.map(note=><p key={note}>{note}</p>)}
      <p>Commission percentage and per-unit rates are unconfigured, so payable commission is deliberately unavailable.</p>
    </article>}
    {report&&<div className="dba-actions">
      <UiButton label="Previous" disabled={page<=1||query.isFetching} onClick={()=>setPage(p=>Math.max(1,p-1))}/>
      <span>Page {report.meta.current_page} / {report.meta.last_page}</span>
      <UiButton label="Next" disabled={page>=report.meta.last_page||query.isFetching} onClick={()=>setPage(p=>p+1)}/>
    </div>}
  </section>
}
