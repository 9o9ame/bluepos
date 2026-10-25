import { Fragment, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { apiFetch } from '../api/client'
import { UiButton } from '../components/ui/UiButton'
import { useAuth } from '../features/auth/AuthProvider'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './TotalTurnoverReportPage.css'

type AccountType = { ulid: string; name: string }
type Row = { name: string; purchases: string; sales: string; sales_returns: string; net_sales: string; received: string; receivable: string }
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
const recoveryPercent = (movement: string, recovery: string) => {
  const units = (text: string) => {
    const negative = text.startsWith('-')
    const [whole, decimal = ''] = (negative ? text.slice(1) : text).split('.')
    const result = BigInt(whole || '0') * 10000n + BigInt((decimal + '0000').slice(0, 4))
    return negative ? -result : result
  }
  const denominator = units(movement)
  if (denominator === 0n) return '0%'
  const scaled = (units(recovery) * 10000n) / denominator
  const negative = scaled < 0n
  const abs = negative ? -scaled : scaled
  const fraction = String(abs % 100n).padStart(2, '0').replace(/0+$/, '')
  return (negative ? '-' : '') + String(abs / 100n) + (fraction ? '.' + fraction : '') + '%'
}
export function TotalTurnoverReportPage() {
  const { session } = useAuth()
  const { openModule, closeActiveTab } = useWorkspace()
  const params = new URLSearchParams(useLocation().search)
  const preview = params.get('view') === 'preview'
  const periodFromUrl = (params.get('period') || 'this_month') as Period
  const [period,setPeriod] = useState<Period>(periodFromUrl)
  const [from,setFrom] = useState(params.get('from') || periodDates('this_month')[0])
  const [to,setTo] = useState(params.get('to') || periodDates('this_month')[1])
  const [above,setAbove] = useState(params.get('above') || '0')
  const mode = params.get('mode') || 'detailed'
  const [groups,setGroups] = useState<string[]>(params.get('types')?.split(',').filter(Boolean) || [])
  const [partyGroup,setPartyGroup] = useState(params.get('group') || 'accounts')
  const [search,setSearch] = useState('')
  const [zoom,setZoom] = useState(100)
  const paperRef = useRef<HTMLElement>(null)
  const typesQuery = useQuery({ queryKey:['total-turnover-types'],
    queryFn:()=>apiFetch<{account_types:AccountType[]}>('/api/reports/accounting/total-turnover/options'),
    enabled:!preview })
  // Preview URLs are authoritative: workspace tabs can reuse a mounted page
  // while switching modes, dates or group filters.
  const previewQuery = new URLSearchParams({
    from: params.get('from') || from,
    to: params.get('to') || to,
    above: params.get('above') || '0',
    mode: params.get('mode') === 'simple' ? 'simple' : 'detailed',
    period: params.get('period') || period,
    group: params.get('group') || partyGroup,
  })
  ;(params.get('types')?.split(',').filter(Boolean) || []).forEach((id, i) =>
    previewQuery.set('account_type_ulids[' + i + ']', id))
  const resultQuery = useQuery({
    queryKey: ['total-turnover', previewQuery.toString()],
    enabled: preview,
    queryFn: () => apiFetch<Result>('/api/reports/accounting/total-turnover?' + previewQuery.toString()),
  })
  const data = resultQuery.data
  const openPreview = (selectedMode:string) => {
    const sp = new URLSearchParams({view:'preview',from,to,above,mode:selectedMode,period,group:partyGroup,types:groups.join(',')})
    openModule('/reports/accounts/total-turnover?'+sp)
  }
  const exportFile = (kind:'txt'|'html'|'csv') => {
    if (!paperRef.current || !data) return
    const safe = (s:string)=>'"'+(/^\s*[=+@-]/.test(s)?"'":'')+s.replace(/"/g,'""')+'"'
    const txt=kind==='csv' ? '\uFEFF'+(mode==='simple' ? [['Party Name','Sales - Returns','Recovery','Recovery %age','Turnover','Receivable'], ...data.rows.map(x=>[x.name,x.sales_returns,x.received,recoveryPercent(x.sales_returns,x.received),'—',x.receivable])] : [['Party Name',...Array.from({length:6},()=>['Ltrs','Amount']).flat(),'Received','Recovery %age','Turnover','Receivable'], ...data.rows.map(x=>[x.name,...Array.from({length:12},()=>''),x.received,'Unavailable','',x.receivable])]).map(x=>x.map(safe).join(',')).join('\r\n') : kind==='html'? '<!doctype html><meta charset="utf-8">'+paperRef.current.innerHTML: paperRef.current.innerText
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
    {data && <article ref={paperRef} className={`turnover-paper ${mode === 'detailed' ? 'turnover-paper--detailed' : ''}`} style={{zoom:zoom+'%'}}>
      <header><strong>{session?.tenant.name}</strong>
        <p>{session?.branch.code} / {session?.branch.name}</p><h1>PARTIES, PRODUCT WISE (RECEIVABLE &amp; PAYABLE SUMMARY WITH TURNOVER)</h1>
        <p>From {data.from} to {data.to}</p></header>
      {mode === 'simple' ? <table className="turnover-simple-table"><thead>
        <tr><th rowSpan={2}>PARTY NAME</th><th colSpan={3}>RECOVERY POSITION</th><th colSpan={2}>TOTAL</th></tr>
        <tr><th>Sales - Returns</th><th>Recovery</th><th>%age</th><th>Turnover</th><th>RECEIVABLE</th></tr>
      </thead><tbody>{data.rows.length === 0 && <tr><td colSpan={6}><p role="status">No matching parties for the selected branch, dates, group, account types and turnover threshold. Review the selection options and posted party-ledger activity.</p></td></tr>}{data.rows.map((r,i)=><tr key={i}><td>{r.name}</td>
        <td>{fmt(r.sales_returns)}</td><td>{fmt(r.received)}</td>
        <td>{recoveryPercent(r.sales_returns,r.received)}</td><td>—</td>
        <td>{fmt(r.receivable)}</td></tr>)}</tbody>
      {data.rows.length > 0 && <tfoot><tr><th>TOTAL</th><th>{fmt(data.totals.sales_returns)}</th>
        <th>{fmt(data.totals.received)}</th><th></th><th>—</th>
        <th>{fmt(data.totals.receivable)}</th></tr></tfoot>}</table>
      : <div className="turnover-detailed-scroll"><table className="turnover-detailed-table"><thead>
        <tr><th rowSpan={2}>PARTY NAME</th><th colSpan={2}>PURCHASES</th>
          {Array.from({length:5},(_,i)=><th key={i} colSpan={2}></th>)}
          <th colSpan={2}>RECOVERY POSITION</th><th colSpan={2}>TOTAL</th></tr>
        <tr>{Array.from({length:6},(_,i)=><Fragment key={i}><th>Ltrs</th><th>AMOUNT</th></Fragment>)}
          <th>Received</th><th>%age</th><th>Turnover</th><th>RECEIVABLE</th></tr></thead>
        <tbody>{data.rows.length === 0 && <tr><td colSpan={17}><p role="status">No matching parties for the selected branch, dates, group, account types and turnover threshold. Review the selection options and posted party-ledger activity.</p></td></tr>}{data.rows.map((r,i)=><tr key={i}><td>{r.name}</td>
          {Array.from({length:6},(_,j)=><Fragment key={j}><td>—</td><td>—</td></Fragment>)}
          <td>{fmt(r.received)}</td><td>Unavailable</td><td>—</td><td>{fmt(r.receivable)}</td></tr>)}</tbody>
        {data.rows.length > 0 && <tfoot><tr><th>TOTAL</th>
          {Array.from({length:12},(_,i)=><th key={i}>—</th>)}
          <th>{fmt(data.totals.received)}</th><th>Unavailable</th><th>—</th>
          <th>{fmt(data.totals.receivable)}</th></tr></tfoot>}
      </table></div>}
      <p className="turnover-disclaimer">In Simple Turn Over, Sales − Returns is signed posted party-ledger movement, and %age = Recovery ÷ that movement × 100 (0% if the movement is zero). Turnover filtering uses net posted sales or purchases on accounting dates, including amendments and reversals. Receivable is the signed debit minus credit closing balance (negative denotes payable/credit). Product pairs, detailed recovery percentage and Total Turnover are unavailable: six product-group assignments, litre conversion rules and the Total Turnover formula are not configured.</p>
    </article>}
  </section>
  const valid=!!from&&!!to&&from<=to&&/^\d{1,16}(\.\d{1,4})?$/.test(above)
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
