import { apiFetch } from './client'
import type {
  BarcodeGroup,
  Brand,
  BusinessSettings,
  CatalogItem,
  Paginated,
  Product,
  Subcategory,
  Supplier,
  Unit,
} from '../types/catalog'

type ArchiveResponse = {
  ok: boolean
  archived?: boolean
}

type CatalogMasterPayload = {
  code: string
  name: string
  description?: string | null
  is_active?: boolean
  sort_order?: number
}

type UnitPayload = {
  code: string
  name: string
  symbol: string
  allows_decimal: boolean
  is_active?: boolean
}

/*
|--------------------------------------------------------------------------
| Business Settings
|--------------------------------------------------------------------------
*/

export function fetchBusinessSettings() {
  return apiFetch<BusinessSettings>('/api/settings/business')
}

export function saveBusinessSettings(
  payload: Partial<BusinessSettings>,
) {
  return apiFetch<BusinessSettings>('/api/settings/business', {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

export function fetchCategories() {
  return apiFetch<CatalogItem[]>('/api/categories')
}

export function createCategory(
  payload: CatalogMasterPayload,
) {
  return apiFetch<CatalogItem>('/api/categories', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateCategory(
  ulid: string,
  payload: Partial<CatalogMasterPayload>,
) {
  return apiFetch<CatalogItem>(
    `/api/categories/${ulid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deactivateCategory(
  ulid: string,
) {
  return apiFetch<ArchiveResponse>(
    `/api/categories/${ulid}`,
    {
      method: 'DELETE',
    },
  )
}

/*
|--------------------------------------------------------------------------
| Subcategories
|--------------------------------------------------------------------------
*/

export function fetchSubcategories(
  categoryUlid?: string,
) {
  const query = categoryUlid
    ? `?category_ulid=${categoryUlid}`
    : ''

  return apiFetch<Subcategory[]>(
    `/api/subcategories${query}`,
  )
}

export function createSubcategory(
  payload: {
    category_ulid: string
    code: string
    name: string
    description?: string | null
    is_active?: boolean
    sort_order?: number
  },
) {
  return apiFetch<Subcategory>(
    '/api/subcategories',
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
  )
}

export function updateSubcategory(
  ulid: string,
  payload: Partial<{
    category_ulid: string
    code: string
    name: string
    description: string | null
    is_active: boolean
    sort_order: number
  }>,
) {
  return apiFetch<Subcategory>(
    `/api/subcategories/${ulid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deactivateSubcategory(
  ulid: string,
) {
  return apiFetch<ArchiveResponse>(
    `/api/subcategories/${ulid}`,
    {
      method: 'DELETE',
    },
  )
}

/*
|--------------------------------------------------------------------------
| Brands / Manufacture
|--------------------------------------------------------------------------
*/

export function fetchBrands() {
  return apiFetch<Brand[]>('/api/brands')
}

export function createBrand(
  payload: CatalogMasterPayload,
) {
  return apiFetch<Brand>('/api/brands', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateBrand(
  ulid: string,
  payload: Partial<CatalogMasterPayload>,
) {
  return apiFetch<Brand>(
    `/api/brands/${ulid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deactivateBrand(
  ulid: string,
) {
  return apiFetch<ArchiveResponse>(
    `/api/brands/${ulid}`,
    {
      method: 'DELETE',
    },
  )
}

/*
|--------------------------------------------------------------------------
| Units
|--------------------------------------------------------------------------
*/

export function fetchUnits() {
  return apiFetch<Unit[]>('/api/units')
}

export function createUnit(
  payload: UnitPayload,
) {
  return apiFetch<Unit>('/api/units', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateUnit(
  ulid: string,
  payload: Partial<UnitPayload>,
) {
  return apiFetch<Unit>(
    `/api/units/${ulid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deactivateUnit(
  ulid: string,
) {
  return apiFetch<ArchiveResponse>(
    `/api/units/${ulid}`,
    {
      method: 'DELETE',
    },
  )
}

/*
|--------------------------------------------------------------------------
| Barcode Groups
|--------------------------------------------------------------------------
*/

export function fetchBarcodeGroups() {
  return apiFetch<BarcodeGroup[]>(
    '/api/barcode-groups',
  )
}

export function createBarcodeGroup(
  payload: CatalogMasterPayload,
) {
  return apiFetch<BarcodeGroup>(
    '/api/barcode-groups',
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
  )
}

export function updateBarcodeGroup(
  ulid: string,
  payload: Partial<CatalogMasterPayload>,
) {
  return apiFetch<BarcodeGroup>(
    `/api/barcode-groups/${ulid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deactivateBarcodeGroup(
  ulid: string,
) {
  return apiFetch<ArchiveResponse>(
    `/api/barcode-groups/${ulid}`,
    {
      method: 'DELETE',
    },
  )
}

/*
|--------------------------------------------------------------------------
| Suppliers
|--------------------------------------------------------------------------
*/

export type SupplierPayload = {
  code: string
  name: string
  contact_person?: string | null
  phone?: string | null
  email?: string | null
  address?: string | null
  tax_number?: string | null
  notes?: string | null
  is_active?: boolean
}

export function fetchSuppliers() {
  return apiFetch<Supplier[]>('/api/suppliers')
}

export function fetchSupplier(ulid: string) {
  return apiFetch<Supplier>(`/api/suppliers/${ulid}`)
}

export function createSupplier(payload: SupplierPayload) {
  return apiFetch<Supplier>('/api/suppliers', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateSupplier(
  ulid: string,
  payload: Partial<SupplierPayload>,
) {
  return apiFetch<Supplier>(`/api/suppliers/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deactivateSupplier(ulid: string) {
  return apiFetch<ArchiveResponse>(`/api/suppliers/${ulid}`, {
    method: 'DELETE',
  })
}

/*
|--------------------------------------------------------------------------
| Products
|--------------------------------------------------------------------------
*/

export function fetchProducts(
  params: {
    q?: string
    page?: number
    per_page?: number
  },
) {
  const search = new URLSearchParams()

  if (params.q) {
    search.set('q', params.q)
  }

  if (params.page) {
    search.set('page', String(params.page))
  }

  search.set(
    'per_page',
    String(params.per_page ?? 25),
  )

  return apiFetch<Paginated<Product>>(
    `/api/products?${search.toString()}`,
  )
}

export function fetchProduct(
  ulid: string,
) {
  return apiFetch<Product>(
    `/api/products/${ulid}`,
  )
}

export function createProduct(
  payload: Record<string, unknown>,
) {
  return apiFetch<Product>('/api/products', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateProduct(
  ulid: string,
  payload: Record<string, unknown>,
) {
  return apiFetch<Product>(
    `/api/products/${ulid}`,
    {
      method: 'PATCH',
      body: JSON.stringify(payload),
    },
  )
}

export function deactivateProduct(
  ulid: string,
) {
  return apiFetch<Product>(
    `/api/products/${ulid}`,
    {
      method: 'DELETE',
    },
  )
}

export function saveProductBarcodes(
  ulid: string,
  barcodes: unknown[],
) {
  return apiFetch<Product>(
    `/api/products/${ulid}/barcodes`,
    {
      method: 'PUT',
      body: JSON.stringify({ barcodes }),
    },
  )
}

export function saveProductPrices(
  ulid: string,
  prices: unknown[],
) {
  return apiFetch<Product>(
    `/api/products/${ulid}/prices`,
    {
      method: 'PUT',
      body: JSON.stringify({ prices }),
    },
  )
}