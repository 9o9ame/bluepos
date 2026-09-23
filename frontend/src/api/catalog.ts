import { apiFetch } from './client'
import type {
  BarcodeGroup,
  Brand,
  BusinessSettings,
  CatalogItem,
  Paginated,
  Product,
  Subcategory,
  Unit,
} from '../types/catalog'

export function fetchBusinessSettings() {
  return apiFetch<BusinessSettings>('/api/settings/business')
}

export function saveBusinessSettings(payload: Partial<BusinessSettings>) {
  return apiFetch<BusinessSettings>('/api/settings/business', {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function fetchCategories() {
  return apiFetch<CatalogItem[]>('/api/categories')
}

export function createCategory(payload: { code: string; name: string }) {
  return apiFetch<CatalogItem>('/api/categories', { method: 'POST', body: JSON.stringify(payload) })
}

export function updateCategory(ulid: string, payload: { code: string; name: string }) {
  return apiFetch<CatalogItem>(`/api/categories/${ulid}`, { method: 'PATCH', body: JSON.stringify(payload) })
}

export function deactivateCategory(ulid: string) {
  return apiFetch<{ ok: boolean; archived: boolean }>(`/api/categories/${ulid}`, { method: 'DELETE' })
}

export function fetchSubcategories(categoryUlid?: string) {
  const query = categoryUlid ? `?category_ulid=${categoryUlid}` : ''
  return apiFetch<Subcategory[]>(`/api/subcategories${query}`)
}

export function createSubcategory(payload: { category_ulid: string; code: string; name: string }) {
  return apiFetch<Subcategory>('/api/subcategories', { method: 'POST', body: JSON.stringify(payload) })
}

export function fetchBrands() {
  return apiFetch<Brand[]>('/api/brands')
}

export function createBrand(payload: { code: string; name: string }) {
  return apiFetch<Brand>('/api/brands', { method: 'POST', body: JSON.stringify(payload) })
}

export function updateBrand(ulid: string, payload: { code: string; name: string }) {
  return apiFetch<Brand>(`/api/brands/${ulid}`, { method: 'PATCH', body: JSON.stringify(payload) })
}

export function deactivateBrand(ulid: string) {
  return apiFetch<{ ok: boolean; archived: boolean }>(`/api/brands/${ulid}`, { method: 'DELETE' })
}

export function fetchUnits() {
  return apiFetch<Unit[]>('/api/units')
}

export function createUnit(payload: { code: string; name: string; symbol: string; allows_decimal: boolean }) {
  return apiFetch<Unit>('/api/units', { method: 'POST', body: JSON.stringify(payload) })
}

export function updateUnit(
  ulid: string,
  payload: { code: string; name: string; symbol: string; allows_decimal: boolean },
) {
  return apiFetch<Unit>(`/api/units/${ulid}`, { method: 'PATCH', body: JSON.stringify(payload) })
}

export function deactivateUnit(ulid: string) {
  return apiFetch<{ ok: boolean; archived: boolean }>(`/api/units/${ulid}`, { method: 'DELETE' })
}

export function fetchBarcodeGroups() {
  return apiFetch<BarcodeGroup[]>('/api/barcode-groups')
}

export function createBarcodeGroup(payload: { code: string; name: string }) {
  return apiFetch<BarcodeGroup>('/api/barcode-groups', { method: 'POST', body: JSON.stringify(payload) })
}

export function updateBarcodeGroup(ulid: string, payload: { code: string; name: string }) {
  return apiFetch<BarcodeGroup>(`/api/barcode-groups/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deactivateBarcodeGroup(ulid: string) {
  return apiFetch<{ ok: boolean; archived: boolean }>(`/api/barcode-groups/${ulid}`, { method: 'DELETE' })
}

export function fetchProducts(params: { q?: string; page?: number; per_page?: number }) {
  const search = new URLSearchParams()
  if (params.q) search.set('q', params.q)
  if (params.page) search.set('page', String(params.page))
  search.set('per_page', String(params.per_page ?? 25))
  return apiFetch<Paginated<Product>>(`/api/products?${search.toString()}`)
}

export function fetchProduct(ulid: string) {
  return apiFetch<Product>(`/api/products/${ulid}`)
}

export function createProduct(payload: Record<string, unknown>) {
  return apiFetch<Product>('/api/products', { method: 'POST', body: JSON.stringify(payload) })
}

export function updateProduct(ulid: string, payload: Record<string, unknown>) {
  return apiFetch<Product>(`/api/products/${ulid}`, { method: 'PATCH', body: JSON.stringify(payload) })
}

export function deactivateProduct(ulid: string) {
  return apiFetch<Product>(`/api/products/${ulid}`, { method: 'DELETE' })
}

export function saveProductBarcodes(ulid: string, barcodes: unknown[]) {
  return apiFetch<Product>(`/api/products/${ulid}/barcodes`, {
    method: 'PUT',
    body: JSON.stringify({ barcodes }),
  })
}

export function saveProductPrices(ulid: string, prices: unknown[]) {
  return apiFetch<Product>(`/api/products/${ulid}/prices`, {
    method: 'PUT',
    body: JSON.stringify({ prices }),
  })
}
