import { apiFetch } from './client'
import type { Paginated } from '../types/catalog'
import type { PurchaseInvoice, PurchaseInvoiceLine } from '../types/purchases'

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

export function createPurchase(payload: {
  supplier_ulid: string
  warehouse_ulid: string
  invoice_date?: string
  due_date?: string | null
  supplier_invoice_number?: string | null
  freight_amount?: string
  other_charges?: string
  notes?: string | null
}) {
  return apiFetch<PurchaseInvoice>('/api/purchases', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updatePurchase(
  ulid: string,
  payload: Partial<{
    supplier_ulid: string
    warehouse_ulid: string
    invoice_date: string
    due_date: string | null
    supplier_invoice_number: string | null
    freight_amount: string
    other_charges: string
    notes: string | null
  }>,
) {
  return apiFetch<PurchaseInvoice>(`/api/purchases/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function createPurchaseLine(
  purchaseUlid: string,
  payload: {
    product_ulid: string
    unit_ulid: string
    quantity: string
    conversion_factor?: string
    unit_cost: string
    discount_amount?: string
    tax_amount?: string
    supplier_product_code?: string | null
    batch_number?: string | null
    expiry_date?: string | null
    notes?: string | null
  },
) {
  return apiFetch<PurchaseInvoiceLine>(`/api/purchases/${purchaseUlid}/lines`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updatePurchaseLine(
  purchaseUlid: string,
  lineUlid: string,
  payload: Partial<{
    product_ulid: string
    unit_ulid: string
    quantity: string
    conversion_factor: string
    unit_cost: string
    discount_amount: string
    tax_amount: string
    supplier_product_code: string | null
    batch_number: string | null
    expiry_date: string | null
    notes: string | null
  }>,
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
