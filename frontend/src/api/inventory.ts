import { apiFetch } from './client'
import type { Paginated } from '../types/catalog'
import type {
  OpeningBalance,
  OpeningBalanceLine,
  ProductStock,
  StockBalance,
  StockTake,
  StockTakeLine,
  InventoryAdjustment,
  InventoryAdjustmentAccount,
  InventoryAdjustmentLine,
} from '../types/inventory'
import type { Warehouse } from '../types/auth'

export function fetchWarehouses() {
  return apiFetch<Warehouse[]>('/api/warehouses')
}

export function createWarehouse(payload: {
  code: string
  name: string
}) {
  return apiFetch<Warehouse>('/api/warehouses', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateWarehouse(
  warehouseUlid: string,
  payload: {
    code?: string
    name?: string
    status?: 'active' | 'inactive'
    is_default?: boolean
  },
) {
  return apiFetch<Warehouse>(`/api/warehouses/${warehouseUlid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
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

export function deleteOpeningBalance(ulid: string) {
  return apiFetch<{ ok: boolean }>(`/api/inventory/opening-balances/${ulid}`, {
    method: 'DELETE',
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


export function fetchStockTakes(params?: {
  product_ulid?: string
  warehouse_ulid?: string
  status?: string
}) {
  const search = new URLSearchParams()
  if (params?.product_ulid) search.set('product_ulid', params.product_ulid)
  if (params?.warehouse_ulid) search.set('warehouse_ulid', params.warehouse_ulid)
  if (params?.status) search.set('status', params.status)

  const suffix = search.toString() ? `?${search.toString()}` : ''
  return apiFetch<StockTake[]>(`/api/inventory/stock-takes${suffix}`)
}

export function fetchStockTake(ulid: string) {
  return apiFetch<StockTake>(`/api/inventory/stock-takes/${ulid}`)
}

export function createStockTake(payload: {
  warehouse_ulid: string
  count_date?: string
  notes?: string | null
}) {
  return apiFetch<StockTake>('/api/inventory/stock-takes', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateStockTake(
  ulid: string,
  payload: { count_date?: string; notes?: string | null },
) {
  return apiFetch<StockTake>(`/api/inventory/stock-takes/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deleteStockTake(ulid: string) {
  return apiFetch<{ ok: boolean }>(`/api/inventory/stock-takes/${ulid}`, {
    method: 'DELETE',
  })
}

export function createStockTakeLine(
  documentUlid: string,
  payload: {
    product_ulid: string
    counted_quantity: string
    notes?: string | null
  },
) {
  return apiFetch<StockTakeLine>(`/api/inventory/stock-takes/${documentUlid}/lines`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateStockTakeLine(
  documentUlid: string,
  lineUlid: string,
  payload: {
    product_ulid?: string
    counted_quantity?: string
    notes?: string | null
  },
) {
  return apiFetch<StockTakeLine>(
    `/api/inventory/stock-takes/${documentUlid}/lines/${lineUlid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deleteStockTakeLine(documentUlid: string, lineUlid: string) {
  return apiFetch<{ ok: boolean }>(
    `/api/inventory/stock-takes/${documentUlid}/lines/${lineUlid}`,
    { method: 'DELETE' },
  )
}

export function postStockTake(documentUlid: string) {
  return apiFetch<StockTake>(`/api/inventory/stock-takes/${documentUlid}/post`, {
    method: 'POST',
  })
}


export function fetchInventoryAdjustmentAccounts() {
  return apiFetch<{ data: InventoryAdjustmentAccount[] }>('/api/inventory/adjustments/accounts')
}

export function fetchInventoryAdjustments(params?: {
  status?: string
  from_date?: string
  to_date?: string
  product_ulid?: string
}) {
  const search = new URLSearchParams()
  if (params?.status) search.set('status', params.status)
  if (params?.from_date) search.set('from_date', params.from_date)
  if (params?.to_date) search.set('to_date', params.to_date)
  if (params?.product_ulid) search.set('product_ulid', params.product_ulid)

  const suffix = search.toString() ? `?${search.toString()}` : ''
  return apiFetch<InventoryAdjustment[]>(`/api/inventory/adjustments${suffix}`)
}

export function fetchInventoryAdjustment(ulid: string) {
  return apiFetch<InventoryAdjustment>(`/api/inventory/adjustments/${ulid}`)
}

export function createInventoryAdjustment(payload: {
  account_ulid: string
  adjustment_date?: string
  adjustment_type?: 'stock_adjustment'
  notes?: string | null
}) {
  return apiFetch<InventoryAdjustment>('/api/inventory/adjustments', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateInventoryAdjustment(
  ulid: string,
  payload: {
    account_ulid?: string
    adjustment_date?: string
    adjustment_type?: 'stock_adjustment'
    notes?: string | null
  },
) {
  return apiFetch<InventoryAdjustment>(`/api/inventory/adjustments/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deleteInventoryAdjustment(ulid: string) {
  return apiFetch<{ ok: boolean }>(`/api/inventory/adjustments/${ulid}`, {
    method: 'DELETE',
  })
}

export function createInventoryAdjustmentLine(
  documentUlid: string,
  payload: {
    product_ulid: string
    qty_plus?: string | null
    qty_minus?: string | null
    notes?: string | null
  },
) {
  return apiFetch<InventoryAdjustmentLine>(`/api/inventory/adjustments/${documentUlid}/lines`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateInventoryAdjustmentLine(
  documentUlid: string,
  lineUlid: string,
  payload: {
    product_ulid?: string
    qty_plus?: string | null
    qty_minus?: string | null
    notes?: string | null
  },
) {
  return apiFetch<InventoryAdjustmentLine>(
    `/api/inventory/adjustments/${documentUlid}/lines/${lineUlid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deleteInventoryAdjustmentLine(documentUlid: string, lineUlid: string) {
  return apiFetch<{ ok: boolean }>(
    `/api/inventory/adjustments/${documentUlid}/lines/${lineUlid}`,
    { method: 'DELETE' },
  )
}

export function postInventoryAdjustment(documentUlid: string) {
  return apiFetch<InventoryAdjustment>(`/api/inventory/adjustments/${documentUlid}/post`, {
    method: 'POST',
  })
}

