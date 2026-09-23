import { apiFetch } from './client'
import type { Paginated } from '../types/catalog'
import type {
  PurchaseReturn,
  PurchaseReturnLine,
  ReturnablePurchaseLine,
} from '../types/purchaseReturns'
import type { PurchaseInvoice } from '../types/purchases'

export function fetchPurchaseReturns(params?: {
  q?: string
  supplier_ulid?: string
  purchase_ulid?: string
  warehouse_ulid?: string
  status?: string
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}) {
  const search = new URLSearchParams()
  if (params?.q) search.set('q', params.q)
  if (params?.supplier_ulid) search.set('supplier_ulid', params.supplier_ulid)
  if (params?.purchase_ulid) search.set('purchase_ulid', params.purchase_ulid)
  if (params?.warehouse_ulid) search.set('warehouse_ulid', params.warehouse_ulid)
  if (params?.status) search.set('status', params.status)
  if (params?.date_from) search.set('date_from', params.date_from)
  if (params?.date_to) search.set('date_to', params.date_to)
  if (params?.page) search.set('page', String(params.page))
  search.set('per_page', String(params?.per_page ?? 25))
  const suffix = search.toString() ? `?${search.toString()}` : ''
  return apiFetch<Paginated<PurchaseReturn>>(`/api/purchase-returns${suffix}`)
}

export function fetchPurchaseReturn(ulid: string) {
  return apiFetch<PurchaseReturn>(`/api/purchase-returns/${ulid}`)
}

export function createPurchaseReturn(payload: {
  purchase_ulid: string
  warehouse_ulid?: string | null
  return_date?: string
  supplier_reference?: string | null
  reason?: string | null
  notes?: string | null
}) {
  return apiFetch<PurchaseReturn>('/api/purchase-returns', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updatePurchaseReturn(
  ulid: string,
  payload: Partial<{
    warehouse_ulid: string
    return_date: string
    supplier_reference: string | null
    reason: string | null
    notes: string | null
  }>,
) {
  return apiFetch<PurchaseReturn>(`/api/purchase-returns/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function createPurchaseReturnLine(
  returnUlid: string,
  payload: {
    purchase_line_ulid: string
    quantity: string
    discount_amount?: string
    tax_amount?: string
    batch_number?: string | null
    expiry_date?: string | null
    reason?: string | null
    notes?: string | null
  },
) {
  return apiFetch<PurchaseReturnLine>(`/api/purchase-returns/${returnUlid}/lines`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updatePurchaseReturnLine(
  returnUlid: string,
  lineUlid: string,
  payload: Partial<{
    purchase_line_ulid: string
    quantity: string
    discount_amount: string
    tax_amount: string
    batch_number: string | null
    expiry_date: string | null
    reason: string | null
    notes: string | null
  }>,
) {
  return apiFetch<PurchaseReturnLine>(
    `/api/purchase-returns/${returnUlid}/lines/${lineUlid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deletePurchaseReturnLine(returnUlid: string, lineUlid: string) {
  return apiFetch<{ ok: boolean }>(
    `/api/purchase-returns/${returnUlid}/lines/${lineUlid}`,
    { method: 'DELETE' },
  )
}

export function postPurchaseReturn(returnUlid: string) {
  return apiFetch<PurchaseReturn>(`/api/purchase-returns/${returnUlid}/post`, {
    method: 'POST',
  })
}

export function fetchReturnableLines(purchaseUlid: string) {
  return apiFetch<{ data: ReturnablePurchaseLine[] }>(
    `/api/purchases/${purchaseUlid}/returnable-lines`,
  )
}

export function fetchPostedPurchases(params?: { q?: string; page?: number; per_page?: number }) {
  const search = new URLSearchParams()
  search.set('status', 'posted')
  if (params?.q) search.set('q', params.q)
  if (params?.page) search.set('page', String(params.page))
  search.set('per_page', String(params?.per_page ?? 25))
  return apiFetch<Paginated<PurchaseInvoice>>(`/api/purchases?${search.toString()}`)
}
