import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { apiFetch } from '../api/client'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiDateRangePicker } from '../components/ui/UiDateRangePicker'
import { UiSelect } from '../components/ui/UiSelect'
import { UiButton } from '../components/ui/UiButton'
import { UiSkeleton } from '../components/ui/UiSkeleton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './DailyBusinessActivityPage.css'
import './DailyReportSheets.css'

type Kind = 'advance' | 'daybook' | 'product-transactions' | 'detailed' | 'fbr-sales' | 'fbr-purchases'
type Choice = { ulid: string; name: string; code?: string; product_number?: string }
type ReportResult = {
  rows: Record<string, unknown>[] | Record<string, Record<string, unknown>[]>
  totals?: Record<string, string | number>
  ledger?: { opening: string; closing: string; rows: Record<string, unknown>[]; account: Choice } | null
  limitations: string[]
  compliance_ready?: boolean
  register?: string
}
const definition: Record<Kind, { label: string; path: string; endpoint: string }> = {
  advance: { label: 'Advance Sales View', path: '/reports/advance-sales', endpoint: '/api/reports/next/advance-sales' },
  daybook: { label: 'Day Book — Daily Business Summary', path: '/reports/daily/day-book', endpoint: '/api/reports/next/day-book' },
  'product-transactions': { label: 'Product Wise Transactions', path: '/reports/daily/product-transactions', endpoint: '/api/reports/next/product-transactions' },
  detailed: { label: 'Detailed Transactions Wise Report', path: '/reports/daily/detailed-transactions', endpoint: '/api/reports/next/detailed-transactions' },
  'fbr-sales': { label: 'FBR Sales Register — Internal Review', path: '/reports/daily/fbr-sales-register', endpoint: '/api/reports/next/fbr/sales' },
  'fbr-purchases': { label: 'FBR Purchase Register — Internal Review', path: '/reports/daily/fbr-purchase-register', endpoint: '/api/reports/next/fbr/purchases' },
}
const dateLocal = () => {
  const now = new Date()
  return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}
const TYPES = ['sale', 'purchase', 'sale_return', 'purchase_return'] as const
const titleType = (s: string) => s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
const present = (value: unknown): string => {
  if (value === null || value === undefined) return '—'
  if (typeof value === 'object') {
    const object = value as { name?: string; product_number?: string; code?: string }
    return [object.code ?? object.product_number, object.name].filter(Boolean).join(' — ') || '—'
  }
  return String(value)
}
const display = (raw: unknown) => {
  const value = present(raw)
  if (!/^-?\d+(?:\.\d+)?$/.test(value)) return value
  const [whole, decimal] = value.split('.')
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (decimal === undefined ? '' : '.' + decimal)
}
const columns: Record<Kind, { key: string; title: string }[]> = {
  advance: [
    {key:'number',title:'Trans #'}, {key:'date',title:'Date'}, {key:'product',title:'Description'},
    {key:'sold',title:'Sold'}, {key:'stock_at',title:'Stock @'}, {key:'current_stock',title:'Current Stock'},
    {key:'rate',title:'Rate'}, {key:'amount',title:'Amount'},
  ],
  daybook: [
    {key:'date',title:'Date'}, {key:'type',title:'Document Type'}, {key:'number',title:'Document'},
    {key:'party',title:'Party'}, {key:'amount',title:'Amount'},
  ],
  'product-transactions': [
    {key:'date',title:'Date'}, {key:'type',title:'Movement'}, {key:'number',title:'Document'},
    {key:'party',title:'Party'}, {key:'product',title:'Product'},
    {key:'quantity_in',title:'Qty In'}, {key:'quantity_out',title:'Qty Out'},
    {key:'unit_cost',title:'Unit Cost'}, {key:'total_cost',title:'Movement Cost'},
  ],
  detailed: [
    {key:'date',title:'Date'}, {key:'type',title:'Document Type'}, {key:'party',title:'Party'},
    {key:'number',title:'Invoice / Document'}, {key:'count',title:'Documents'},
    {key:'amount',title:'Net Amount'},
  ],
  'fbr-sales': [
    {key:'date',title:'Date'}, {key:'number',title:'Invoice #'}, {key:'type',title:'Type'},
    {key:'party',title:'Customer'}, {key:'party_ntn',title:'NTN'}, {key:'party_strn',title:'STRN'},
    {key:'product',title:'Product'}, {key:'hs_code',title:'HS Code'}, {key:'quantity',title:'Quantity'},
    {key:'tax_rate',title:'Tax %'}, {key:'value',title:'Value ex Tax'},
    {key:'tax',title:'Tax'}, {key:'gross',title:'Gross'},
  ],
  'fbr-purchases': [
    {key:'date',title:'Date'}, {key:'number',title:'Invoice #'}, {key:'type',title:'Type'},
    {key:'party',title:'Supplier'}, {key:'party_ntn',title:'NTN'}, {key:'party_strn',title:'STRN'},
    {key:'product',title:'Product'}, {key:'hs_code',title:'HS Code'}, {key:'quantity',title:'Quantity'},
    {key:'tax_rate',title:'Tax %'}, {key:'value',title:'Value ex Tax'},
    {key:'tax',title:'Tax'}, {key:'gross',title:'Gross'},
  ],
}

export function NextReportsPage({ kind }: { kind: Kind }) {
  const { openModule, closeActiveTab } = useWorkspace()
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const preview = params.get('view') === 'preview'
  const selected = definition[kind]
  const [from, setFrom] = useState(params.get('from') || dateLocal())
  const [to, setTo] = useState(params.get('to') || dateLocal())
  const [product, setProduct] = useState(params.get('product_ulid') || '')
  const [account, setAccount] = useState(params.get('account_ulid') || '')
  const [format, setFormat] = useState(params.get('format') || (kind === 'detailed' ? 'summary' : 'short'))
  const [types, setTypes] = useState<string[]>(params.getAll('types[]'))
  const [zoom, setZoom] = useState(100)
  const [search, setSearch] = useState('')
  const paper = useRef<HTMLElement>(null)
  const options = useQuery({
    queryKey: ['next-report-options'],
    queryFn: () => apiFetch<{products: Choice[]; accounts: Choice[]; types: string[]}>('/api/reports/next/options'),
    enabled: !preview,
  })
  const queryString = (() => {
    const query = new URLSearchParams({ from: params.get('from') || '', to: params.get('to') || '' })
    if (params.get('product_ulid')) query.set('product_ulid', params.get('product_ulid')!)
    if (params.get('account_ulid')) query.set('account_ulid', params.get('account_ulid')!)
    if (params.get('format')) query.set('format', params.get('format')!)
    params.getAll('types[]').forEach(type => query.append('types[]', type))
    return query.toString()
  })()
  const reportQuery = useQuery({
    queryKey: ['next-report', kind, queryString],
    queryFn: () => apiFetch<ReportResult>(selected.endpoint + '?' + queryString),
    enabled: preview && Boolean(params.get('from')) && Boolean(params.get('to')),
  })
  const report = reportQuery.data
  const rows: Record<string, unknown>[] = !report
    ? []
    : Array.isArray(report.rows)
      ? report.rows
      : Object.entries(report.rows).flatMap(([type, parts]) => parts.map(item => ({...item, type})))
  const calendarDays = (() => {
    if (kind !== 'detailed' || params.get('format') !== 'daily') return []
    const fromDate = params.get('from')
    const toDate = params.get('to')
    if (!fromDate || !toDate) return []
    const start = new Date(fromDate + 'T00:00:00Z')
    const end = new Date(toDate + 'T00:00:00Z')
    const result: string[] = []
    for (let t = start.getTime(); t <= end.getTime() && result.length < 31; t += 86400000)
      result.push(new Date(t).toISOString().slice(0, 10))
    return result
  })()
  const headers = calendarDays.length
    ? [
        {key:'type',title:'Type'}, {key:'party',title:'Party'},
        ...calendarDays.map(day => ({key:'day:' + day,title:day.slice(8)})),
        {key:'amount',title:'Total'},
      ]
    : columns[kind]
  const cell = (row: Record<string, unknown>, key: string): unknown => {
    if (key.startsWith('day:')) {
      const days = row.days as Record<string, string> | undefined
      return days?.[key.slice(4)] ?? '0.0000'
    }
    return row[key]
  }
  const exportRows = () => [
    headers.map(column => column.title),
    ...rows.map(row => headers.map(col => present(cell(row,col.key)))),
  ]
  const exportContent = (type: 'txt' | 'html' | 'csv') => {
    if (!report || !paper.current) return
    const quote = (value: string) => '"' + (/^\s*[=+@-]/.test(value) ? "'" : '') + value.replace(/"/g, '""') + '"'
    const content = type === 'csv'
      ? '\uFEFF' + exportRows().map(row => row.map(quote).join(',')).join('\r\n')
      : type === 'html'
        ? '<!doctype html><html><head><meta charset="utf-8"></head><body>' + paper.current.innerHTML + '</body></html>'
        : paper.current.innerText
    const url = URL.createObjectURL(new Blob([content], {
      type: type === 'csv' ? 'text/csv;charset=utf-8' : type === 'html' ? 'text/html;charset=utf-8' : 'text/plain;charset=utf-8',
    }))
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = kind + '-report.' + type
    anchor.click()
    URL.revokeObjectURL(url)
  }
  if (!preview) return <section className="dba-page">
    <div className="dba-select-card">
      <h2>{selected.label} — Selection Options</h2>
      <div className="dba-fields">
        <div>
          {(kind === 'advance' || kind === 'product-transactions' || kind.startsWith('fbr-')) &&
            <label className="desktop-field"><span>Product</span>
              <UiSelect value={product} onChange={setProduct} triggerClassName="desktop-select" aria-label="Product"
                options={[{value:'',label:'All products'},
                  ...(options.data?.products ?? []).map(p => ({value:p.ulid,label:[p.product_number,p.name].filter(Boolean).join(' — ')}))]}/>
            </label>}
          {kind === 'daybook' &&
            <label className="desktop-field"><span>Ledger account (optional)</span>
              <UiSelect value={account} onChange={setAccount} triggerClassName="desktop-select" aria-label="Ledger account"
                options={[{value:'',label:'All accounts'},
                  ...(options.data?.accounts ?? []).map(p => ({value:p.ulid,label:[p.code,p.name].filter(Boolean).join(' — ')}))]}/>
            </label>}
          {(kind === 'daybook' || kind === 'detailed' || kind === 'product-transactions') && <div className="dba-types">
            <strong>Document types (none selected = all)</strong>
            {(kind === 'daybook' ? [...TYPES, 'voucher'] : [...TYPES]).map(type => <label key={type}>
              <input type="checkbox" checked={types.includes(type)} onChange={event =>
                setTypes(previous => event.target.checked ? [...previous, type] : previous.filter(t => t !== type))}/>
              {titleType(type)}
            </label>)}
          </div>}
          {(kind === 'detailed' || kind === 'daybook' || kind.startsWith('fbr-')) &&
            <label className="desktop-field"><span>Report format</span>
              <UiSelect value={format} onChange={setFormat} triggerClassName="desktop-select" aria-label="Report format"
                options={(kind === 'detailed'
                  ? [{value:'summary',label:'Party Summary'},{value:'daily',label:'Day by Day'},
                     {value:'detail',label:'Transaction Detail'}]
                  : kind === 'daybook'
                    ? [{value:'short',label:'Daily Summary'},{value:'ledger',label:'Account Ledger'}]
                    : [{value:'short',label:'Short Register'},{value:'invoice',label:'Invoice Wise Register'}])}/>
            </label>}
        </div>
        <div className="dba-dates">
          <label><span>Date range (maximum 31 days)</span>
            <UiDateRangePicker
              fromInputProps={{type:'date',value:from,onChange:event=>setFrom(event.target.value)}}
              toInputProps={{type:'date',value:to,onChange:event=>setTo(event.target.value)}}
            />
          </label>
        </div>
      </div>
      {options.isLoading && <UiSkeleton label="Loading report filters…" />}
      {options.isError && <p role="alert">Report filters could not be loaded.</p>}
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
      <UiButton variant="info" label="View Report"
        disabled={!from || !to || from > to || options.isLoading || options.isError ||
          (kind === 'daybook' && format === 'ledger' && !account)}
        onClick={() => {
          const query = new URLSearchParams({from,to})
          if (product) query.set('product_ulid',product)
          if (kind === 'daybook' && format === 'ledger' && account) query.set('account_ulid',account)
          if (kind === 'daybook' || kind === 'detailed' || kind.startsWith('fbr-')) query.set('format',format)
          types.forEach(type => query.append('types[]',type))
          openModule(selected.path + '?view=preview&' + query.toString())
        }}
      />
    </div>
  </section>

  return <section className="dba-page dba-preview">
    <ReportViewerToolbar
      ready={Boolean(report)} busy={reportQuery.isFetching}
      search={search} onSearchChange={setSearch}
      onFind={() => {
        const term = search.toLowerCase().trim()
        if (term) Array.from(paper.current?.querySelectorAll('td,th') ?? [])
          .find(node => node.textContent?.toLowerCase().includes(term))?.scrollIntoView({block:'center'})
      }}
      zoom={zoom} onZoomChange={setZoom}
      onPrint={() => window.print()}
      onCopy={async () => {if (paper.current) await navigator.clipboard.writeText(paper.current.innerText)}}
      onExport={exportContent}
      onRefresh={() => void reportQuery.refetch()}
      onEditOptions={() => openModule(selected.path)}
      onClose={closeActiveTab}
    />
    {reportQuery.isLoading && <UiSkeleton label="Loading report…" />}
    {reportQuery.isError && <p role="alert">Report could not be generated. Check selection, permissions or date range.</p>}
    {report && <article className="dba-paper daily-report-sheet" ref={paper} style={{zoom: zoom + '%'}}>
      <header>
        <h1>{selected.label.toUpperCase()}</h1>
        <p>{params.get('from')} to {params.get('to')}</p>
        {kind.startsWith('fbr-') && <p><strong>INTERNAL TAX REVIEW — NOT FBR FILING-READY</strong></p>}
      </header>
      <table>
        <thead><tr>{headers.map(col => <th key={col.key}>{col.title}</th>)}</tr></thead>
        <tbody>
          {rows.map((row,i) => <tr key={String(row.line_ulid ?? row.ulid ?? row.document_ulid ?? i) + '-' + i}>
            {headers.map(col => <td key={col.key} className={typeof cell(row,col.key) === 'string' &&
                /^-?\d+(?:\.\d+)?$/.test(String(cell(row,col.key))) ? 'dba-amount' : undefined}>
              {display(cell(row,col.key))}
              {kind === 'detailed' && col.key === 'party' && Array.isArray(row.lines) &&
                <div className="dba-account-meta">
                  {(row.lines as {product?:{name:string};quantity:string;amount:string}[]).map((line,j) =>
                    <div key={j}>{line.product?.name ?? '—'}: {display(line.quantity)} / {display(line.amount)}</div>)}
                </div>}
            </td>)}
          </tr>)}
          {rows.length === 0 && <tr><td colSpan={headers.length}>No posted data for the selected period.</td></tr>}
        </tbody>
      </table>
      {report.totals && <section className="dba-report-totals">
        <h2>Totals</h2>
        <p>{Object.entries(report.totals).map(([key,value]) =>
          <span key={key}>{titleType(key)}: <strong>{display(value)}</strong>　</span>)}</p>
      </section>}
      {report.ledger && <section>
        <h2>{report.ledger.account.name} — Posted Account Ledger</h2>
        <p>Opening: {display(report.ledger.opening)}　Closing: {display(report.ledger.closing)}</p>
        <table><thead><tr><th>Date</th><th>Voucher</th><th>Description</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead>
          <tbody>{report.ledger.rows.map(line => <tr key={String(line.ulid)}>
            {['date','number','description','debit','credit','balance'].map(field =>
              <td key={field}>{display(line[field])}</td>)}
          </tr>)}</tbody>
        </table>
      </section>}
      {report.limitations.map(note => <p key={note}>{note}</p>)}
    </article>}
  </section>
}
