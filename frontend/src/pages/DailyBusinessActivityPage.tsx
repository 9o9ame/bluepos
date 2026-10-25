import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { apiFetch } from '../api/client'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiButton } from '../components/ui/UiButton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './DailyBusinessActivityPage.css'

type AccountType = { ulid: string; name: string }
type LedgerRow = { line_ulid: string; date: string; trans_no: string; doc: string; narration: string; debit: string; credit: string; balance: string }
type LedgerSection = {
  account: { ulid: string; name: string; address: string | null; area: string | null; account_type: string | null; normal_balance: 'debit' | 'credit' | null }
  opening: string; debit: string; credit: string; closing: string; rows: LedgerRow[]
}
type LedgerReport = { start_date: string; end_date: string; sections: LedgerSection[] }
const today = () => {
  const d = new Date()
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}
const money = (value: string) => {
  const negative = value.startsWith('-')
  const digits = negative ? value.slice(1) : value
  const [whole, fractional = ''] = digits.split('.')
  const formatted = (whole || '0').replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  const cents = fractional.slice(0, 4).replace(/0+$/, '')
  return (negative ? '-' : '') + formatted + (cents ? '.' + cents : '')
}
const balance = (value: string, creditNormal: boolean) => {
  const neg = value.startsWith('-')
  return `${money(neg ? value.slice(1) : value)} ${neg ? (creditNormal ? 'Dr' : 'Cr') : (creditNormal ? 'Cr' : 'Dr')}`
}
export function DailyBusinessActivityPage() {
  const { openModule, closeActiveTab } = useWorkspace()
  const params = new URLSearchParams(useLocation().search)
  const preview = params.get('view') === 'preview'
  const [start, setStart] = useState(params.get('start_date') || today())
  const [end, setEnd] = useState(params.get('end_date') || today())
  const [selected, setSelected] = useState<string[]>(params.get('types')?.split(',').filter(Boolean) || [])
  const [search, setSearch] = useState('')
  const [zoom, setZoom] = useState(100)
  const paperRef = useRef<HTMLElement>(null)
  const typesQuery = useQuery({
    queryKey: ['daily-business-account-types'],
    queryFn: () => apiFetch<{ account_types: AccountType[] }>('/api/reports/accounting/daily-business-activity/types'),
    enabled: !preview,
  })
  const reportQuery = useQuery({
    queryKey: ['daily-business-report', params.toString()],
    queryFn: () => {
      const search = new URLSearchParams({ start_date: start, end_date: end })
      selected.forEach((ulid, i) => search.set(`account_type_ulids[${i}]`, ulid))
      return apiFetch<LedgerReport>(`/api/reports/accounting/daily-business-activity?${search}`)
    },
    enabled: preview,
  })
  const exportReport = (format: 'txt' | 'html' | 'csv') => {
    const paper = paperRef.current
    if (!paper || !reportQuery.data) return
    const safe = (value: string) => {
      const guarded = /^\s*[=+@-]/.test(value) ? "'" + value : value
      return '"' + guarded.replace(/"/g, '""') + '"'
    }
    const content = format === 'csv'
      ? '\uFEFF' + [['Account', 'Date', 'Transaction', 'Document', 'Narration', 'Debit', 'Credit', 'Balance'],
        ...reportQuery.data.sections.flatMap((section) => section.rows.map((row) =>
          [section.account.name, row.date, row.trans_no, row.doc, row.narration,
            row.debit, row.credit, row.balance]))].map((line) => line.map(safe).join(',')).join('\r\n')
      : format === 'html'
        ? '<!doctype html><html><head><meta charset="utf-8"></head><body>' + paper.innerHTML + '</body></html>'
        : paper.innerText || paper.textContent || ''
    const url = URL.createObjectURL(new Blob([content], { type: format === 'csv' ? 'text/csv;charset=utf-8' : format === 'html' ? 'text/html;charset=utf-8' : 'text/plain;charset=utf-8' }))
    const link = document.createElement('a')
    link.href = url
    link.download = 'daily-ledger-wise-activity.' + format
    link.click()
    URL.revokeObjectURL(url)
  }
  const copyReport = async () => {
    if (paperRef.current) await navigator.clipboard.writeText(paperRef.current.innerText || '')
  }
  const findReport = () => {
    const term = search.trim().toLowerCase()
    if (!term) return
    Array.from(paperRef.current?.querySelectorAll('td,th,h1,strong') ?? [])
      .find((node) => node.textContent?.toLowerCase().includes(term))
      ?.scrollIntoView({ block: 'center' })
  }
  if (preview) return <section className="dba-page dba-preview">
    <ReportViewerToolbar
      ready={Boolean(reportQuery.data)}
      busy={reportQuery.isFetching}
      search={search}
      onSearchChange={setSearch}
      onFind={findReport}
      zoom={zoom}
      onZoomChange={setZoom}
      onPrint={() => window.print()}
      onCopy={() => void copyReport()}
      onExport={exportReport}
      onRefresh={() => void reportQuery.refetch()}
      onEditOptions={() => openModule('/reports/daily/business-activity')}
      onClose={closeActiveTab}
    />
    {reportQuery.isLoading && <p>Loading daily ledger report…</p>}
    {reportQuery.isError && <p role="alert">Unable to load report.</p>}
    {reportQuery.data && <article className="dba-paper" ref={paperRef} style={{ zoom: `${zoom}%` }}>
      <header><h1>DAILY LEDGER WISE ACTIVITY</h1>
        <p>From {reportQuery.data.start_date} to {reportQuery.data.end_date}</p></header>
      {reportQuery.data.sections.length === 0 && <p>No posted ledger transactions for the selected account types and period.</p>}
      {reportQuery.data.sections.map((section) => {
        const creditNormal = section.account.normal_balance === 'credit'
        return <div className="dba-account" key={section.account.ulid}>
          <div className="dba-account-title"><strong>{section.account.name}</strong>
            <span>For the date: {reportQuery.data.end_date}</span></div>
          <div className="dba-account-meta"><strong>Name: {section.account.name}</strong>
            <span>Address: {section.account.address || '—'}</span>
            <span>Area: {section.account.area || '(NONE)'}</span></div>
          <table><thead><tr><th>#</th><th>Date</th><th>Trans#</th><th>DOC</th><th>Narration</th><th>Debit</th><th>Credit</th><th>Balance</th></tr></thead>
            <tbody>
              <tr><td colSpan={7} className="dba-caption">Opening Balance:</td><td className="dba-amount">{balance(section.opening, creditNormal)}</td></tr>
              {section.rows.map((row, i) => <tr key={row.line_ulid}>
                <td>{i + 1}</td><td>{row.date}</td><td>{row.trans_no}</td><td>{row.doc}</td>
                <td>{row.narration}</td><td className="dba-amount">{money(row.debit)}</td>
                <td className="dba-amount">{money(row.credit)}</td>
                <td className="dba-amount">{balance(row.balance, creditNormal)}</td>
              </tr>)}
            </tbody>
            <tfoot><tr><th colSpan={5} className="dba-caption">Closing Balance:</th>
              <td className="dba-amount">{money(section.debit)}</td>
              <td className="dba-amount">{money(section.credit)}</td>
              <td className="dba-amount">{balance(section.closing, creditNormal)}</td></tr></tfoot>
          </table>
        </div>
      })}
    </article>}
  </section>
  const invalid = !start || !end || start > end || typesQuery.isLoading || typesQuery.isError
  return <section className="dba-page">
    <div className="dba-select-card">
      <h2>Selection Options</h2>
      <div className="dba-fields">
        <div><strong>Account Types</strong>
          <div className="dba-types">
            {typesQuery.isLoading && <p>Loading account types…</p>}
            {typesQuery.isError && <p role="alert">Unable to load account types.</p>}
            {(typesQuery.data?.account_types || []).map((type) => <label key={type.ulid}>
              <input type="checkbox" checked={selected.includes(type.ulid)} onChange={(event) =>
                setSelected((current) => event.target.checked ? [...current, type.ulid] : current.filter((id) => id !== type.ulid))} />
              {type.name}
            </label>)}
          </div>
        </div>
        <div className="dba-dates">
          <label>From<input type="date" value={start} onChange={(event) => setStart(event.target.value)} /></label>
          <label>To<input type="date" value={end} onChange={(event) => setEnd(event.target.value)} /></label>
        </div>
      </div>
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab} />
      <UiButton variant="info" label="View Report" disabled={invalid} onClick={() => {
        const query = new URLSearchParams({ view: 'preview', start_date: start, end_date: end, types: selected.join(',') })
        openModule(`/reports/daily/business-activity?${query}`)
      }} />
    </div>
  </section>
}
