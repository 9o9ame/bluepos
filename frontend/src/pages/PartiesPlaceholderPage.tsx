import { useEffect, useMemo, useRef, useState } from 'react'
import {
  BookOpen,
  ChevronFirst,
  ChevronLast,
  ChevronLeft,
  ChevronRight,
  FileSpreadsheet,
  FolderPlus,
  RefreshCw,
  Save,
  Table2,
  UsersRound,
  XCircle,
} from 'lucide-react'
import {
  createParty,
  fetchParties,
  updateParty,
  type Party,
  type PartyListFilter,
  type PartyTypeApi,
} from '../api/parties'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './PartiesPlaceholderPage.css'

type DetailTab = 'contact' | 'bank' | 'others' | 'formulas' | 'opening'
type ViewTab = 'entry' | 'ledger' | 'bulk' | 'coa'
type PartyType = 'VENDORS' | 'CUSTOMERS' | 'ACCOUNTS' | 'SALES MAN' | 'ALL'

type PartyListRow = {
  key: string
  ulid: string
  partyType: PartyTypeApi
  no: string
  name: string
  address: string
  type: string
}

const PARTY_TYPES: PartyType[] = ['VENDORS', 'CUSTOMERS', 'ACCOUNTS', 'SALES MAN', 'ALL']
const CREATABLE_TYPES: PartyType[] = ['VENDORS', 'CUSTOMERS']

const ACCOUNT_ROOTS = [
  'ASSETS',
  'LIABILITIES',
  'EXPENSES',
  'REVENUES',
  'CAPITAL',
  'INVENTORY',
] as const

function uiTypeToFilter(type: PartyType): PartyListFilter {
  switch (type) {
    case 'VENDORS':
      return 'vendor'
    case 'CUSTOMERS':
      return 'customer'
    case 'ACCOUNTS':
      return 'account'
    case 'SALES MAN':
      return 'salesman'
    default:
      return 'all'
  }
}

function apiTypeToUi(type: PartyTypeApi): PartyType {
  return type === 'vendor' ? 'VENDORS' : 'CUSTOMERS'
}

function uiTypeToApi(type: PartyType): PartyTypeApi | null {
  if (type === 'VENDORS') return 'vendor'
  if (type === 'CUSTOMERS') return 'customer'
  return null
}

function typeLabel(type: PartyTypeApi): string {
  return type === 'vendor' ? 'VENDOR' : 'CUSTOMER'
}

function emptyForm(listFilter: PartyType = 'ALL') {
  return {
    ulid: '',
    code: '',
    type: listFilter,
    name: '',
    dealsIn: '',
    address: '',
    billAddress: '',
    contactPerson: '',
    mobile1: '',
    mobile2: '',
    phone1: '',
    phone2: '',
    email: '',
    license: '',
    licenseIssue: '',
    licenseType: 'A',
    licenseExp: '',
    ignoreWarranty: false,
    printLicense: false,
    rfId: '',
    storeAllowed: '',
    formulaDraft: '',
    discontinued: false,
    invoiceRestricted: false,
    addPercent: '0',
    crLimit: '0',
    days: '0',
    cnic: '',
    ntn: '',
    stn: '',
    accountType: '',
  }
}

type FormState = ReturnType<typeof emptyForm>

function partyToForm(party: Party, listFilter: PartyType): FormState {
  return {
    ...emptyForm(listFilter),
    ulid: party.ulid,
    code: party.code,
    type: apiTypeToUi(party.party_type),
    name: party.name,
    dealsIn: party.deals_in ?? '',
    address: party.address ?? '',
    billAddress: party.billing_address ?? '',
    contactPerson: party.contact_person ?? '',
    mobile1: party.mobile ?? '',
    mobile2: party.mobile_secondary ?? '',
    phone1: party.phone ?? '',
    phone2: party.phone_secondary ?? '',
    email: party.email ?? '',
    discontinued: !party.is_active,
  }
}

function formSnapshot(form: FormState): string {
  return JSON.stringify({
    ulid: form.ulid,
    code: form.code,
    type: form.type,
    name: form.name,
    dealsIn: form.dealsIn,
    address: form.address,
    billAddress: form.billAddress,
    contactPerson: form.contactPerson,
    mobile1: form.mobile1,
    mobile2: form.mobile2,
    phone1: form.phone1,
    phone2: form.phone2,
    email: form.email,
    discontinued: form.discontinued,
  })
}

function errMessage(err: unknown): string {
  if (err && typeof err === 'object' && 'message' in err && typeof (err as { message: unknown }).message === 'string') {
    return (err as { message: string }).message
  }
  return 'Request failed'
}

export function PartiesPlaceholderPage() {
  const { closeActiveTab } = useWorkspace()
  const [viewTab, setViewTab] = useState<ViewTab>('entry')
  const [subTab, setSubTab] = useState<DetailTab>('contact')
  const [listFilter, setListFilter] = useState<PartyType>('ALL')
  const [form, setForm] = useState(() => emptyForm('ALL'))
  const [parties, setParties] = useState<Party[]>([])
  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [expandedRoots, setExpandedRoots] = useState<Record<string, boolean>>({
    ASSETS: true,
  })
  const [coaShowGrouped, setCoaShowGrouped] = useState(true)
  const [bulkOnlyExpired, setBulkOnlyExpired] = useState(false)
  const baselineRef = useRef(formSnapshot(emptyForm('ALL')))

  const rows: PartyListRow[] = useMemo(
    () =>
      parties.map((party, index) => ({
        key: `${party.party_type}:${party.ulid}`,
        ulid: party.ulid,
        partyType: party.party_type,
        no: String(index + 1),
        name: party.name,
        address: party.address ?? '',
        type: typeLabel(party.party_type),
      })),
    [parties],
  )

  const selectedIndex = rows.findIndex((row) => row.key === selectedKey)
  const recordLabel =
    rows.length === 0
      ? 'Record 0 of 0'
      : `Record ${Math.max(selectedIndex, 0) + 1} of ${rows.length}`
  const dirty = formSnapshot(form) !== baselineRef.current
  const canSave =
    !saving &&
    form.name.trim().length > 0 &&
    form.code.trim().length > 0 &&
    CREATABLE_TYPES.includes(form.type) &&
    listFilter !== 'ACCOUNTS' &&
    listFilter !== 'SALES MAN'

  const subTabs = useMemo(
    () =>
      [
        ['contact', 'Contact Info.'],
        ['bank', 'Bank A/Cs'],
        ['others', 'Others'],
        ['formulas', 'Formulas'],
        ['opening', 'Opening'],
      ] as const,
    [],
  )

  function patchForm<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((current) => ({ ...current, [key]: value }))
  }

  function applyBaseline(next: FormState) {
    baselineRef.current = formSnapshot(next)
    setForm(next)
  }

  function confirmDiscard(): boolean {
    if (!dirty) return true
    return window.confirm('Discard unsaved changes?')
  }

  async function loadParties(filter: PartyType = listFilter, keepSelection = false) {
    setLoading(true)
    setError(null)
    try {
      const data = await fetchParties(uiTypeToFilter(filter))
      setParties(data)
      if (!keepSelection) {
        setSelectedKey(null)
      } else if (selectedKey) {
        const stillThere = data.some((p) => `${p.party_type}:${p.ulid}` === selectedKey)
        if (!stillThere) setSelectedKey(null)
      }
    } catch (err) {
      setError(errMessage(err))
      setParties([])
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadParties('ALL')
    // initial load only
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function startNew(filter: PartyType = listFilter) {
    const nextType = CREATABLE_TYPES.includes(filter) ? filter : 'VENDORS'
    applyBaseline(emptyForm(nextType))
    setSelectedKey(null)
    setSubTab('contact')
    setError(null)
  }

  async function refresh() {
    if (!confirmDiscard()) return
    await loadParties(listFilter, false)
    startNew(listFilter)
    setViewTab('entry')
  }

  function selectRow(row: PartyListRow) {
    if (row.key === selectedKey) return
    if (!confirmDiscard()) return
    const party = parties.find((p) => p.ulid === row.ulid && p.party_type === row.partyType)
    if (!party) return
    applyBaseline(partyToForm(party, listFilter))
    setSelectedKey(row.key)
    setError(null)
  }

  function moveSelection(index: number) {
    if (rows.length === 0) return
    const bounded = Math.min(Math.max(index, 0), rows.length - 1)
    selectRow(rows[bounded])
  }

  function onTypeChange(next: PartyType) {
    if (!confirmDiscard()) return
    setListFilter(next)
    applyBaseline(emptyForm(CREATABLE_TYPES.includes(next) ? next : 'VENDORS'))
    setSelectedKey(null)
    void loadParties(next, false)
  }

  async function save() {
    const partyType = uiTypeToApi(form.type)
    if (!partyType) {
      setError('ACCOUNTS and SALES MAN are not creatable in this phase.')
      return
    }
    if (!form.code.trim() || !form.name.trim()) {
      setError('Code and Name are required.')
      return
    }

    setSaving(true)
    setError(null)
    const payload = {
      party_type: partyType,
      code: form.code.trim(),
      name: form.name.trim(),
      deals_in: form.dealsIn.trim() || null,
      contact_person: form.contactPerson.trim() || null,
      mobile: form.mobile1.trim() || null,
      mobile_secondary: form.mobile2.trim() || null,
      phone: form.phone1.trim() || null,
      phone_secondary: form.phone2.trim() || null,
      email: form.email.trim() || null,
      address: form.address.trim() || null,
      billing_address: form.billAddress.trim() || null,
      is_active: !form.discontinued,
    }

    try {
      const saved = form.ulid
        ? await updateParty(form.ulid, payload)
        : await createParty(payload)
      await loadParties(listFilter, true)
      const next = partyToForm(saved, listFilter)
      applyBaseline(next)
      setSelectedKey(`${saved.party_type}:${saved.ulid}`)
    } catch (err) {
      setError(errMessage(err))
    } finally {
      setSaving(false)
    }
  }

  function toggleRoot(name: string) {
    setExpandedRoots((current) => ({ ...current, [name]: !current[name] }))
  }

  return (
    <div className="parties-vca">
      <header className="parties-vca-header">
        <div className="parties-vca-title-wrap">
          <h1>Vendor / Customers / Accounts</h1>
          <span className="parties-vca-title-icon" aria-hidden>
            <UsersRound size={36} strokeWidth={1.7} />
          </span>
        </div>

        <div className="parties-vca-actions">
          <button
            type="button"
            className="parties-vca-action is-save"
            disabled={!canSave}
            title={
              CREATABLE_TYPES.includes(form.type)
                ? form.ulid
                  ? 'Update party'
                  : 'Create party'
                : 'Only VENDORS and CUSTOMERS can be saved in this phase'
            }
            onClick={() => void save()}
          >
            <Save size={22} />
            <span>{saving ? 'Saving…' : 'Save'}</span>
          </button>
          <button type="button" className="parties-vca-action is-refresh" disabled={loading} onClick={() => void refresh()}>
            <RefreshCw size={22} />
            <span>Refresh</span>
          </button>
          <button type="button" className="parties-vca-action is-close" onClick={closeActiveTab}>
            <XCircle size={22} />
            <span>Close</span>
          </button>
        </div>
      </header>

      {error ? <div className="parties-vca-error" role="alert">{error}</div> : null}

      <nav className="parties-vca-tabs" aria-label="Party views">
        <button type="button" className={viewTab === 'entry' ? 'is-active' : undefined} onClick={() => setViewTab('entry')}>
          <UsersRound size={14} />
          <span>Data Entry Details</span>
        </button>
        <button type="button" className={viewTab === 'ledger' ? 'is-active' : undefined} onClick={() => setViewTab('ledger')}>
          <BookOpen size={14} />
          <span>Ledger</span>
        </button>
        <button type="button" className={viewTab === 'bulk' ? 'is-active' : undefined} onClick={() => setViewTab('bulk')}>
          <FileSpreadsheet size={14} />
          <span>Bulk Updation</span>
        </button>
        <button type="button" className={viewTab === 'coa' ? 'is-active' : undefined} onClick={() => setViewTab('coa')}>
          <Table2 size={14} />
          <span>Chart Of Account View</span>
        </button>
      </nav>

      {viewTab === 'entry' ? (
        <div className="parties-vca-main">
          <section className="parties-vca-left" aria-label="Party data entry">
            <fieldset className="parties-vca-panel">
              <legend>Personal Information</legend>

              <div className="parties-vca-personal-top">
                <div className="parties-vca-field-row parties-vca-id-row">
                  <label htmlFor="vca-id">ID</label>
                  <input id="vca-id" value={form.ulid || '—'} readOnly title="Public ULID" />
                  <label htmlFor="vca-code">CODE</label>
                  <input
                    id="vca-code"
                    value={form.code}
                    onChange={(e) => patchForm('code', e.target.value)}
                  />
                  <label htmlFor="vca-type">Type</label>
                  <select
                    id="vca-type"
                    value={form.ulid ? form.type : listFilter}
                    onChange={(e) => {
                      const next = e.target.value as PartyType
                      if (form.ulid) {
                        if (!CREATABLE_TYPES.includes(next)) {
                          setError('Cannot change an existing party to ACCOUNTS or SALES MAN.')
                          return
                        }
                        patchForm('type', next)
                        return
                      }
                      onTypeChange(next)
                    }}
                  >
                    {PARTY_TYPES.map((type) => (
                      <option key={type} value={type} disabled={Boolean(form.ulid) && !CREATABLE_TYPES.includes(type)}>
                        {type}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="parties-vca-nav-arrows" aria-label="Record navigation">
                  <button type="button" disabled={rows.length === 0 || selectedIndex <= 0} onClick={() => moveSelection(selectedIndex - 1)}>
                    ←
                  </button>
                  <button
                    type="button"
                    disabled={rows.length === 0 || selectedIndex < 0 || selectedIndex >= rows.length - 1}
                    onClick={() => moveSelection(selectedIndex + 1)}
                  >
                    →
                  </button>
                </div>
              </div>

              <div className="parties-vca-field-row">
                <label htmlFor="vca-name">Name</label>
                <input
                  id="vca-name"
                  value={form.name}
                  onChange={(e) => patchForm('name', e.target.value)}
                />
              </div>

              <div className="parties-vca-field-row">
                <label htmlFor="vca-deals">Deals In</label>
                <input
                  id="vca-deals"
                  value={form.dealsIn}
                  onChange={(e) => patchForm('dealsIn', e.target.value)}
                />
              </div>

              <div className="parties-vca-subtabs" role="tablist" aria-label="Detail sections">
                {subTabs.map(([key, label]) => (
                  <button
                    key={key}
                    type="button"
                    role="tab"
                    aria-selected={subTab === key}
                    className={subTab === key ? 'is-active' : undefined}
                    onClick={() => setSubTab(key)}
                  >
                    {label}
                  </button>
                ))}
              </div>

              <div className="parties-vca-subpanel" role="tabpanel">
                {subTab === 'contact' ? (
                  <div className="parties-vca-contact">
                    <div className="parties-vca-contact-fields">
                      <div className="parties-vca-field-row parties-vca-field-row-top">
                        <label htmlFor="vca-address">Address</label>
                        <textarea
                          id="vca-address"
                          rows={2}
                          value={form.address}
                          onChange={(e) => patchForm('address', e.target.value)}
                        />
                      </div>
                      <div className="parties-vca-field-row parties-vca-field-row-top">
                        <label htmlFor="vca-bill">Bill Address</label>
                        <textarea
                          id="vca-bill"
                          rows={2}
                          value={form.billAddress}
                          onChange={(e) => patchForm('billAddress', e.target.value)}
                        />
                      </div>
                      <div className="parties-vca-field-row">
                        <label htmlFor="vca-person">Cont. Person</label>
                        <input
                          id="vca-person"
                          value={form.contactPerson}
                          onChange={(e) => patchForm('contactPerson', e.target.value)}
                        />
                      </div>
                      <div className="parties-vca-field-row parties-vca-twin">
                        <label>Mobile(s)</label>
                        <input value={form.mobile1} onChange={(e) => patchForm('mobile1', e.target.value)} />
                        <input value={form.mobile2} onChange={(e) => patchForm('mobile2', e.target.value)} />
                      </div>
                      <div className="parties-vca-field-row parties-vca-twin">
                        <label>Phone(s)</label>
                        <input value={form.phone1} onChange={(e) => patchForm('phone1', e.target.value)} />
                        <input value={form.phone2} onChange={(e) => patchForm('phone2', e.target.value)} />
                      </div>
                      <div className="parties-vca-field-row">
                        <label htmlFor="vca-email">Email</label>
                        <input
                          id="vca-email"
                          type="email"
                          value={form.email}
                          onChange={(e) => patchForm('email', e.target.value)}
                        />
                      </div>
                    </div>
                    <button
                      type="button"
                      className="parties-vca-photo"
                      disabled
                      title="Party image upload deferred — no shared media upload architecture for parties yet"
                    >
                      Right Click to Add
                    </button>
                  </div>
                ) : null}

                {subTab === 'bank' ? (
                  <div className="parties-vca-mini-grid-wrap">
                    <table className="parties-vca-mini-grid">
                      <thead>
                        <tr>
                          <th>Bank Name</th>
                          <th>Branch Name</th>
                          <th>Branch Code</th>
                          <th>City</th>
                          <th>Account No</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr>
                          <td colSpan={5} className="parties-vca-empty">No bank accounts</td>
                        </tr>
                      </tbody>
                    </table>
                    <div className="parties-vca-mini-foot">Record 0 of 0</div>
                  </div>
                ) : null}

                {subTab === 'others' ? (
                  <div className="parties-vca-others">
                    <div className="parties-vca-field-row parties-vca-others-license">
                      <label htmlFor="vca-license">License</label>
                      <input id="vca-license" value={form.license} onChange={(e) => patchForm('license', e.target.value)} />
                      <label htmlFor="vca-issue">Issue</label>
                      <input id="vca-issue" type="date" value={form.licenseIssue} onChange={(e) => patchForm('licenseIssue', e.target.value)} />
                    </div>
                    <div className="parties-vca-field-row parties-vca-others-license">
                      <label htmlFor="vca-lic-type">Lic Type</label>
                      <select id="vca-lic-type" value={form.licenseType} onChange={(e) => patchForm('licenseType', e.target.value)}>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                      </select>
                      <label htmlFor="vca-exp">Exp</label>
                      <input id="vca-exp" type="date" value={form.licenseExp} onChange={(e) => patchForm('licenseExp', e.target.value)} />
                    </div>
                    <div className="parties-vca-check-row">
                      <label>
                        <input
                          type="checkbox"
                          checked={form.ignoreWarranty}
                          onChange={(e) => patchForm('ignoreWarranty', e.target.checked)}
                        />
                        <span>Ignore Warranty</span>
                      </label>
                      <label>
                        <input
                          type="checkbox"
                          checked={form.printLicense}
                          onChange={(e) => patchForm('printLicense', e.target.checked)}
                        />
                        <span>Print License</span>
                      </label>
                    </div>
                    <div className="parties-vca-field-row">
                      <label htmlFor="vca-rfid">RF ID</label>
                      <input id="vca-rfid" value={form.rfId} onChange={(e) => patchForm('rfId', e.target.value)} />
                    </div>
                    <div className="parties-vca-field-row">
                      <label htmlFor="vca-store">Store Allowed</label>
                      <select id="vca-store" value={form.storeAllowed} onChange={(e) => patchForm('storeAllowed', e.target.value)}>
                        <option value="">—</option>
                        <option value="ALL">ALL</option>
                        <option value="BRANCH">BRANCH</option>
                      </select>
                    </div>
                  </div>
                ) : null}

                {subTab === 'formulas' ? (
                  <div className="parties-vca-formulas">
                    <div className="parties-vca-formula-list" aria-label="Formula list">
                      <div className="parties-vca-formula-list-empty">No formulas saved</div>
                    </div>
                    <textarea
                      className="parties-vca-formula-editor"
                      spellCheck={false}
                      placeholder="Formula editor (visual only — not executed)"
                      value={form.formulaDraft}
                      onChange={(e) => patchForm('formulaDraft', e.target.value)}
                    />
                  </div>
                ) : null}

                {subTab === 'opening' ? (
                  <div className="parties-vca-mini-grid-wrap">
                    <table className="parties-vca-mini-grid">
                      <thead>
                        <tr>
                          <th>Sales Person</th>
                          <th>Narration</th>
                          <th>Balance</th>
                          <th>Debit</th>
                          <th>Credit</th>
                          <th>Closing</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr>
                          <td colSpan={6} className="parties-vca-empty">No opening lines</td>
                        </tr>
                      </tbody>
                    </table>
                    <div className="parties-vca-mini-foot">Opening balances post after Phase 5 journals</div>
                  </div>
                ) : null}
              </div>
            </fieldset>

            <fieldset className="parties-vca-panel parties-vca-account-panel">
              <legend>Account Related Information</legend>

              <div className="parties-vca-check-row">
                <label>
                  <input
                    type="checkbox"
                    checked={form.discontinued}
                    onChange={(e) => patchForm('discontinued', e.target.checked)}
                  />
                  <span>Discontinued</span>
                </label>
                <label>
                  <input
                    type="checkbox"
                    checked={form.invoiceRestricted}
                    onChange={(e) => patchForm('invoiceRestricted', e.target.checked)}
                  />
                  <span>Invoice Related / Restricted</span>
                </label>
              </div>

              <div className="parties-vca-account-grid">
                <label htmlFor="vca-add">Add %</label>
                <input id="vca-add" value={form.addPercent} onChange={(e) => patchForm('addPercent', e.target.value)} />
                <label htmlFor="vca-cr">Cr Limit</label>
                <input id="vca-cr" value={form.crLimit} onChange={(e) => patchForm('crLimit', e.target.value)} />
                <label htmlFor="vca-days">Days</label>
                <input id="vca-days" value={form.days} onChange={(e) => patchForm('days', e.target.value)} />

                <label htmlFor="vca-cnic">CNIC</label>
                <input id="vca-cnic" value={form.cnic} onChange={(e) => patchForm('cnic', e.target.value)} />
                <label htmlFor="vca-ntn">NTN</label>
                <input id="vca-ntn" value={form.ntn} onChange={(e) => patchForm('ntn', e.target.value)} />
                <label htmlFor="vca-stn">STN</label>
                <input id="vca-stn" value={form.stn} onChange={(e) => patchForm('stn', e.target.value)} />
              </div>

              <div className="parties-vca-account-type-row">
                <label htmlFor="vca-account-type">Account Type</label>
                <input
                  id="vca-account-type"
                  list="vca-account-type-hints"
                  value={form.accountType}
                  placeholder="Select when COA API exists"
                  onChange={(e) => patchForm('accountType', e.target.value)}
                />
                <datalist id="vca-account-type-hints">
                  {ACCOUNT_ROOTS.map((root) => (
                    <option key={root} value={root} />
                  ))}
                </datalist>
                <button type="button" className="parties-vca-account-type-btn" disabled title="Account types await COA API">
                  + Account Type
                </button>
              </div>
            </fieldset>
          </section>

          <section className="parties-vca-right" aria-label="Party list and classification">
            <div className="parties-vca-grid-wrap">
              <table className="parties-vca-grid">
                <thead>
                  <tr>
                    <th className="col-no">No</th>
                    <th className="col-name">Name</th>
                    <th className="col-address">Address</th>
                    <th className="col-type">Type</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={4} className="parties-vca-empty">
                        {loading
                          ? 'Loading…'
                          : listFilter === 'ACCOUNTS' || listFilter === 'SALES MAN'
                            ? `${listFilter} master is not available in this phase`
                            : 'No party records yet'}
                      </td>
                    </tr>
                  ) : (
                    rows.map((row) => (
                      <tr
                        key={row.key}
                        className={row.key === selectedKey ? 'is-selected' : undefined}
                        onClick={() => selectRow(row)}
                      >
                        <td>{row.no}</td>
                        <td>{row.name}</td>
                        <td>{row.address}</td>
                        <td>{row.type}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            <div className="parties-vca-grid-footer">
              <div className="parties-vca-record-nav">
                <button type="button" disabled={rows.length === 0} onClick={() => moveSelection(0)}>
                  <ChevronFirst size={13} />
                </button>
                <button type="button" disabled={rows.length === 0 || selectedIndex <= 0} onClick={() => moveSelection(selectedIndex - 1)}>
                  <ChevronLeft size={13} />
                </button>
                <span>{recordLabel}</span>
                <button
                  type="button"
                  disabled={rows.length === 0 || selectedIndex < 0 || selectedIndex >= rows.length - 1}
                  onClick={() => moveSelection(selectedIndex + 1)}
                >
                  <ChevronRight size={13} />
                </button>
                <button type="button" disabled={rows.length === 0} onClick={() => moveSelection(rows.length - 1)}>
                  <ChevronLast size={13} />
                </button>
              </div>
              <div className="parties-vca-drcr">
                <span>Dr</span>
                <strong>0</strong>
                <span>Cr</span>
                <strong>0</strong>
              </div>
            </div>

            <div className="parties-vca-tree">
              <div className="parties-vca-tree-title">Account classification</div>
              <ul className="parties-vca-tree-list">
                {ACCOUNT_ROOTS.map((root, index) => {
                  const open = Boolean(expandedRoots[root])
                  return (
                    <li key={root} className="parties-vca-tree-node">
                      <button type="button" className="parties-vca-tree-summary" onClick={() => toggleRoot(root)}>
                        <span className="parties-vca-tree-toggle">{open ? '−' : '+'}</span>
                        <span className="parties-vca-tree-label">{root}</span>
                        <span className="parties-vca-tree-id">{index + 1}</span>
                      </button>
                      {open ? (
                        <div className="parties-vca-tree-child">Child accounts appear when COA API is available</div>
                      ) : null}
                    </li>
                  )
                })}
              </ul>
            </div>
          </section>
        </div>
      ) : null}

      {viewTab === 'ledger' ? (
        <div className="parties-vca-shell">
          <div className="parties-vca-ledger-head">
            <div className="parties-vca-ledger-meta">
              <div><span>Name:</span> <strong>{form.name || '—'}</strong></div>
              <div><span>Address:</span> <strong>{form.address || '—'}</strong></div>
              <div><span>Area:</span> <strong>(NONE)</strong></div>
            </div>
            <button type="button" className="parties-vca-shell-btn" disabled title="Requires parties + journals API">
              <FolderPlus size={16} />
              Create Necessary A/Cs
            </button>
          </div>
          <div className="parties-vca-shell-grid-wrap">
            <table className="parties-vca-shell-grid">
              <thead>
                <tr>
                  <th>Trans#</th>
                  <th>Date</th>
                  <th>DOC</th>
                  <th>Remarks</th>
                  <th>Debit</th>
                  <th>Credit</th>
                  <th>Balance</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td colSpan={7} className="parties-vca-empty">Ledger posting is not implemented in VCA-1</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div className="parties-vca-shell-foot">
            <span>Record 0 of 0</span>
            <div className="parties-vca-drcr">
              <span>Dr</span>
              <strong>0</strong>
              <span>Cr</span>
              <strong>0</strong>
            </div>
          </div>
        </div>
      ) : null}

      {viewTab === 'bulk' ? (
        <div className="parties-vca-shell">
          <div className="parties-vca-bulk-toolbar">
            <button type="button" className="parties-vca-shell-btn is-update" disabled title="Bulk update awaits parties API">
              <Save size={16} />
              Update
            </button>
            <label className="parties-vca-check-inline">
              <input
                type="checkbox"
                checked={bulkOnlyExpired}
                onChange={(e) => setBulkOnlyExpired(e.target.checked)}
              />
              <span>Only Expired Lic.</span>
            </label>
          </div>
          <div className="parties-vca-shell-grid-wrap">
            <table className="parties-vca-shell-grid parties-vca-bulk-grid">
              <thead>
                <tr>
                  <th>No</th>
                  <th>D</th>
                  <th>I/R</th>
                  <th>Code</th>
                  <th>Name</th>
                  <th>Type</th>
                  <th>Area</th>
                  <th>Account Type</th>
                  <th>Cr Limit</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td colSpan={9} className="parties-vca-empty">No rows — bulk list loads with parties API</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div className="parties-vca-bulk-actions">
            <button type="button" disabled>Export Excel Template</button>
            <button type="button" disabled>Convert Vendor</button>
            <button type="button" disabled>Import COA</button>
            <button type="button" disabled>Import from Excel</button>
          </div>
        </div>
      ) : null}

      {viewTab === 'coa' ? (
        <div className="parties-vca-shell">
          <div className="parties-vca-coa-toolbar">
            <button type="button" className="parties-vca-shell-btn" disabled title="Export awaits COA API">
              <FileSpreadsheet size={16} />
              Export to XLSX
            </button>
            <label className="parties-vca-check-inline">
              <input
                type="checkbox"
                checked={coaShowGrouped}
                onChange={(e) => setCoaShowGrouped(e.target.checked)}
              />
              <span>Show Grouped Columns Also</span>
            </label>
          </div>
          <div className="parties-vca-shell-grid-wrap">
            <table className="parties-vca-shell-grid parties-vca-coa-grid">
              <thead>
                <tr>
                  <th>Main Head</th>
                  <th>Account</th>
                  <th>Head</th>
                  <th>Sub Head</th>
                </tr>
              </thead>
              <tbody>
                {coaShowGrouped
                  ? ACCOUNT_ROOTS.map((root, index) => (
                      <tr key={root} className="parties-vca-coa-group">
                        <td colSpan={4}>{`${String(index + 1).padStart(2, '0')}-${root}`}</td>
                      </tr>
                    ))
                  : null}
                <tr>
                  <td colSpan={4} className="parties-vca-empty">
                    Hierarchical COA rows appear when accounts API is available
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div className="parties-vca-shell-foot">
            <span>Record 0 of 0</span>
          </div>
        </div>
      ) : null}
    </div>
  )
}
