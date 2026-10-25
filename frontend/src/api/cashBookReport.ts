import { apiFetch } from './client'

export type CashBookAccount = { ulid: string; code: string; name: string; is_cash: boolean; is_bank: boolean }
export type CashBookEntry = {
  line_ulid: string; trans_no: string; date: string | null; doc: string
  description: string; debit: string; credit: string; balance: string
  account_ulid: string; account_name: string; creator_name: string; area_name: string; salesperson_name: string
}
export type CashBookResult = {
  start_date: string; end_date: string
  accounts: Pick<CashBookAccount, 'ulid' | 'code' | 'name'>[]
  group_by: 'none' | 'date' | 'document' | 'user' | 'area' | 'salesperson'
  rows: CashBookEntry[]
  supplementary: { entry_ulid: string; date: string | null; trans_no: string; doc: string; description: string; type: 'non_cash' }[]
  totals: { opening: string; received: string; paid: string; closing: string }
}
export const fetchCashBookAccounts = () =>
  apiFetch<{ accounts: CashBookAccount[] }>('/api/reports/accounting/cash-book/accounts', { busy: 'fetch' })

export function fetchCashBookReport(params: {
  start_date: string; end_date: string; account_ulids: string[]
  include_bank: boolean; show_purchase: boolean; non_cash_also: boolean; summary_only: boolean; group_by: 'none' | 'date' | 'document' | 'user' | 'area' | 'salesperson'
}) {
  return apiFetch<CashBookResult>('/api/reports/accounting/cash-book/preview', {
    method: 'POST',
    busy: 'fetch',
    body: JSON.stringify(params),
  })
}
