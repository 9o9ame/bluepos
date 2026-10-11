import { useEffect, useMemo, useState } from 'react'
import { Banknote, Printer, RefreshCw } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import {
  fetchCashBook,
  fetchCashPositionAccounts,
  type CashBookRow,
} from '../api/cashPosition'
import { DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { useFeedback } from '../feedback/FeedbackProvider'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './DailyCashPositionPage.css'

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function money(value: string | null | undefined) {
  const amount = Number(value ?? 0)
  return Number.isFinite(amount)
    ? amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : '0.00'
}

function escapeHtml(value: string | null | undefined) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

export function DailyCashPositionPage() {
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const [accountUlid, setAccountUlid] = useState('')
  const [dateFrom, setDateFrom] = useState(todayIso())
  const [dateTo, setDateTo] = useState(todayIso())
  const [ignoreDate, setIgnoreDate] = useState(false)

  const accountsQuery = useQuery({
    queryKey: ['cash-position-accounts'],
    queryFn: fetchCashPositionAccounts,
  })

  useEffect(() => {
    if (!accountUlid && accountsQuery.data?.length) {
      setAccountUlid(accountsQuery.data[0].ulid)
    }
  }, [accountUlid, accountsQuery.data])

  const reportQuery = useQuery({
    queryKey: ['cash-book', accountUlid, dateFrom, dateTo, ignoreDate],
    enabled: Boolean(accountUlid),
    queryFn: () =>
      fetchCashBook(accountUlid, {
        date_from: ignoreDate ? undefined : dateFrom,
        date_to: ignoreDate ? undefined : dateTo,
        ignore_date: ignoreDate,
      }),
  })

  const columns = useMemo<PosGridColumn<CashBookRow>[]>(
    () => [
      { key: 'row', header: '#', width: 52, align: 'center', render: (row) => (reportQuery.data?.rows.indexOf(row) ?? 0) + 1 },
      { key: 'trans', header: 'Trans#', width: 140, render: (row) => row.trans_no || '—' },
      { key: 'doc', header: 'DOC', width: 180, render: (row) => row.doc },
      { key: 'date', header: 'Date', width: 130, render: (row) => row.date ?? '—' },
      { key: 'description', header: 'Description', render: (row) => row.description || '—' },
      { key: 'debit', header: 'Debit', width: 150, align: 'right', render: (row) => money(row.debit) },
      { key: 'credit', header: 'Credit', width: 150, align: 'right', render: (row) => money(row.credit) },
    ],
    [reportQuery.data?.rows],
  )

  function refresh() {
    void accountsQuery.refetch()
    if (accountUlid) void reportQuery.refetch()
  }

  function printView() {
    const report = reportQuery.data
    if (!report) return

    const popup = window.open('', '_blank', 'width=1100,height=800')
    if (!popup) {
      feedback.error('Allow pop-ups to print the Cash Book.', 'Daily Cash Position')
      return
    }

    const period = report.ignore_date
      ? 'ALL DATES'
      : `${escapeHtml(report.date_from)} TO ${escapeHtml(report.date_to)}`

    const rows = report.rows
      .map(
        (row, index) => `
          <tr>
            <td class="center">${index + 1}</td>
            <td>${escapeHtml(row.trans_no)}</td>
            <td>${escapeHtml(row.doc)}</td>
            <td>${escapeHtml(row.date)}</td>
            <td>${escapeHtml(row.description)}</td>
            <td class="num">${money(row.debit)}</td>
            <td class="num">${money(row.credit)}</td>
          </tr>`,
      )
      .join('')

    popup.document.write(`<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Cash Book - ${escapeHtml(report.account.name)}</title>
  <style>
    @page { size: A4 landscape; margin: 10mm; }
    body { font-family: Arial, sans-serif; color: #111; font-size: 11px; }
    h1 { font-size: 16px; text-align: center; margin: 0 0 4px; }
    .meta { display:flex; justify-content:space-between; margin: 0 0 8px; font-weight:700; }
    table { width:100%; border-collapse:collapse; }
    th, td { border:1px solid #777; padding:4px 5px; }
    th { background:#eee; }
    .num { text-align:right; }
    .center { text-align:center; }
    .summary { margin-top:10px; display:grid; grid-template-columns:repeat(5,1fr); gap:6px; }
    .summary div { border:1px solid #777; padding:6px; }
    .summary strong { display:block; margin-bottom:2px; }
  </style>
</head>
<body>
  <h1>CASH BOOK</h1>
  <div class="meta">
    <span>${escapeHtml(report.account.code)} · ${escapeHtml(report.account.name)}</span>
    <span>${period}</span>
  </div>
  <table>
    <thead>
      <tr><th>#</th><th>Trans#</th><th>DOC</th><th>Date</th><th>Description</th><th>Debit</th><th>Credit</th></tr>
    </thead>
    <tbody>
      <tr><td></td><td></td><td></td><td></td><td><strong>OPENING</strong></td><td class="num">${money(report.summary.opening)}</td><td></td></tr>
      ${rows || '<tr><td colspan="7" class="center">No posted cash transactions in this period.</td></tr>'}
      <tr><td></td><td></td><td></td><td></td><td><strong>CLOSING</strong></td><td class="num">${money(report.summary.closing)}</td><td></td></tr>
    </tbody>
  </table>
  <div class="summary">
    <div><strong>Opening</strong>${money(report.summary.opening)}</div>
    <div><strong>Received</strong>${money(report.summary.received)}</div>
    <div><strong>Paid</strong>${money(report.summary.paid)}</div>
    <div><strong>Bal</strong>${money(report.summary.balance)}</div>
    <div><strong>Closing</strong>${money(report.summary.closing)}</div>
  </div>
  <script>window.addEventListener('load', () => window.print())</script>
</body>
</html>`)
    popup.document.close()
  }

  const report = reportQuery.data

  return (
    <DesktopPanel
      className="daily-cash-position"
      title="Cash book view"
      toolbar={
        <>
          <UiButton variant="info" icon={<RefreshCw size={14} />} onClick={refresh}>Refresh</UiButton>
          <UiButton variant="info" icon={<Printer size={14} />} disabled={!report} onClick={printView}>Print View</UiButton>
          <UiButton variant="info" onClick={closeActiveTab}>Close</UiButton>
        </>
      }
    >
      <div className="dcp-tabs" role="tablist" aria-label="Daily Cash Position views">
        <UiButton variant="primary" icon={<Banknote size={14} />}>Cash Book</UiButton>
        <UiButton disabled title="Cash/Credit Summary will be implemented in the next phase.">Cash/Credit Summary</UiButton>
        <UiButton disabled title="Dashboard will be implemented in a later phase.">Dash Board</UiButton>
        <UiButton disabled title="Day Close requires separate close-day workflow and controls.">Day Close</UiButton>
      </div>

      <div className="dcp-filter-strip">
        <div className="dcp-account-block">
          <div className="dcp-account-label">Cash &amp; Bank Book</div>
          <UiSelect
            aria-label="Cash or bank account"
            value={accountUlid}
            searchable
            placeholder={accountsQuery.isLoading ? 'Loading cash accounts…' : 'Select cash / bank account'}
            options={(accountsQuery.data ?? []).map((account) => ({
              value: account.ulid,
              label: account.name,
              columns: [account.code, account.name, account.account_type ?? ''],
            }))}
            menuColumns={[
              { header: 'Code', width: '110px' },
              { header: 'Cash / Bank Account', width: 'minmax(240px, 1fr)' },
              { header: 'Account Type', width: '180px' },
            ]}
            menuMinWidth={620}
            onChange={setAccountUlid}
          />
          <label className="dcp-ignore-date">
            <input
              type="checkbox"
              checked={ignoreDate}
              onChange={(event) => setIgnoreDate(event.target.checked)}
            />
            <span>Ignore Date</span>
          </label>
        </div>

        <div className="dcp-dates">
          <label>
            <span>From:</span>
            <input type="date" value={dateFrom} disabled={ignoreDate} onChange={(event) => setDateFrom(event.target.value)} />
          </label>
          <label>
            <span>To:</span>
            <input type="date" value={dateTo} disabled={ignoreDate} onChange={(event) => setDateTo(event.target.value)} />
          </label>
        </div>
      </div>

      {accountsQuery.isError || reportQuery.isError ? (
        <div className="dcp-error">Unable to load Daily Cash Position data.</div>
      ) : null}

      <div className="dcp-grid-area">
        <PosDataGrid
          columns={columns}
          rows={report?.rows ?? []}
          rowKey={(row) => row.line_ulid}
          emptyMessage={
            reportQuery.isFetching
              ? 'Loading cash book…'
              : accountUlid
                ? 'No posted cash transactions in this period.'
                : 'Select a cash or bank account.'
          }
        />
      </div>

      <div className="dcp-summary-title">Cash Summary Between Dates</div>
      <div className="dcp-summary">
        <div><span>Opening</span><strong>{money(report?.summary.opening)}</strong></div>
        <div><span>Received</span><strong>{money(report?.summary.received)}</strong></div>
        <div><span>Paid</span><strong>{money(report?.summary.paid)}</strong></div>
        <div><span>Bal</span><strong>{money(report?.summary.balance)}</strong></div>
        <div><span>Closing</span><strong>{money(report?.summary.closing)}</strong></div>
      </div>
    </DesktopPanel>
  )
}
