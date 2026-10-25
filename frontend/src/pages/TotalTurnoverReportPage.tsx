import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { apiFetch } from '../api/client'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './TotalTurnoverReportPage.css'

type AccountType = { ulid: string; name: string }
type Row = { name: string; purchases: string; sales: string; received: string; receivable: string }
type Result = { from: string; to: string; mode: string; rows: Row[]; totals: Omit<Row, 'name'> }
type Period = 'this_month' | 'previous_month' | 'today' | 'yesterday' | 'custom'
const dateString = (d: Date) => {
  const x = new Date(d.getTime() - d.getTimezoneOffset() * 60000)
  return x.toISOString().slice(0, 10)
}
const periodDates = (p: Period) => {
  const now = new Date()
  const y = now.getFullYear(), m = now.getMonth()
  if (p === 'today') return [dateString(now), dateString(now)]
  if (p === 'yesterday') { const d = new Date(y,m,now.getDate()-1); return [dateString(d),dateString(d)] }
  if (p === 'previous_month') return [dateString(new Date(y,m-1,1)),dateString(new Date(y,m,0))]
  return [dateString(new Date(y,m,1)),dateString(new Date(y,m+1,0))]
}
const fmt = (v: string) => {
  const negative = v.startsWith('-')
  const [w, f = ''] = (negative ? v.slice(1) : v).split('.')
  const fraction = f.slice(0,4).replace(/0+$/,'')
  return (negative ? '(' : '') + (w || '0').replace(/\B(?=(\d{3})+(?!\d))/g,',') + (fraction ? '.'+fraction : '') + (negative ? ')' : '')
}
export function TotalTurnoverReportPage() {
  const { openModule, closeActiveTab } = useWorkspace()
  const params = new URLSearchParams(useLocation().search)
  const preview = params.get('view') === 'preview'
  const periodFromUrl = (params.get('period') || 'this_month') as Period
  const [period,setPeriod] = useState<Period>(periodFromUrl)
  const [from,setFrom] = useState(params.get('from') || periodDates('this_month')[0])
  const [to,setTo] = useState(params.get('to') || periodDates('this_month')[1])
  const [above,setAbove] = useState(params.get('above') || '0')
  const [mode,setMode] = useState(params.get('mode') || 'detailed')
  const [groups,setGroups] = useState<string[]>(params.get('types')?.split(',').filter(Boolean) || [])
  const [partyGroup,setPartyGroup] = useState(params.get('group') || 'accounts')
  const [search,setSearch] = useState('')
  const [zoom,setZoom] = useState(100)
  const paperRef = useRef<HTMLElement>(null)
  const typesQuery = useQuery({ queryKey:['total-turnover-types'],
    queryFn:()=>apiFetch<{account_types:AccountType[]}>('/api/reports/accounting/total-turnover/options'),
    enabled:!preview })
  const resultQuery = useQuery({queryKey:['total-turnover',params.toString()],enabled:preview,queryFn:()=>{
    const sp = new URLSearchParams({from,to,above,mode,period,group:partyGroup})
    groups.forEach((id,i)=>sp.set('account_type_ulids['+i+']',id))
    return apiFetch<Result>('/api/reports/accounting/total-turnover?'+sp)
  }})
  const data = resultQuery.data
  const openPreview = (selectedMode:string) => {
    const sp = new URLSearchParams({view:'preview',from,to,above,mode:selectedMode,period,group:partyGroup,types:groups.join(',')})
    openModule('/reports/accounts/total-turnover?'+sp)
  }
  const exportFile = (kind:'txt'|'html'|'csv') => {
    if (!paperRef.current || !data) return
    const safe = (s:string)=>'"'+(/^\s*[=+@-]/.test(s)?"'":'')+s.replace(/"/g,'""')+'"'
    const txt=kind==='csv' ? '\uFEFF'+[['Party Name','Purchases','Sales','Received','Receivable'], ...data.rows.map(x=>[x.name,x.purchases,x.sales,x.received,x.receivable])].map(x=>x.map(safe).join(',')).join('\r\n') : kind==='html'? '<!doctype html><meta charset="utf-8">'+paperRef.current.innerHTML: paperRef.current.innerText
    const url=URL.createObjectURL(new Blob([txt],{type:kind==='csv'?'text/csv;charset=utf-8':kind==='html'?'text/html;charset=utf-8':'text/plain;charset=utf-8'}))
    const a=document.createElement('a');a.href=url;a.download='total-turnover.'+kind;a.click();URL.revokeObjectURL(url)
  }
  if(preview) return <section className="turnover-page turnover-preview">
    <ReportViewerToolbar ready={Boolean(data)} busy={resultQuery.isFetching} search={search}
      onSearchChange={setSearch} onFind={()=>Array.from(paperRef.current?.querySelectorAll('td') || []).find(x=>x.textContent?.toLowerCase().includes(search.toLowerCase()))?.scrollIntoView({block:'center'})}
      zoom={zoom} onZoomChange={setZoom} onPrint={()=>window.print()}
      onCopy={()=>{if(paperRef.current)void navigator.clipboard.writeText(paperRef.current.innerText)}}
      onExport={exportFile} onRefresh={()=>void resultQuery.refetch()}
      onEditOptions={()=>openModule('/reports/accounts/total-turnover')} onClose={closeActiveTab}/>
    {resultQuery.isLoading && <p>Loading turnover…</p>}
    {resultQuery.isError && <p role="alert">Unable to load turnover report.</p>}
    {data && <article ref={paperRef} className="turnover-paper" style={{zoom:zoom+'%'}}>
      <header><h1>PARTIES, PRODUCT WISE (RECEIVABLE &amp; PAYABLE SUMMARY WITH TURNOVER)</h1>
        <p>From {data.from} to {data.to}</p></header>
      {data.mode === 'simple' ? <table><thead><tr>
        <th>PARTY NAME</th><th>PURCHASES</th><th>SALES TURNOVER</th><th>RECEIVED</th><th>RECEIVABLE</th>
      </tr></thead><tbody>{data.rows.map((r,i)=><tr key={i}><td>{r.name}</td><td>{fmt(r.purchases)}</td>
        <td>{fmt(r.sales)}</td><td>{fmt(r.received)}</td><td>{fmt(r.receivable)}</td></tr>)}</tbody>
      <tfoot><tr><th>TOTAL</th><th>{fmt(data.totals.purchases)}</th><th>{fmt(data.totals.sales)}</th>
        <th>{fmt(data.totals.received)}</th><th>{fmt(data.totals.receivable)}</th></tr></tfoot></table>
      : <table><thead><tr><th rowSpan={2}>PARTY NAME</th><th colSpan={2}>PURCHASES</th><th>SALES</th><th colSpan={2}>RECOVERY POSITION</th><th colSpan={2}>TOTAL</th></tr>
      <tr><th>Amount</th><th>Qty</th><th>Amount</th><th>Received</th><th>%age</th><th>Turnover</th><th>RECEIVABLE</th></tr></thead>
      <tbody>{data.rows.map((r,i)=><tr key={i}><td>{r.name}</td>
        <td>{fmt(r.purchases)}</td><td>—</td><td>{fmt(r.sales)}</td><td>{fmt(r.received)}</td><td>—</td><td>{fmt(r.sales)}</td><td>{fmt(r.receivable)}</td></tr>)}
      </tbody><tfoot><tr><th>TOTAL</th><th>{fmt(data.totals.purchases)}</th><th>—</th><th>{fmt(data.totals.sales)}</th><th>{fmt(data.totals.received)}</th><th>—</th><th>{fmt(data.totals.sales)}</th><th>{fmt(data.totals.receivable)}</th></tr></tfoot></table>}
      <p className="turnover-disclaimer">Posted transactions only. Product-wise litres and recovery percentages are not yet mapped; these fields are not calculated.</p>
    </article>}
  </section>
  const valid=!!from&&!!to&&from<=to&&/^\d+(\.\d{1,4})?$/.test(above)
  return <section className="turnover-page">
    <div className="turnover-panel"><h2>Selection Options</h2>
      <div className="turnover-grid">
        <fieldset className="turnover-period"><legend>Period</legend>
          {([['this_month','This Month'],['previous_month','Previous Month'],['today','Today'],['yesterday','Yesterday'],['custom','User Defined']] as const).map(([value,label])=><label key={value}>
            <input type="radio" checked={period===value} onChange={()=>{setPeriod(value);if(value!=='custom'){const [a,b]=periodDates(value);setFrom(a);setTo(b)}}}/>{label}</label>)}
        </fieldset>
        <div className="turnover-dates"><strong>Date Selection</strong>
          <label>From Date<input type="date" value={from} onChange={e=>{setFrom(e.target.value);setPeriod('custom')}}/></label>
          <label>To Date<input type="date" value={to} onChange={e=>{setTo(e.target.value);setPeriod('custom')}}/></label>
          <label>Show Turnover above<input type="text" inputMode="decimal" value={above} onChange={e=>setAbove(e.target.value)}/></label>
        </div>
        <div><strong>Non Accounting Group Type</strong><div className="turnover-types"><label><input type="radio" checked={partyGroup==='accounts'} onChange={()=>setPartyGroup('accounts')}/>ACCOUNTS</label>
          <label><input type="radio" checked={partyGroup==='all'} onChange={()=>setPartyGroup('all')}/>ALL</label>
          <label><input type="radio" checked={partyGroup==='customers'} onChange={()=>setPartyGroup('customers')}/>CUSTOMERS</label>
          <label><input type="radio" checked={partyGroup==='vendors'} onChange={()=>setPartyGroup('vendors')}/>VENDORS</label>
          <label><input type="radio" disabled/>SALES MAN (pending mapping)</label></div></div>
        <div className="turnover-account-types"><strong>Account Type</strong><div className="turnover-types">
          {typesQuery.isLoading && <p>Loading types…</p>}
          {typesQuery.isError && <p role="alert">Unable to load types.</p>}
          {(typesQuery.data?.account_types || []).map(t=><label key={t.ulid}><input type="checkbox" checked={groups.includes(t.ulid)} onChange={e=>setGroups(current=>e.target.checked?[...current,t.ulid]:current.filter(x=>x!==t.ulid))}/>{t.name}</label>)}
        </div></div>
      </div>
    </div>
    <div className="turnover-actions">
      <UiButton variant="info" label="Preview" disabled={!valid} onClick={()=>openPreview('detailed')}/>
      <UiButton variant="default" label="Simple Turn Over" disabled={!valid} onClick={()=>openPreview('simple')}/>
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
    </div>
  </section>
}
