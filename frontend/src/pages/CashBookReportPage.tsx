import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { ClipboardCopy, Download, FileCode, FileText, Minus, Plus, Printer, RefreshCw, Search, ArrowLeft, X } from 'lucide-react'
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
// All cash flow aggregations use integer minor units (4 decimal places), never floats.
function moneyUnits(value: string): bigint {
  const negative = value.startsWith('-')
  const digits = negative ? value.slice(1) : value
  const [whole, decimal = ''] = digits.split('.')
  const units = BigInt(whole || '0') * 10000n + BigInt(decimal.padEnd(4, '0').slice(0, 4) || '0')
  return negative ? -units : units
}
function unitsText(value: bigint): string {
  const negative = value < 0n
  const positive = negative ? -value : value
  return `${negative ? '-' : ''}${positive / 10000n}.${String(positive % 10000n).padStart(4, '0')}`
}
const CASH_FLOW_SOURCES = [
  ['RECEIVING VOUCHER', 'Receiving Voucher'],
  ['PAYMENT VOUCHER', 'Payments / Expenses'],
  ['JOURNAL VOUCHER', 'Journal Voucher'],
  ['PURCHASE RETURN', 'Purchase Return'],
  ['PURCHASE INVOICE', 'Purchases'],
  ['SALE INVOICE', 'Sales Invoice'],
  ['SALE RETURN', 'Sales Return'],
] as const

function weekdayLabel(date: string) {
  const [year, month, day] = date.split('-').map(Number)
  return new Intl.DateTimeFormat('en-US', { weekday: 'long', timeZone: 'UTC' })
    .format(new Date(Date.UTC(year, month - 1, day)))
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
  const flowPreview = params.get('view') === 'cash_flow_preview'
  const cashFlow = params.get('view') === 'cash_flow' || flowPreview
  const [from, setFrom] = useState(params.get('start_date') || localDay().slice(0, 8) + '01')
  const [to, setTo] = useState(params.get('end_date') || localDay())
  const [includeBank, setIncludeBank] = useState(params.get('include_bank') === '1')
  const [summaryOnly, setSummaryOnly] = useState(params.get('summary_only') === '1')
  const [showDetails, setShowDetails] = useState(params.get('detail') !== '0')
  const [group, setGroup] = useState<'none' | 'date' | 'document'>((params.get('group_by') as 'none' | 'date' | 'document') || 'none')
  const [accountUlids, setAccountUlids] = useState<string[]>(params.get('account_ulids')?.split(',').filter(Boolean) ?? [])
  const [search, setSearch] = useState('')
  const [zoom, setZoom] = useState(100)
  const [copyLabel, setCopyLabel] = useState('Copy')
  const paperRef = useRef<HTMLElement>(null)
  const accountsQuery = useQuery({ queryKey: ['cash-book-accounts'], queryFn: fetchCashBookAccounts })
  const accounts = (accountsQuery.data?.accounts ?? []).filter((a) => includeBank || a.is_cash)
  const reportQuery = useQuery({
    queryKey: ['cash-book-report', from, to, includeBank, summaryOnly, group, accountUlids],
    queryFn: () => fetchCashBookReport({
      start_date: from, end_date: to, include_bank: includeBank, summary_only: summaryOnly,
      group_by: group, account_ulids: accountUlids,
    }),
    enabled: (preview || cashFlow) && Boolean(from && to && from <= to),
  })
  const launch = (view: 'preview' | 'cash_flow' | 'cash_flow_preview') => {
    const q = new URLSearchParams({
      view, start_date: from, end_date: to, include_bank: includeBank ? '1' : '0',
      summary_only: summaryOnly ? '1' : '0', detail: showDetails ? '1' : '0',
      group_by: group, account_ulids: accountUlids.join(','),
    })
    openModule(`/reports/accounts/cash-book?${q}`)
  }
  const report = reportQuery.data
  const rows = [...(report?.rows ?? [])].sort((a, b) => group === 'document'
      ? a.doc.localeCompare(b.doc) || (a.date ?? '').localeCompare(b.date ?? '')
      : (a.date ?? '').localeCompare(b.date ?? '') || a.line_ulid.localeCompare(b.line_ulid))
  const dailyCashFlow = (() => {
    const days = new Map<string, {
      debit: bigint; credit: bigint; sources: Map<string, bigint>
    }>()
    for (const row of rows) {
      const day = row.date ?? ''
      if (!day) continue
      if (!days.has(day)) days.set(day, { debit: 0n, credit: 0n, sources: new Map() })
      const total = days.get(day)!
      const debit = moneyUnits(row.debit)
      const credit = moneyUnits(row.credit)
      total.debit += debit
      total.credit += credit
      total.sources.set(row.doc, (total.sources.get(row.doc) ?? 0n) + debit - credit)
    }
    let opening = moneyUnits(report?.totals.opening ?? '0.0000')
    return [...days.entries()].sort(([a], [b]) => a.localeCompare(b)).map(([day, totals]) => {
      const closing = opening + totals.debit - totals.credit
      const result = { day, opening, closing, ...totals }
      opening = closing
      return result
    })
  })()
  const exportFile = (format: 'txt' | 'html' | 'csv') => {
    const paper = paperRef.current
    if (!paper || !report) return
    const filename = `${cashFlow ? 'cash-flow' : 'cash-book'}-${report.start_date}-${report.end_date}`
    let content: string
    let mime: string
    if (format === 'csv') {
      const escapeCell = (value: string) => {
        const safe = /^\s*[=+@-]/.test(value) ? "'" + value : value
        return '"' + safe.replace(/"/g, '""') + '"'
      }
      const data = [['Date', 'Reference', 'Voucher', 'Account', 'Description', 'Debit', 'Credit'],
        ...(report.rows.map((row) => [row.date ?? '', row.trans_no, row.doc,
          row.account_name, row.description, row.debit, row.credit]))]
      content = '\uFEFF' + data.map((line) => line.map(escapeCell).join(',')).join('\r\n')
      mime = 'text/csv;charset=utf-8'
    } else if (format === 'html') {
      content = '<!doctype html><html><head><meta charset="utf-8"><title>Cash Book</title></head><body>' +
        paper.innerHTML + '</body></html>'
      mime = 'text/html;charset=utf-8'
    } else {
      content = paper.innerText || paper.textContent || ''
      mime = 'text/plain;charset=utf-8'
    }
    const url = URL.createObjectURL(new Blob([content], { type: mime }))
    const link = document.createElement('a')
    link.href = url
    link.download = `${filename}.${format}`
    link.click()
    URL.revokeObjectURL(url)
  }
  const copyReport = async () => {
    if (!paperRef.current) return
    try {
      await navigator.clipboard.writeText(paperRef.current.innerText || paperRef.current.textContent || '')
      setCopyLabel('Copied')
    } catch {
      setCopyLabel('Copy failed')
    }
  }
  const findReport = () => {
    const term = search.trim().toLocaleLowerCase()
    if (!term) return
    const cells = paperRef.current?.querySelectorAll('td, th, h2, h3, p')
    Array.from(cells ?? []).find((cell) => cell.textContent?.toLocaleLowerCase().includes(term))
      ?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }
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
      <section className={flowPreview ? "cashbook-page cashbook-view cashbook-flow-view cashbook-flow-preview" : cashFlow ? "cashbook-page cashbook-view cashbook-flow-view" : "cashbook-page cashbook-view"}>
        {(!cashFlow || flowPreview) ? <div className="trial-balance-viewer-toolbar cashbook-tools">
          <UiButton variant="info" icon={<Printer size={15}/>} label="Print / PDF" disabled={!report} onClick={() => window.print()} />
          <UiButton variant="default" icon={<ClipboardCopy size={15}/>} label={copyLabel} disabled={!report} onClick={() => void copyReport()} />
          <UiButton variant="default" icon={<FileText size={15}/>} label="Text" disabled={!report} onClick={() => exportFile('txt')} />
          <UiButton variant="default" icon={<FileCode size={15}/>} label="HTML" disabled={!report} onClick={() => exportFile('html')} />
          <UiButton variant="info" icon={<Download size={15}/>} label="Export CSV" disabled={!report} onClick={() => exportFile('csv')} />
          <UiSelect className="trial-balance-export-choice" aria-label="Export report format"
            value="" searchable={false} options={[
              {value:'',label:'Export as…'}, {value:'csv',label:'CSV (Excel compatible)'},
              {value:'html',label:'HTML document'}, {value:'txt',label:'Plain text'},
              {value:'pdf',label:'PDF (browser print)'},
            ]} onChange={(format) => {
              if (format === 'csv' || format === 'html' || format === 'txt') exportFile(format)
              else if (format === 'pdf') window.print()
            }} />
          <div className="trial-balance-viewer-search">
            <Search size={14} aria-hidden />
            <input aria-label="Search report text" placeholder="Search report…" value={search}
              onChange={(event) => setSearch(event.target.value)}
              onKeyDown={(event) => { if (event.key === 'Enter') findReport() }} />
            <UiButton variant="default" label="Find" disabled={!report || !search.trim()} onClick={findReport} />
          </div>
          <div className="trial-balance-viewer-zoom">
            <UiButton variant="default" icon={<Minus size={14}/>} aria-label="Zoom out" disabled={zoom <= 50}
              onClick={() => setZoom((value) => Math.max(50, value - 25))} />
            <span>{zoom}%</span>
            <UiButton variant="default" icon={<Plus size={14}/>} aria-label="Zoom in" disabled={zoom >= 200}
              onClick={() => setZoom((value) => Math.min(200, value + 25))} />
            <UiButton variant="default" label="Fit" onClick={() => setZoom(100)} />
          </div>
          <UiButton variant="info" icon={<RefreshCw size={15}/>} label="Refresh" disabled={reportQuery.isFetching}
            onClick={() => void reportQuery.refetch()} />
          <UiButton variant="default" icon={<ArrowLeft size={15}/>} label="Edit Options"
            onClick={() => openModule('/reports/accounts/cash-book')} />
          <UiButton variant="info" icon={<X size={15}/>} label="Close" onClick={closeActiveTab} />
        </div> : null}
        {cashFlow ? <div className="cashbook-flow-heading">
          <h1>Cash Flow Statement</h1>
          {flowPreview ? <span>From: {asLabel(from)}　To: {asLabel(to)}</span> : (
            <div className="cashbook-flow-filters">
              <label>From: <input aria-label="Cash flow from date" type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
              <label>To: <input aria-label="Cash flow to date" type="date" value={to} onChange={(e) => setTo(e.target.value)} /></label>
              <UiButton variant="info" icon={<RefreshCw size={15}/>} label="Refresh"
                disabled={reportQuery.isFetching || !from || !to || from > to}
                onClick={() => void reportQuery.refetch()} />
            </div>
          )}
        </div> : null}
        {cashFlow && from > to ? <p role="alert">From Date must not exceed To Date.</p> : null}
        {reportQuery.isLoading ? <p>Loading Cash Book…</p> : null}
        {reportQuery.isError ? <p role="alert">Unable to load Cash Book. Please check the selected accounts and dates.</p> : null}
        {report ? <article className="cashbook-paper" ref={paperRef} style={{ zoom: `${flowPreview || preview ? zoom : 100}%` }}>
          <header className={cashFlow ? "cashbook-flow-print-heading" : undefined}><h2>{cashFlow ? 'Cash Flow Statement' : 'CASH BOOK'}</h2>
            <p>From: {asLabel(report.start_date)} to: {asLabel(report.end_date)}</p>
          </header>
          <div className={cashFlow ? "cashbook-balances cashbook-flow-balances" : "cashbook-balances"}>
            <span>Previous Balance: <b>{displayMoney(report.totals.opening)}</b></span>
            <span>Total Income: <b>{displayMoney(report.totals.received)}</b></span>
            <span>Total Payments: <b>{displayMoney(report.totals.paid)}</b></span>
            <span>Closing Balance: <b>{displayMoney(report.totals.closing)}</b></span>
          </div>
          {cashFlow ? (
            <div className="cashbook-flow-grid">
              <table className="cashbook-flow-table">
                <thead><tr>
                  <th>#</th><th>Date</th><th>Description</th><th>Opening</th>
                  {CASH_FLOW_SOURCES.map(([key, label]) => <th key={key}>{label}</th>)}
                  <th>Debit</th><th>Today Cash</th><th>Credit</th><th>Closing</th>
                </tr></thead>
                <tbody>{dailyCashFlow.map((day, index) => (
                  <tr key={day.day}>
                    <td>{index + 1}</td>
                    <td>{asLabel(day.day)}</td>
                    <td>{weekdayLabel(day.day)}</td>
                    <td className="cashbook-money">{displayMoney(unitsText(day.opening))}</td>
                    {CASH_FLOW_SOURCES.map(([key]) => <td className="cashbook-money" key={key}>
                      {(day.sources.get(key) ?? 0n) === 0n ? '–' : displayMoney(unitsText(day.sources.get(key)!))}
                    </td>)}
                    <td className="cashbook-money">{displayMoney(unitsText(day.debit))}</td>
                    <td className="cashbook-money">{displayMoney(unitsText(day.opening + day.debit))}</td>
                    <td className="cashbook-money">{displayMoney(unitsText(day.credit))}</td>
                    <td className="cashbook-money">{displayMoney(unitsText(day.closing))}</td>
                  </tr>
                ))}</tbody>
                <tfoot><tr>
                  <th colSpan={3}>{dailyCashFlow.length} Entries</th>
                  <td className="cashbook-money">{displayMoney(unitsText(dailyCashFlow.reduce((sum, d) => sum + d.opening, 0n)))}</td>
                  {CASH_FLOW_SOURCES.map(([key]) => <td key={key} className="cashbook-money">
                    {displayMoney(unitsText(dailyCashFlow.reduce((sum, d) => sum + (d.sources.get(key) ?? 0n), 0n)))}
                  </td>)}
                  <td className="cashbook-money">{displayMoney(report.totals.received)}</td>
                  <td className="cashbook-money">{displayMoney(unitsText(dailyCashFlow.reduce((sum, d) => sum + d.opening + d.debit, 0n)))}</td>
                  <td className="cashbook-money">{displayMoney(report.totals.paid)}</td>
                  <td className="cashbook-money">{displayMoney(unitsText(dailyCashFlow.reduce((sum, d) => sum + d.closing, 0n)))}</td>
                </tr></tfoot>
              </table>
            </div>
          ) : <div className="cashbook-dual">
            <section><h3>Receivings</h3>{entries(receipts, 'debit')}</section>
            <section><h3>Payments</h3>{entries(payments, 'credit')}</section>
          </div>}
          {summaryOnly ? <p>Summary only — transaction details hidden.</p> : null}
        </article> : null}
        {cashFlow && !flowPreview ? <div className="cashbook-flow-footer">
          <UiButton variant="info" label="Preview" disabled={!report || reportQuery.isFetching || from > to}
            onClick={() => launch('cash_flow_preview')} />
        </div> : null}
      </section>
    )
  }
  return (
    <section className="cashbook-page">
      <h1 className="cashbook-title">Cash Book</h1>
      <div className="cashbook-selection">
        <div className="cashbook-selection-form">
          <h2>Selection Options</h2>
          <div className="cashbook-date-grid">
            <label>From Date<input type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
            <label>To Date<input type="date" value={to} onChange={(e) => setTo(e.target.value)} /></label>
          </div>
          <div className="cashbook-checkbox-grid">
          <label className="cashbook-check" title="Purchase-source inclusion is pending verified accounting mapping">
            <input type="checkbox" disabled /> Show Purchase also
          </label>
          <label className="cashbook-check" title="Non-cash transaction classification is pending verification">
            <input type="checkbox" disabled /> Non Cash Also
          </label>
          <label className="cashbook-check"><input type="checkbox" checked={includeBank}
            onChange={(e) => { setIncludeBank(e.target.checked); setAccountUlids([]) }} /> Include Bank Book</label>
          <label className="cashbook-check"><input type="checkbox" checked={summaryOnly}
            onChange={(e) => setSummaryOnly(e.target.checked)} /> Only Summary</label>
          <label className="cashbook-check"><input type="checkbox" checked={!showDetails}
            onChange={(e) => setShowDetails(!e.target.checked)} /> Don't Show Detail</label>
          </div>
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
          <p className="cashbook-note">Posted journals only · current branch. Unsupported reference filters (area, salesman, purchase/non-cash) are not applied.</p>
        </div>
        <div className="cashbook-footer-actions">
          <div className="cashbook-actions">
            <UiButton variant="info" label="Preview" disabled={from > to || !from || !to} onClick={() => launch('preview')} />
            <UiButton variant="default" label="Close" onClick={closeActiveTab} />
          </div>
          <UiButton variant="info" label="Show Cash Flow" disabled={from > to || !from || !to} onClick={() => launch('cash_flow')} />
        </div>
      </div>
    </section>
  )
}
