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


export type CashCreditMetric = {
  supported: boolean
  cash: string | null
  credit: string | null
}

export type CashCreditPositionRow = {
  account: {
    ulid: string
    code: string
    name: string
    account_type: string | null
  }
  opening: string
  debit: string
  credit: string
  closing: string
}

export type CashCreditFilterKey = 'document' | 'entry_date' | 'user_name' | 'sales_person'

export type CashCreditFilterOption = {
  value: string
  label: string
}

export type CashCreditSummary = {
  date: string
  filter_by: CashCreditFilterKey | null
  only_for: string | null
  filter_options: Record<CashCreditFilterKey, CashCreditFilterOption[]>
  metrics: {
    sales: CashCreditMetric
    retail_sale: CashCreditMetric
    sale_return: CashCreditMetric
    purchase: CashCreditMetric
    purchase_return: CashCreditMetric
    recovery: CashCreditMetric
    payment: CashCreditMetric
    receivings: CashCreditMetric
  }
  positions: CashCreditPositionRow[]
  totals: {
    opening: string
    debit: string
    credit: string
    closing: string
  }
  unsupported_filters: Record<string, string>
  unsupported_reasons: Record<string, string>
}

export function fetchCashCreditSummary(
  date: string,
  params: { filter_by?: CashCreditFilterKey; only_for?: string } = {},
) {
  const search = new URLSearchParams({ date })
  if (params.filter_by) search.set('filter_by', params.filter_by)
  if (params.only_for) search.set('only_for', params.only_for)

  return apiFetch<CashCreditSummary>(`/api/cash-position/summary?${search.toString()}`)
}


export type CashDashboardSeries = {
  account: {
    ulid: string
    code: string
    name: string
  }
  values: string[]
}

export type CashDashboardAccountRow = {
  account: {
    ulid: string
    code: string
    name: string
  }
  amount: string
}

export type CashDashboard = {
  date: string
  days: number
  chart: {
    dates: string[]
    series: CashDashboardSeries[]
  }
  debit_accounts: CashDashboardAccountRow[]
  credit_accounts: CashDashboardAccountRow[]
  totals: {
    debit: string
    credit: string
  }
}

export function fetchCashDashboard(date: string, days = 7) {
  const search = new URLSearchParams({ date, days: String(days) })
  return apiFetch<CashDashboard>(`/api/cash-position/dashboard?${search.toString()}`)
}
