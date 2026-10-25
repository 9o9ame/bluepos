import { useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { apiFetch } from '../api/client'
import { UiButton } from '../components/ui/UiButton'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { savePreviewSelection, readPreviewSelection } from '../features/reports/previewSelection'
import './IncomeStatementReportPage.css'

type Selection = {from: string; to: string; period: string}
type Item = {account:{ulid:string;name:string;code:string};amount:string}
type Section = {name:string;class:string;amount:string;running_total:string;rows:Item[]}
type Report = {from:string;to:string;sections:Section[];net_income:string;limitations:string[];basis:string}
const key = 'daily-income-statement:preview'
const today = () => {
  const date = new Date()
  return new Date(date.getTime() - date.getTimezoneOffset()*60000).toISOString().slice(0,10)
}
const fmt = (amount:string) => {
  const n=Number(amount)
  if (!Number.isFinite(n)) return amount
  return n<0?'('+Math.abs(n).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:4})+')':n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:4})
}
const firstDay = (value:string) => value.slice(0,8)+'01'
const previousMonth = (value:string) => {
  const d = new Date(value+'T12:00:00')
  d.setMonth(d.getMonth()-1)
  return d.toISOString().slice(0,7)
}
const monthEnd = (month:string) => {
  const [year,m] = month.split('-').map(Number)
  return new Date(Date.UTC(year,m,0)).toISOString().slice(0,10)
}
export function DailyIncomeStatementReportPage() {
  const {openModule,closeActiveTab}=useWorkspace()
  const preview=new URLSearchParams(useLocation().search).get('view')==='preview'
  const [period,setPeriod]=useState('this_month')
  const [from,setFrom]=useState(firstDay(today()))
  const [to,setTo]=useState(monthEnd(today().slice(0,7)))
  const choosePeriod=(value:string)=>{
    setPeriod(value)
    const now=today()
    if(value==='this_month'){setFrom(firstDay(now));setTo(monthEnd(now.slice(0,7)))}
    else if(value==='previous_month'){const month=previousMonth(firstDay(now));setFrom(month+'-01');setTo(monthEnd(month))}
    else if(value==='today'||value==='yesterday'){
      const d=new Date(now+'T12:00:00');if(value==='yesterday')d.setDate(d.getDate()-1)
      const day=d.toISOString().slice(0,10);setFrom(day);setTo(day)
    }
  }
  const [zoom,setZoom]=useState(100)
  const [search,setSearch]=useState('')
  const paperRef=useRef<HTMLElement>(null)
  const selection=preview?readPreviewSelection<Selection>(key):null
  const report=useQuery({
    queryKey:['daily-income-statement',selection],
    enabled:preview && Boolean(selection),
    queryFn:()=>apiFetch<Report>('/api/reports/accounting/income-statement/period-preview',{
      method:'POST',body:JSON.stringify(selection),busy:'fetch',
    }),
  })
  const openPreview=()=>{
    savePreviewSelection<Selection>(key,{from,to,period})
    openModule('/reports/accounts/daily-income-statement?view=preview')
  }
  const exportReport=(format:'txt'|'html'|'csv')=>{
    if (!report.data||!paperRef.current) return
    const esc=(s:string)=>'"'+(/^[=+@-]/.test(s)?"'":'')+s.replace(/"/g,'""')+'"'
    const content=format==='html'?'<!doctype html><meta charset="utf-8">'+paperRef.current.innerHTML
      :format==='txt'?paperRef.current.innerText
      :'\uFEFF'+[['Particulars','Amount','Running Total'],
        ...report.data.sections.flatMap(s=>[
          [s.name,s.amount,s.running_total],
          ...s.rows.map(r=>[r.account.name,r.amount,''])
        ]),['NET INCOME',report.data.net_income,'']]
        .map(row=>row.map(esc).join(',')).join('\r\n')
    const url=URL.createObjectURL(new Blob([content],{type:format==='html'?'text/html':format==='csv'?'text/csv':'text/plain'}))
    const link=document.createElement('a');link.href=url;link.download='daily-income-statement.'+format;link.click();URL.revokeObjectURL(url)
  }
  if(preview) return <section className="is-page is-preview">
    <ReportViewerToolbar ready={Boolean(report.data)} busy={report.isFetching} search={search}
      onSearchChange={setSearch} zoom={zoom} onZoomChange={setZoom}
      onFind={()=>Array.from(paperRef.current?.querySelectorAll('td,th')||[])
        .find(element=>element.textContent?.toLowerCase().includes(search.toLowerCase()))?.scrollIntoView({block:'center'})}
      onPrint={()=>window.print()}
      onCopy={()=>{if(paperRef.current)void navigator.clipboard.writeText(paperRef.current.innerText)}}
      onExport={exportReport} onRefresh={()=>void report.refetch()}
      onEditOptions={()=>openModule('/reports/accounts/daily-income-statement')} onClose={closeActiveTab}/>
    {!selection&&<p role="status">Selection unavailable. Open Income Statement options and preview again.</p>}
    {report.isLoading&&<p>Loading Income Statement…</p>}
    {report.isError&&<p role="alert">Unable to load Income Statement.</p>}
    {report.data&&<article ref={paperRef} className="is-paper" style={{zoom:zoom+'%'}}>
      <header><h1>INCOME STATEMENT</h1><p>From {report.data.from} to {report.data.to}</p></header>
      <table><thead><tr><th>PARTICULARS</th><th>AMOUNT</th><th>RUNNING TOTAL</th></tr></thead>
        <tbody>{report.data.sections.map((section,index)=><>
          <tr key={'head-'+index} className="is-section"><th colSpan={3}>{section.name}</th></tr>
          {section.rows.map(item=><tr key={item.account.ulid}>
            <td>{item.account.name}</td><td>{fmt(item.amount)}</td><td></td>
          </tr>)}
          <tr key={'sum-'+index} className="is-total"><td></td><th>{fmt(section.amount)}</th><th>{fmt(section.running_total)}</th></tr>
        </>)}</tbody>
        <tfoot><tr><th>Net Income</th><th>{fmt(report.data.net_income)}</th><th></th></tr></tfoot>
      </table>
      {report.data.sections.length===0&&<p>No posted revenue or expense activity for the selected period.</p>}
      <p className="is-note">{report.data.limitations.join(' ')}</p>
    </article>}
  </section>
  return <section className="is-page is-options is-period-options">
    <div className="is-panel">
      <h2>Income Statement (Daily / Monthly / Yearly)</h2>
      <div className="is-date-card">
        <strong>Selection</strong>
        <fieldset className="is-period-picker">
          <legend>Period</legend>
          {[
            ['this_month','This Month'],['previous_month','Previous Month'],
            ['today','Today'],['yesterday','Yesterday'],['user_defined','User Defined'],
          ].map(([value,label])=><label key={value}>
            <input type="radio" name="income-period" checked={period===value} onChange={()=>choosePeriod(value)}/>
            {label}
          </label>)}
        </fieldset>
        <div className="is-period-dates">
          <label>From Date<input type="date" value={from} onChange={e=>{setFrom(e.target.value);setPeriod('user_defined')}}/></label>
          <label>To Date<input type="date" value={to} min={from} onChange={e=>{setTo(e.target.value);setPeriod('user_defined')}}/></label>
        </div>
        <p>Posted P&L movement only. FIFO stock revaluation is not yet supported.</p>
      </div>
    </div>
    <div className="is-actions">
      <UiButton variant="info" label="Preview" disabled={!from||!to||from>to} onClick={openPreview}/>
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
    </div>
  </section>
}
