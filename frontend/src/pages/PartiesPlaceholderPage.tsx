import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import {
  BookOpen,
  ChevronFirst,
  ChevronLast,
  ChevronLeft,
  ChevronRight,
  FileSpreadsheet,
  FolderPlus,
  ImagePlus,
  Plus,
  RefreshCw,
  Save,
  Table2,
  UsersRound,
  XCircle,
} from 'lucide-react'
import { fetchBusinessSettings } from '../api/catalog'
import {
  bulkUpdateParties,
  createParty,
  createPartyProfile,
  createPartyBankAccount,
  createPartyOpeningBalance,
  deletePartyBankAccount,
  deletePartyImage,
  deletePartyOpeningBalance,
  downloadPartyExcelTemplate,
  ensurePartyLeafAccount,
  fetchParties,
  fetchPartyProfile,
  fetchPartyProfiles,
  fetchPartyBankAccounts,
  fetchPartyLedger,
  fetchPartyOpeningBalances,
  importPartyExcel,
  postPartyOpeningBalance,
  previewPartyExcel,
  updateParty,
  updatePartyProfile,
  updatePartyBankAccount,
  updatePartyOpeningBalance,
  uploadPartyImage,
  type Party,
  type PartyBusinessType,
  type PartyProfilePayload,
  type PartyBankAccount,
  type PartyExcelPreview,
  type PartyLedger,
  type PartyListFilter,
  type PartyOpeningBalance,
  type PartyTypeApi,
} from '../api/parties'
import {
  fetchAccountTypes,
  fetchCoaChart,
  type CoaAccountType,
  type CoaFlatRow,
  type CoaGroupedNode,
} from '../api/coa'
import { CoaHierarchyModals } from '../components/parties/CoaHierarchyModals'
import { PartyTypeMultiSelect } from '../components/parties/PartyTypeMultiSelect'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { AnimatedSelect } from '../components/ui/AnimatedSelect'
import { ToggleSwitch } from '../components/ui/ToggleSwitch'
import { askConfirm, formatApiError, useFeedback } from '../feedback/FeedbackProvider'
import './PartiesPlaceholderPage.css'
import './PartiesPlaceholderPage.modern.css'

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
const CREATABLE_TYPES: PartyType[] = ['VENDORS', 'CUSTOMERS', 'ACCOUNTS', 'SALES MAN']

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
  if (type === 'vendor') return 'VENDORS'
  if (type === 'customer') return 'CUSTOMERS'
  if (type === 'salesman') return 'SALES MAN'
  return 'ACCOUNTS'
}

function uiTypeToApi(type: PartyType): PartyTypeApi | null {
  if (type === 'VENDORS') return 'vendor'
  if (type === 'CUSTOMERS') return 'customer'
  if (type === 'ACCOUNTS') return 'account'
  if (type === 'SALES MAN') return 'salesman'
  return null
}

function typeLabel(type: PartyTypeApi): string {
  if (type === 'vendor') return 'VENDOR'
  if (type === 'customer') return 'CUSTOMER'
  if (type === 'salesman') return 'SALESMAN'
  return 'ACCOUNT'
}

function defaultBusinessTypes(type: PartyType): PartyBusinessType[] {
  if (type === 'CUSTOMERS') return ['customer']
  if (type === 'SALES MAN') return ['salesman']
  if (type === 'VENDORS' || type === 'ALL') return ['vendor']
  return []
}

function suggestedAccountTypeName(partyType: PartyType): string | null {
  if (partyType === 'CUSTOMERS') return 'ACCOUNT RECEIVABLE'
  if (partyType === 'VENDORS') return 'ACCOUNT PAYABLE'
  return null
}

function emptyForm(listFilter: PartyType = 'ALL') {
  return {
    ulid: '',
    identityUlid: '',
    vendorUlid: '',
    customerUlid: '',
    code: '',
    type: listFilter,
    types: defaultBusinessTypes(listFilter),
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
    accountTypeUlid: '',
    vendorAccountTypeUlid: '',
    customerAccountTypeUlid: '',
  }
}

type FormState = ReturnType<typeof emptyForm>

function partyToForm(party: Party, listFilter: PartyType): FormState {
  return {
    ...emptyForm(listFilter),
    ulid: party.ulid,
    identityUlid: party.identity_ulid ?? '',
    vendorUlid: party.vendor_ulid ?? '',
    customerUlid: party.customer_ulid ?? '',
    code: party.code,
    type: apiTypeToUi(party.party_type),
    types: party.party_types ?? (party.party_type === 'account' ? [] : [party.party_type as PartyBusinessType]),
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
    invoiceRestricted: Boolean(party.invoice_restricted),
    addPercent: party.add_percent ?? '0',
    crLimit: party.credit_limit_amount ?? '0.0000',
    days: String(party.credit_limit_days ?? 0),
    cnic: party.cnic ?? '',
    ntn: party.ntn ?? '',
    stn: party.stn ?? '',
    formulaDraft: party.formulas ?? '',
    accountType: party.account_type?.name ?? '',
    accountTypeUlid: party.account_type_ulid ?? '',
    vendorAccountTypeUlid: party.vendor_account_type_ulid ?? (party.party_type === 'vendor' ? party.account_type_ulid ?? '' : ''),
    customerAccountTypeUlid: party.customer_account_type_ulid ?? (party.party_type === 'customer' ? party.account_type_ulid ?? '' : ''),
    license: party.license_number ?? '',
    licenseIssue: party.license_issued_on ?? '',
    licenseType: party.license_type || 'A',
    licenseExp: party.license_expires_on ?? '',
    ignoreWarranty: Boolean(party.ignore_warranty),
    printLicense: Boolean(party.print_license),
    rfId: party.rf_id ?? '',
    storeAllowed: party.store_allowed ?? '',
  }
}

function formSnapshot(form: FormState): string {
  return JSON.stringify({
    ulid: form.ulid,
    identityUlid: form.identityUlid,
    vendorUlid: form.vendorUlid,
    customerUlid: form.customerUlid,
    code: form.code,
    type: form.type,
    types: form.types,
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
    invoiceRestricted: form.invoiceRestricted,
    addPercent: form.addPercent,
    crLimit: form.crLimit,
    days: form.days,
    cnic: form.cnic,
    ntn: form.ntn,
    stn: form.stn,
    formulaDraft: form.formulaDraft,
    accountTypeUlid: form.accountTypeUlid,
    vendorAccountTypeUlid: form.vendorAccountTypeUlid,
    customerAccountTypeUlid: form.customerAccountTypeUlid,
    license: form.license,
    licenseIssue: form.licenseIssue,
    licenseType: form.licenseType,
    licenseExp: form.licenseExp,
    ignoreWarranty: form.ignoreWarranty,
    printLicense: form.printLicense,
    rfId: form.rfId,
    storeAllowed: form.storeAllowed,
  })
}

type BankDraft = {
  key: string
  ulid: string | null
  bank_name: string
  branch_name: string
  branch_code: string
  city: string
  account_number: string
  dirty: boolean
}

type OpeningDraft = {
  key: string
  ulid: string | null
  status: 'draft' | 'posted'
  narration: string
  debit: string
  credit: string
  balance: string
  closing: string
  dirty: boolean
}

type BulkDraft = {
  key: string
  ulid: string
  party_type: PartyTypeApi
  discontinued: boolean
  restricted: boolean
  code: string
  name: string
  typeLabel: string
  area: string
  account_type_ulid: string
  account_type_name: string
  credit_limit_amount: string
  dirty: boolean
}

function openingClosing(debit: string, credit: string, balance = '0.0000'): string {
  const d = Number(debit) || 0
  const c = Number(credit) || 0
  const b = Number(balance) || 0
  return (b + d - c).toFixed(4)
}

function openingsFromApi(rows: PartyOpeningBalance[]): OpeningDraft[] {
  return rows.map((row) => ({
    key: row.ulid,
    ulid: row.ulid,
    status: row.status,
    narration: row.narration ?? '',
    debit: row.debit,
    credit: row.credit,
    balance: row.balance,
    closing: row.closing,
    dirty: false,
  }))
}

function errMessage(err: unknown): string {
  return formatApiError(err)
}

function partyKey(party: Party): string {
  return party.identity_ulid ? `profile:${party.identity_ulid}` : `${party.party_type}:${party.ulid}`
}

function partyTypeSummary(party: Party): string {
  if (party.party_type === 'account') return 'ACCOUNT'
  const types = party.party_types ?? [party.party_type as PartyBusinessType]
  return types.map((type) => typeLabel(type)).join(' + ')
}


export function PartiesPlaceholderPage({
  embedded = false,
  onClose,
  onSaved,
}: {
  embedded?: boolean
  onClose?: () => void
  onSaved?: (party: Party) => void
} = {}) {
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const [viewTab, setViewTab] = useState<ViewTab>('entry')
  const [subTab, setSubTab] = useState<DetailTab>('contact')
  const [listFilter, setListFilter] = useState<PartyType>('ALL')
  const [form, setForm] = useState(() => emptyForm('ALL'))
  const [parties, setParties] = useState<Party[]>([])
  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [coaShowGrouped, setCoaShowGrouped] = useState(true)
  const [coaFlatRows, setCoaFlatRows] = useState<CoaFlatRow[]>([])
  const [coaGroupedRows, setCoaGroupedRows] = useState<CoaGroupedNode[]>([])
  const [coaChartLoading, setCoaChartLoading] = useState(false)
  const [expandedCoaMain, setExpandedCoaMain] = useState<Record<string, boolean>>({})
  const [expandedCoaHead, setExpandedCoaHead] = useState<Record<string, boolean>>({})
  const [expandedCoaSub, setExpandedCoaSub] = useState<Record<string, boolean>>({})
  const [bulkOnlyExpired, setBulkOnlyExpired] = useState(false)
  const [bulkRows, setBulkRows] = useState<BulkDraft[]>([])
  const [bulkSaving, setBulkSaving] = useState(false)
  const [excelPreview, setExcelPreview] = useState<PartyExcelPreview | null>(null)
  const [excelFile, setExcelFile] = useState<File | null>(null)
  const excelInputRef = useRef<HTMLInputElement | null>(null)
  const imageInputRef = useRef<HTMLInputElement | null>(null)
  const [pendingImage, setPendingImage] = useState<File | null>(null)
  const [pendingImagePreview, setPendingImagePreview] = useState<string | null>(null)
  const [savedImageUrl, setSavedImageUrl] = useState<string | null>(null)
  const [imageRemoveRequested, setImageRemoveRequested] = useState(false)
  const [accountTypes, setAccountTypes] = useState<CoaAccountType[]>([])
  const [coaModalOpen, setCoaModalOpen] = useState(false)
  const [bankRows, setBankRows] = useState<BankDraft[]>([])
  const [selectedBankKey, setSelectedBankKey] = useState<string | null>(null)
  const [openingRows, setOpeningRows] = useState<OpeningDraft[]>([])
  const [selectedOpeningKey, setSelectedOpeningKey] = useState<string | null>(null)
  const [openingBusy, setOpeningBusy] = useState(false)
  const [openingEquityConfigured, setOpeningEquityConfigured] = useState(false)
  const [ledger, setLedger] = useState<PartyLedger | null>(null)
  const [ledgerLoading, setLedgerLoading] = useState(false)
  const [ledgerPage, setLedgerPage] = useState(1)
  const [ensureBusy, setEnsureBusy] = useState(false)
  const baselineRef = useRef(formSnapshot(emptyForm('ALL')))

  const rows: PartyListRow[] = useMemo(
    () =>
      parties.map((party, index) => ({
        key: partyKey(party),
        ulid: party.ulid,
        partyType: party.party_type,
        no: String(index + 1),
        name: party.name,
        address: party.address ?? '',
        type: partyTypeSummary(party),
      })),
    [parties],
  )

  const selectedIndex = rows.findIndex((row) => row.key === selectedKey)
  const recordLabel =
    rows.length === 0
      ? 'Record 0 of 0'
      : `Record ${Math.max(selectedIndex, 0) + 1} of ${rows.length}`
  const dirty =
    formSnapshot(form) !== baselineRef.current ||
    bankRows.some((r) => r.dirty) ||
    openingRows.some((r) => r.dirty)
  const isManualAccount = form.type === 'ACCOUNTS'
  const canSave =
    !saving &&
    form.name.trim().length > 0 &&
    form.code.trim().length > 0 &&
    CREATABLE_TYPES.includes(form.type) &&
    (isManualAccount
      ? form.accountTypeUlid.trim().length > 0
      : form.types.length > 0 &&
        (!form.types.includes('vendor') || form.vendorAccountTypeUlid.trim().length > 0) &&
        (!form.types.includes('customer') || form.customerAccountTypeUlid.trim().length > 0))

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

  async function confirmDiscard(): Promise<boolean> {
    if (!dirty) return true
    return askConfirm('Discard unsaved changes?')
  }

  async function loadParties(filter: PartyType = listFilter, keepSelection = false) {
    setLoading(true)
    setError(null)
    try {
      let data: Party[]
      if (filter === 'ACCOUNTS') {
        data = await fetchParties('account')
      } else if (filter === 'ALL') {
        const [profiles, accounts] = await Promise.all([
          fetchPartyProfiles('all'),
          fetchParties('account'),
        ])
        data = [...profiles, ...accounts]
      } else {
        const type = uiTypeToFilter(filter) as PartyBusinessType
        data = await fetchPartyProfiles(type)
      }

      setParties(data)
      if (!keepSelection) {
        setSelectedKey(null)
      } else if (selectedKey) {
        const stillThere = data.some((party) => partyKey(party) === selectedKey)
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
    void loadCoaTree()
    // initial load only
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (viewTab !== 'coa') return
    void loadCoaChart()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [viewTab, coaShowGrouped])

  useEffect(() => {
    if (subTab !== 'opening') return
    void (async () => {
      try {
        const settings = await fetchBusinessSettings()
        setOpeningEquityConfigured(Boolean(settings.opening_balance_equity_account_ulid))
      } catch {
        setOpeningEquityConfigured(false)
      }
    })()
  }, [subTab])

  useEffect(() => {
    if (viewTab !== 'ledger') return
    if (!form.ulid) {
      setLedger(null)
      return
    }
    const partyType = uiTypeToApi(form.type)
    if (!partyType) {
      setLedger(null)
      return
    }
    void loadLedger(form.ulid, partyType, ledgerPage)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [viewTab, form.ulid, form.type, ledgerPage])

  useEffect(() => {
    if (viewTab !== 'bulk') return
    void loadBulkRows()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [viewTab, bulkOnlyExpired, listFilter])

  async function loadBulkRows() {
    try {
      const filter = uiTypeToFilter(listFilter === 'SALES MAN' ? 'ALL' : listFilter)
      const data = await fetchParties(filter, { expiredLicense: bulkOnlyExpired })
      setBulkRows(
        data.map((party) => ({
          key: `${party.party_type}:${party.ulid}`,
          ulid: party.ulid,
          party_type: party.party_type,
          discontinued: !party.is_active,
          restricted: Boolean(party.invoice_restricted),
          code: party.code,
          name: party.name,
          typeLabel: typeLabel(party.party_type),
          area: party.area ?? '',
          account_type_ulid: party.account_type_ulid ?? '',
          account_type_name: party.account_type?.name ?? '',
          credit_limit_amount: party.credit_limit_amount ?? '0.0000',
          dirty: false,
        })),
      )
    } catch (err) {
      setBulkRows([])
      setError(errMessage(err))
    }
  }

  function patchBulk(key: string, patch: Partial<BulkDraft>) {
    setBulkRows((rows) =>
      rows.map((row) => (row.key === key ? { ...row, ...patch, dirty: true } : row)),
    )
  }

  async function saveBulkDirty() {
    const dirty = bulkRows.filter((r) => r.dirty)
    if (dirty.length === 0) {
      setError('No dirty bulk rows to update.')
      return
    }
    setBulkSaving(true)
    setError(null)
    try {
      await bulkUpdateParties(
        dirty.map((row) => ({
          ulid: row.ulid,
          party_type: row.party_type,
          is_active: !row.discontinued,
          invoice_restricted: row.restricted,
          area: row.area.trim() || null,
          account_type_ulid: row.account_type_ulid || null,
          credit_limit_amount: row.credit_limit_amount,
        })),
      )
      await loadBulkRows()
      await loadParties(listFilter, true)
    } catch (err) {
      setError(errMessage(err))
    } finally {
      setBulkSaving(false)
    }
  }

  async function onExcelSelected(file: File | null) {
    setExcelFile(file)
    setExcelPreview(null)
    if (!file) return
    try {
      const preview = await previewPartyExcel(file)
      setExcelPreview(preview)
    } catch (err) {
      setError(errMessage(err))
    }
  }

  async function confirmExcelImport() {
    if (!excelFile) return
    try {
      const result = await importPartyExcel(excelFile)
      setError(`Import complete: ${result.created} created, ${result.updated} updated.`)
      setExcelPreview(null)
      setExcelFile(null)
      if (excelInputRef.current) excelInputRef.current.value = ''
      await loadBulkRows()
      await loadParties(listFilter, true)
    } catch (err) {
      setError(errMessage(err))
    }
  }

  async function loadLedger(partyUlid: string, partyType: PartyTypeApi, page: number) {
    setLedgerLoading(true)
    try {
      const data = await fetchPartyLedger(partyUlid, partyType, page)
      setLedger(data)
    } catch (err) {
      setLedger(null)
      setError(errMessage(err))
    } finally {
      setLedgerLoading(false)
    }
  }

  async function onEnsureLeafAccount() {
    if (!form.ulid) {
      setError('Select or save a party first.')
      return
    }
    const partyType = uiTypeToApi(form.type)
    if (!partyType) {
      setError('Select Vendor, Customer, or Account.')
      return
    }
    setEnsureBusy(true)
    setError(null)
    try {
      const result = await ensurePartyLeafAccount(form.ulid, partyType)
      setError(result.message)
      if (viewTab === 'ledger') {
        await loadLedger(form.ulid, partyType, ledgerPage)
      }
    } catch (err) {
      setError(errMessage(err))
    } finally {
      setEnsureBusy(false)
    }
  }

  async function loadCoaTree() {
    try {
      const types = await fetchAccountTypes()
      setAccountTypes(types.filter((t) => t.is_active))
    } catch {
      setAccountTypes([])
    }
  }

  async function loadCoaChart() {
    setCoaChartLoading(true)
    try {
      const payload = await fetchCoaChart(coaShowGrouped)
      if (payload.mode === 'flat') {
        setCoaFlatRows(payload.flat)
        setCoaGroupedRows([])
      } else {
        setCoaGroupedRows(payload.grouped)
        setCoaFlatRows([])
        setExpandedCoaMain((current) => {
          if (Object.keys(current).length > 0) return current
          const next: Record<string, boolean> = {}
          for (const main of payload.grouped) next[main.ulid] = true
          return next
        })
        setExpandedCoaHead((current) => {
          if (Object.keys(current).length > 0) return current
          const next: Record<string, boolean> = {}
          for (const main of payload.grouped) {
            for (const head of main.heads) next[head.ulid] = true
          }
          return next
        })
        setExpandedCoaSub((current) => {
          if (Object.keys(current).length > 0) return current
          const next: Record<string, boolean> = {}
          for (const main of payload.grouped) {
            for (const head of main.heads) {
              for (const sub of head.sub_heads) next[sub.ulid] = true
            }
          }
          return next
        })
      }
    } catch {
      setCoaFlatRows([])
      setCoaGroupedRows([])
    } finally {
      setCoaChartLoading(false)
    }
  }

  function toggleCoaMain(ulid: string) {
    setExpandedCoaMain((current) => ({ ...current, [ulid]: !current[ulid] }))
  }

  function toggleCoaHead(ulid: string) {
    setExpandedCoaHead((current) => ({ ...current, [ulid]: !current[ulid] }))
  }

  function toggleCoaSub(ulid: string) {
    setExpandedCoaSub((current) => ({ ...current, [ulid]: !current[ulid] }))
  }

  function applySuggestedAccountType(partyType: PartyType, formState: FormState): FormState {
    if (formState.accountTypeUlid) return formState
    const hint = suggestedAccountTypeName(partyType)
    if (!hint) return formState
    const match = accountTypes.find((t) => t.name.toUpperCase() === hint)
    if (!match) return formState
    return { ...formState, accountType: match.name, accountTypeUlid: match.ulid }
  }


  function applySuggestedProfileAccountTypes(formState: FormState): FormState {
    let next = { ...formState }

    if (next.types.includes('vendor') && !next.vendorAccountTypeUlid) {
      const match = accountTypes.find((t) => t.name.toUpperCase() === 'ACCOUNT PAYABLE')
      if (match) next = { ...next, vendorAccountTypeUlid: match.ulid }
    }

    if (next.types.includes('customer') && !next.customerAccountTypeUlid) {
      const match = accountTypes.find((t) => t.name.toUpperCase() === 'ACCOUNT RECEIVABLE')
      if (match) next = { ...next, customerAccountTypeUlid: match.ulid }
    }

    return next
  }

  function partyFinancialContext(party: Party): { ulid: string; type: 'vendor' | 'customer' | 'account' } | null {
    if (party.party_type === 'account') return { ulid: party.ulid, type: 'account' }
    if (party.party_type === 'vendor' && party.vendor_ulid) return { ulid: party.vendor_ulid, type: 'vendor' }
    if (party.party_type === 'customer' && party.customer_ulid) return { ulid: party.customer_ulid, type: 'customer' }
    if (party.customer_ulid) return { ulid: party.customer_ulid, type: 'customer' }
    if (party.vendor_ulid) return { ulid: party.vendor_ulid, type: 'vendor' }
    return null
  }

  function formFinancialContext(): { ulid: string; type: 'vendor' | 'customer' | 'account' } | null {
    if (form.type === 'ACCOUNTS' && form.ulid) return { ulid: form.ulid, type: 'account' }
    if (form.type === 'CUSTOMERS' && form.customerUlid) return { ulid: form.customerUlid, type: 'customer' }
    if (form.type === 'VENDORS' && form.vendorUlid) return { ulid: form.vendorUlid, type: 'vendor' }
    if (form.customerUlid) return { ulid: form.customerUlid, type: 'customer' }
    if (form.vendorUlid) return { ulid: form.vendorUlid, type: 'vendor' }
    return null
  }

  function banksFromApi(rows: PartyBankAccount[]): BankDraft[] {
    return rows.map((row) => ({
      key: row.ulid,
      ulid: row.ulid,
      bank_name: row.bank_name,
      branch_name: row.branch_name ?? '',
      branch_code: row.branch_code ?? '',
      city: row.city ?? '',
      account_number: row.account_number ?? '',
      dirty: false,
    }))
  }

  async function loadBanks(partyUlid: string, partyType: PartyTypeApi) {
    try {
      const rows = await fetchPartyBankAccounts(partyUlid, partyType)
      setBankRows(banksFromApi(rows))
      setSelectedBankKey(null)
    } catch {
      setBankRows([])
      setSelectedBankKey(null)
    }
  }

  async function loadOpenings(partyUlid: string, partyType: PartyTypeApi) {
    try {
      const rows = await fetchPartyOpeningBalances(partyUlid, partyType)
      setOpeningRows(openingsFromApi(rows))
      setSelectedOpeningKey(null)
    } catch {
      setOpeningRows([])
      setSelectedOpeningKey(null)
    }
  }

  function clearAncillaryPartyState() {
    setBankRows([])
    setSelectedBankKey(null)
    setOpeningRows([])
    setSelectedOpeningKey(null)
    setLedger(null)
    setLedgerPage(1)
    setError(null)
    clearPartyImageState()
  }

  function clearPartyImageState() {
    if (pendingImagePreview) URL.revokeObjectURL(pendingImagePreview)
    setPendingImage(null)
    setPendingImagePreview(null)
    setSavedImageUrl(null)
    setImageRemoveRequested(false)
    if (imageInputRef.current) imageInputRef.current.value = ''
  }

  function applyPartyImage(party: Party) {
    if (pendingImagePreview) URL.revokeObjectURL(pendingImagePreview)
    setPendingImage(null)
    setPendingImagePreview(null)
    setSavedImageUrl(party.image_url)
    setImageRemoveRequested(false)
    if (imageInputRef.current) imageInputRef.current.value = ''
  }

  function openPartyImagePicker() {
    imageInputRef.current?.click()
  }

  function onPartyImageSelected(file: File | null) {
    if (!file) return
    if (pendingImagePreview) URL.revokeObjectURL(pendingImagePreview)
    setPendingImage(file)
    setPendingImagePreview(URL.createObjectURL(file))
    setImageRemoveRequested(false)
  }

  function removePartyImagePreview() {
    if (pendingImagePreview) URL.revokeObjectURL(pendingImagePreview)
    setPendingImage(null)
    setPendingImagePreview(null)
    if (savedImageUrl) {
      setImageRemoveRequested(true)
    }
    if (imageInputRef.current) imageInputRef.current.value = ''
  }

  function startNew(filter: PartyType = listFilter) {
    const nextType = CREATABLE_TYPES.includes(filter) ? filter : 'VENDORS'
    const base = emptyForm(nextType)
    const blank = nextType === 'ACCOUNTS'
      ? applySuggestedAccountType(nextType, base)
      : applySuggestedProfileAccountTypes(base)
    applyBaseline(blank)
    setSelectedKey(null)
    clearAncillaryPartyState()
    setSubTab('contact')
  }

  async function closeEditor() {
    if (!(await confirmDiscard())) return
    ;(onClose ?? closeActiveTab)()
  }

  async function refresh() {
    if (!(await confirmDiscard())) return
    await loadParties(listFilter, false)
    startNew(listFilter)
    setViewTab('entry')
  }

  async function selectRow(row: PartyListRow) {
    if (row.key === selectedKey) return
    if (!(await confirmDiscard())) return
    const party = parties.find((item) => partyKey(item) === row.key)
    if (!party) return
    applyBaseline(partyToForm(party, listFilter))
    setSelectedKey(row.key)
    setError(null)
    setLedger(null)
    setLedgerPage(1)
    applyPartyImage(party)
    const financial = partyFinancialContext(party)
    if (financial) {
      void loadBanks(financial.ulid, financial.type)
      void loadOpenings(financial.ulid, financial.type)
    } else {
      setBankRows([])
      setOpeningRows([])
    }
  }

  function moveSelection(index: number) {
    if (rows.length === 0) return
    const bounded = Math.min(Math.max(index, 0), rows.length - 1)
    void selectRow(rows[bounded])
  }

  async function onTypeChange(next: PartyType) {
    if (!(await confirmDiscard())) return
    setListFilter(next)
    const nextType = CREATABLE_TYPES.includes(next) ? next : 'VENDORS'
    const base = emptyForm(nextType)
    applyBaseline(
      nextType === 'ACCOUNTS'
        ? applySuggestedAccountType(nextType, base)
        : applySuggestedProfileAccountTypes(base),
    )
    setSelectedKey(null)
    clearAncillaryPartyState()
    setSubTab('contact')
    void loadParties(next, false)
  }

  async function save() {
    if (!form.code.trim() || !form.name.trim()) {
      setError('Code and Name are required.')
      return
    }

    const manualAccount = form.type === 'ACCOUNTS'
    if (manualAccount && !form.accountTypeUlid.trim()) {
      setError('Account Type is required. Create one via + Account Type if needed.')
      return
    }

    if (!manualAccount) {
      if (form.types.length === 0) {
        setError('Select at least one Type.')
        return
      }
      if (form.types.includes('vendor') && !form.vendorAccountTypeUlid.trim()) {
        setError('Vendor Account Type is required.')
        return
      }
      if (form.types.includes('customer') && !form.customerAccountTypeUlid.trim()) {
        setError('Customer Account Type is required.')
        return
      }
    }

    setSaving(true)
    setError(null)

    const common = {
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
      invoice_restricted: form.invoiceRestricted,
      credit_limit_amount: form.crLimit.trim() || '0.0000',
      credit_limit_days: Number(form.days) || 0,
      add_percent: form.addPercent.trim() || '0',
      cnic: form.cnic.trim() || null,
      ntn: form.ntn.trim() || null,
      stn: form.stn.trim() || null,
      formulas: form.formulaDraft.trim() || null,
      license_number: form.license.trim() || null,
      license_issued_on: form.licenseIssue || null,
      license_type: form.licenseType || null,
      license_expires_on: form.licenseExp || null,
      ignore_warranty: form.ignoreWarranty,
      print_license: form.printLicense,
      rf_id: form.rfId.trim() || null,
      store_allowed: form.storeAllowed || null,
    }

    try {
      const wasNew = manualAccount ? !form.ulid : !form.identityUlid
      let saved: Party

      if (manualAccount) {
        const payload = {
          party_type: 'account' as const,
          code: common.code,
          name: common.name,
          account_type_ulid: form.accountTypeUlid.trim(),
          address: common.address,
          is_active: common.is_active,
          invoice_restricted: common.invoice_restricted,
          credit_limit_amount: common.credit_limit_amount,
          credit_limit_days: common.credit_limit_days,
          add_percent: common.add_percent,
          cnic: common.cnic,
          ntn: common.ntn,
          stn: common.stn,
          formulas: common.formulas,
        }
        saved = form.ulid
          ? await updateParty(form.ulid, payload)
          : await createParty(payload)
      } else {
        const requestedPrimary = uiTypeToApi(form.type)
        const primaryType: PartyBusinessType =
          requestedPrimary && requestedPrimary !== 'account' && form.types.includes(requestedPrimary)
            ? requestedPrimary
            : form.types[0]

        const payload: PartyProfilePayload = {
          party_types: form.types,
          primary_type: primaryType,
          vendor_account_type_ulid: form.types.includes('vendor')
            ? form.vendorAccountTypeUlid
            : null,
          customer_account_type_ulid: form.types.includes('customer')
            ? form.customerAccountTypeUlid
            : null,
          ...common,
        }

        saved = form.identityUlid
          ? await updatePartyProfile(form.identityUlid, payload)
          : await createPartyProfile(payload)
      }

      const financial = partyFinancialContext(saved)

      if (financial && wasNew && bankRows.length > 0) {
        for (const row of bankRows) {
          if (!row.bank_name.trim()) continue
          await createPartyBankAccount(financial.ulid, financial.type, {
            bank_name: row.bank_name.trim(),
            branch_name: row.branch_name.trim() || null,
            branch_code: row.branch_code.trim() || null,
            city: row.city.trim() || null,
            account_number: row.account_number.trim() || null,
          })
        }
      } else if (financial && !wasNew) {
        for (const row of bankRows) {
          if (!row.dirty || !row.bank_name.trim()) continue
          if (row.ulid) {
            await updatePartyBankAccount(financial.ulid, financial.type, row.ulid, {
              bank_name: row.bank_name.trim(),
              branch_name: row.branch_name.trim() || null,
              branch_code: row.branch_code.trim() || null,
              city: row.city.trim() || null,
              account_number: row.account_number.trim() || null,
            })
          } else {
            await createPartyBankAccount(financial.ulid, financial.type, {
              bank_name: row.bank_name.trim(),
              branch_name: row.branch_name.trim() || null,
              branch_code: row.branch_code.trim() || null,
              city: row.city.trim() || null,
              account_number: row.account_number.trim() || null,
            })
          }
        }
      }

      let finalSaved = saved
      if (financial && pendingImage) {
        await uploadPartyImage(financial.ulid, financial.type, pendingImage)
        if (saved.identity_ulid) {
          finalSaved = await fetchPartyProfile(saved.identity_ulid)
        } else {
          finalSaved = await fetchParty(financial.ulid, financial.type)
        }
      } else if (financial && imageRemoveRequested && savedImageUrl) {
        await deletePartyImage(financial.ulid, financial.type)
        if (saved.identity_ulid) {
          finalSaved = await fetchPartyProfile(saved.identity_ulid)
        } else {
          finalSaved = await fetchParty(financial.ulid, financial.type)
        }
      }

      await loadParties(listFilter, true)
      const next = partyToForm(finalSaved, listFilter)
      applyBaseline(next)
      setSelectedKey(partyKey(finalSaved))
      applyPartyImage(finalSaved)

      const finalFinancial = partyFinancialContext(finalSaved)
      if (finalFinancial) {
        await loadBanks(finalFinancial.ulid, finalFinancial.type)
        await loadOpenings(finalFinancial.ulid, finalFinancial.type)
      } else {
        setBankRows([])
        setOpeningRows([])
      }

      feedback.success(wasNew ? 'Party created successfully.' : 'Party saved successfully.')
      onSaved?.(finalSaved)
    } catch (err) {
      const message = errMessage(err)
      setError(message)
      feedback.error(message)
    } finally {
      setSaving(false)
    }
  }

  function addBankRow() {
    const key = `draft-${Date.now()}`
    setBankRows((rows) => [
      ...rows,
      {
        key,
        ulid: null,
        bank_name: '',
        branch_name: '',
        branch_code: '',
        city: '',
        account_number: '',
        dirty: true,
      },
    ])
    setSelectedBankKey(key)
  }

  function patchBank(key: string, field: keyof BankDraft, value: string) {
    setBankRows((rows) =>
      rows.map((row) => (row.key === key ? { ...row, [field]: value, dirty: true } : row)),
    )
  }

  async function removeSelectedBank() {
    if (!selectedBankKey) return
    const row = bankRows.find((r) => r.key === selectedBankKey)
    if (!row) return
    if (row.ulid && form.ulid) {
      const partyType = uiTypeToApi(form.type)
      if (!partyType) return
      try {
        await deletePartyBankAccount(form.ulid, partyType, row.ulid)
      } catch (err) {
        setError(errMessage(err))
        return
      }
    }
    setBankRows((rows) => rows.filter((r) => r.key !== selectedBankKey))
    setSelectedBankKey(null)
  }

  function addOpeningRow() {
    if (!form.ulid) {
      setError('Save the party first, then add opening balances.')
      return
    }
    const key = `draft-ob-${Date.now()}`
    setOpeningRows((rows) => [
      ...rows,
      {
        key,
        ulid: null,
        status: 'draft',
        narration: '',
        debit: '0.0000',
        credit: '0.0000',
        balance: '0.0000',
        closing: '0.0000',
        dirty: true,
      },
    ])
    setSelectedOpeningKey(key)
  }

  function patchOpening(key: string, field: 'narration' | 'debit' | 'credit', value: string) {
    setOpeningRows((rows) =>
      rows.map((row) => {
        if (row.key !== key || row.status === 'posted') return row
        const next = { ...row, [field]: value, dirty: true }
        next.closing = openingClosing(next.debit, next.credit, next.balance)
        return next
      }),
    )
  }

  async function persistOpeningRow(row: OpeningDraft) {
    const partyType = uiTypeToApi(form.type)
    if (!partyType || !form.ulid) return
    const debit = Number(row.debit) || 0
    const credit = Number(row.credit) || 0
    if (debit > 0 && credit > 0) {
      setError('Debit and credit cannot both be positive.')
      return
    }
    if (debit <= 0 && credit <= 0) {
      setError('Opening amount requires debit or credit.')
      return
    }
    setOpeningBusy(true)
    setError(null)
    try {
      const payload = {
        opening_date: new Date().toISOString().slice(0, 10),
        narration: row.narration.trim() || null,
        debit: debit.toFixed(4),
        credit: credit.toFixed(4),
      }
      if (row.ulid) {
        await updatePartyOpeningBalance(form.ulid, partyType, row.ulid, payload)
      } else {
        await createPartyOpeningBalance(form.ulid, partyType, payload)
      }
      await loadOpenings(form.ulid, partyType)
    } catch (err) {
      setError(errMessage(err))
    } finally {
      setOpeningBusy(false)
    }
  }

  async function removeSelectedOpening() {
    if (!selectedOpeningKey) return
    const row = openingRows.find((r) => r.key === selectedOpeningKey)
    if (!row) return
    if (row.status === 'posted') {
      setError('Posted opening balances are immutable.')
      return
    }
    if (row.ulid && form.ulid) {
      const partyType = uiTypeToApi(form.type)
      if (!partyType) return
      try {
        await deletePartyOpeningBalance(form.ulid, partyType, row.ulid)
      } catch (err) {
        setError(errMessage(err))
        return
      }
    }
    setOpeningRows((rows) => rows.filter((r) => r.key !== selectedOpeningKey))
    setSelectedOpeningKey(null)
  }

  async function postSelectedOpening() {
    if (!selectedOpeningKey || !form.ulid) return
    if (!openingEquityConfigured) {
      setError('Configure Opening Balance Equity Account in Business Settings before posting.')
      return
    }
    const row = openingRows.find((r) => r.key === selectedOpeningKey)
    if (!row?.ulid || row.status === 'posted') return
    const partyType = uiTypeToApi(form.type)
    if (!partyType) return
    if (row.dirty) {
      await persistOpeningRow(row)
    }
    setOpeningBusy(true)
    setError(null)
    try {
      await postPartyOpeningBalance(form.ulid, partyType, row.ulid)
      await loadOpenings(form.ulid, partyType)
    } catch (err) {
      setError(errMessage(err))
    } finally {
      setOpeningBusy(false)
    }
  }

  const displayImageUrl = pendingImagePreview ?? (!imageRemoveRequested ? savedImageUrl : null)

  return (
    <div className="parties-vca">
      <header className="parties-vca-header">
        <div className="parties-vca-title-wrap">
          <h1>Vendor / Customers / Accounts</h1>
          <span className="parties-vca-title-icon" aria-hidden>
            <UsersRound size={26} strokeWidth={1.7} />
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
            <Save size={16} />
            <span>{saving ? 'Saving…' : 'Save'}</span>
          </button>
          <button type="button" className="parties-vca-action is-refresh" disabled={loading} onClick={() => void refresh()}>
            <RefreshCw size={16} />
            <span>Refresh</span>
          </button>
          <button type="button" className="parties-vca-action is-close" onClick={() => void closeEditor()}>
            <XCircle size={16} />
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
        {!embedded ? (
          <>
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
          </>
        ) : null}
      </nav>

      {viewTab === 'entry' ? (
        <div className="parties-vca-main">
          <section className="parties-vca-left" aria-label="Party data entry">
            <fieldset className="parties-vca-panel">
              <legend className="parties-vca-legend-row">
                <span>Personal Information</span>
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
              </legend>

              <div className="parties-vca-field-row parties-vca-id-row">
                <label htmlFor="vca-id">ID</label>
                <input id="vca-id" value={form.ulid || '—'} readOnly title="Public ULID" />
                <label htmlFor="vca-code">CODE</label>
                <input
                  id="vca-code"
                  value={form.code}
                  onChange={(e) => patchForm('code', e.target.value)}
                />
                <label>Type</label>
                {form.type === 'ACCOUNTS' ? (
                  <input value="ACCOUNT" readOnly title="Manual ledger accounts remain a separate accounting master." />
                ) : (
                  <PartyTypeMultiSelect
                    value={form.types}
                    onChange={(nextTypes) => {
                      setForm((current) => {
                        const currentApi = uiTypeToApi(current.type)
                        const primary =
                          currentApi && currentApi !== 'account' && nextTypes.includes(currentApi)
                            ? current.type
                            : apiTypeToUi(nextTypes[0])
                        return applySuggestedProfileAccountTypes({
                          ...current,
                          type: primary,
                          types: nextTypes,
                        })
                      })
                    }}
                  />
                )}
              </div>

              <div className="parties-vca-field-row parties-vca-name-deals">
                <label htmlFor="vca-name">Name</label>
                <input
                  id="vca-name"
                  value={form.name}
                  onChange={(e) => patchForm('name', e.target.value)}
                />
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
                      <div className="parties-vca-field-row">
                        <label htmlFor="vca-address">Address</label>
                        <input
                          id="vca-address"
                          value={form.address}
                          onChange={(e) => patchForm('address', e.target.value)}
                        />
                      </div>
                      <div className="parties-vca-field-row">
                        <label htmlFor="vca-bill">Bill Address</label>
                        <input
                          id="vca-bill"
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
                    <div
                      className={`parties-vca-photo${displayImageUrl ? ' has-image' : ''}`}
                      role="button"
                      tabIndex={0}
                      aria-label={displayImageUrl ? 'Change party image' : 'Add party image'}
                      title={
                        displayImageUrl
                          ? 'Click to replace image — Save to persist'
                          : 'Click to add image — Save to persist'
                      }
                      onClick={openPartyImagePicker}
                      onContextMenu={(e) => {
                        e.preventDefault()
                        openPartyImagePicker()
                      }}
                      onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                          e.preventDefault()
                          openPartyImagePicker()
                        }
                      }}
                    >
                      <input
                        ref={imageInputRef}
                        type="file"
                        className="parties-vca-photo-input"
                        accept="image/jpeg,image/png,image/webp"
                        tabIndex={-1}
                        aria-hidden="true"
                        onChange={(e) => onPartyImageSelected(e.target.files?.[0] ?? null)}
                      />
                      {displayImageUrl ? (
                        <>
                          <img src={displayImageUrl} alt="" className="parties-vca-photo-preview" />
                          <button
                            type="button"
                            className="parties-vca-photo-remove"
                            title="Remove image"
                            onClick={(e) => {
                              e.stopPropagation()
                              removePartyImagePreview()
                            }}
                          >
                            ×
                          </button>
                        </>
                      ) : (
                        <span className="parties-vca-photo-empty" aria-hidden="true">
                          <span className="parties-vca-photo-icon">
                            <ImagePlus size={28} strokeWidth={1.7} />
                          </span>
                        </span>
                      )}
                    </div>
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
                        {bankRows.length === 0 ? (
                          <tr>
                            <td colSpan={5} className="parties-vca-empty">No bank accounts</td>
                          </tr>
                        ) : (
                          bankRows.map((row) => (
                            <tr
                              key={row.key}
                              className={row.key === selectedBankKey ? 'is-selected' : undefined}
                              onClick={() => setSelectedBankKey(row.key)}
                            >
                              <td>
                                <input
                                  value={row.bank_name}
                                  onChange={(e) => patchBank(row.key, 'bank_name', e.target.value)}
                                  onFocus={() => setSelectedBankKey(row.key)}
                                />
                              </td>
                              <td>
                                <input
                                  value={row.branch_name}
                                  onChange={(e) => patchBank(row.key, 'branch_name', e.target.value)}
                                  onFocus={() => setSelectedBankKey(row.key)}
                                />
                              </td>
                              <td>
                                <input
                                  value={row.branch_code}
                                  onChange={(e) => patchBank(row.key, 'branch_code', e.target.value)}
                                  onFocus={() => setSelectedBankKey(row.key)}
                                />
                              </td>
                              <td>
                                <input
                                  value={row.city}
                                  onChange={(e) => patchBank(row.key, 'city', e.target.value)}
                                  onFocus={() => setSelectedBankKey(row.key)}
                                />
                              </td>
                              <td>
                                <input
                                  value={row.account_number}
                                  onChange={(e) => patchBank(row.key, 'account_number', e.target.value)}
                                  onFocus={() => setSelectedBankKey(row.key)}
                                />
                              </td>
                            </tr>
                          ))
                        )}
                      </tbody>
                    </table>
                    <div className="parties-vca-mini-foot parties-vca-bank-foot">
                      <span>
                        {bankRows.length === 0
                          ? 'Record 0 of 0'
                          : `Record ${Math.max(bankRows.findIndex((r) => r.key === selectedBankKey), 0) + 1} of ${bankRows.length}`}
                      </span>
                      <span className="parties-vca-bank-actions">
                        <button type="button" onClick={addBankRow}>Add</button>
                        <button type="button" disabled={!selectedBankKey} onClick={() => void removeSelectedBank()}>
                          Remove
                        </button>
                      </span>
                    </div>
                  </div>
                ) : null}

                {subTab === 'others' ? (
                  <div className="parties-vca-others">
                    {form.type === 'ACCOUNTS' ? (
                      <div className="parties-vca-empty" style={{ padding: 8 }}>
                        Others details apply to Vendors and Customers only
                      </div>
                    ) : (
                      <>
                    <div className="parties-vca-field-row parties-vca-others-license">
                      <label htmlFor="vca-license">License</label>
                      <input id="vca-license" value={form.license} onChange={(e) => patchForm('license', e.target.value)} />
                      <label htmlFor="vca-issue">Issue</label>
                      <input id="vca-issue" type="date" value={form.licenseIssue} onChange={(e) => patchForm('licenseIssue', e.target.value)} />
                    </div>
                    <div className="parties-vca-field-row parties-vca-others-license">
                      <label htmlFor="vca-lic-type">Lic Type</label>
                      <AnimatedSelect id="vca-lic-type" value={form.licenseType} onChange={(e) => patchForm('licenseType', e.target.value)}>
                        <option value="A">A</option>
                        <option value="B">B</option>
                        <option value="C">C</option>
                      </AnimatedSelect>
                      <label htmlFor="vca-exp">Exp</label>
                      <input id="vca-exp" type="date" value={form.licenseExp} onChange={(e) => patchForm('licenseExp', e.target.value)} />
                    </div>
                    <div className="parties-vca-check-row">
                      <ToggleSwitch
                        label="Ignore Warranty"
                        checked={form.ignoreWarranty}
                        onChange={(v) => patchForm('ignoreWarranty', v)}
                      />
                      <ToggleSwitch
                        label="Print License"
                        checked={form.printLicense}
                        onChange={(v) => patchForm('printLicense', v)}
                      />
                    </div>
                    <div className="parties-vca-field-row">
                      <label htmlFor="vca-rfid">RF ID</label>
                      <input id="vca-rfid" value={form.rfId} onChange={(e) => patchForm('rfId', e.target.value)} />
                    </div>
                    <div className="parties-vca-field-row">
                      <label htmlFor="vca-store">Store Allowed</label>
                      <AnimatedSelect id="vca-store" value={form.storeAllowed} onChange={(e) => patchForm('storeAllowed', e.target.value)}>
                        <option value="">—</option>
                        <option value="ALL">ALL</option>
                        <option value="BRANCH">BRANCH</option>
                      </AnimatedSelect>
                    </div>
                      </>
                    )}
                  </div>
                ) : null}

                {subTab === 'formulas' ? (
                  <div className="parties-vca-formulas">
                    <div className="parties-vca-formula-list" aria-label="Formula list">
                      {form.formulaDraft
                        .split('\n')
                        .map((line) => line.trim())
                        .filter(Boolean).length === 0 ? (
                        <div className="parties-vca-formula-list-empty">No formulas saved</div>
                      ) : (
                        form.formulaDraft
                          .split('\n')
                          .map((line) => line.trim())
                          .filter(Boolean)
                          .map((line, index) => (
                            <div key={`${index}-${line.slice(0, 24)}`} className="parties-vca-formula-list-item">
                              {line}
                            </div>
                          ))
                      )}
                    </div>
                    <textarea
                      className="parties-vca-formula-editor"
                      spellCheck={false}
                      placeholder="Enter formulas (one per line). Saved with the party — not executed."
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
                        {openingRows.length === 0 ? (
                          <tr>
                            <td colSpan={6} className="parties-vca-empty">No opening lines</td>
                          </tr>
                        ) : (
                          openingRows.map((row) => {
                            const posted = row.status === 'posted'
                            return (
                              <tr
                                key={row.key}
                                className={row.key === selectedOpeningKey ? 'is-selected' : undefined}
                                onClick={() => setSelectedOpeningKey(row.key)}
                              >
                                <td>
                                  <input value="" disabled title="Sales person mapping deferred" placeholder="—" />
                                </td>
                                <td>
                                  <input
                                    value={row.narration}
                                    disabled={posted || openingBusy}
                                    onChange={(e) => patchOpening(row.key, 'narration', e.target.value)}
                                    onFocus={() => setSelectedOpeningKey(row.key)}
                                  />
                                </td>
                                <td>
                                  <input value={row.balance} disabled readOnly />
                                </td>
                                <td>
                                  <input
                                    value={row.debit}
                                    disabled={posted || openingBusy}
                                    onChange={(e) => patchOpening(row.key, 'debit', e.target.value)}
                                    onFocus={() => setSelectedOpeningKey(row.key)}
                                  />
                                </td>
                                <td>
                                  <input
                                    value={row.credit}
                                    disabled={posted || openingBusy}
                                    onChange={(e) => patchOpening(row.key, 'credit', e.target.value)}
                                    onFocus={() => setSelectedOpeningKey(row.key)}
                                  />
                                </td>
                                <td>
                                  <input value={row.closing} disabled readOnly />
                                </td>
                              </tr>
                            )
                          })
                        )}
                      </tbody>
                    </table>
                    <div className="parties-vca-mini-foot parties-vca-bank-foot">
                      <span>
                        {openingRows.length === 0
                          ? 'Record 0 of 0'
                          : `Record ${Math.max(openingRows.findIndex((r) => r.key === selectedOpeningKey), 0) + 1} of ${openingRows.length}`}
                        {!openingEquityConfigured
                          ? ' — Configure Opening Balance Equity Account in Business Settings before posting.'
                          : ''}
                      </span>
                      <span className="parties-vca-bank-actions">
                        <button type="button" disabled={!form.ulid || openingBusy} onClick={addOpeningRow}>
                          Add
                        </button>
                        <button
                          type="button"
                          disabled={
                            !selectedOpeningKey ||
                            openingBusy ||
                            openingRows.find((r) => r.key === selectedOpeningKey)?.status === 'posted'
                          }
                          onClick={() => {
                            const row = openingRows.find((r) => r.key === selectedOpeningKey)
                            if (row) void persistOpeningRow(row)
                          }}
                        >
                          Save
                        </button>
                        <button
                          type="button"
                          disabled={
                            !openingEquityConfigured ||
                            !selectedOpeningKey ||
                            openingBusy ||
                            !openingRows.find((r) => r.key === selectedOpeningKey)?.ulid ||
                            openingRows.find((r) => r.key === selectedOpeningKey)?.status === 'posted'
                          }
                          title={
                            openingEquityConfigured
                              ? undefined
                              : 'Configure Opening Balance Equity Account in Business Settings before posting.'
                          }
                          onClick={() => void postSelectedOpening()}
                        >
                          Post
                        </button>
                        <button
                          type="button"
                          disabled={
                            !selectedOpeningKey ||
                            openingBusy ||
                            openingRows.find((r) => r.key === selectedOpeningKey)?.status === 'posted'
                          }
                          onClick={() => void removeSelectedOpening()}
                        >
                          Remove
                        </button>
                      </span>
                    </div>
                  </div>
                ) : null}
              </div>
            </fieldset>

            <fieldset className="parties-vca-panel parties-vca-account-panel">
              <legend className="parties-vca-legend-row parties-vca-account-legend">
                <span>Account Related Information</span>
                <ToggleSwitch
                  label="Discontinued"
                  tone="pay"
                  checked={form.discontinued}
                  onChange={(v) => patchForm('discontinued', v)}
                />
                <ToggleSwitch
                  label="Invoice Related / Restricted"
                  tone="rec"
                  checked={form.invoiceRestricted}
                  onChange={(v) => patchForm('invoiceRestricted', v)}
                />
              </legend>

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

              {form.type === 'ACCOUNTS' ? (
                <div className="parties-vca-account-type-row">
                  <label htmlFor="vca-account-type">Account Type</label>
                  <AnimatedSelect
                    id="vca-account-type"
                    value={form.accountTypeUlid}
                    onChange={(e) => {
                      const ulid = e.target.value
                      const match = accountTypes.find((t) => t.ulid === ulid)
                      patchForm('accountTypeUlid', ulid)
                      patchForm('accountType', match?.name ?? '')
                    }}
                  >
                    <option value="">Select Account Type…</option>
                    {accountTypes.map((type) => (
                      <option key={type.ulid} value={type.ulid}>{type.name}</option>
                    ))}
                  </AnimatedSelect>
                  <button
                    type="button"
                    className="parties-vca-account-type-btn"
                    title="Define Account Types"
                    aria-label="Define Account Types"
                    onClick={() => setCoaModalOpen(true)}
                  >
                    <Plus size={15} strokeWidth={3} />
                  </button>
                </div>
              ) : (
                <div className="parties-vca-profile-account-types">
                  {form.types.includes('vendor') ? (
                    <div className="parties-vca-account-type-row">
                      <label htmlFor="vca-vendor-account-type">Vendor A/C Type</label>
                      <AnimatedSelect
                        id="vca-vendor-account-type"
                        value={form.vendorAccountTypeUlid}
                        onChange={(e) => patchForm('vendorAccountTypeUlid', e.target.value)}
                      >
                        <option value="">Select vendor account type…</option>
                        {accountTypes.map((type) => (
                          <option key={type.ulid} value={type.ulid}>{type.name}</option>
                        ))}
                      </AnimatedSelect>
                      <button type="button" className="parties-vca-account-type-btn" title="Define Account Types" onClick={() => setCoaModalOpen(true)}>
                        <Plus size={15} strokeWidth={3} />
                      </button>
                    </div>
                  ) : null}

                  {form.types.includes('customer') ? (
                    <div className="parties-vca-account-type-row">
                      <label htmlFor="vca-customer-account-type">Customer A/C Type</label>
                      <AnimatedSelect
                        id="vca-customer-account-type"
                        value={form.customerAccountTypeUlid}
                        onChange={(e) => patchForm('customerAccountTypeUlid', e.target.value)}
                      >
                        <option value="">Select customer account type…</option>
                        {accountTypes.map((type) => (
                          <option key={type.ulid} value={type.ulid}>{type.name}</option>
                        ))}
                      </AnimatedSelect>
                      <button type="button" className="parties-vca-account-type-btn" title="Define Account Types" onClick={() => setCoaModalOpen(true)}>
                        <Plus size={15} strokeWidth={3} />
                      </button>
                    </div>
                  ) : null}

                  {form.types.length === 1 && form.types[0] === 'salesman' ? (
                    <div className="parties-vca-salesman-account-note">
                      Salesman-only parties do not require a ledger account type.
                    </div>
                  ) : null}
                </div>
              )}
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
                        {loading ? 'Loading…' : 'No party records yet'}
                      </td>
                    </tr>
                  ) : (
                    rows.map((row) => (
                      <tr
                        key={row.key}
                        className={row.key === selectedKey ? 'is-selected' : undefined}
                        onClick={() => void selectRow(row)}
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

            {/* <div className="parties-vca-tree">
              <div className="parties-vca-tree-title">Account classification</div>
              <ul className="parties-vca-tree-list">
                {coaTree.length === 0 ? (
                  <li className="parties-vca-tree-child">No classification yet — use + Account Type</li>
                ) : (
                  coaTree.map((main, index) => {
                    const open = Boolean(expandedRoots[main.ulid])
                    return (
                      <li key={main.ulid} className="parties-vca-tree-node">
                        <button type="button" className="parties-vca-tree-summary" onClick={() => toggleRoot(main.ulid)}>
                          <span className="parties-vca-tree-toggle">{open ? '−' : '+'}</span>
                          <span className="parties-vca-tree-label">{main.name}</span>
                          <span className="parties-vca-tree-id">{index + 1}</span>
                        </button>
                        {open ? (
                          <ul className="parties-vca-tree-list parties-vca-tree-nested">
                            {main.sub_heads.length === 0 ? (
                              <li className="parties-vca-tree-child">No sub heads</li>
                            ) : (
                              main.sub_heads.map((sub) => {
                                const subOpen = Boolean(expandedSubs[sub.ulid])
                                return (
                                  <li key={sub.ulid} className="parties-vca-tree-node">
                                    <button
                                      type="button"
                                      className="parties-vca-tree-summary"
                                      onClick={() => toggleSub(sub.ulid)}
                                    >
                                      <span className="parties-vca-tree-toggle">{subOpen ? '−' : '+'}</span>
                                      <span className="parties-vca-tree-label">{sub.name}</span>
                                    </button>
                                    {subOpen ? (
                                      <ul className="parties-vca-tree-list parties-vca-tree-nested">
                                        {sub.account_types.length === 0 ? (
                                          <li className="parties-vca-tree-child">No account types</li>
                                        ) : (
                                          sub.account_types.map((type) => (
                                            <li key={type.ulid} className="parties-vca-tree-child parties-vca-tree-leaf">
                                              <button
                                                type="button"
                                                className={
                                                  form.accountTypeUlid === type.ulid
                                                    ? 'parties-vca-tree-leaf-btn is-selected'
                                                    : 'parties-vca-tree-leaf-btn'
                                                }
                                                onClick={() => {
                                                  patchForm('accountType', type.name)
                                                  patchForm('accountTypeUlid', type.ulid)
                                                }}
                                              >
                                                {type.name}
                                              </button>
                                            </li>
                                          ))
                                        )}
                                      </ul>
                                    ) : null}
                                  </li>
                                )
                              })
                            )}
                          </ul>
                        ) : null}
                      </li>
                    )
                  })
                )}
              </ul>
            </div> */}
          </section>
        </div>
      ) : null}

      {viewTab === 'ledger' ? (
        <div className="parties-vca-shell">
          <div className="parties-vca-ledger-head">
            <div className="parties-vca-ledger-meta">
              <div>
                <span>Name:</span>{' '}
                <strong>{ledger?.account.name || form.name || '—'}</strong>
              </div>
              <div>
                <span>Address:</span>{' '}
                <strong>{ledger?.account.address || form.address || '—'}</strong>
              </div>
              <div>
                <span>Area:</span> <strong>{ledger?.account.area || '(NONE)'}</strong>
              </div>
            </div>
            <button
              type="button"
              className="parties-vca-shell-btn"
              disabled={!form.ulid || ensureBusy || !uiTypeToApi(form.type)}
              onClick={() => void onEnsureLeafAccount()}
              title="Repair missing leaf account link without creating duplicates"
            >
              <FolderPlus size={16} />
              Create Necessary A/Cs
            </button>
          </div>
          <div className="parties-vca-shell-grid-wrap">
            <table className="parties-vca-shell-grid parties-vca-ledger-grid">
              <thead>
                <tr>
                  <th>Trans#</th>
                  <th>Date</th>
                  <th>DOC</th>
                  <th>Remarks</th>
                  <th className="is-num">Debit</th>
                  <th className="is-num">Credit</th>
                  <th className="is-num">Balance</th>
                </tr>
              </thead>
              <tbody>
                {!form.ulid ? (
                  <tr>
                    <td colSpan={7} className="parties-vca-empty">
                      Select a party to view ledger
                    </td>
                  </tr>
                ) : ledgerLoading ? (
                  <tr>
                    <td colSpan={7} className="parties-vca-empty">
                      Loading ledger…
                    </td>
                  </tr>
                ) : !ledger || ledger.rows.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="parties-vca-empty">
                      No posted ledger entries.
                    </td>
                  </tr>
                ) : (
                  ledger.rows.map((row) => (
                    <tr key={row.line_ulid}>
                      <td>{row.trans_no}</td>
                      <td>{row.date}</td>
                      <td>{row.doc}</td>
                      <td>{row.remarks || '—'}</td>
                      <td className="is-num">{row.debit}</td>
                      <td className="is-num">{row.credit}</td>
                      <td className="is-num">{row.balance}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
          <div className="parties-vca-shell-foot">
            <span>
              {ledger
                ? `Record ${ledger.rows.length} of ${ledger.pagination.total}`
                : 'Record 0 of 0'}
              {ledger && ledger.pagination.last_page > 1
                ? ` — Page ${ledger.pagination.page}/${ledger.pagination.last_page}`
                : ''}
            </span>
            <div className="parties-vca-drcr">
              <span>Dr</span>
              <strong>{ledger?.totals.debit ?? '0.0000'}</strong>
              <span>Cr</span>
              <strong>{ledger?.totals.credit ?? '0.0000'}</strong>
              <span>Bal</span>
              <strong>{ledger?.totals.closing_balance ?? '0.0000'}</strong>
            </div>
            {ledger && ledger.pagination.last_page > 1 ? (
              <span className="parties-vca-bank-actions">
                <button
                  type="button"
                  disabled={ledgerPage <= 1 || ledgerLoading}
                  onClick={() => setLedgerPage((p) => Math.max(1, p - 1))}
                >
                  Prev
                </button>
                <button
                  type="button"
                  disabled={ledgerPage >= ledger.pagination.last_page || ledgerLoading}
                  onClick={() => setLedgerPage((p) => p + 1)}
                >
                  Next
                </button>
              </span>
            ) : null}
          </div>
        </div>
      ) : null}

      {viewTab === 'bulk' ? (
        <div className="parties-vca-shell">
          <div className="parties-vca-bulk-toolbar">
            <button
              type="button"
              className="parties-vca-shell-btn is-update"
              disabled={bulkSaving || !bulkRows.some((r) => r.dirty)}
              onClick={() => void saveBulkDirty()}
            >
              <Save size={16} />
              Update
            </button>
            <label className="parties-vca-check-inline">
              <ToggleSwitch
                label="Only Expired Lic."
                checked={bulkOnlyExpired}
                onChange={setBulkOnlyExpired}
              />
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
                {bulkRows.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="parties-vca-empty">
                      {bulkOnlyExpired ? 'No expired-license parties.' : 'No party rows.'}
                    </td>
                  </tr>
                ) : (
                  bulkRows.map((row, index) => (
                    <tr key={row.key} className={row.dirty ? 'is-selected' : undefined}>
                      <td>{index + 1}</td>
                      <td>
                        <ToggleSwitch
                          label=""
                          title="Discontinued"
                          tone="pay"
                          checked={row.discontinued}
                          onChange={(v) => patchBulk(row.key, { discontinued: v })}
                        />
                      </td>
                      <td>
                        <ToggleSwitch
                          label=""
                          title="Invoice Restricted"
                          tone="rec"
                          checked={row.restricted}
                          onChange={(v) => patchBulk(row.key, { restricted: v })}
                        />
                      </td>
                      <td>{row.code}</td>
                      <td>{row.name}</td>
                      <td>{row.typeLabel}</td>
                      <td>
                        <input
                          value={row.area}
                          onChange={(e) => patchBulk(row.key, { area: e.target.value })}
                        />
                      </td>
                      <td>
                        <AnimatedSelect
                          value={row.account_type_ulid}
                          onChange={(e) => {
                            const ulid = e.target.value
                            const match = accountTypes.find((t) => t.ulid === ulid)
                            patchBulk(row.key, {
                              account_type_ulid: ulid,
                              account_type_name: match?.name ?? '',
                            })
                          }}
                        >
                          <option value="">—</option>
                          {accountTypes.map((type) => (
                            <option key={type.ulid} value={type.ulid}>
                              {type.name}
                            </option>
                          ))}
                        </AnimatedSelect>
                      </td>
                      <td>
                        <input
                          className="is-num"
                          value={row.credit_limit_amount}
                          onChange={(e) => patchBulk(row.key, { credit_limit_amount: e.target.value })}
                        />
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
          <div className="parties-vca-bulk-actions">
            <button
              type="button"
              onClick={() => void downloadPartyExcelTemplate().catch((err) => setError(errMessage(err)))}
            >
              Export Excel Template
            </button>
            <button type="button" disabled title="Conversion rules not configured.">
              Convert Vendor
            </button>
            <button type="button" disabled title="COA import deferred to a dedicated COA template phase (VCA-7B).">
              Import COA
            </button>
            <button type="button" onClick={() => excelInputRef.current?.click()}>
              Import from Excel
            </button>
            <input
              ref={excelInputRef}
              type="file"
              accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
              hidden
              onChange={(e) => void onExcelSelected(e.target.files?.[0] ?? null)}
            />
          </div>
          {excelPreview ? (
            <div className="parties-vca-bulk-preview">
              <div>
                Preview: {excelPreview.valid.length} valid, {excelPreview.invalid.length} invalid
                {excelPreview.warnings.length ? ` — ${excelPreview.warnings.join(' ')}` : ''}
              </div>
              {excelPreview.invalid.slice(0, 5).map((row) => (
                <div key={`inv-${row.row}`}>
                  Row {row.row}: {row.errors.join('; ')}
                </div>
              ))}
              <button
                type="button"
                disabled={excelPreview.valid.length === 0 || excelPreview.invalid.length > 0}
                onClick={() => void confirmExcelImport()}
              >
                Confirm Import
              </button>
            </div>
          ) : null}
        </div>
      ) : null}

      {viewTab === 'coa' ? (
        <div className="parties-vca-shell">
          <div className="parties-vca-coa-toolbar">
            <button type="button" className="parties-vca-shell-btn" disabled title="Export awaits COA API">
              <FileSpreadsheet size={16} />
              Export to XLSX
            </button>
            <button type="button" className="parties-vca-shell-btn" onClick={() => void loadCoaChart()} disabled={coaChartLoading}>
              <RefreshCw size={16} />
              Refresh
            </button>
            <ToggleSwitch
              label="Show Grouped Columns Also"
              checked={coaShowGrouped}
              onChange={setCoaShowGrouped}
            />
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
                {coaChartLoading ? (
                  <tr>
                    <td colSpan={4} className="parties-vca-empty">
                      Loading chart…
                    </td>
                  </tr>
                ) : coaShowGrouped ? (
                  coaGroupedRows.length === 0 ? (
                    <tr>
                      <td colSpan={4} className="parties-vca-empty">
                        No leaf accounts yet — save vendors, customers, or manual accounts
                      </td>
                    </tr>
                  ) : (
                    coaGroupedRows.flatMap((main) => {
                      const mainOpen = expandedCoaMain[main.ulid] !== false
                      const out: ReactNode[] = [
                        <tr key={`m-${main.ulid}`} className="parties-vca-coa-group parties-vca-coa-level-0">
                          <td colSpan={4}>
                            <button type="button" className="parties-vca-coa-toggle" onClick={() => toggleCoaMain(main.ulid)}>
                              {mainOpen ? '−' : '+'}
                            </button>
                            {main.label}
                          </td>
                        </tr>,
                      ]
                      if (!mainOpen) return out
                      for (const head of main.heads) {
                        const headOpen = expandedCoaHead[head.ulid] !== false
                        out.push(
                          <tr key={`h-${head.ulid}`} className="parties-vca-coa-level-1">
                            <td colSpan={4}>
                              <button type="button" className="parties-vca-coa-toggle" onClick={() => toggleCoaHead(head.ulid)}>
                                {headOpen ? '−' : '+'}
                              </button>
                              {head.label}
                            </td>
                          </tr>,
                        )
                        if (!headOpen) continue
                        for (const sub of head.sub_heads) {
                          const subOpen = expandedCoaSub[sub.ulid] !== false
                          out.push(
                            <tr key={`s-${sub.ulid}`} className="parties-vca-coa-level-2">
                              <td colSpan={4}>
                                <button type="button" className="parties-vca-coa-toggle" onClick={() => toggleCoaSub(sub.ulid)}>
                                  {subOpen ? '−' : '+'}
                                </button>
                                {sub.label}
                              </td>
                            </tr>,
                          )
                          if (!subOpen) continue
                          for (const leaf of sub.accounts) {
                            out.push(
                              <tr key={`a-${leaf.ulid}`} className="parties-vca-coa-level-3">
                                <td colSpan={4}>{leaf.label}</td>
                              </tr>,
                            )
                          }
                        }
                      }
                      return out
                    })
                  )
                ) : coaFlatRows.length === 0 ? (
                  <tr>
                    <td colSpan={4} className="parties-vca-empty">
                      No leaf accounts yet — save vendors, customers, or manual accounts
                    </td>
                  </tr>
                ) : (
                  coaFlatRows.map((row) => (
                    <tr key={row.account_ulid}>
                      <td>{row.main_head_label}</td>
                      <td>{row.account_label}</td>
                      <td>{row.head_label}</td>
                      <td>{row.sub_head_label}</td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
          <div className="parties-vca-shell-foot">
            <span>
              {coaShowGrouped
                ? `Hierarchy ${coaGroupedRows.length} main head(s)`
                : `Record ${coaFlatRows.length} of ${coaFlatRows.length}`}
            </span>
          </div>
        </div>
      ) : null}

      <CoaHierarchyModals
        open={coaModalOpen}
        selectedAccountTypeUlid={form.accountTypeUlid || null}
        onClose={() => setCoaModalOpen(false)}
        onAccountTypeSaved={(type) => {
          patchForm('accountType', type.name)
          patchForm('accountTypeUlid', type.ulid)
          void loadCoaTree()
        }}
        onHierarchyChanged={() => {
          void loadCoaTree()
        }}
      />
    </div>
  )
}
