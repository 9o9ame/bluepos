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

export function fetchCoaTree() {
  return apiFetch<{ data: CoaTreeNode[] }>('/api/coa/tree').then((r) => r.data)
}

export function fetchMainHeads() {
  return apiFetch<{ data: CoaMainHead[] }>('/api/coa/main-heads').then((r) => r.data)
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
  return apiFetch<{ data: CoaSubHead[] }>('/api/coa/sub-heads').then((r) => r.data)
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
  return apiFetch<{ data: CoaAccountType[] }>('/api/coa/account-types').then((r) => r.data)
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
