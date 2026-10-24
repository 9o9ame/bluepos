import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { fetchCashBookAccounts, fetchCashBookReport, type CashBookEntry } from '../api/cashBookReport'
import './CashBookReportPage.css'

function localDay() {
  const now = new Date()
  return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}
function displayMoney(value: string) {
  const [whole, fractional = ''] = value.split('.')
  return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fractional.replace(/0+$/, '') ? '.' + fractional.replace(/0+$/, '') : '')
}
function asLabel(date: string | null) {
  if (!date) return ''
  const [year, month, day] = date.split('-')
  return `${day}-${month}-${year}`
}

export function CashBookReportPage() {
  const { openModule, closeActiveTab } = useWorkspace()
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const preview = params.get('view') === 'preview'
  const cashFlow = params.get('view') === 'cash_flow'
  const [from, setFrom] = useState(params.get('start_date') || localDay().slice(0, 8) + '01')
  const [to, setTo] = useState(params.get('end_date') || localDay())
  const [includeBank, setIncludeBank] = useState(params.get('include_bank') === '1')
  const [summaryOnly, setSummaryOnly] = useState(params.get('summary_only') === '1')
  const [showDetails, setShowDetails] = useState(params.get('detail') !== '0')
  const [group, setGroup] = useState<'none' | 'date' | 'document'>((params.get('group_by') as 'none' | 'date' | 'document') || 'none')
  const [accountUlids, setAccountUlids] = useState<string[]>(params.get('account_ulids')?.split(',').filter(Boolean) ?? [])
  const [search, setSearch] = useState('')
  const accountsQuery = useQuery({ queryKey: ['cash-book-accounts'], queryFn: fetchCashBookAccounts })
  const accounts = (accountsQuery.data?.accounts ?? []).filter((a) => includeBank || a.is_cash)
  const reportQuery = useQuery({
    queryKey: ['cash-book-report', from, to, includeBank, summaryOnly, group, accountUlids],
    queryFn: () => fetchCashBookReport({
      start_date: from, end_date: to, include_bank: includeBank, summary_only: summaryOnly,
      group_by: group, account_ulids: accountUlids,
    }),
    enabled: preview || cashFlow,
  })
  const launch = (view: 'preview' | 'cash_flow') => {
    const q = new URLSearchParams({
      view, start_date: from, end_date: to, include_bank: includeBank ? '1' : '0',
      summary_only: summaryOnly ? '1' : '0', detail: showDetails ? '1' : '0',
      group_by: group, account_ulids: accountUlids.join(','),
    })
    openModule(`/reports/accounts/cash-book?${q}`)
  }
  const report = reportQuery.data
  const rows = (report?.rows ?? []).filter((row) =>
    [row.description, row.trans_no, row.doc, row.account_name].some((v) => v.toLowerCase().includes(search.toLowerCase())))
    .sort((a, b) => group === 'document'
      ? a.doc.localeCompare(b.doc) || (a.date ?? '').localeCompare(b.date ?? '')
      : (a.date ?? '').localeCompare(b.date ?? '') || a.line_ulid.localeCompare(b.line_ulid))
  const receipts = rows.filter((r) => r.debit !== '0.0000')
  const payments = rows.filter((r) => r.credit !== '0.0000')
  const groupLabel = (row: CashBookEntry) => group === 'date' ? asLabel(row.date)
    : group === 'document' ? row.doc : ''
  const entries = (items: CashBookEntry[], kind: 'debit' | 'credit') => (
    <table className="cashbook-entries">
      <thead><tr><th>Ref</th><th>#</th><th>Date</th><th>Detail / Narration</th><th>Amount</th></tr></thead>
      <tbody>
        {items.map((row, index) => (
          <tr key={row.line_ulid}>
            <td>{row.doc}</td><td>{row.trans_no}</td><td>{asLabel(row.date)}</td>
            <td>{group !== 'none' && (index === 0 || groupLabel(items[index - 1]) !== groupLabel(row))
              ? <strong className="cashbook-group">{groupLabel(row)}</strong> : null}
              {showDetails ? row.description : row.account_name}
            </td>
            <td className="cashbook-money">{displayMoney(row[kind])}</td>
          </tr>
        ))}
      </tbody>
    </table>
  )
  if (preview || cashFlow) {
    return (
      <section className="cashbook-page cashbook-view">
        <div className="cashbook-tools">
          <UiButton variant="info" label="Print / PDF" disabled={!report} onClick={() => window.print()} />
          <input aria-label="Search entries" placeholder="Search entries…" value={search} onChange={(e) => setSearch(e.target.value)} />
          <UiButton variant="info" label="Refresh" onClick={() => void reportQuery.refetch()} />
          <UiButton variant="default" label="Options" onClick={() => openModule('/reports/accounts/cash-book')} />
          <UiButton variant="default" label="Close" onClick={closeActiveTab} />
        </div>
        {reportQuery.isLoading ? <p>Loading Cash Book…</p> : null}
        {reportQuery.isError ? <p role="alert">Unable to load Cash Book. Please check the selected accounts and dates.</p> : null}
        {report ? <article className="cashbook-paper">
          <header><h2>{cashFlow ? 'Cash Flow Statement' : 'CASH BOOK'}</h2>
            <p>From: {asLabel(report.start_date)} to: {asLabel(report.end_date)}</p>
          </header>
          <div className="cashbook-balances">
            <span>Previous Balance: <b>{displayMoney(report.totals.opening)}</b></span>
            <span>Total Income: <b>{displayMoney(report.totals.received)}</b></span>
            <span>Total Payments: <b>{displayMoney(report.totals.paid)}</b></span>
            <span>Closing Balance: <b>{displayMoney(report.totals.closing)}</b></span>
          </div>
          {cashFlow ? (
            <table className="cashbook-flow-table">
              <thead><tr><th>Date</th><th>Description</th><th>Opening</th><th>Receiving</th><th>Payments</th><th>Closing</th></tr></thead>
              <tbody>{rows.map((row) => <tr key={row.line_ulid}>
                <td>{asLabel(row.date)}</td><td>{row.description}</td><td>—</td>
                <td className="cashbook-money">{displayMoney(row.debit)}</td>
                <td className="cashbook-money">{displayMoney(row.credit)}</td><td>—</td>
              </tr>)}</tbody>
              <tfoot><tr><th colSpan={3}>Total</th><td>{displayMoney(report.totals.received)}</td>
                <td>{displayMoney(report.totals.paid)}</td><td>{displayMoney(report.totals.closing)}</td></tr></tfoot>
            </table>
          ) : <div className="cashbook-dual">
            <section><h3>Receivings</h3>{entries(receipts, 'debit')}</section>
            <section><h3>Payments</h3>{entries(payments, 'credit')}</section>
          </div>}
          {summaryOnly ? <p>Summary only — transaction details hidden.</p> : null}
        </article> : null}
      </section>
    )
  }
  return (
    <section className="cashbook-page">
      <h1 className="cashbook-title">Cash Book</h1>
      <div className="cashbook-selection">
        <div className="cashbook-selection-form">
          <h2>Date Selection</h2>
          <div className="cashbook-date-grid">
            <label>From Date<input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
            <label>To Date<input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></label>
          </div>
          <label className="cashbook-check"><input type="checkbox" checked={includeBank}
            onChange={(e) => { setIncludeBank(e.target.checked); setAccountUlids([]) }} /> Include Bank Book</label>
          <label className="cashbook-check"><input type="checkbox" checked={summaryOnly}
            onChange={(e) => setSummaryOnly(e.target.checked)} /> Only Summary</label>
          <label className="cashbook-check"><input type="checkbox" checked={!showDetails}
            onChange={(e) => setShowDetails(!e.target.checked)} /> Don't Show Detail</label>
          <label>Cash Book Group By
            <UiSelect value={group} searchable={false} onChange={(value) => setGroup(value as typeof group)}
              options={[{value:'none',label:'No Grouping'}, {value:'date',label:'Date'}, {value:'document',label:'Document'}]} />
          </label>
          <label>Cash / Bank Accounts
            <UiSelect value={accountUlids.length === 1 ? accountUlids[0] : ''}
              options={[{value:'',label:'ALL CASH ACCOUNTS'}, ...accounts.map((a) => ({
                value:a.ulid, label:`${a.code} — ${a.name}`,
              }))]}
              onChange={(value) => setAccountUlids(value ? [value] : [])} />
          </label>
          {accountsQuery.isError ? <p role="alert">Unable to load cash accounts.</p> : null}
          <div className="cashbook-actions">
            <UiButton variant="info" label="Preview" disabled={from > to || !from || !to} onClick={() => launch('preview')} />
            <UiButton variant="default" label="Close" onClick={closeActiveTab} />
          </div>
          <UiButton variant="info" label="Show Cash Flow" disabled={from > to || !from || !to} onClick={() => launch('cash_flow')} />
          <p className="cashbook-note">Posted journals only · current branch. Unsupported reference filters (area, salesman, purchase/non-cash) are not applied.</p>
        </div>
        <div className="cashbook-selection-blank" aria-hidden="true" />
      </div>
    </section>
  )
}
