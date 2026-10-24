import { apiFetch } from './client'

export type CashBookAccount = { ulid: string; code: string; name: string; is_cash: boolean; is_bank: boolean }
export type CashBookEntry = {
  line_ulid: string; trans_no: string; date: string | null; doc: string
  description: string; debit: string; credit: string; balance: string
  account_ulid: string; account_name: string
}
export type CashBookResult = {
  start_date: string; end_date: string
  accounts: Pick<CashBookAccount, 'ulid' | 'code' | 'name'>[]
  group_by: 'none' | 'date' | 'document'
  rows: CashBookEntry[]
  totals: { opening: string; received: string; paid: string; closing: string }
}
export const fetchCashBookAccounts = () =>
  apiFetch<{ accounts: CashBookAccount[] }>('/api/reports/accounting/cash-book/accounts', { busy: 'fetch' })

export function fetchCashBookReport(params: {
  start_date: string; end_date: string; account_ulids: string[]
  include_bank: boolean; summary_only: boolean; group_by: 'none' | 'date' | 'document'
}) {
  const query = new URLSearchParams({
    start_date: params.start_date, end_date: params.end_date,
    include_bank: params.include_bank ? '1' : '0',
    summary_only: params.summary_only ? '1' : '0', group_by: params.group_by,
  })
  params.account_ulids.forEach((ulid, index) => query.set(`account_ulids[${index}]`, ulid))
  return apiFetch<CashBookResult>(`/api/reports/accounting/cash-book?${query.toString()}`, { busy: 'fetch' })
}
