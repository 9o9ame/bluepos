import { apiFetch, ApiClientError, ensureCsrfCookie } from './client'
import { readCookie } from '../utils/cookies'

export type PartyTypeApi = 'vendor' | 'customer' | 'account'

export type PartyAccountType = {
  ulid: string
  name: string
}

export type PartyBankAccount = {
  ulid: string
  bank_name: string
  branch_name: string | null
  branch_code: string | null
  city: string | null
  account_number: string | null
  sort_order: number
}

export type Party = {
  ulid: string
  party_type: PartyTypeApi
  code: string
  name: string
  deals_in: string | null
  contact_person: string | null
  mobile: string | null
  mobile_secondary: string | null
  phone: string | null
  phone_secondary: string | null
  email: string | null
  address: string | null
  billing_address: string | null
  is_active: boolean
  area: string | null
  invoice_restricted: boolean
  credit_limit_amount: string
  credit_limit_days: number
  account_type_ulid: string | null
  account_type: PartyAccountType | null
  license_number: string | null
  license_issued_on: string | null
  license_type: string | null
  license_expires_on: string | null
  ignore_warranty: boolean
  print_license: boolean
  rf_id: string | null
  store_allowed: string | null
}

export type PartyListFilter = 'all' | 'vendor' | 'customer' | 'account' | 'salesman'

export type PartyPayload = {
  party_type: PartyTypeApi
  code: string
  name: string
  account_type_ulid: string
  deals_in?: string | null
  contact_person?: string | null
  mobile?: string | null
  mobile_secondary?: string | null
  phone?: string | null
  phone_secondary?: string | null
  email?: string | null
  address?: string | null
  billing_address?: string | null
  area?: string | null
  invoice_restricted?: boolean
  credit_limit_amount?: string
  credit_limit_days?: number
  is_active?: boolean
  license_number?: string | null
  license_issued_on?: string | null
  license_type?: string | null
  license_expires_on?: string | null
  ignore_warranty?: boolean
  print_license?: boolean
  rf_id?: string | null
  store_allowed?: string | null
}

export type PartyBankPayload = {
  bank_name: string
  branch_name?: string | null
  branch_code?: string | null
  city?: string | null
  account_number?: string | null
  sort_order?: number
}

export function fetchParties(type: PartyListFilter = 'all', options?: { expiredLicense?: boolean }) {
  const params = new URLSearchParams()
  params.set('type', type && type !== 'all' ? type : 'all')
  if (options?.expiredLicense) params.set('expired_license', '1')
  return apiFetch<{ data: Party[] }>(`/api/parties?${params.toString()}`).then((res) => res.data)
}

export function fetchParty(ulid: string, type: PartyTypeApi) {
  return apiFetch<Party>(`/api/parties/${ulid}?type=${encodeURIComponent(type)}`)
}

export function createParty(payload: PartyPayload) {
  return apiFetch<Party>('/api/parties', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateParty(ulid: string, payload: Partial<PartyPayload> & { party_type: PartyTypeApi }) {
  return apiFetch<Party>(`/api/parties/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deactivateParty(ulid: string, type: PartyTypeApi) {
  return apiFetch<{ ok: boolean; archived: boolean }>(
    `/api/parties/${ulid}?type=${encodeURIComponent(type)}`,
    { method: 'DELETE' },
  )
}

export function fetchPartyBankAccounts(partyUlid: string, type: PartyTypeApi) {
  return apiFetch<PartyBankAccount[]>(
    `/api/parties/${partyUlid}/bank-accounts?type=${encodeURIComponent(type)}`,
  )
}

export function createPartyBankAccount(partyUlid: string, type: PartyTypeApi, payload: PartyBankPayload) {
  return apiFetch<PartyBankAccount>(
    `/api/parties/${partyUlid}/bank-accounts?type=${encodeURIComponent(type)}`,
    { method: 'POST', body: JSON.stringify(payload) },
  )
}

export function updatePartyBankAccount(
  partyUlid: string,
  type: PartyTypeApi,
  bankUlid: string,
  payload: Partial<PartyBankPayload>,
) {
  return apiFetch<PartyBankAccount>(
    `/api/parties/${partyUlid}/bank-accounts/${bankUlid}?type=${encodeURIComponent(type)}`,
    { method: 'PATCH', body: JSON.stringify(payload) },
  )
}

export function deletePartyBankAccount(partyUlid: string, type: PartyTypeApi, bankUlid: string) {
  return apiFetch<{ ok: boolean; deleted: boolean }>(
    `/api/parties/${partyUlid}/bank-accounts/${bankUlid}?type=${encodeURIComponent(type)}`,
    { method: 'DELETE' },
  )
}

export type PartyOpeningBalance = {
  ulid: string
  status: 'draft' | 'posted'
  opening_date: string
  narration: string | null
  debit: string
  credit: string
  balance: string
  closing: string
  leaf_account_ulid: string | null
  sales_person: string | null
  posted_at: string | null
}

export type PartyOpeningPayload = {
  opening_date: string
  narration?: string | null
  debit: string
  credit: string
}

export function fetchPartyOpeningBalances(partyUlid: string, type: PartyTypeApi) {
  return apiFetch<{ data: PartyOpeningBalance[]; leaf_account_ulid: string }>(
    `/api/parties/${partyUlid}/opening-balances?type=${encodeURIComponent(type)}`,
  ).then((res) => res.data)
}

export function createPartyOpeningBalance(partyUlid: string, type: PartyTypeApi, payload: PartyOpeningPayload) {
  return apiFetch<PartyOpeningBalance>(
    `/api/parties/${partyUlid}/opening-balances?type=${encodeURIComponent(type)}`,
    { method: 'POST', body: JSON.stringify(payload) },
  )
}

export function updatePartyOpeningBalance(
  partyUlid: string,
  type: PartyTypeApi,
  openingUlid: string,
  payload: Partial<PartyOpeningPayload>,
) {
  return apiFetch<PartyOpeningBalance>(
    `/api/parties/${partyUlid}/opening-balances/${openingUlid}?type=${encodeURIComponent(type)}`,
    { method: 'PATCH', body: JSON.stringify(payload) },
  )
}

export function deletePartyOpeningBalance(partyUlid: string, type: PartyTypeApi, openingUlid: string) {
  return apiFetch<{ ok: boolean; deleted: boolean }>(
    `/api/parties/${partyUlid}/opening-balances/${openingUlid}?type=${encodeURIComponent(type)}`,
    { method: 'DELETE' },
  )
}

export function postPartyOpeningBalance(partyUlid: string, type: PartyTypeApi, openingUlid: string) {
  return apiFetch<PartyOpeningBalance>(
    `/api/parties/${partyUlid}/opening-balances/${openingUlid}/post?type=${encodeURIComponent(type)}`,
    { method: 'POST', body: JSON.stringify({}) },
  )
}

export type PartyLedgerRow = {
  line_ulid: string
  trans_no: string
  date: string
  doc: string
  remarks: string | null
  debit: string
  credit: string
  balance: string
}

export type PartyLedger = {
  account: {
    ulid: string
    code: string
    name: string
    address: string | null
    area: string | null
  }
  rows: PartyLedgerRow[]
  totals: {
    debit: string
    credit: string
    closing_balance: string
  }
  pagination: {
    page: number
    per_page: number
    total: number
    last_page: number
  }
  carry_forward: string
}

export function fetchPartyLedger(partyUlid: string, type: PartyTypeApi, page = 1) {
  const qs = new URLSearchParams({
    type,
    page: String(page),
    per_page: '100',
  })
  return apiFetch<PartyLedger>(`/api/parties/${partyUlid}/ledger?${qs.toString()}`)
}

export function ensurePartyLeafAccount(partyUlid: string, type: PartyTypeApi) {
  return apiFetch<{ status: string; leaf_account_ulid: string; message: string }>(
    `/api/parties/${partyUlid}/ensure-leaf-account?type=${encodeURIComponent(type)}`,
    { method: 'POST', body: JSON.stringify({}) },
  )
}

export type PartyBulkRowPatch = {
  ulid: string
  party_type: PartyTypeApi
  is_active?: boolean
  invoice_restricted?: boolean
  area?: string | null
  account_type_ulid?: string | null
  credit_limit_amount?: string
  credit_limit_days?: number
}

export function bulkUpdateParties(rows: PartyBulkRowPatch[]) {
  return apiFetch<{ updated: number; data: Party[] }>('/api/parties/bulk', {
    method: 'PATCH',
    body: JSON.stringify({ rows }),
  })
}

export async function downloadPartyExcelTemplate() {
  await ensureCsrfCookie()
  const headers = new Headers({
    Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'X-Requested-With': 'XMLHttpRequest',
  })
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers.set('X-XSRF-TOKEN', xsrf)
  const response = await fetch(`${import.meta.env.VITE_API_URL ?? ''}/api/parties/excel-template`, {
    method: 'GET',
    credentials: 'include',
    headers,
  })
  if (!response.ok) {
    throw new ApiClientError('DOWNLOAD_FAILED', 'Unable to download Excel template.', response.status)
  }
  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'bluepos-parties-template.xlsx'
  a.click()
  URL.revokeObjectURL(url)
}

export type PartyExcelPreview = {
  valid: Array<{ row: number; action: string; errors: string[]; data: Record<string, unknown> }>
  invalid: Array<{ row: number; action: string; errors: string[]; data: Record<string, unknown> }>
  warnings: string[]
}

async function postPartySpreadsheet(path: string, file: File, extra?: Record<string, string>) {
  await ensureCsrfCookie()
  const form = new FormData()
  form.append('file', file)
  if (extra) {
    for (const [k, v] of Object.entries(extra)) form.append(k, v)
  }
  const headers = new Headers({
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  })
  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) headers.set('X-XSRF-TOKEN', xsrf)
  const response = await fetch(`${import.meta.env.VITE_API_URL ?? ''}${path}`, {
    method: 'POST',
    credentials: 'include',
    headers,
    body: form,
  })
  const payload = (await response.json().catch(() => ({}))) as {
    error?: { key?: string; message?: string; fields?: Record<string, string[]> }
  } & Record<string, unknown>
  if (!response.ok) {
    throw new ApiClientError(
      payload.error?.key ?? 'REQUEST_FAILED',
      payload.error?.message ?? 'Request failed',
      response.status,
      payload.error?.fields,
      payload,
    )
  }
  return payload
}

export function previewPartyExcel(file: File) {
  return postPartySpreadsheet('/api/parties/excel/preview', file) as Promise<PartyExcelPreview>
}

export function importPartyExcel(file: File) {
  return postPartySpreadsheet('/api/parties/excel/import', file, { confirm: '1' }) as Promise<{
    created: number
    updated: number
    data: unknown[]
  }>
}
