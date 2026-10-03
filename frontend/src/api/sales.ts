import { apiFetch } from './client'
import type { Sale, SalePayment, SalePaymentMethod, SalePayload } from '../types/sales'

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

export function fetchSales(params: { page?: number; per_page?: number } = {}) {
  const search = new URLSearchParams()
  if (params.page) search.set('page', String(params.page))
  if (params.per_page) search.set('per_page', String(params.per_page))
  const query = search.toString()

  return apiFetch<{
    data: Sale[]
    meta: {
      current_page: number
      per_page: number
      total: number
      last_page: number
    }
  }>(`/api/sales${query ? `?${query}` : ''}`)
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
