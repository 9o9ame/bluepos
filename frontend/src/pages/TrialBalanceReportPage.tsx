import { Fragment, useRef, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ClipboardCopy, Download, Eye, FileCode, FileText, Minus, Plus, Printer, RefreshCw, Search, X } from 'lucide-react'
import { fetchTrialBalanceReport } from '../api/reports'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { useAuth } from '../features/auth/AuthProvider'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import type {
  TrialBalanceArrangeBy,
  TrialBalanceGroupBy,
  TrialBalanceReport,
  TrialBalanceRow,
  TrialBalanceTotals,
} from '../types/reports'
import './TrialBalanceReportPage.css'

const GROUP_OPTIONS = [
  { value: 'account_type', label: 'Account Type' },
  { value: 'account_type_head', label: 'Account Type Head' },
  { value: 'main_account', label: 'Main Account' },
  { value: 'level_wise', label: 'Level Wise Trial Balance' },
] satisfies Array<{ value: TrialBalanceGroupBy; label: string }>

const ARRANGE_OPTIONS = [
  { value: 'balance_asc', label: 'Bal' },
  { value: 'balance_desc', label: 'Bal DESC' },
  { value: 'credit_asc', label: 'Credit' },
  { value: 'credit_desc', label: 'Credit DESC' },
  { value: 'debit_asc', label: 'Debit' },
  { value: 'debit_desc', label: 'Debit DESC' },
  { value: 'code_asc', label: 'VCID' },
  { value: 'code_desc', label: 'VCID DESC' },
  { value: 'name_asc', label: 'VCName' },
  { value: 'name_desc', label: 'VCName DESC' },
] satisfies Array<{ value: TrialBalanceArrangeBy; label: string }>

function today(): string {
  const now = new Date()
  const offset = now.getTimezoneOffset()
  return new Date(now.getTime() - offset * 60_000).toISOString().slice(0, 10)
}

function amount(value: string): string {
  const normalized = value.trim()
  if (!/^-?\d+(?:\.\d+)?$/.test(normalized)) return value

  const negative = normalized.startsWith('-')
  const unsigned = negative ? normalized.slice(1) : normalized
  const [integerRaw, fractionRaw = ''] = unsigned.split('.')
  const fraction = fractionRaw.replace(/0+$/, '')
  const integer = integerRaw.replace(/^0+(?=\d)/, '') || '0'

  if (integer === '0' && fraction === '') return '—'

  const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `${negative ? '-' : ''}${grouped}${fraction ? `.${fraction}` : ''}`
}

function reportDate(value: string): string {
  const [year, month, day] = value.split('-')
  if (!year || !month || !day) return value
  return `${day}-${month}-${year}`
}

function signedBalance(totals: TrialBalanceTotals): string {
  if (totals.difference === '0.0000') return '—'
  const formatted = amount(totals.difference)
  return totals.difference_side === 'Cr' ? `(${formatted})` : formatted
}

function AccountRow({ row }: { row: TrialBalanceRow }) {
  return (
    <tr className="trial-balance-account-row">
      <td>
        <span>{row.account.code}</span>
        <strong>{row.account.name}</strong>
      </td>
      <td>{amount(row.debit)}</td>
      <td>{amount(row.credit)}</td>
    </tr>
  )
}
function MajorMinorHeadWisePreview({ report }: { report: TrialBalanceReport }) {
  const sections = report.groups.flatMap((group) => group.sections ?? [])

  return (
    <section className="trial-balance-preview trial-balance-head-wise">
      <header className="trial-balance-report-heading">
        <h2>TRIAL BALANCE (MAJOR HEAD WISE)</h2>
        <p>For The Period Ended on: {reportDate(report.as_of_date)}</p>
      </header>

      <table className="trial-balance-head-wise-table">
        <thead>
          <tr>
            <th colSpan={2}>DESCRIPTION</th>
            <th>DEBIT</th>
            <th>CREDIT</th>
            <th>BALANCE</th>
          </tr>
        </thead>
        <tbody>
          {sections.flatMap((section, sectionIndex) => {
            const rows = section.account_types
            if (!rows.length) return []

            return rows.map((typeSection, typeIndex) => (
              <tr key={`${section.account_type_head?.ulid ?? sectionIndex}-${typeSection.account_type?.ulid ?? typeIndex}`}>
                {typeIndex === 0 ? (
                  <td className="trial-balance-major-head" rowSpan={rows.length}>
                    {section.account_type_head?.name ?? 'Ungrouped'}
                  </td>
                ) : null}
                <td className="trial-balance-minor-head">
                  {typeSection.account_type?.name ?? 'Ungrouped'}
                </td>
                <td>{amount(typeSection.totals.debit)}</td>
                <td>{amount(typeSection.totals.credit)}</td>
                <td>{signedBalance(typeSection.totals)}</td>
              </tr>
            ))
          })}
          <tr className="trial-balance-head-wise-total">
            <th colSpan={2}>Grand Total:</th>
            <td>{amount(report.totals.debit)}</td>
            <td>{amount(report.totals.credit)}</td>
            <td>{signedBalance(report.totals)}</td>
          </tr>
        </tbody>
      </table>
    </section>
  )
}

function TrialBalancePreview({
  report,
  separatePages,
}: {
  report: TrialBalanceReport
  separatePages: boolean
}) {
  return (
    <section className={`trial-balance-preview${separatePages ? ' is-separate-pages' : ''}`}>
      <header className="trial-balance-report-heading">
        <h2>Trial Balance</h2>
        <p>As on and Before {reportDate(report.as_of_date)}</p>
      </header>

      {report.groups.map((group) => (
        <section className="trial-balance-group" key={group.key}>
          <table>
            <thead>
              <tr>
                <th>Account Name</th>
                <th>Debit</th>
                <th>Credit</th>
              </tr>
            </thead>
            <tbody>
              <tr className="trial-balance-main-head">
                <td colSpan={3}>{group.label}</td>
              </tr>

              {report.group_by === 'level_wise' && group.sections
                ? group.sections.map((section, sectionIndex) => (
                    <Fragment key={`section-${section.account_type_head?.ulid ?? sectionIndex}`}>
                      <tr className="trial-balance-sub-head">
                        <td colSpan={3}>{section.account_type_head?.name ?? 'Ungrouped'}</td>
                      </tr>

                      {section.account_types.map((typeSection, typeIndex) => (
                        <Fragment key={`type-${typeSection.account_type?.ulid ?? typeIndex}`}>
                          <tr className="trial-balance-account-type">
                            <td colSpan={3}>
                              {[typeSection.account_type?.code, typeSection.account_type?.name]
                                .filter(Boolean)
                                .join(' — ') || 'Ungrouped'}
                            </td>
                          </tr>

                          {typeSection.rows.map((row) => (
                            <AccountRow key={row.account.ulid} row={row} />
                          ))}

                          <tr className="trial-balance-subtotal">
                            <td>Account Type Wise Total:</td>
                            <td>{amount(typeSection.totals.debit)}</td>
                            <td>{amount(typeSection.totals.credit)}</td>
                          </tr>
                        </Fragment>
                      ))}

                      <tr className="trial-balance-subtotal">
                        <td>Account Type Head Wise Total:</td>
                        <td>{amount(section.totals.debit)}</td>
                        <td>{amount(section.totals.credit)}</td>
                      </tr>
                    </Fragment>
                  ))
                : group.rows.map((row) => (
                    <AccountRow key={row.account.ulid} row={row} />
                  ))}

              <tr className="trial-balance-group-total">
                <td>
                  {report.group_by === 'level_wise'
                    ? 'Main Head Wise Sub Total:'
                    : 'Group Total:'}
                </td>
                <td>{amount(group.totals.debit)}</td>
                <td>{amount(group.totals.credit)}</td>
              </tr>
            </tbody>
          </table>
        </section>
      ))}

      <table className="trial-balance-grand-total">
        <tbody>
          <tr>
            <th>Grand Total:</th>
            <td>{amount(report.totals.debit)}</td>
            <td>{amount(report.totals.credit)}</td>
          </tr>
          <tr>
            <th>Difference:</th>
            <td colSpan={2}>
              {amount(report.totals.difference)} {report.totals.difference_side}
            </td>
          </tr>
        </tbody>
      </table>
    </section>
  )
}

export function TrialBalanceReportPage() {
  const { session } = useAuth()
  const { closeActiveTab, openModule } = useWorkspace()
  const location = useLocation()
  const params = new URLSearchParams(location.search)
  const viewParam = params.get('view')
  const isViewer = viewParam === 'detail' || viewParam === 'major_minor'

  const initialDate = params.get('as_of_date') || today()
  const initialGroup = (params.get('group_by') || 'level_wise') as TrialBalanceGroupBy
  const initialArrange = (params.get('arrange_by') || 'code_asc') as TrialBalanceArrangeBy
  const initialSeparatePages = params.get('separate_pages') === '1'

  const [asOfDate, setAsOfDate] = useState(initialDate)
  const [groupBy, setGroupBy] = useState<TrialBalanceGroupBy>(initialGroup)
  const [arrangeBy, setArrangeBy] = useState<TrialBalanceArrangeBy>(initialArrange)
  const [separatePages, setSeparatePages] = useState(initialSeparatePages)
  const [arrangeIds] = useState(false)
  const [viewerSearch, setViewerSearch] = useState('')
  const [viewerZoom, setViewerZoom] = useState(100)
  const [copyStatus, setCopyStatus] = useState('Copy')
  const [sectionIndex, setSectionIndex] = useState(0)
  const reportPaperRef = useRef<HTMLDivElement>(null)
  const viewerCanvasRef = useRef<HTMLDivElement>(null)

  const viewerQuery = useQuery({
    queryKey: [
      'reports',
      'trial-balance',
      viewParam,
      initialDate,
      initialGroup,
      initialArrange,
    ],
    queryFn: () => fetchTrialBalanceReport({
      as_of_date: initialDate,
      group_by: viewParam === 'major_minor' ? 'level_wise' : initialGroup,
      arrange_by: viewParam === 'major_minor' ? 'code_asc' : initialArrange,
    }),
    enabled: isViewer,
  })

  const openReportView = (view: 'detail' | 'major_minor') => {
    const query = new URLSearchParams({
      view,
      as_of_date: asOfDate,
      group_by: view === 'major_minor' ? 'level_wise' : groupBy,
      arrange_by: view === 'major_minor' ? 'code_asc' : arrangeBy,
      separate_pages: separatePages ? '1' : '0',
    })
    openModule(`/reports/accounts/trial-balance?${query.toString()}`)
  }

  const printReport = () => {
    if (!viewerQuery.data) return
    const root = document.documentElement
    root.setAttribute('data-trial-balance-print', 'true')
    const cleanup = () => root.removeAttribute('data-trial-balance-print')
    window.addEventListener('afterprint', cleanup, { once: true })
    window.requestAnimationFrame(() => window.print())
  }

  const jumpToMatch = () => {
    const query = viewerSearch.trim().toLocaleLowerCase()
    if (!query || !reportPaperRef.current) return
    const cells = Array.from(reportPaperRef.current.querySelectorAll('td, th, strong'))
    const match = cells.find((cell) => cell.textContent?.toLocaleLowerCase().includes(query))
    match?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }

  const exportCsv = () => {
    const report = viewerQuery.data
    if (!report) return
    // Treat all exported cells as data, never spreadsheet formulas.
    const csvCell = (value: string) => {
      const safe = /^\s*[=+@-]/.test(value) ? "'" + value : value
      return '"' + safe.replace(/"/g, '""') + '"'
    }
    const rows: string[][] = [
      [viewParam === 'major_minor' ? 'Trial Balance Major Head' : 'Trial Balance Detailed'],
      ['As of', report.as_of_date],
      [],
      ['Description', 'Debit', 'Credit', 'Balance'],
    ]
    if (viewParam === 'major_minor') {
      report.groups.forEach((group) => group.sections?.forEach((section) => {
        section.account_types.forEach((type) => rows.push([
          [section.account_type_head?.name ?? 'Ungrouped', type.account_type?.name ?? 'Ungrouped'].join(' / '),
          type.totals.debit, type.totals.credit, signedBalance(type.totals),
        ]))
      }))
    } else {
      report.groups.forEach((group) => {
        rows.push([group.label, '', '', ''])
        const sections = group.sections
        if (report.group_by === 'level_wise' && sections) {
          sections.forEach((section) => {
            rows.push([section.account_type_head?.name ?? 'Ungrouped', '', '', ''])
            section.account_types.forEach((type) => {
              rows.push([type.account_type?.name ?? 'Ungrouped', '', '', ''])
              type.rows.forEach((row) => rows.push([row.account.code + ' - ' + row.account.name, row.debit, row.credit, '']))
            })
          })
        } else {
          group.rows.forEach((row) => rows.push([row.account.code + ' - ' + row.account.name, row.debit, row.credit, '']))
        }
      })
    }
    rows.push(['Grand Total', report.totals.debit, report.totals.credit, signedBalance(report.totals)])
    const csv = '\uFEFF' + rows.map((row) => row.map(csvCell).join(',')).join('\r\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `trial-balance-${viewParam === 'major_minor' ? 'major-head' : 'detailed'}-${report.as_of_date}.csv`
    link.click()
    URL.revokeObjectURL(url)
  }

  const downloadReport = (kind: 'html' | 'txt') => {
    const paper = reportPaperRef.current
    const report = viewerQuery.data
    if (!paper || !report) return
    const heading = viewParam === 'major_minor' ? 'Trial Balance Major Head' : 'Trial Balance Detailed'
    const name = `trial-balance-${viewParam === 'major_minor' ? 'major-head' : 'detailed'}-${report.as_of_date}`
    const body = kind === 'html'
      ? `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>${heading}</title><style>body{font:14px Arial;margin:32px}table{border-collapse:collapse;width:100%;margin:8px 0}td,th{border:1px solid #555;padding:4px 6px}h2,p{text-align:center}</style></head><body>${paper.innerHTML}</body></html>`
      : (paper.innerText || paper.textContent || '')
    const blob = new Blob([body], { type: kind === 'html' ? 'text/html;charset=utf-8' : 'text/plain;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${name}.${kind}`
    link.click()
    URL.revokeObjectURL(url)
  }

  const copyReport = async () => {
    const paper = reportPaperRef.current
    if (!paper) return
    try {
      await navigator.clipboard.writeText(paper.innerText || paper.textContent || '')
      setCopyStatus('Copied')
    } catch {
      setCopyStatus('Copy failed')
    }
  }

  const moveToSection = (next: number) => {
    const groups = reportPaperRef.current?.querySelectorAll('.trial-balance-group')
    if (!groups?.length) return
    const bounded = Math.max(0, Math.min(groups.length - 1, next))
    setSectionIndex(bounded)
    groups[bounded]?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  if (isViewer) {
    const report = viewerQuery.data
    const sectionCount = viewParam === 'major_minor' ? 0 : (report?.groups.length ?? 0)

    return (
      <section className="trial-balance-page trial-balance-viewer-page" aria-label="Trial Balance Report">
        <div className="trial-balance-viewer-toolbar">
          <UiButton
            variant="info"
            icon={<Printer size={15} />}
            label="Print / PDF"
            disabled={!report}
            onClick={printReport}
            title="Use the browser print dialog to print or save as PDF."
          />
          <UiButton
            variant="default"
            icon={<ClipboardCopy size={15} />}
            label={copyStatus}
            disabled={!report}
            onClick={() => void copyReport()}
            title="Copy the report text to the clipboard."
          />
          <UiButton
            variant="default"
            icon={<FileText size={15} />}
            label="Text"
            disabled={!report}
            onClick={() => downloadReport('txt')}
          />
          <UiButton
            variant="default"
            icon={<FileCode size={15} />}
            label="HTML"
            disabled={!report}
            onClick={() => downloadReport('html')}
          />
          <UiButton
            variant="info"
            icon={<Download size={15} />}
            label="Export CSV"
            disabled={!report}
            onClick={exportCsv}
            title="Open the exported CSV in Excel or another spreadsheet app."
          />
          <UiSelect
            className="trial-balance-export-choice"
            aria-label="Export report format"
            value=""
            searchable={false}
            options={[
              { value: '', label: 'Export as…' },
              { value: 'csv', label: 'CSV (Excel compatible)' },
              { value: 'html', label: 'HTML document' },
              { value: 'txt', label: 'Plain text' },
              { value: 'pdf', label: 'PDF (browser print)' },
            ]}
            onChange={(format) => {
              if (format === 'csv') exportCsv()
              else if (format === 'html' || format === 'txt') downloadReport(format)
              else if (format === 'pdf') printReport()
            }}
          />
          <div className="trial-balance-viewer-search">
            <Search size={14} aria-hidden />
            <input
              aria-label="Search report text"
              placeholder="Search report…"
              value={viewerSearch}
              onChange={(event) => setViewerSearch(event.target.value)}
              onKeyDown={(event) => { if (event.key === 'Enter') jumpToMatch() }}
            />
            <UiButton variant="default" label="Find" disabled={!report || !viewerSearch.trim()} onClick={jumpToMatch} />
          </div>
          <div className="trial-balance-viewer-zoom">
            <UiButton variant="default" icon={<Minus size={14} />} aria-label="Zoom out" disabled={viewerZoom <= 50} onClick={() => setViewerZoom((current) => Math.max(50, current - 25))} />
            <span aria-live="polite">{viewerZoom}%</span>
            <UiButton variant="default" icon={<Plus size={14} />} aria-label="Zoom in" disabled={viewerZoom >= 200} onClick={() => setViewerZoom((current) => Math.min(200, current + 25))} />
            <UiButton variant="default" label="Fit" onClick={() => setViewerZoom(100)} />
          </div>
          {sectionCount > 0 ? (
            <div className="trial-balance-viewer-zoom" aria-label="Report section navigation">
              <UiButton variant="default" label="First" disabled={sectionIndex === 0} onClick={() => moveToSection(0)} />
              <UiButton variant="default" label="Prev" disabled={sectionIndex === 0} onClick={() => moveToSection(sectionIndex - 1)} />
              <span className="trial-balance-section-count">Section {sectionIndex + 1} / {sectionCount}</span>
              <UiButton variant="default" label="Next" disabled={sectionIndex >= sectionCount - 1} onClick={() => moveToSection(sectionIndex + 1)} />
              <UiButton variant="default" label="Last" disabled={sectionIndex >= sectionCount - 1} onClick={() => moveToSection(sectionCount - 1)} />
            </div>
          ) : null}
          <UiButton
            variant="info"
            icon={<RefreshCw size={15} />}
            label={viewerQuery.isFetching ? 'Refreshing…' : 'Refresh'}
            disabled={viewerQuery.isFetching}
            onClick={() => void viewerQuery.refetch()}
          />
          <UiButton
            variant="default"
            icon={<ArrowLeft size={15} />}
            label="Edit Options"
            onClick={() => openModule('/reports/accounts/trial-balance')}
          />
          <UiButton
            variant="info"
            icon={<X size={15} />}
            label="Close"
            onClick={closeActiveTab}
          />
        </div>

        {viewerQuery.isError ? (
          <div className="trial-balance-error" role="alert">
            Unable to load Trial Balance. Please check the selected options and try again.
          </div>
        ) : null}

        <div className="trial-balance-viewer-canvas" ref={viewerCanvasRef}>
          {viewerQuery.isLoading ? (
            <div className="trial-balance-viewer-loading">Loading report…</div>
          ) : report ? (
            <div className="trial-balance-result" ref={reportPaperRef} style={{ zoom: `${viewerZoom}%` }}>
              <div className="trial-balance-business-heading">
                <strong>{session?.tenant.name ?? 'BluePOS'}</strong>
                <span>
                  {session?.branch.code ?? '—'} — {session?.branch.name ?? '—'}
                </span>
              </div>
              {viewParam === 'major_minor' ? (
                <MajorMinorHeadWisePreview report={report} />
              ) : (
                <TrialBalancePreview report={report} separatePages={initialSeparatePages} />
              )}
            </div>
          ) : null}
        </div>
      </section>
    )
  }

  return (
    <section className="trial-balance-page" aria-label="Trial Balance">
      <header className="trial-balance-title">
        <h1>Trial Balance</h1>
      </header>

      <div className="trial-balance-options-area">
        <div className="trial-balance-options-card">
          <div className="trial-balance-card-title">Selection Options</div>

          <label>
            <span>As and Before Date:</span>
            <input
              type="date"
              value={asOfDate}
              onChange={(event) => setAsOfDate(event.target.value)}
            />
          </label>

          <label>
            <span>Group by:</span>
            <UiSelect
              value={groupBy}
              options={GROUP_OPTIONS}
              onChange={(value) => setGroupBy(value as TrialBalanceGroupBy)}
              searchable={false}
            />
          </label>

          <label className="trial-balance-check">
            <input
              type="checkbox"
              checked={separatePages}
              onChange={(event) => setSeparatePages(event.target.checked)}
            />
            <span>Each Group on Separate Page</span>
          </label>

          <label
            className="trial-balance-check"
            title="Legacy Arrange By IDs semantics are not mapped to a safe BluePOS business key yet."
          >
            <input type="checkbox" checked={arrangeIds} disabled />
            <span>Arrange By IDs</span>
          </label>

          <label>
            <span>Show Only:</span>
            <UiSelect
              value="all"
              searchable={false}
              options={[
                { value: 'all', label: 'All Entries' },
                { value: 'marked', label: 'Marked Entries', disabled: true },
                { value: 'unmarked', label: 'Un-Marked Entries', disabled: true },
              ]}
              onChange={() => undefined}
              title="Marked / Un-Marked semantics are not stored in BluePOS yet."
            />
          </label>

          <label>
            <span>Arrange By:</span>
            <UiSelect
              value={arrangeBy}
              searchable={false}
              options={ARRANGE_OPTIONS}
              onChange={(value) => setArrangeBy(value as TrialBalanceArrangeBy)}
            />
          </label>
        </div>

        <div className="trial-balance-actions">
          <UiButton
            variant="info"
            icon={<Eye size={15} />}
            label="Preview"
            disabled={!asOfDate}
            onClick={() => openReportView('detail')}
          />
          <UiButton
            variant="info"
            icon={<Printer size={15} />}
            label="Print"
            disabled
            title="Open Preview first, then print from the report tab."
          />
          <UiButton
            variant="info"
            icon={<X size={15} />}
            label="Close"
            onClick={closeActiveTab}
          />
        </div>

        <UiButton
          className="trial-balance-major-minor"
          variant="default"
          label="Major / Minor Head Wise"
          disabled={!asOfDate}
          onClick={() => openReportView('major_minor')}
        />
      </div>
    </section>
  )
}
