import { Fragment, useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Eye, Printer, X } from 'lucide-react'
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
  const { closeActiveTab } = useWorkspace()
  const [asOfDate, setAsOfDate] = useState(today())
  const [groupBy, setGroupBy] = useState<TrialBalanceGroupBy>('level_wise')
  const [arrangeBy, setArrangeBy] = useState<TrialBalanceArrangeBy>('code_asc')
  const [separatePages, setSeparatePages] = useState(false)
  const [arrangeIds] = useState(false)
  const [report, setReport] = useState<TrialBalanceReport | null>(null)
  const [reportView, setReportView] = useState<'detail' | 'major_minor'>('detail')

  const previewMutation = useMutation({
    mutationFn: fetchTrialBalanceReport,
    onSuccess: setReport,
  })

  const preview = () => {
    setReportView('detail')
    previewMutation.mutate({
      as_of_date: asOfDate,
      group_by: groupBy,
      arrange_by: arrangeBy,
    })
  }

  const previewMajorMinor = () => {
    setReportView('major_minor')
    previewMutation.mutate({
      as_of_date: asOfDate,
      group_by: 'level_wise',
      arrange_by: 'code_asc',
    })
  }

  const printReport = () => {
    if (!report) return
    const root = document.documentElement
    root.setAttribute('data-trial-balance-print', 'true')
    const cleanup = () => root.removeAttribute('data-trial-balance-print')
    window.addEventListener('afterprint', cleanup, { once: true })
    window.requestAnimationFrame(() => window.print())
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
            label={previewMutation.isPending ? 'Loading…' : 'Preview'}
            disabled={previewMutation.isPending || !asOfDate}
            onClick={preview}
          />
          <UiButton
            variant="info"
            icon={<Printer size={15} />}
            label="Print"
            disabled={!report}
            onClick={printReport}
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
          label={previewMutation.isPending && reportView === 'major_minor' ? 'Loading…' : 'Major / Minor Head Wise'}
          disabled={previewMutation.isPending || !asOfDate}
          onClick={previewMajorMinor}
        />
      </div>

      {previewMutation.isError ? (
        <div className="trial-balance-error" role="alert">
          Unable to load Trial Balance. Please check the selected options and try again.
        </div>
      ) : null}

      {report ? (
        <div className="trial-balance-result">
          <div className="trial-balance-business-heading">
            <strong>{session?.tenant.name ?? 'BluePOS'}</strong>
            <span>
              {session?.branch.code ?? '—'} — {session?.branch.name ?? '—'}
            </span>
          </div>
          {reportView === 'major_minor' ? (
            <MajorMinorHeadWisePreview report={report} />
          ) : (
            <TrialBalancePreview report={report} separatePages={separatePages} />
          )}
        </div>
      ) : null}
    </section>
  )
}
