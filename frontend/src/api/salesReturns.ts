import { apiFetch } from './client'
import type { Paginated } from '../types/catalog'
import type {
  ReturnableSaleResponse,
  SaleReturn,
  SaleReturnLine,
  SaleReturnProductWiseRow,
} from '../types/salesReturns'

export function fetchSaleReturns(params?: {
  q?: string
  status?: string
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}) {
  const search = new URLSearchParams()
  if (params?.q) search.set('q', params.q)
  if (params?.status) search.set('status', params.status)
  if (params?.date_from) search.set('date_from', params.date_from)
  if (params?.date_to) search.set('date_to', params.date_to)
  if (params?.page) search.set('page', String(params.page))
  search.set('per_page', String(params?.per_page ?? 40))

  const suffix = search.toString() ? `?${search.toString()}` : ''
  return apiFetch<Paginated<SaleReturn>>(`/api/sales-returns${suffix}`, {
    busy: 'none',
  })
}

export function fetchSaleReturn(returnUlid: string) {
  return apiFetch<SaleReturn>(`/api/sales-returns/${returnUlid}`)
}

export function createSaleReturn(
  payload: {
    sale_ulid: string
    return_date?: string
    reason?: string | null
    notes?: string | null
  },
  idempotencyKey: string,
) {
  return apiFetch<SaleReturn>('/api/sales-returns', {
    method: 'POST',
    body: JSON.stringify(payload),
    headers: { 'Idempotency-Key': idempotencyKey },
  })
}

export function updateSaleReturn(
  returnUlid: string,
  payload: {
    return_date?: string
    reason?: string | null
    notes?: string | null
  },
) {
  return apiFetch<SaleReturn>(`/api/sales-returns/${returnUlid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function fetchReturnableSaleLines(saleUlid: string) {
  return apiFetch<ReturnableSaleResponse>(
    `/api/sales/${saleUlid}/returnable-lines`,
    { busy: 'none' },
  )
}

export function createSaleReturnLine(
  returnUlid: string,
  payload: {
    sale_item_ulid: string
    quantity: string
    reason?: string | null
    notes?: string | null
  },
) {
  return apiFetch<SaleReturnLine>(`/api/sales-returns/${returnUlid}/lines`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateSaleReturnLine(
  returnUlid: string,
  lineUlid: string,
  payload: {
    sale_item_ulid?: string
    quantity?: string
    reason?: string | null
    notes?: string | null
  },
) {
  return apiFetch<SaleReturnLine>(
    `/api/sales-returns/${returnUlid}/lines/${lineUlid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deleteSaleReturnLine(returnUlid: string, lineUlid: string) {
  return apiFetch<{ ok: boolean }>(
    `/api/sales-returns/${returnUlid}/lines/${lineUlid}`,
    { method: 'DELETE' },
  )
}

export function postSaleReturn(returnUlid: string) {
  return apiFetch<SaleReturn>(`/api/sales-returns/${returnUlid}/post`, {
    method: 'POST',
  })
}

export function fetchSaleReturnProductWise(params?: {
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}) {
  const search = new URLSearchParams()
  if (params?.date_from) search.set('date_from', params.date_from)
  if (params?.date_to) search.set('date_to', params.date_to)
  if (params?.page) search.set('page', String(params.page))
  search.set('per_page', String(params?.per_page ?? 50))
  const suffix = search.toString() ? `?${search.toString()}` : ''

  return apiFetch<Paginated<SaleReturnProductWiseRow>>(
    `/api/sales-returns/product-wise${suffix}`,
    { busy: 'none' },
  )
}
