import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { apiFetch } from '../api/client'
import { UiButton } from '../components/ui/UiButton'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { readPreviewSelection, savePreviewSelection } from '../features/reports/previewSelection'
import './BalanceSheetReportPage.css'

type AccountType = { ulid: string; name: string }
type Selection = {
  as_of_date: string; summarized: boolean; arrange_by_ids: boolean;
  account_type_ulids: string[]; party_groups: string[]
}
type BalanceRow = { account: {ulid:string;code:string;name:string}; debit:string;credit:string }
type BalanceGroup = {name:string; debit:string;credit:string;sections:{name:string;rows:BalanceRow[];debit:string;credit:string}[]}
type BalanceResult = {as_of_date:string;summarized:boolean;groups:BalanceGroup[];totals:{debit:string;credit:string;difference:string;difference_side:string};limitations:string[]}
const key = 'balance-sheet:preview'
const localDate = () => {
  const now = new Date()
  return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}
const money = (value:string) => {
  if (!value || /^-?0(?:\.0+)?$/.test(value)) return '—'
  const [whole,decimal=''] = value.split('.')
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')+(decimal.replace(/0+$/,'') ? '.'+decimal.replace(/0+$/,'') : '')
}
export function BalanceSheetReportPage() {
  const {openModule,closeActiveTab} = useWorkspace()
  const preview = new URLSearchParams(useLocation().search).get('view') === 'preview'
  const [tab,setTab] = useState<'main'|'additional'>('main')
  const [date,setDate] = useState(localDate())
  const [summarized,setSummarized] = useState(true)
  const [arrangeIds] = useState(false)
  const [selectedTypes,setSelectedTypes] = useState<string[]>([])
  const [partyGroups,setPartyGroups] = useState<string[]>([])
  const [zoom,setZoom] = useState(100)
  const [search,setSearch] = useState('')
  const paperRef = useRef<HTMLElement>(null)
  const selection = preview ? readPreviewSelection<Selection>(key) : null
  const optionsQuery = useQuery({
    queryKey:['balance-sheet-types'],
    queryFn:()=>apiFetch<{account_types:AccountType[]}>('/api/reports/accounting/balance-sheet/options'),
    enabled:!preview,
  })
  const reportQuery = useQuery({
    queryKey:['balance-sheet-preview',selection],
    queryFn:()=>apiFetch<BalanceResult>('/api/reports/accounting/balance-sheet/preview',{
      method:'POST',busy:'fetch',body:JSON.stringify(selection),
    }),
    enabled:preview && Boolean(selection),
  })
  const openPreview = () => {
    savePreviewSelection<Selection>(key,{
      as_of_date:date,summarized,arrange_by_ids:arrangeIds,
      account_type_ulids:selectedTypes,party_groups:partyGroups,
    })
    openModule('/reports/accounts/balance-sheet?view=preview')
  }
  const exportFile = (format:'txt'|'html'|'csv') => {
    const paper=paperRef.current, result=reportQuery.data
    if (!paper || !result) return
    const csv=(value:string)=>'"'+(/^\s*[=+@-]/.test(value)?"'":'')+value.replace(/"/g,'""')+'"'
    const content=format==='html'?'<!doctype html><meta charset="utf-8">'+paper.innerHTML
      :format==='txt'?paper.innerText
      :'\uFEFF'+[['Description','Debit','Credit'],...result.groups.flatMap(g=>[
          [g.name,g.debit,g.credit],
          ...g.sections.flatMap(s=>[
            [s.name,s.debit,s.credit],...s.rows.map(r=>[r.account.name,r.debit,r.credit])
          ])
        ]),['GRAND TOTAL',result.totals.debit,result.totals.credit]]
        .map(r=>r.map(csv).join(',')).join('\r\n')
    const type=format==='csv'?'text/csv;charset=utf-8':format==='html'?'text/html;charset=utf-8':'text/plain;charset=utf-8'
    const url=URL.createObjectURL(new Blob([content],{type}))
    const a=document.createElement('a');a.href=url;a.download='balance-sheet.'+format;a.click();URL.revokeObjectURL(url)
  }
  if(preview)return <section className="bs-page bs-preview">
    <ReportViewerToolbar ready={Boolean(reportQuery.data)} busy={reportQuery.isFetching}
      search={search} onSearchChange={setSearch}
      onFind={()=>Array.from(paperRef.current?.querySelectorAll('td,th')||[])
        .find(x=>x.textContent?.toLowerCase().includes(search.toLowerCase()))?.scrollIntoView({block:'center'})}
      zoom={zoom} onZoomChange={setZoom} onPrint={()=>window.print()}
      onCopy={()=>{if(paperRef.current)void navigator.clipboard.writeText(paperRef.current.innerText)}}
      onExport={exportFile} onRefresh={()=>void reportQuery.refetch()}
      onEditOptions={()=>openModule('/reports/accounts/balance-sheet')} onClose={closeActiveTab}/>
    {!selection && <p role="status">Report selection unavailable in this browser session. Open Selection Options and preview again.</p>}
    {reportQuery.isLoading && <p>Loading Balance Sheet…</p>}
    {reportQuery.isError && <p role="alert">Unable to load Balance Sheet.</p>}
    {reportQuery.data && <article className="bs-paper" ref={paperRef} style={{zoom:zoom+'%'}}>
      <header><h2>BALANCE SHEET</h2><p>As on and Before {reportQuery.data.as_of_date}</p></header>
      <table><thead><tr><th>DESCRIPTION</th><th>DEBIT</th><th>CREDIT</th></tr></thead>
        <tbody>{reportQuery.data.groups.map((g,i)=><>
          <tr key={'g'+i} className="bs-heading"><th colSpan={3}>{g.name}</th></tr>
          {g.sections.map((s,j)=><>
            <tr key={'s'+i+'-'+j} className="bs-heading"><th colSpan={3}>{s.name}</th></tr>
            {s.rows.map((r,k)=><tr key={r.account.ulid}>
              <td>{k+1}. {r.account.name}</td><td>{money(r.debit)}</td><td>{money(r.credit)}</td></tr>)}
            <tr key={'sub'+j} className="bs-subtotal"><th>{s.name}</th><th>{money(s.debit)}</th><th>{money(s.credit)}</th></tr>
          </>)}
          <tr className="bs-total"><th>{g.name}</th><th>{money(g.debit)}</th><th>{money(g.credit)}</th></tr>
        </>)}</tbody>
        <tfoot><tr><th>Grand Total</th><th>{money(reportQuery.data.totals.debit)}</th><th>{money(reportQuery.data.totals.credit)}</th></tr>
          <tr><th>Difference {reportQuery.data.totals.difference_side}</th><th colSpan={2}>{money(reportQuery.data.totals.difference)}</th></tr></tfoot>
      </table>
      {reportQuery.data.groups.length===0&&<p>No posted balances match the selected filters.</p>}
      <p className="bs-disclaimer">{reportQuery.data.limitations.join(' ')}</p>
    </article>}
  </section>
  return <section className="bs-page">
    <div className="bs-panel"><h2>Balance Sheet</h2>
      <div className="bs-tabs"><button type="button" aria-pressed={tab==='main'} onClick={()=>setTab('main')}>Main Selection</button>
        <button type="button" aria-pressed={tab==='additional'} onClick={()=>setTab('additional')}>Additional Selection</button></div>
      {tab==='main'?<div className="bs-main">
        <strong>Show Balance Sheet as on:</strong>
        <label className="bs-date-field"><span>Date</span><input type="date" value={date} onChange={e=>setDate(e.target.value)}/></label>
        <label><input type="checkbox" checked={summarized} onChange={e=>setSummarized(e.target.checked)}/> Summarized Form</label>
        <label title="Original ordering rule is not yet mapped"><input type="checkbox" checked={arrangeIds} disabled/> Arrange By IDs (pending mapping)</label>
        <p>FIFO stock revaluation is not offered until a verified valuation source is available.</p>
      </div>:<div className="bs-additional">
        <section><strong>Account Type</strong><div className="bs-checklist">
          {optionsQuery.isLoading&&<p>Loading types…</p>}
          {optionsQuery.isError&&<p role="alert">Unable to load account types.</p>}
          {(optionsQuery.data?.account_types||[]).map(t=><label key={t.ulid}>
            <input type="checkbox" checked={selectedTypes.includes(t.ulid)}
              onChange={e=>setSelectedTypes(current=>e.target.checked?[...current,t.ulid]:current.filter(v=>v!==t.ulid))}/>
            {t.name}</label>)}</div></section>
        <section><strong>Non Accounting Group Type</strong><div className="bs-checklist">
          {(['accounts','all','customers','vendors'] as const).map(g=><label key={g}>
            <input type="checkbox" checked={partyGroups.includes(g)}
              onChange={e=>setPartyGroups(current=>e.target.checked?[...current,g]:current.filter(v=>v!==g))}/>
            {g.toUpperCase()}</label>)}
          <label><input type="checkbox" disabled/> SALES MAN (pending mapping)</label></div></section>
      </div>}
    </div>
    <div className="bs-actions"><UiButton variant="info" label="Preview" disabled={!date||Boolean(optionsQuery.isError)}
      onClick={openPreview}/><UiButton variant="default" label="Close" onClick={closeActiveTab}/></div>
  </section>
}
