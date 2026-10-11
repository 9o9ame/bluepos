import { useEffect, useMemo, useState } from 'react'
import { Banknote, Printer, RefreshCw } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import {
  fetchCashBook,
  fetchCashCreditSummary,
  fetchCashDashboard,
  fetchCashPositionAccounts,
  type CashBookRow,
  type CashCreditFilterKey,
  type CashCreditPositionRow,
  type CashDashboardAccountRow,
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

type DashboardSeries = {
  account: { ulid: string; code: string; name: string }
  values: string[]
}

function CashBalanceChart({
  dates,
  series,
  loading,
}: {
  dates: string[]
  series: DashboardSeries[]
  loading: boolean
}) {
  const width = 900
  const height = 460
  const pad = { top: 28, right: 18, bottom: 64, left: 70 }
  const plotWidth = width - pad.left - pad.right
  const plotHeight = height - pad.top - pad.bottom
  const values = series.flatMap((item) => item.values.map((value) => Number(value) || 0))
  const minValue = Math.min(0, ...values)
  const maxValue = Math.max(0, ...values)
  const range = Math.max(1, maxValue - minValue)
  const y = (value: number) => pad.top + ((maxValue - value) / range) * plotHeight
  const zeroY = y(0)
  const groupWidth = dates.length ? plotWidth / dates.length : plotWidth
  const barGap = 2
  const barWidth = series.length
    ? Math.max(3, Math.min(18, (groupWidth - 10) / series.length - barGap))
    : 8
  const tones = [
    'var(--ui-success)',
    'var(--ui-info)',
    'var(--ui-warning)',
    'var(--ui-danger)',
    'var(--ui-accent)',
    'var(--ui-accent-strong)',
    'color-mix(in srgb, var(--ui-success) 60%, var(--ui-info))',
    'color-mix(in srgb, var(--ui-warning) 65%, var(--ui-danger))',
  ]

  if (loading && dates.length === 0) {
    return <div className="dcp-dashboard-empty">Loading dashboard chart…</div>
  }

  if (dates.length === 0 || series.length === 0) {
    return <div className="dcp-dashboard-empty">No cash / bank balance series available.</div>
  }

  return (
    <div className="dcp-dashboard-chart-wrap">
      <div className="dcp-dashboard-chart-title">
        <strong>7-Day Cash / Bank Closing Balance</strong>
        <span>Hover a bar to see the exact account balance</span>
      </div>
      <svg viewBox={`0 0 ${width} ${height}`} role="img" aria-label="Cash and bank closing balance trend">
        <rect
          x={pad.left}
          y={pad.top}
          width={plotWidth}
          height={plotHeight}
          className="dcp-chart-plot-bg"
          rx={8}
        />
        {[0, 0.25, 0.5, 0.75, 1].map((ratio) => {
          const value = maxValue - range * ratio
          const lineY = pad.top + plotHeight * ratio
          return (
            <g key={ratio}>
              <line x1={pad.left} x2={width - pad.right} y1={lineY} y2={lineY} className="dcp-chart-grid-line" />
              <text x={pad.left - 8} y={lineY + 4} textAnchor="end" className="dcp-chart-axis-label">
                {money(String(value))}
              </text>
            </g>
          )
        })}
        <line x1={pad.left} x2={width - pad.right} y1={zeroY} y2={zeroY} className="dcp-chart-zero-line" />

        {dates.map((date, dateIndex) => {
          const groupX = pad.left + dateIndex * groupWidth + groupWidth / 2
          const totalBarsWidth = series.length * barWidth + Math.max(0, series.length - 1) * barGap
          return (
            <g key={date}>
              {series.map((item, seriesIndex) => {
                const value = Number(item.values[dateIndex] ?? 0) || 0
                const valueY = y(value)
                const rectY = Math.min(valueY, zeroY)
                const rectHeight = Math.max(1, Math.abs(valueY - zeroY))
                const x = groupX - totalBarsWidth / 2 + seriesIndex * (barWidth + barGap)
                return (
                  <rect
                    key={item.account.ulid}
                    x={x}
                    y={rectY}
                    width={barWidth}
                    height={rectHeight}
                    rx={3}
                    className="dcp-chart-bar"
                    style={{ fill: tones[seriesIndex % tones.length] }}
                  >
                    <title>{item.account.name}: {money(String(value))}</title>
                  </rect>
                )
              })}
              <text x={groupX} y={height - 34} textAnchor="middle" className="dcp-chart-axis-label">
                {date.slice(5)}
              </text>
            </g>
          )
        })}
      </svg>

      <div className="dcp-dashboard-legend">
        {series.map((item, index) => (
          <div key={item.account.ulid}>
            <span className="dcp-dashboard-legend-swatch" style={{ background: tones[index % tones.length] }} />
            <span>{item.account.name}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

export function DailyCashPositionPage() {
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const [activeView, setActiveView] = useState<'cash-book' | 'cash-credit' | 'dashboard'>('cash-book')
  const [accountUlid, setAccountUlid] = useState('')
  const [dateFrom, setDateFrom] = useState(todayIso())
  const [dateTo, setDateTo] = useState(todayIso())
  const [ignoreDate, setIgnoreDate] = useState(false)
  const [summaryFilterBy, setSummaryFilterBy] = useState<CashCreditFilterKey | ''>('')
  const [summaryOnlyFor, setSummaryOnlyFor] = useState('')

  const accountsQuery = useQuery({
    queryKey: ['cash-position-accounts'],
    queryFn: fetchCashPositionAccounts,
  })

  useEffect(() => {
    if (!accountUlid && accountsQuery.data?.length) {
      setAccountUlid(accountsQuery.data[0].ulid)
    }
  }, [accountUlid, accountsQuery.data])

  const dashboardQuery = useQuery({
    queryKey: ['cash-dashboard', dateTo],
    enabled: activeView === 'dashboard',
    queryFn: () => fetchCashDashboard(dateTo, 7),
  })

  const summaryQuery = useQuery({
    queryKey: ['cash-credit-summary', dateTo, summaryFilterBy, summaryOnlyFor],
    enabled: activeView === 'cash-credit',
    queryFn: () =>
      fetchCashCreditSummary(dateTo, {
        filter_by: summaryFilterBy || undefined,
        only_for: summaryOnlyFor || undefined,
      }),
  })

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
    if (activeView === 'dashboard') {
      void dashboardQuery.refetch()
      return
    }

    if (activeView === 'cash-credit') {
      void summaryQuery.refetch()
      return
    }

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
  const cashCredit = summaryQuery.data
  const dashboard = dashboardQuery.data

  const positionColumns = useMemo<PosGridColumn<CashCreditPositionRow>[]>(
    () => [
      {
        key: 'account',
        header: 'BANK / CASH ACCOUNT',
        render: (row) => row.account.name,
      },
      { key: 'opening', header: 'Opening', width: 180, align: 'right', render: (row) => money(row.opening) },
      { key: 'debit', header: 'Debit', width: 180, align: 'right', render: (row) => money(row.debit) },
      { key: 'credit', header: 'Credit', width: 180, align: 'right', render: (row) => money(row.credit) },
      { key: 'closing', header: 'Closing', width: 180, align: 'right', render: (row) => money(row.closing) },
    ],
    [],
  )

  const dashboardAccountColumns = useMemo<PosGridColumn<CashDashboardAccountRow>[]>(
    () => [
      {
        key: 'account',
        header: 'ACCOUNT',
        render: (row) => row.account.name,
      },
      {
        key: 'amount',
        header: 'AMOUNT',
        width: 150,
        align: 'right',
        render: (row) => money(row.amount),
      },
    ],
    [],
  )

  const summaryFilterOptions = [
    { value: '', label: 'No Filter' },
    { value: 'document', label: 'Document' },
    { value: 'entry_date', label: 'Date' },
    { value: 'user_name', label: 'User Name' },
    { value: 'sales_person', label: 'Sales Person Name' },
  ]

  const summaryOnlyForOptions = summaryFilterBy
    ? [
        { value: '', label: 'All Values' },
        ...(cashCredit?.filter_options[summaryFilterBy] ?? []),
      ]
    : [{ value: '', label: 'Select Filter By first' }]

  const metricCards = [
    ['Sales', cashCredit?.metrics.sales, 'sales'],
    ['Retail Sale', cashCredit?.metrics.retail_sale, 'retail'],
    ['Sale Return', cashCredit?.metrics.sale_return, 'return'],
    ['Purchase', cashCredit?.metrics.purchase, 'purchase'],
    ['Pur. Return', cashCredit?.metrics.purchase_return, 'purchase-return'],
    ['Recovery', cashCredit?.metrics.recovery, 'recovery'],
    ['Payment', cashCredit?.metrics.payment, 'payment'],
    ['Receivings', cashCredit?.metrics.receivings, 'receiving'],
  ] as const

  return (
    <DesktopPanel
      className="daily-cash-position"
      title={
        activeView === 'cash-book'
          ? 'Cash book view'
          : activeView === 'cash-credit'
            ? 'Daily Cash / Credit Summary'
            : 'Daily Cash Dashboard'
      }
    >
      <div className="dcp-tabs" role="tablist" aria-label="Daily Cash Position views">
        <UiButton
          variant={activeView === 'cash-book' ? 'primary' : 'default'}
          icon={<Banknote size={14} />}
          onClick={() => setActiveView('cash-book')}
        >
          Cash Book
        </UiButton>
        <UiButton
          variant={activeView === 'cash-credit' ? 'primary' : 'default'}
          onClick={() => setActiveView('cash-credit')}
        >
          Cash/Credit Summary
        </UiButton>
        <UiButton
          variant={activeView === 'dashboard' ? 'primary' : 'default'}
          onClick={() => setActiveView('dashboard')}
        >
          Dash Board
        </UiButton>
        <UiButton disabled title="Day Close requires separate close-day workflow and controls.">Day Close</UiButton>
      </div>

      {activeView === 'cash-book' ? (
      <>
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

        <div className="dcp-right-controls">
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
          <div className="dcp-actions">
            <UiButton variant="info" icon={<RefreshCw size={14} />} onClick={refresh}>Refresh</UiButton>
            <UiButton variant="info" icon={<Printer size={14} />} disabled={!report} onClick={printView}>Print View</UiButton>
            <UiButton variant="info" onClick={closeActiveTab}>Close</UiButton>
          </div>
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
      </>
      ) : activeView === 'cash-credit' ? (
        <div className="dcp-credit-summary">
          <div className="dcp-credit-heading-row">
            <div>
              <strong>Daily Cash / Credit Summary</strong>
              <span>Authoritative posted accounting for {dateTo}</span>
            </div>
            <div className="dcp-credit-actions">
              <label>
                <span>Date:</span>
                <input type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} />
              </label>
              <UiButton variant="info" icon={<RefreshCw size={14} />} onClick={refresh}>Refresh</UiButton>
              <UiButton variant="info" onClick={closeActiveTab}>Close</UiButton>
            </div>
          </div>

          <div className="dcp-metrics-grid">
            {metricCards.map(([label, metric, tone]) => {
              const supported = metric?.supported ?? true
              return (
                <div
                  className={`dcp-metric-card tone-${tone}${supported ? '' : ' is-unsupported'}`}
                  key={label}
                  title={
                    supported
                      ? undefined
                      : cashCredit?.unsupported_reasons[
                          label === 'Retail Sale'
                            ? 'retail_sale'
                            : label === 'Sale Return'
                              ? 'sale_return'
                              : 'sales'
                        ]
                  }
                >
                  <span>{label}</span>
                  <strong>{supported ? money(metric?.cash) : 'N/A'}</strong>
                  <small>{supported ? `Credit: ${money(metric?.credit)}` : 'Accounting source unavailable'}</small>
                </div>
              )
            })}
          </div>

          <div className="dcp-credit-band">CREDIT</div>
          <div className="dcp-credit-values">
            {metricCards.slice(0, 5).map(([label, metric, tone]) => (
              <div className={`tone-${tone}`} key={`credit-${label}`}>
                <span>{label}</span>
                <strong>{metric?.supported ? money(metric.credit) : 'N/A'}</strong>
              </div>
            ))}
          </div>

          <div className="dcp-credit-filters">
            <label>
              <span>Filter By:</span>
              <UiSelect
                aria-label="Cash summary filter"
                value={summaryFilterBy}
                title="Supported: Document, Date, User Name, Sales Person Name. Area/Main Area/Category need an authoritative journal-level source."
                options={summaryFilterOptions}
                onChange={(value) => {
                  setSummaryFilterBy(value as CashCreditFilterKey | '')
                  setSummaryOnlyFor('')
                }}
              />
            </label>
            <label>
              <span>Only For:</span>
              <UiSelect
                aria-label="Cash summary only for"
                value={summaryOnlyFor}
                disabled={!summaryFilterBy}
                title={summaryFilterBy ? 'Filter the selected day by this value.' : 'Select Filter By first.'}
                options={summaryOnlyForOptions}
                onChange={setSummaryOnlyFor}
              />
            </label>
          </div>

          {summaryQuery.isError ? (
            <div className="dcp-error">Unable to load Cash/Credit Summary.</div>
          ) : null}

          <div className="dcp-credit-grid-title">Overall Bank / Cash Position For The Date: {dateTo}</div>
          <div className="dcp-credit-grid">
            <PosDataGrid
              columns={positionColumns}
              rows={cashCredit?.positions ?? []}
              rowKey={(row) => row.account.ulid}
              emptyMessage={summaryQuery.isFetching ? 'Loading cash / bank position…' : 'No cash or bank accounts available.'}
            />
          </div>
          <div className="dcp-credit-total-row">
            <span>Totals</span>
            <strong>{money(cashCredit?.totals.opening)}</strong>
            <strong>{money(cashCredit?.totals.debit)}</strong>
            <strong>{money(cashCredit?.totals.credit)}</strong>
            <strong>{money(cashCredit?.totals.closing)}</strong>
          </div>
        </div>
      ) : (
        <div className="dcp-dashboard">
          <div className="dcp-dashboard-heading">
            <div>
              <strong>Cash / Bank Balance Trend</strong>
              <span>Recent 7 days ending {dateTo}</span>
            </div>
            <div className="dcp-dashboard-actions">
              <label>
                <span>Date:</span>
                <input type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} />
              </label>
              <UiButton variant="info" icon={<RefreshCw size={14} />} onClick={refresh}>Refresh</UiButton>
              <UiButton variant="info" onClick={closeActiveTab}>Close</UiButton>
            </div>
          </div>

          {dashboardQuery.isError ? (
            <div className="dcp-error">Unable to load Daily Cash Dashboard.</div>
          ) : null}

          <div className="dcp-dashboard-body">
            <div className="dcp-dashboard-chart-card">
              <CashBalanceChart
                dates={dashboard?.chart.dates ?? []}
                series={dashboard?.chart.series ?? []}
                loading={dashboardQuery.isFetching}
              />
            </div>

            <div className="dcp-dashboard-side">
              <div className="dcp-dashboard-table-card is-debit">
                <div className="dcp-dashboard-table-title">Debit Balance Accounts</div>
                <PosDataGrid
                  columns={dashboardAccountColumns}
                  rows={dashboard?.debit_accounts ?? []}
                  rowKey={(row) => row.account.ulid}
                  emptyMessage={dashboardQuery.isFetching ? 'Loading balances…' : 'No debit balances.'}
                />
                <div className="dcp-dashboard-table-total">
                  <span>Total</span><strong>{money(dashboard?.totals.debit)}</strong>
                </div>
              </div>

              <div className="dcp-dashboard-table-card is-credit">
                <div className="dcp-dashboard-table-title">Credit Balance Accounts</div>
                <PosDataGrid
                  columns={dashboardAccountColumns}
                  rows={dashboard?.credit_accounts ?? []}
                  rowKey={(row) => row.account.ulid}
                  emptyMessage={dashboardQuery.isFetching ? 'Loading balances…' : 'No credit balances.'}
                />
                <div className="dcp-dashboard-table-total">
                  <span>Total</span><strong>{money(dashboard?.totals.credit)}</strong>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </DesktopPanel>
  )
}
