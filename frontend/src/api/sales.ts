import { apiFetch } from './client'
import type { Sale, SaleHold, SaleHoldPayload, SalePayment, SalePaymentMethod, SalePayload, SalesmanOption } from '../types/sales'

/**
 * Post a sale. The server recalculates every money figure and revalidates
 * schemes, packaging limits and stock — nothing sent here is trusted.
 *
 * `idempotencyKey` must be stable for one attempt: retrying the same save
 * replays the original sale instead of creating a duplicate.
 */
export function createSale(payload: SalePayload, idempotencyKey: string) {
  return apiFetch<Sale>('/api/sales', {
    method: 'POST',
    body: JSON.stringify(payload),
    headers: { 'Idempotency-Key': idempotencyKey },
  })
}

export function fetchSales(
  params: {
    page?: number
    per_page?: number
    date_from?: string
    date_to?: string
    q?: string
    salesman_ulid?: string
    status?: 'posted' | 'void'
    due_only?: boolean
  } = {},
) {
  const search = new URLSearchParams()
  if (params.page) search.set('page', String(params.page))
  if (params.per_page) search.set('per_page', String(params.per_page))
  if (params.date_from) search.set('date_from', params.date_from)
  if (params.date_to) search.set('date_to', params.date_to)
  if (params.q) search.set('q', params.q)
  if (params.salesman_ulid) search.set('salesman_ulid', params.salesman_ulid)
  if (params.status) search.set('status', params.status)
  if (params.due_only) search.set('due_only', '1')
  const query = search.toString()

  return apiFetch<{
    data: Sale[]
    meta: {
      current_page: number
      per_page: number
      total: number
      last_page: number
    }
  }>(`/api/sales${query ? `?${query}` : ''}`, { busy: 'none' })
}

export function fetchSalesmen() {
  return apiFetch<SalesmanOption[]>('/api/sales/salesmen', { busy: 'none' })
}

/**
 * Collect a payment against a saved sale. Partial amounts are allowed; the
 * server recomputes what is due and rejects anything over it.
 */
export function createSalePayment(
  saleUlid: string,
  payload: { amount: string; method: SalePaymentMethod; reference?: string | null },
  idempotencyKey: string,
) {
  return apiFetch<SalePayment>(`/api/sales/${saleUlid}/payments`, {
    method: 'POST',
    body: JSON.stringify(payload),
    headers: { 'Idempotency-Key': idempotencyKey },
  })
}

export function fetchSale(saleUlid: string) {
  return apiFetch<Sale>(`/api/sales/${saleUlid}`)
}


export function fetchSaleHolds() {
  return apiFetch<{ data: SaleHold[]; count: number }>('/api/sales/holds', {
    busy: 'none',
  })
}

export function createSaleHold(
  payload: SaleHoldPayload,
  idempotencyKey: string,
) {
  return apiFetch<SaleHold>('/api/sales/holds', {
    method: 'POST',
    body: JSON.stringify(payload),
    headers: { 'Idempotency-Key': idempotencyKey },
  })
}

export function fetchSaleHold(holdUlid: string) {
  return apiFetch<SaleHold>(`/api/sales/holds/${holdUlid}`, {
    busy: 'none',
  })
}

export function deleteSaleHold(holdUlid: string) {
  return apiFetch<{ ok: boolean }>(`/api/sales/holds/${holdUlid}`, {
    method: 'DELETE',
  })
}
