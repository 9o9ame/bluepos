import { apiFetch } from './client'

export type CashPositionAccount = {
  ulid: string
  code: string
  name: string
  account_type: string | null
  is_cash: boolean
  is_bank: boolean
}

export type CashBookRow = {
  line_ulid: string
  trans_no: string
  date: string | null
  doc: string
  description: string
  debit: string
  credit: string
  balance: string
}

export type CashBookReport = {
  account: CashPositionAccount
  date_from: string | null
  date_to: string | null
  ignore_date: boolean
  rows: CashBookRow[]
  summary: {
    opening: string
    received: string
    paid: string
    balance: string
    closing: string
  }
}

export function fetchCashPositionAccounts() {
  return apiFetch<CashPositionAccount[]>('/api/cash-position/accounts')
}

export function fetchCashBook(
  accountUlid: string,
  params: { date_from?: string; date_to?: string; ignore_date?: boolean },
) {
  const search = new URLSearchParams()
  if (params.date_from) search.set('date_from', params.date_from)
  if (params.date_to) search.set('date_to', params.date_to)
  if (params.ignore_date) search.set('ignore_date', '1')
  const suffix = search.toString() ? `?${search.toString()}` : ''

  return apiFetch<CashBookReport>(
    `/api/cash-position/${encodeURIComponent(accountUlid)}/book${suffix}`,
  )
}
