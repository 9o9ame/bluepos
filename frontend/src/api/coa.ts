import { apiFetch } from './client'

export type CoaMainHead = {
  ulid: string
  name: string
  sort_order: number
  is_active: boolean
}

export type CoaSubHead = {
  ulid: string
  name: string
  sort_order: number
  is_active: boolean
  main_head_ulid?: string
  main_head?: { ulid: string; name: string }
}

export type CoaAccountType = {
  ulid: string
  name: string
  is_cash: boolean
  is_bank: boolean
  is_receivable: boolean
  is_payable: boolean
  pnl_grouping_label: string | null
  hint: string | null
  sort_order: number
  is_active: boolean
  sub_head_ulid?: string
  sub_head?: { ulid: string; name: string; main_head_ulid?: string | null }
}

export type CoaTreeNode = {
  ulid: string
  name: string
  sort_order: number
  is_active: boolean
  sub_heads: Array<{
    ulid: string
    name: string
    sort_order: number
    is_active: boolean
    account_types: Array<{
      ulid: string
      name: string
      sort_order: number
      is_active: boolean
      is_cash: boolean
      is_bank: boolean
      is_receivable: boolean
      is_payable: boolean
    }>
  }>
}

export type MainHeadPayload = {
  name: string
  sort_order?: number
  is_active?: boolean
}

export type SubHeadPayload = {
  main_head_ulid: string
  name: string
  sort_order?: number
  is_active?: boolean
}

export type AccountTypePayload = {
  sub_head_ulid: string
  name: string
  is_cash?: boolean
  is_bank?: boolean
  is_receivable?: boolean
  is_payable?: boolean
  pnl_grouping_label?: string | null
  hint?: string | null
  sort_order?: number
  is_active?: boolean
}

export type CoaFlatRow = {
  account_ulid: string
  main_head_label: string
  account_label: string
  head_label: string
  sub_head_label: string
  leaf_source: 'manual' | 'supplier' | 'customer'
}

export type CoaGroupedNode = {
  ulid: string
  label: string
  heads: Array<{
    ulid: string
    label: string
    sub_heads: Array<{
      ulid: string
      label: string
      accounts: Array<{ ulid: string; label: string; leaf_source: string }>
    }>
  }>
}

export function fetchCoaTree() {
  return apiFetch<{ data: CoaTreeNode[] }>('/api/coa/tree').then((r) => r.data)
}

export function fetchCoaChart(grouped: boolean) {
  const qs = grouped ? '?grouped=1' : ''
  return apiFetch<{ mode: 'flat'; flat: CoaFlatRow[] } | { mode: 'grouped'; grouped: CoaGroupedNode[] }>(
    `/api/coa/chart${qs}`,
  )
}

export function fetchMainHeads() {
  return apiFetch<CoaMainHead[]>('/api/coa/main-heads')
}

export function createMainHead(payload: MainHeadPayload) {
  return apiFetch<CoaMainHead>('/api/coa/main-heads', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateMainHead(ulid: string, payload: Partial<MainHeadPayload>) {
  return apiFetch<CoaMainHead>(`/api/coa/main-heads/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function fetchSubHeads() {
  return apiFetch<CoaSubHead[]>('/api/coa/sub-heads')
}

export function createSubHead(payload: SubHeadPayload) {
  return apiFetch<CoaSubHead>('/api/coa/sub-heads', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateSubHead(ulid: string, payload: Partial<SubHeadPayload>) {
  return apiFetch<CoaSubHead>(`/api/coa/sub-heads/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function fetchAccountTypes() {
  return apiFetch<CoaAccountType[]>('/api/coa/account-types')
}

export function createAccountType(payload: AccountTypePayload) {
  return apiFetch<CoaAccountType>('/api/coa/account-types', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateAccountType(ulid: string, payload: Partial<AccountTypePayload>) {
  return apiFetch<CoaAccountType>(`/api/coa/account-types/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export type CoaLeafAccount = {
  ulid: string
  code: string
  name: string
}

export function fetchLeafAccounts() {
  return apiFetch<{ data: CoaLeafAccount[] }>('/api/coa/leaf-accounts').then((r) => r.data)
}
