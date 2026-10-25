import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { apiFetch } from '../api/client'
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
const money = (value: string) => Number(value).toLocaleString('en-PK', { minimumFractionDigits: 0, maximumFractionDigits: 4 })
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
    enabled: preview && selected.length > 0,
  })
  if (preview) return <section className="dba-page dba-preview">
    <div className="dba-toolbar">
      <UiButton variant="info" label="Print / PDF" onClick={() => window.print()} />
      <UiButton variant="info" label="Refresh" onClick={() => void reportQuery.refetch()} />
      <UiButton variant="default" label="Selection" onClick={() => openModule('/reports/daily/business-activity')} />
      <UiButton variant="default" label="Close" onClick={closeActiveTab} />
    </div>
    {reportQuery.isLoading && <p>Loading daily ledger report…</p>}
    {reportQuery.isError && <p role="alert">Unable to load report.</p>}
    {reportQuery.data && <article className="dba-paper">
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
  const invalid = start > end || selected.length === 0
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
