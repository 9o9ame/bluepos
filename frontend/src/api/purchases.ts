import { apiFetch } from './client'
import type { Paginated } from '../types/catalog'
import type {
  PurchaseHeaderPayload,
  PurchaseInvoice,
  PurchaseInvoiceLine,
  PurchaseLinePayload,
  PurchasePayment,
  PurchasePaymentMethod,
  PurchaseOrder,
  PurchaseOrderGeneratePayload,
  PurchaseOrderGenerateRow,
  PurchaseOrderPayload,
  PurchaseOrderStatusRow,
} from '../types/purchases'

export function fetchPurchases(params?: {
  q?: string
  supplier_ulid?: string
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
  if (params?.warehouse_ulid) search.set('warehouse_ulid', params.warehouse_ulid)
  if (params?.status) search.set('status', params.status)
  if (params?.date_from) search.set('date_from', params.date_from)
  if (params?.date_to) search.set('date_to', params.date_to)
  if (params?.page) search.set('page', String(params.page))
  search.set('per_page', String(params?.per_page ?? 25))
  const suffix = search.toString() ? `?${search.toString()}` : ''
  return apiFetch<Paginated<PurchaseInvoice>>(`/api/purchases${suffix}`)
}

export function fetchPurchase(ulid: string) {
  return apiFetch<PurchaseInvoice>(`/api/purchases/${ulid}`)
}

export function createPurchase(payload: PurchaseHeaderPayload) {
  return apiFetch<PurchaseInvoice>('/api/purchases', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updatePurchase(ulid: string, payload: Partial<PurchaseHeaderPayload>) {
  return apiFetch<PurchaseInvoice>(`/api/purchases/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function createPurchaseLine(purchaseUlid: string, payload: PurchaseLinePayload) {
  return apiFetch<PurchaseInvoiceLine>(`/api/purchases/${purchaseUlid}/lines`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updatePurchaseLine(
  purchaseUlid: string,
  lineUlid: string,
  payload: Partial<PurchaseLinePayload>,
) {
  return apiFetch<PurchaseInvoiceLine>(
    `/api/purchases/${purchaseUlid}/lines/${lineUlid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deletePurchaseLine(purchaseUlid: string, lineUlid: string) {
  return apiFetch<{ ok: boolean }>(
    `/api/purchases/${purchaseUlid}/lines/${lineUlid}`,
    { method: 'DELETE' },
  )
}

export function postPurchase(purchaseUlid: string) {
  return apiFetch<PurchaseInvoice>(`/api/purchases/${purchaseUlid}/post`, {
    method: 'POST',
  })
}


export function createPurchasePayment(
  purchaseUlid: string,
  payload: {
    amount: string
    method: PurchasePaymentMethod
    reference?: string | null
  },
  idempotencyKey: string,
) {
  return apiFetch<PurchasePayment>(`/api/purchases/${purchaseUlid}/payments`, {
    method: 'POST',
    headers: {
      'Idempotency-Key': idempotencyKey,
    },
    body: JSON.stringify(payload),
  })
}


export function fetchPurchaseOrders(params?: {
  q?: string
  supplier_ulid?: string
  status?: string
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}) {
  const search = new URLSearchParams()
  if (params?.q) search.set('q', params.q)
  if (params?.supplier_ulid) search.set('supplier_ulid', params.supplier_ulid)
  if (params?.status) search.set('status', params.status)
  if (params?.date_from) search.set('date_from', params.date_from)
  if (params?.date_to) search.set('date_to', params.date_to)
  if (params?.page) search.set('page', String(params.page))
  search.set('per_page', String(params?.per_page ?? 25))
  const suffix = search.toString() ? `?${search.toString()}` : ''

  return apiFetch<Paginated<PurchaseOrder>>(`/api/purchase-orders${suffix}`)
}

export function fetchPurchaseOrderStatus(params?: {
  supplier_ulid?: string
  status?: string
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}) {
  const search = new URLSearchParams()
  if (params?.supplier_ulid) search.set('supplier_ulid', params.supplier_ulid)
  if (params?.status) search.set('status', params.status)
  if (params?.date_from) search.set('date_from', params.date_from)
  if (params?.date_to) search.set('date_to', params.date_to)
  if (params?.page) search.set('page', String(params.page))
  search.set('per_page', String(params?.per_page ?? 50))
  const suffix = search.toString() ? `?${search.toString()}` : ''

  return apiFetch<Paginated<PurchaseOrderStatusRow>>(`/api/purchase-orders/status${suffix}`)
}

export function fetchPurchaseOrder(ulid: string) {
  return apiFetch<PurchaseOrder>(`/api/purchase-orders/${ulid}`)
}

export function cancelPurchaseOrder(ulid: string) {
  return apiFetch<PurchaseOrder>(`/api/purchase-orders/${ulid}/cancel`, {
    method: 'POST',
  })
}

export function createPurchaseOrder(payload: PurchaseOrderPayload) {
  return apiFetch<PurchaseOrder>('/api/purchase-orders', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}


export function generatePurchaseOrder(payload: PurchaseOrderGeneratePayload) {
  return apiFetch<{
    data: PurchaseOrderGenerateRow[]
    meta: {
      mode: string
      count: number
      current_page: number
      per_page: number
      last_page: number
      total: number
      has_more: boolean
    }
  }>('/api/purchase-orders/generate', {
    method: 'POST',
    body: JSON.stringify(payload),
    busy: 'none',
  })
}
