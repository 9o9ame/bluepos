import { useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { apiFetch } from '../api/client'
import { UiButton } from '../components/ui/UiButton'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { savePreviewSelection, readPreviewSelection } from '../features/reports/previewSelection'
import './GeneralJournalReportPage.css'

type Selection = {
  from:string;to:string;document_types:string[];narration:boolean;only_problematic:boolean;
  print_format:'transaction_wise'|'plain'|'group_by_doc'|'single_line';
  transaction_from?:number;transaction_to?:number;
  balance_mode:'all'|'between'|'not_between';balance_from:string;balance_to:string
}
type Line = {ulid:string;account:{ulid:string|null;code:string|null;name:string};narration:string;debit:string;credit:string}
type Entry = {ulid:string;voucher_number:string|null;document_type:string;entry_date:string;narration:string;lines:Line[];totals:{debit:string;credit:string;difference:string}}
type Result = {from:string;to:string;print_format:Selection['print_format'];entries:Entry[];has_more:boolean;totals:{debit:string;credit:string;difference:string};note:string|null}
const key='general-journal:preview'
const today=()=>{const d=new Date();return new Date(d.getTime()-d.getTimezoneOffset()*60000).toISOString().slice(0,10)}
const amt=(v:string)=>/^0(?:\.0+)?$/.test(v)?'—':v.replace(/\B(?=(\d{3})+(?!\d))/g,',')
const label=(v:string)=>v.replace(/_/g,' ').toUpperCase()
export function GeneralJournalReportPage(){
  const {openModule,closeActiveTab}=useWorkspace()
  const preview=new URLSearchParams(useLocation().search).get('view')==='preview'
  const [from,setFrom]=useState(today()),[to,setTo]=useState(today())
  const [types,setTypes]=useState<string[]>([])
  const [narration,setNarration]=useState(true),[problematic,setProblematic]=useState(false)
  const [format,setFormat]=useState<Selection['print_format']>('transaction_wise')
  const [transactionFrom,setTransactionFrom]=useState(''),[transactionTo,setTransactionTo]=useState('')
  const [balanceMode,setBalanceMode]=useState<Selection['balance_mode']>('all')
  const [balanceFrom,setBalanceFrom]=useState('0'),[balanceTo,setBalanceTo]=useState('0')
  const [zoom,setZoom]=useState(100),[search,setSearch]=useState('')
  const paperRef=useRef<HTMLElement>(null)
  const options=useQuery({queryKey:['general-journal-options'],enabled:!preview,
    queryFn:()=>apiFetch<{document_types:string[]}>('/api/reports/accounting/general-journal/options')})
  const selected=preview?readPreviewSelection<Selection>(key):null
  const report=useQuery({queryKey:['general-journal-preview',selected],enabled:preview&&Boolean(selected),
    queryFn:()=>apiFetch<Result>('/api/reports/accounting/general-journal/preview',{
      method:'POST',body:JSON.stringify(selected),busy:'fetch',
    })})
  const openPreview=()=>{
    const params:Selection={from,to,document_types:types,narration,only_problematic:problematic,
      print_format:format,balance_mode:balanceMode,balance_from:balanceFrom,balance_to:balanceTo}
    if(transactionFrom) params.transaction_from=Number(transactionFrom)
    if(transactionTo) params.transaction_to=Number(transactionTo)
    savePreviewSelection(key,params)
    openModule('/reports/accounts/general-journal?view=preview')
  }
  const exportReport=(format:'txt'|'html'|'csv')=>{
    if(!report.data||!paperRef.current)return
    const csv=(s:string)=>'"'+(/^[=+@-]/.test(s)?"'":'')+s.replace(/"/g,'""')+'"'
    const rows=report.data.entries.flatMap(e=>[
      [e.voucher_number??'',e.document_type,e.entry_date,'','','',''],
      ...e.lines.map(l=>['','','',l.account.code??'',l.account.name,l.debit,l.credit])
    ])
    const content=format==='html'?'<!doctype html><meta charset="utf-8">'+paperRef.current.innerHTML:
      format==='txt'?paperRef.current.innerText:'\uFEFF'+[['Voucher','Type','Date','Code','Account','Debit','Credit'],...rows].map(r=>r.map(csv).join(',')).join('\r\n')
    const url=URL.createObjectURL(new Blob([content],{type:format==='csv'?'text/csv;charset=utf-8':format==='html'?'text/html;charset=utf-8':'text/plain;charset=utf-8'}))
    const a=document.createElement('a');a.href=url;a.download='general-journal.'+format;a.click();URL.revokeObjectURL(url)
  }
  if(preview)return <section className="gj-page gj-preview">
    <ReportViewerToolbar ready={Boolean(report.data)} busy={report.isFetching} search={search}
      onSearchChange={setSearch} onFind={()=>Array.from(paperRef.current?.querySelectorAll('td,th')||[])
        .find(x=>x.textContent?.toLowerCase().includes(search.toLowerCase()))?.scrollIntoView({block:'center'})}
      zoom={zoom} onZoomChange={setZoom} onPrint={()=>window.print()}
      onCopy={()=>{if(paperRef.current)void navigator.clipboard.writeText(paperRef.current.innerText)}}
      onExport={exportReport} onRefresh={()=>void report.refetch()}
      onEditOptions={()=>openModule('/reports/accounts/general-journal')} onClose={closeActiveTab}/>
    {!selected&&<p role="status">Selection unavailable; open report options and preview again.</p>}
    {report.isLoading&&<p>Loading General Journal…</p>}
    {report.isError&&<p role="alert">Unable to load General Journal.</p>}
    {report.data&&<article className="gj-paper" ref={paperRef} style={{zoom:zoom+'%'}}>
      <header><h2>GENERAL JOURNAL VIEW</h2><p>From {report.data.from} to {report.data.to}</p></header>
      {report.data.entries.length===0&&<p>No posted journal entries match these filters.</p>}
      {report.data.entries.map(entry=><section className="gj-voucher" key={entry.ulid}>
        <div className="gj-voucher-header"><strong>{entry.voucher_number||'—'}</strong><strong>{label(entry.document_type)}</strong><strong>{entry.entry_date}</strong></div>
        <table><thead><tr><th>Account Code</th><th>Title / Description Of Account</th><th>Narration</th><th>Debit +</th><th>Credit -</th></tr></thead>
          <tbody>{entry.lines.map(line=><tr key={line.ulid}>
            <td>{line.account.code||'—'}</td><td>{line.account.name}</td>
            <td>{line.narration}</td><td>{amt(line.debit)}</td><td>{amt(line.credit)}</td></tr>)}</tbody>
          <tfoot><tr><th colSpan={3}>Sub Total By Group {entry.totals.difference!=='0.0000'?'(Difference: '+amt(entry.totals.difference)+')':''}</th>
            <th>{amt(entry.totals.debit)}</th><th>{amt(entry.totals.credit)}</th></tr></tfoot>
        </table>
        {entry.narration&&<p className="gj-narration">{entry.narration}</p>}
      </section>)}
      <div className="gj-grand"><strong>Selected Vouchers Total</strong><span>Debit: {amt(report.data.totals.debit)}</span><span>Credit: {amt(report.data.totals.credit)}</span><span>Diff: {amt(report.data.totals.difference)}</span></div>
      {report.data.note&&<p role="status">{report.data.note}</p>}
    </article>}
  </section>
  return <section className="gj-page gj-options">
    <div className="gj-panel"><h2>General Journal Selection</h2><div className="gj-content">
      <div className="gj-left">
        <fieldset><legend>Date Selection</legend><label>From Date<input type="date" value={from} onChange={e=>setFrom(e.target.value)}/></label>
          <label>To Date<input type="date" value={to} min={from} onChange={e=>setTo(e.target.value)}/></label></fieldset>
        <fieldset><legend>Transactions Selection (voucher number)</legend>
          <label>From Transaction #<input type="number" min="1" value={transactionFrom} onChange={e=>setTransactionFrom(e.target.value)}/></label>
          <label>To Transaction #<input type="number" min="1" value={transactionTo} onChange={e=>setTransactionTo(e.target.value)}/></label></fieldset>
        <label className="gj-inline"><input type="checkbox" checked={problematic} onChange={e=>setProblematic(e.target.checked)}/> Only Problematic Entries</label>
        <label className="gj-inline"><input type="checkbox" checked={narration} onChange={e=>setNarration(e.target.checked)}/> Narration Also</label>
        <label>Print Format<select value={format} onChange={e=>setFormat(e.target.value as Selection['print_format'])}>
          <option value="transaction_wise">Transaction Wise Format</option><option value="plain" disabled>Plain Format (pending mapping)</option>
          <option value="group_by_doc" disabled>Group By Doc (pending mapping)</option><option value="single_line" disabled>Single Line Format (pending mapping)</option></select></label>
        <label>Voucher Debit/Credit Difference<select value={balanceMode} onChange={e=>setBalanceMode(e.target.value as Selection['balance_mode'])}>
          <option value="all">Any</option><option value="not_between">Not Between</option><option value="between">Between</option></select></label>
        {balanceMode!=='all'&&<div className="gj-balance-range"><label>From<input type="number" step="0.0001" min="0" value={balanceFrom} onChange={e=>setBalanceFrom(e.target.value)}/></label>
          <label>To<input type="number" step="0.0001" min="0" value={balanceTo} onChange={e=>setBalanceTo(e.target.value)}/></label></div>}
      </div>
      <fieldset className="gj-types"><legend>Posted Voucher Types</legend>
        {options.isLoading&&<p>Loading types…</p>}{options.isError&&<p role="alert">Unable to load voucher types.</p>}
        <div className="gj-types-scroll">{(options.data?.document_types||[]).map(type=><label key={type}>
          <input type="checkbox" checked={types.includes(type)} onChange={e=>setTypes(v=>e.target.checked?[...v,type]:v.filter(x=>x!==type))}/>
          {label(type)}</label>)}</div>
        <small>No selection means all posted voucher types.</small>
      </fieldset>
    </div></div>
    <div className="gj-actions"><UiButton variant="info" label="Preview" onClick={openPreview}
      disabled={!from||!to||from>to||(!!transactionFrom&&!!transactionTo&&Number(transactionFrom)>Number(transactionTo))}/>
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/></div>
  </section>
}
