import { useMemo, useState } from 'react'
import { BookOpen, Eye, Plus, XCircle } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import {
  fetchParties,
  fetchPartyLedger,
  fetchPartyProfiles,
  type Party,
  type PartyLedger,
  type PartyTypeApi,
} from '../api/parties'
import { fetchAccountTypes } from '../api/coa'
import { DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiModal } from '../components/ui/UiModal'
import { UiSelect } from '../components/ui/UiSelect'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import './VendorCustomerLedgerPage.css'

type LedgerPartyRow = {
  rowNo: number
  key: string
  ulid: string
  partyType: Exclude<PartyTypeApi, 'salesman'>
  code: string
  name: string
  contact: string
  accountType: string
  typeLabel: string
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function firstDayOfYearIso() {
  const now = new Date()
  return `${now.getFullYear()}-01-01`
}

function roleAccountTypeUlid(party: Party, type: LedgerPartyRow['partyType']): string | null {
  if (type === 'vendor') return party.vendor_account_type_ulid ?? party.account_type_ulid
  if (type === 'customer') return party.customer_account_type_ulid ?? party.account_type_ulid
  return party.account_account_type_ulid ?? party.account_type_ulid
}

function buildLedgerRows(
  profiles: Party[],
  manualAccounts: Party[],
  accountTypes: Array<{ ulid: string; name: string }>,
): LedgerPartyRow[] {
  const accountTypeNames = new Map(accountTypes.map((item) => [item.ulid, item.name]))
  const rows = new Map<string, LedgerPartyRow>()

  const push = (party: Party, type: LedgerPartyRow['partyType'], ulid: string | null | undefined) => {
    if (!ulid) return
    const key = `${type}:${ulid}`
    if (rows.has(key)) return
    const accountTypeUlid = roleAccountTypeUlid(party, type)
    rows.set(key, {
      rowNo: 0,
      key,
      ulid,
      partyType: type,
      code: party.code,
      name: party.name,
      contact: party.mobile ?? party.phone ?? party.contact_person ?? '',
      accountType: accountTypeUlid ? accountTypeNames.get(accountTypeUlid) ?? party.account_type?.name ?? '' : '',
      typeLabel: type === 'vendor' ? 'VENDORS' : type === 'customer' ? 'CUSTOMERS' : 'ACCOUNTS',
    })
  }

  for (const party of profiles) {
    const roles = party.party_types ?? [party.party_type]
    if (roles.includes('vendor')) push(party, 'vendor', party.vendor_ulid ?? (party.party_type === 'vendor' ? party.ulid : null))
    if (roles.includes('customer')) push(party, 'customer', party.customer_ulid ?? (party.party_type === 'customer' ? party.ulid : null))
    if (roles.includes('account')) push(party, 'account', party.account_ulid ?? (party.party_type === 'account' ? party.ulid : null))
  }

  for (const account of manualAccounts) {
    push(account, 'account', account.ulid)
  }

  return [...rows.values()]
    .sort((a, b) =>
      a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }) ||
      a.code.localeCompare(b.code, undefined, { sensitivity: 'base' }),
    )
    .map((row, index) => ({ ...row, rowNo: index + 1 }))
}

export function VendorCustomerLedgerPage() {
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()

  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [showBalances, setShowBalances] = useState(false)
  const [allDates, setAllDates] = useState(true)
  const [dateFrom, setDateFrom] = useState(firstDayOfYearIso())
  const [dateTo, setDateTo] = useState(todayIso())
  const [includeOpeningBalance, setIncludeOpeningBalance] = useState(true)
  const [reverseOrder, setReverseOrder] = useState(false)
  const [reportTypeOptionsOpen, setReportTypeOptionsOpen] = useState(false)
  const [groupFieldsOpen, setGroupFieldsOpen] = useState(false)
  const [remarksFontSize, setRemarksFontSize] = useState(9)
  const [preview, setPreview] = useState<PartyLedger | null>(null)
  const [previewLoading, setPreviewLoading] = useState(false)

  const profilesQuery = useQuery({
    queryKey: ['ledger-party-profiles'],
    queryFn: () => fetchPartyProfiles('all'),
  })
  const manualAccountsQuery = useQuery({
    queryKey: ['ledger-manual-accounts'],
    queryFn: () => fetchParties('account'),
  })
  const accountTypesQuery = useQuery({
    queryKey: ['ledger-account-types'],
    queryFn: fetchAccountTypes,
  })

  const rows = useMemo(
    () => buildLedgerRows(
      profilesQuery.data ?? [],
      manualAccountsQuery.data ?? [],
      accountTypesQuery.data ?? [],
    ),
    [profilesQuery.data, manualAccountsQuery.data, accountTypesQuery.data],
  )

  const selected = rows.find((row) => row.key === selectedKey) ?? null
  const loading = profilesQuery.isLoading || manualAccountsQuery.isLoading || accountTypesQuery.isLoading
  const loadError = profilesQuery.error || manualAccountsQuery.error || accountTypesQuery.error

  const columns = useMemo<PosGridColumn<LedgerPartyRow>[]>(() => [
    { key: 'rowNo', header: '#', width: 44, align: 'center', render: (row) => row.rowNo },
    { key: 'code', header: 'CODE', width: 150, render: (row) => row.code },
    { key: 'name', header: 'Vendor / Customer / Accounts', render: (row) => row.name },
    { key: 'contact', header: 'Contacts', width: 210, render: (row) => row.contact || '—' },
    { key: 'accountType', header: 'Account Type', width: 210, render: (row) => row.accountType || '—' },
    { key: 'type', header: 'TYPE', width: 160, render: (row) => row.typeLabel },
  ], [])

  async function openPreview(target: LedgerPartyRow | null = selected) {
    if (!target) {
      feedback.error('Select a Vendor, Customer, or Account first.', 'Ledger')
      return
    }
    if (!allDates && dateFrom > dateTo) {
      feedback.error('From date cannot be after To date.', 'Ledger')
      return
    }

    setPreviewLoading(true)
    try {
      const data = await fetchPartyLedger(target.ulid, target.partyType, 1, {
        date_from: allDates ? undefined : dateFrom,
        date_to: allDates ? undefined : dateTo,
        include_opening_balance: includeOpeningBalance,
        per_page: 100,
      })
      setPreview(data)
    } catch (error) {
      feedback.error(error instanceof Error ? error.message : 'Unable to load ledger.', 'Ledger')
    } finally {
      setPreviewLoading(false)
    }
  }

  const previewRows = reverseOrder ? [...(preview?.rows ?? [])].reverse() : preview?.rows ?? []

  return (
    <DesktopPanel
      className="party-ledger-page"
      title={
        <div className="party-ledger-panel-title">
          <span>Vendor / Customer Ledger Options</span>
          <label title="Balance column requires a future summary endpoint; disabled to avoid N+1 ledger requests.">
            <input
              type="checkbox"
              checked={showBalances}
              disabled
              onChange={(event) => setShowBalances(event.target.checked)}
            />
            <span>Show Balances</span>
          </label>
        </div>
      }
      flush
    >
      <div className="party-ledger-layout">
        <section className="party-ledger-accounts" aria-label="Vendor Customer Accounts">
          <div className="party-ledger-grid-shell">
            <PosDataGrid
              columns={columns}
              rows={rows}
              rowKey={(row) => row.key}
              selectedKey={selectedKey}
              onSelect={(row) => setSelectedKey(row.key)}
              onActivate={(row) => {
                setSelectedKey(row.key)
                void openPreview(row)
              }}
              emptyMessage={loading ? 'Loading accounts…' : loadError ? 'Unable to load accounts' : 'No Vendor / Customer / Accounts'}
            />
          </div>

          <div className="party-ledger-count">
            Count of Vendors / Customer / Account: <strong>{rows.length}</strong>
          </div>
        </section>

        <section className="party-ledger-options" aria-label="Additional Selection and Actions">
          <div className="party-ledger-options-title">
            <span>Additional Selection and Actions</span>
            <div className="party-ledger-reference-options">
              <label title="Report-heading suppression will be enabled with printable ledger output.">
                <input type="checkbox" disabled />
                <span>Without Heading</span>
              </label>
              <label title="Narration field mapping is not defined by the reference screenshots.">
                <input type="checkbox" disabled />
                <span>User Defined Narration</span>
              </label>
              <label title="Sales-officer semantics are not defined by the current accounting model.">
                <input type="checkbox" disabled />
                <span>Treat as Sales Officer</span>
              </label>
              <label>
                <input
                  type="checkbox"
                  checked={reverseOrder}
                  onChange={(event) => setReverseOrder(event.target.checked)}
                />
                <span>In Reverse Order</span>
              </label>
            </div>
          </div>

          <div className="party-ledger-options-body">
            <div className="party-ledger-options-columns">
              <div className="party-ledger-left-options">
                <label className="party-ledger-check disabled-option" title="Document filtering requires a server-side report filter to keep totals authoritative.">
                  <input type="checkbox" disabled />
                  <span>Auto Sale Man</span>
                </label>

                <div className="party-ledger-inline-options">
                  <div className="party-ledger-field-row">
                    <span>Narration Col:</span>
                    <UiSelect
                      value="remarks"
                      disabled
                      searchable={false}
                      options={[
                        { value: 'remarks', label: 'Remarks' },
                        { value: 'detail1', label: 'Detail1' },
                        { value: 'detail2', label: 'Detail2' },
                        { value: 'detail3', label: 'Detail3' },
                        { value: 'detailall', label: 'DetailALL' },
                        { value: 'onlydesc', label: 'OnlyDesc' },
                      ]}
                      onChange={() => undefined}
                      aria-label="Narration column"
                    />
                  </div>

                  <div className="party-ledger-field-row party-ledger-show-only">
                    <span>Show Only:</span>
                    <UiSelect
                      value=""
                      disabled
                      searchable={false}
                      options={[
                        { value: '', label: 'All' },
                        { value: 'non-whole-sale', label: 'Non-Whole Sale' },
                        { value: 'whole-sale', label: 'Whole Sale' },
                      ]}
                      onChange={() => undefined}
                      aria-label="Show only"
                    />
                  </div>

                  <label className="party-ledger-remarks-size">
                    <span>Remarks Font Size:</span>
                    <input
                      type="number"
                      min={7}
                      max={16}
                      value={remarksFontSize}
                      onChange={(event) => setRemarksFontSize(Math.max(7, Math.min(16, Number(event.target.value) || 9)))}
                    />
                  </label>
                </div>

                <div className="party-ledger-left-lower">
                  <div className="party-ledger-zm-stack">
                    <div className="party-ledger-zm-row">
                      <span>ZM:</span>
                      <input type="text" value="" disabled aria-label="ZM" />
                    </div>

                    <fieldset className="party-ledger-zm-card" disabled>
                      <legend>ZM Selection</legend>
                      <label><input type="checkbox" /><span>Selection semantics not mapped yet</span></label>
                    </fieldset>
                  </div>

                  <fieldset className="party-ledger-documents" disabled>
                    <legend>Select a Document</legend>
                    {['PURCHASES', 'PURCHASE RETURN', 'SALES INVOICE', 'SALES RETURN', 'ADJUSTMENTS', 'LOCAL PURCHASE', 'SALES RETAIL'].map((label) => (
                      <label key={label}>
                        <input type="checkbox" />
                        <span>{label}</span>
                      </label>
                    ))}
                  </fieldset>
                </div>
              </div>

              <div className="party-ledger-right-options">
                <label className="party-ledger-check">
                  <input
                    type="checkbox"
                    checked={allDates}
                    onChange={(event) => setAllDates(event.target.checked)}
                  />
                  <span>Ledger for All Dates</span>
                </label>

                <div className="party-ledger-dates">
                  <label>
                    <span>From:</span>
                    <input
                      type="date"
                      value={dateFrom}
                      disabled={allDates}
                      onChange={(event) => setDateFrom(event.target.value)}
                    />
                  </label>
                  <label>
                    <span>To:</span>
                    <input
                      type="date"
                      value={dateTo}
                      disabled={allDates}
                      onChange={(event) => setDateTo(event.target.value)}
                    />
                  </label>
                </div>

                <div className="party-ledger-report-fields">
                  <div className="party-ledger-field-row">
                    <span>Select a Ledger Type:</span>
                    <div className="party-ledger-select-plus">
                      <UiSelect
                        value="summary"
                        searchable={false}
                        options={[
                          { value: 'summary', label: 'Summary Ledger' },
                          { value: 'above', label: 'Above List View', disabled: true, title: 'Reference layout semantics not yet mapped.' },
                          { value: 'pos', label: 'POS Ledger', disabled: true, title: 'Reference layout semantics not yet mapped.' },
                        ]}
                        onChange={() => undefined}
                        aria-label="Ledger type"
                      />
                      <UiButton
                        variant="primary"
                        className="party-ledger-plus-button"
                        title="Report Type On / Off Options"
                        aria-label="Report Type On / Off Options"
                        onClick={() => setReportTypeOptionsOpen(true)}
                      >
                        <Plus size={14} />
                      </UiButton>
                    </div>
                  </div>

                  <div className="party-ledger-field-row">
                    <span>Group by:</span>
                    <div className="party-ledger-select-plus">
                      <UiSelect
                        value=""
                        disabled
                        searchable={false}
                        options={[{ value: '', label: 'Not mapped yet' }]}
                        onChange={() => undefined}
                        aria-label="Group by"
                      />
                      <UiButton
                        variant="primary"
                        className="party-ledger-plus-button"
                        title="Ledger Group Fields"
                        aria-label="Ledger Group Fields"
                        onClick={() => setGroupFieldsOpen(true)}
                      >
                        <Plus size={14} />
                      </UiButton>
                    </div>
                  </div>

                  {['Filter By:', 'Only For:'].map((label) => (
                    <div className="party-ledger-field-row" key={label}>
                      <span>{label}</span>
                      <UiSelect
                        value=""
                        disabled
                        searchable={false}
                        options={[{ value: '', label: 'Not mapped yet' }]}
                        onChange={() => undefined}
                        aria-label={label}
                      />
                    </div>
                  ))}
                </div>

                <div className="party-ledger-bottom-checks">
                  <label>
                    <input
                      type="checkbox"
                      checked={!includeOpeningBalance}
                      onChange={(event) => setIncludeOpeningBalance(!event.target.checked)}
                    />
                    <span>Don't Include Opening Bal</span>
                  </label>
                  <label title="Subgroup total suppression requires server-side grouping support.">
                    <input type="checkbox" disabled />
                    <span>Don't Show Total (Subgroup)</span>
                  </label>
                  <label title="Subgroup reset semantics are not defined by the reference screenshots.">
                    <input type="checkbox" disabled />
                    <span>Reset Total (Subgroup)</span>
                  </label>
                  <label title="Separate-page behavior will be enabled with printable multi-account reports.">
                    <input type="checkbox" disabled />
                    <span>Each Ledger on Separate Page</span>
                  </label>
                  <label title="Book number display will be enabled with the printable report phase.">
                    <input type="checkbox" disabled />
                    <span>Book No Also</span>
                  </label>
                </div>
              </div>
            </div>
          </div>

          <div className="party-ledger-actions">
            <UiButton variant="info" disabled={previewLoading || !selected} onClick={() => void openPreview()}>
              <Eye size={16} />
              {previewLoading ? 'Loading…' : 'Preview'}
            </UiButton>
            <UiButton variant="info" onClick={closeActiveTab}>
              <XCircle size={16} />
              Close
            </UiButton>
          </div>
        </section>
      </div>

      <UiModal
        open={reportTypeOptionsOpen}
        title="Report Type On / Off Options"
        size="md"
        onClose={() => setReportTypeOptionsOpen(false)}
        footer={
          <UiButton variant="info" onClick={() => setReportTypeOptionsOpen(false)}>Close</UiButton>
        }
      >
        <div className="party-ledger-config-table">
          <table>
            <thead>
              <tr>
                <th>Index</th>
                <th>Report Name</th>
                <th>Used For</th>
                <th>Active</th>
              </tr>
            </thead>
            <tbody>
              {[
                ['0', 'Summary Ledger', true],
                ['1', 'Detailed Ledger (Short Form)', false],
                ['2', 'Invoice - Receipt Ledger', false],
                ['3', 'Category Wise VC Ledger', false],
                ['4', 'Detailed Ledger (Landscape)', false],
                ['5', 'Vochers Seperate Ledger', false],
                ['6', 'Above List View', false],
                ['7', 'Ledger With Product Grouping', false],
                ['8', 'POS Ledger', false],
              ].map(([index, name, active]) => (
                <tr key={String(index)}>
                  <td>{index}</td>
                  <td>{name}</td>
                  <td>Ledger</td>
                  <td className="is-center">
                    <input type="checkbox" checked={Boolean(active)} disabled readOnly />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="party-ledger-config-note">
            Summary Ledger is implemented. Other report layouts remain disabled until their report semantics are mapped.
          </p>
        </div>
      </UiModal>

      <UiModal
        open={groupFieldsOpen}
        title="Ledger Group Fields"
        size="md"
        onClose={() => setGroupFieldsOpen(false)}
        footer={
          <UiButton variant="info" onClick={() => setGroupFieldsOpen(false)}>Close</UiButton>
        }
      >
        <div className="party-ledger-config-table">
          <table>
            <thead>
              <tr>
                <th>Field of Database</th>
                <th>Field Display to User</th>
              </tr>
            </thead>
            <tbody>
              {[
                ['AreaName', 'Area Name'],
                ['CategoryName', 'Category Name'],
                ['DOC', 'Document'],
                ['EntryDate', 'Date'],
                ['MAreaName', 'Main Area Name'],
                ['SaleMan', 'Sales Person Name'],
                ['UserName', 'User Name'],
              ].map(([field, label]) => (
                <tr key={field}>
                  <td>{field}</td>
                  <td>{label}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="party-ledger-config-note">
            These fields are shown from the reference. Grouped ledger calculation will be enabled only after the matching server-side data is available.
          </p>
        </div>
      </UiModal>

      <UiModal
        open={Boolean(preview)}
        title={
          <span className="party-ledger-preview-title">
            <BookOpen size={16} />
            {preview?.account.name ?? 'Ledger Preview'}
          </span>
        }
        size="xl"
        onClose={() => setPreview(null)}
        footer={
          <UiButton variant="info" onClick={() => setPreview(null)}>Close</UiButton>
        }
      >
        {preview ? (
          <div className="party-ledger-preview">
            <div className="party-ledger-statement-head">
              <div className="party-ledger-statement-title">ACCOUNT STATEMENT</div>
              <div className="party-ledger-statement-period">
                {allDates ? 'ALL DATES' : `${dateFrom} TO ${dateTo}`}
              </div>
              <div className="party-ledger-statement-name">{preview.account.name}</div>
              <div className="party-ledger-statement-info">
                <span><strong>Code:</strong> {preview.account.code}</span>
                <span><strong>Address:</strong> {preview.account.address || '—'}</span>
              </div>
            </div>

            <div className="party-ledger-preview-grid">
              <table>
                <thead>
                  <tr>
                    <th className="is-center">#</th>
                    <th>Date</th>
                    <th>Trans#</th>
                    <th>DOC</th>
                    <th>Narration</th>
                    <th className="is-num">Debit</th>
                    <th className="is-num">Credit</th>
                    <th className="is-num">Balance</th>
                  </tr>
                </thead>
                <tbody>
                  <tr className="party-ledger-opening-row">
                    <td colSpan={7}>Opening Balance:</td>
                    <td className="is-num">{preview.carry_forward}</td>
                  </tr>
                  {previewRows.map((row, index) => (
                    <tr key={row.line_ulid}>
                      <td className="is-center">{index + 1}</td>
                      <td>{row.date}</td>
                      <td>{row.trans_no}</td>
                      <td>{row.doc}</td>
                      <td style={{ fontSize: `${remarksFontSize}px` }}>{row.remarks ?? '—'}</td>
                      <td className="is-num">{row.debit}</td>
                      <td className="is-num">{row.credit}</td>
                      <td className="is-num">{row.balance}</td>
                    </tr>
                  ))}
                  {previewRows.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="party-ledger-preview-empty">No posted ledger transactions in this period.</td>
                    </tr>
                  ) : null}
                </tbody>
                <tfoot>
                  <tr>
                    <td colSpan={5}>Totals</td>
                    <td className="is-num">{preview.totals.debit}</td>
                    <td className="is-num">{preview.totals.credit}</td>
                    <td className="is-num">{preview.totals.closing_balance}</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        ) : null}
      </UiModal>
    </DesktopPanel>
  )
}
