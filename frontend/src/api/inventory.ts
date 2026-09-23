import { apiFetch } from './client'
import type { Paginated } from '../types/catalog'
import type { OpeningBalance, OpeningBalanceLine, ProductStock, StockBalance } from '../types/inventory'
import type { Warehouse } from '../types/auth'

export function fetchWarehouses() {
  return apiFetch<Warehouse[]>('/api/warehouses')
}

export function fetchProductStock(productUlid: string) {
  return apiFetch<ProductStock>(`/api/products/${productUlid}/stock`)
}

export function fetchStock(params: {
  product_ulid?: string
  warehouse_ulid?: string
  branch_ulid?: string
  q?: string
  page?: number
  per_page?: number
}) {
  const search = new URLSearchParams()
  if (params.product_ulid) search.set('product_ulid', params.product_ulid)
  if (params.warehouse_ulid) search.set('warehouse_ulid', params.warehouse_ulid)
  if (params.branch_ulid) search.set('branch_ulid', params.branch_ulid)
  if (params.q) search.set('q', params.q)
  if (params.page) search.set('page', String(params.page))
  search.set('per_page', String(params.per_page ?? 25))

  return apiFetch<Paginated<StockBalance>>(`/api/inventory/stock?${search.toString()}`)
}

export function fetchOpeningBalances(params?: {
  product_ulid?: string
  warehouse_ulid?: string
  status?: string
}) {
  const search = new URLSearchParams()
  if (params?.product_ulid) search.set('product_ulid', params.product_ulid)
  if (params?.warehouse_ulid) search.set('warehouse_ulid', params.warehouse_ulid)
  if (params?.status) search.set('status', params.status)

  const suffix = search.toString() ? `?${search.toString()}` : ''
  return apiFetch<OpeningBalance[]>(`/api/inventory/opening-balances${suffix}`)
}

export function fetchOpeningBalance(ulid: string) {
  return apiFetch<OpeningBalance>(`/api/inventory/opening-balances/${ulid}`)
}

export function createOpeningBalance(payload: {
  warehouse_ulid: string
  document_date?: string
  notes?: string | null
}) {
  return apiFetch<OpeningBalance>('/api/inventory/opening-balances', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateOpeningBalance(
  ulid: string,
  payload: { document_date?: string; notes?: string | null },
) {
  return apiFetch<OpeningBalance>(`/api/inventory/opening-balances/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function createOpeningBalanceLine(
  documentUlid: string,
  payload: {
    product_ulid: string
    quantity: string
    unit_cost: string
    notes?: string | null
  },
) {
  return apiFetch<OpeningBalanceLine>(`/api/inventory/opening-balances/${documentUlid}/lines`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateOpeningBalanceLine(
  documentUlid: string,
  lineUlid: string,
  payload: {
    product_ulid?: string
    quantity?: string
    unit_cost?: string
    notes?: string | null
  },
) {
  return apiFetch<OpeningBalanceLine>(
    `/api/inventory/opening-balances/${documentUlid}/lines/${lineUlid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deleteOpeningBalanceLine(documentUlid: string, lineUlid: string) {
  return apiFetch<{ ok: boolean }>(
    `/api/inventory/opening-balances/${documentUlid}/lines/${lineUlid}`,
    { method: 'DELETE' },
  )
}

export function postOpeningBalance(documentUlid: string) {
  return apiFetch<OpeningBalance>(`/api/inventory/opening-balances/${documentUlid}/post`, {
    method: 'POST',
  })
}
