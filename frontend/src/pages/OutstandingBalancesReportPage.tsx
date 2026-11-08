import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { apiFetch } from '../api/client'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiButton } from '../components/ui/UiButton'
import { UiDatePicker } from '../components/ui/UiDatePicker'
import { UiSelect } from '../components/ui/UiSelect'
import { UiSkeleton } from '../components/ui/UiSkeleton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useAuth } from '../features/auth/AuthProvider'
import './DailyBusinessActivityPage.css'
import './DailyReportSheets.css'

type Mode = 'address' | 'contact' | 'current_future'
type PartyType = 'customer' | 'vendor'
type BalanceRow = {
  account_ulid: string; party_ulid: string | null; code: string | null; name: string
  address: string | null; mobile: string | null; phone: string | null; ntn: string | null
  license_number: string | null; license_expires_on: string | null; last_sale_date: string | null
  balance: string; current_due: string | null; future_due: string | null
  last_receipt_date: string | null; days_since_receipt: number | null; received_amount: string | null
}
type BalanceReport = {
  data: BalanceRow[]
  meta: {
    as_of: string; party_type: PartyType; mode: Mode; branch_ulid: string
    current_page: number; last_page: number; total: number
    page_balance_total: string; limitations: string[]
  }
}
const isoToday=()=>{
  const date=new Date()
  return new Date(date.getTime()-date.getTimezoneOffset()*60000).toISOString().slice(0,10)
}
const fields: Record<Mode, {key:keyof BalanceRow;label:string}[]> = {
  address:[
    {key:'code',label:'Code'}, {key:'name',label:'Account / Party'}, {key:'address',label:'Address'},
    {key:'ntn',label:'NTN'}, {key:'license_number',label:'License #'},
    {key:'license_expires_on',label:'License Expiry'}, {key:'balance',label:'Balance'},
  ],
  contact:[
    {key:'code',label:'Code'},{key:'name',label:'Account / Party'},{key:'address',label:'Address'},
    {key:'phone',label:'Phone'},{key:'mobile',label:'Mobile'},{key:'last_sale_date',label:'Last Sale'},
    {key:'balance',label:'Net Balance'}, {key:'received_amount',label:'Received Amount'},
  ],
  current_future:[
    {key:'code',label:'Code'},{key:'name',label:'Account / Party'},
    {key:'balance',label:'Balance'},{key:'last_receipt_date',label:'Last Receipt'},
    {key:'days_since_receipt',label:'Days'},{key:'current_due',label:'Current Due'},
    {key:'future_due',label:'Future Due'},
  ],
}
const csv=(s:string)=>'"'+(/^\s*[=+\-@\t\r]/.test(s)?"'":'')+s.replace(/"/g,'""')+'"'
const html=(s:string)=>s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')
function download(format:'csv'|'txt'|'html',columns:string[],rows:string[][]){
  const all=[columns,...rows]
  const result=format==='csv'?all.map(line=>line.map(csv).join(',')).join('\r\n')
    :format==='txt'?all.map(line=>line.join('\t')).join('\n')
      :'<!doctype html><meta charset="utf-8"><table><tbody>'+
       all.map(line=>'<tr>'+line.map(v=>'<td>'+html(v)+'</td>').join('')+'</tr>').join('')+'</tbody></table>'
  const url=URL.createObjectURL(new Blob([result],{type:'text/plain;charset=utf-8'}))
  const a=document.createElement('a');a.href=url;a.download='outstanding-balances-current-page.'+format;a.click()
  URL.revokeObjectURL(url)
}
export function OutstandingBalancesReportPage(){
  const { session } = useAuth()
  const {closeActiveTab}=useWorkspace()
  const [asOf,setAsOf]=useState(isoToday())
  const [partyType,setPartyType]=useState<PartyType>('customer')
  const [mode,setMode]=useState<Mode>('address')
  const [search,setSearch]=useState('')
  const [applied,setApplied]=useState('')
  const [page,setPage]=useState(1)
  const [preview,setPreview]=useState(false)
  const [zoom,setZoom]=useState(100)
  const paper=useRef<HTMLElement>(null)
  const query=useQuery({
    queryKey:[session?.tenant.ulid,session?.branch.ulid,session?.warehouse.ulid,'reports','outstanding',asOf,partyType,mode,applied,page],
    queryFn:()=>apiFetch<BalanceReport>('/api/reports/accounts/outstanding-balances?'+new URLSearchParams({
      as_of:asOf,party_type:partyType,mode,q:applied,page:String(page),per_page:'100',
    }),{busy:'none'}),
    enabled:preview&&!!asOf,
  })
  const report=query.data
  const columns=fields[mode]
  const values=(row:BalanceRow)=>columns.map(column=>String(row[column.key]??'—'))
  if(!preview)return <section className="dba-page">
    <header><h1>Outstanding Balances</h1><p>Posted account balances as of selected date; no due-date estimates.</p></header>
    <div className="dba-options">
      <label>Party Type
        <UiSelect searchable={false} value={partyType}
          options={[{value:'customer',label:'Customers'},{value:'vendor',label:'Vendors'}]}
          onChange={value=>setPartyType(value as PartyType)}/>
      </label>
      <label>Report Format
        <UiSelect searchable={false} value={mode}
          options={[{value:'address',label:'Address / License / Balance'},
            {value:'contact',label:'Contact / Last Sale / Balance'},
            {value:'current_future',label:'Current / Future Due (where known)'}]}
          onChange={value=>setMode(value as Mode)}/>
      </label>
      <label>As Of <UiDatePicker type="date" value={asOf} onChange={e=>setAsOf(e.target.value)}/></label>
      <label>Search Party <input value={search} onChange={e=>setSearch(e.target.value)} maxLength={180}/></label>
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
      <UiButton variant="info" label="View Report" disabled={!asOf}
        onClick={()=>{setApplied(search.trim());setPage(1);setPreview(true)}}/>
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
      onExport={type=>download(type,columns.map(x=>x.label),(report?.data??[]).map(values))}
      onRefresh={()=>void query.refetch()}
      onEditOptions={()=>setPreview(false)}
      onClose={closeActiveTab}/>
    {query.isLoading&&<UiSkeleton label="Loading outstanding balances…"/>}
    {query.isError&&<p role="alert">Outstanding balances could not load. Check accounting and party permissions.</p>}
    {report&&<article className="dba-paper daily-report-sheet" ref={paper} style={{zoom:zoom+'%'}}>
      <header><h1>OUTSTANDING BALANCES</h1><p>{partyType==='customer'?'Customers':'Vendors'} · {asOf}</p>
        <p>{report.meta.total} accounts · Page {report.meta.current_page} of {report.meta.last_page}</p>
      </header>
      <table><thead><tr>{columns.map(column=><th key={column.key}>{column.label}</th>)}</tr></thead>
        <tbody>{report.data.map(row=><tr key={row.account_ulid}>{values(row).map((value,index)=><td key={index}>{value}</td>)}</tr>)}
          {report.data.length===0&&<tr><td colSpan={columns.length}>No linked accounts match.</td></tr>}
        </tbody>
      </table>
      <p>Displayed page balance total: {report.meta.page_balance_total}</p>
      {report.meta.limitations.map(note=><p key={note}>{note}</p>)}
    </article>}
    {report&&<div className="dba-actions">
      <UiButton label="Previous" disabled={page<=1||query.isFetching} onClick={()=>setPage(p=>Math.max(1,p-1))}/>
      <span>Page {report.meta.current_page} / {report.meta.last_page}</span>
      <UiButton label="Next" disabled={page>=report.meta.last_page||query.isFetching} onClick={()=>setPage(p=>p+1)}/>
    </div>}
  </section>
}
