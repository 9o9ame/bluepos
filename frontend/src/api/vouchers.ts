import { apiFetch } from './client'

export type VoucherType = 'payment' | 'receiving' | 'journal' | 'opening'
export type VoucherStatus = 'draft' | 'posted'

export type VoucherAccount = {
  ulid: string
  code: string
  name: string
  address?: string | null
  account_type?: string | null
  is_cash?: boolean
  is_bank?: boolean
  is_payable?: boolean
  normal_balance?: 'debit' | 'credit'
  party_type?: 'vendor' | 'customer' | 'account'
  balance: string
}

export type VoucherLine = {
  ulid: string
  is_header: boolean
  account: VoucherAccount | null
  narration: string | null
  debit: string
  credit: string
  party_type: 'vendor' | 'customer' | 'account'
}

export type Voucher = {
  ulid: string
  voucher_number: string
  book_number: string | null
  type: VoucherType
  entry_date: string
  description: string | null
  status: VoucherStatus
  posted_at: string | null
  total_debit: string
  total_credit: string
  header_account: VoucherAccount | null
  lines: VoucherLine[]
}

export type VoucherInputLine = {
  account_ulid: string
  narration?: string | null
  amount?: string
  debit?: string
  credit?: string
}

export type VoucherPayload = {
  type?: VoucherType
  entry_date: string
  book_number?: string | null
  description?: string | null
  header_account_ulid?: string | null
  lines: VoucherInputLine[]
}

export type VoucherSummaryRow = {
  date: string
  debit: string
  credit: string
}

export function fetchVoucherAccounts(params?: { cash_only?: boolean; q?: string; as_of?: string; before_voucher_ulid?: string }) {
  const search = new URLSearchParams()
  if (params?.cash_only) search.set('cash_only', '1')
  if (params?.q) search.set('q', params.q)
  if (params?.as_of) search.set('as_of', params.as_of)
  if (params?.before_voucher_ulid) search.set('before_voucher_ulid', params.before_voucher_ulid)
  const suffix = search.toString() ? `?${search.toString()}` : ''
  return apiFetch<VoucherAccount[]>(`/api/vouchers/accounts${suffix}`)
}

export function fetchVouchers(params?: {
  q?: string
  type?: VoucherType | ''
  status?: VoucherStatus | ''
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}) {
  const search = new URLSearchParams()
  if (params?.q) search.set('q', params.q)
  if (params?.type) search.set('type', params.type)
  if (params?.status) search.set('status', params.status)
  if (params?.date_from) search.set('date_from', params.date_from)
  if (params?.date_to) search.set('date_to', params.date_to)
  if (params?.page) search.set('page', String(params.page))
  search.set('per_page', String(params?.per_page ?? 50))
  const suffix = search.toString() ? `?${search.toString()}` : ''

  return apiFetch<{
    data: Voucher[]
    meta: { current_page: number; per_page: number; total: number; last_page: number }
  }>(`/api/vouchers${suffix}`)
}

export function fetchVoucher(ulid: string) {
  return apiFetch<Voucher>(`/api/vouchers/${ulid}`)
}

export function createVoucher(payload: VoucherPayload & { type: VoucherType }, idempotencyKey: string) {
  return apiFetch<Voucher>('/api/vouchers', {
    method: 'POST',
    headers: { 'Idempotency-Key': idempotencyKey },
    body: JSON.stringify(payload),
  })
}

export function updateVoucher(ulid: string, payload: VoucherPayload) {
  return apiFetch<Voucher>(`/api/vouchers/${ulid}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

export function deleteVoucher(ulid: string) {
  return apiFetch<{ ok: boolean; deleted: boolean }>(`/api/vouchers/${ulid}`, {
    method: 'DELETE',
  })
}

export function postVoucher(ulid: string) {
  return apiFetch<Voucher>(`/api/vouchers/${ulid}/post`, { method: 'POST' })
}

export function fetchVoucherSummary(params: {
  date_from?: string
  date_to?: string
  type?: VoucherType | ''
}) {
  const search = new URLSearchParams()
  if (params.date_from) search.set('date_from', params.date_from)
  if (params.date_to) search.set('date_to', params.date_to)
  if (params.type) search.set('type', params.type)
  const suffix = search.toString() ? `?${search.toString()}` : ''
  return apiFetch<{ data: VoucherSummaryRow[] }>(`/api/vouchers/summary${suffix}`)
}
