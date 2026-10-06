import { apiFetch } from './client'
import type {
  BarcodeGroup,
  Brand,
  BusinessSettings,
  CatalogItem,
  Paginated,
  Product,
  ProductStockResponse,
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
    category_ulid?: string
    brand_ulid?: string
    status?: string
    sales_lookup?: boolean
    active_only?: boolean
    with_balance?: boolean
    stock_le_reorder?: boolean
    purchase_rate_ge_sale_rate?: boolean
  },
  options?: {
    busy?: 'block' | 'fetch' | 'none'
  },
) {
  const search = new URLSearchParams()

  if (params.q) {
    search.set('q', params.q)
  }

  if (params.page) {
    search.set('page', String(params.page))
  }

  if (params.category_ulid) {
    search.set('category_ulid', params.category_ulid)
  }

  if (params.brand_ulid) {
    search.set('brand_ulid', params.brand_ulid)
  }

  if (params.status) {
    search.set('status', params.status)
  }

  if (params.sales_lookup) {
    search.set('sales_lookup', '1')
  }

  if (params.active_only) {
    search.set('active_only', '1')
  }

  if (params.with_balance) {
    search.set('with_balance', '1')
  }

  if (params.stock_le_reorder) {
    search.set('stock_le_reorder', '1')
  }

  if (params.purchase_rate_ge_sale_rate) {
    search.set('purchase_rate_ge_sale_rate', '1')
  }

  search.set(
    'per_page',
    String(params.per_page ?? 25),
  )

  return apiFetch<Paginated<Product>>(
    `/api/products?${search.toString()}`,
    options?.busy ? { busy: options.busy } : undefined,
  )
}

export function fetchProduct(
  ulid: string,
) {
  return apiFetch<Product>(
    `/api/products/${ulid}`,
  )
}

export function fetchProductStock(
  ulid: string,
) {
  return apiFetch<ProductStockResponse>(
    `/api/products/${ulid}/stock`,
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

export type BulkProductPriceChange = {
  price_type: 'retail' | 'wholesale' | 'minimum_sale'
  amount?: string
  formula?: 'trade_price_plus_percent'
  percent?: string
}

export type BulkProductUpdateRow = {
  product_ulid: string
  product?: {
    reorder_level?: string | null
  }
  prices?: BulkProductPriceChange[]
}

export function bulkUpdateProducts(
  rows: BulkProductUpdateRow[],
) {
  return apiFetch<{ updated: number }>(
    '/api/products/bulk',
    {
      method: 'PATCH',
      body: JSON.stringify({ rows }),
    },
  )
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

function readBrowserCookie(name: string): string | null {
  if (typeof document === 'undefined') {
    return null
  }

  const prefix = `${name}=`
  const part = document.cookie
    .split(';')
    .map((value) => value.trim())
    .find((value) => value.startsWith(prefix))

  return part ? decodeURIComponent(part.slice(prefix.length)) : null
}

async function productImageFetch(
  ulid: string,
  body: FormData,
): Promise<Product> {
  // Ensure Laravel Sanctum has issued an XSRF cookie before the multipart POST.
  await fetch('/sanctum/csrf-cookie', {
    credentials: 'include',
  })

  const headers = new Headers({
    Accept: 'application/json',
  })
  const xsrfToken = readBrowserCookie('XSRF-TOKEN')

  if (xsrfToken) {
    headers.set('X-XSRF-TOKEN', xsrfToken)
  }

  const response = await fetch(`/api/products/${ulid}/image`, {
    method: 'POST',
    credentials: 'include',
    headers,
    body,
  })

  const payload = await response.json().catch(() => null) as
    | Product
    | { message?: string; errors?: Record<string, string[]> }
    | null

  if (!response.ok) {
    const validationMessage =
      payload &&
      'errors' in payload &&
      payload.errors
        ? Object.values(payload.errors).flat()[0]
        : null

    const message =
      validationMessage ||
      (payload && 'message' in payload ? payload.message : null) ||
      'Unable to upload product image.'

    throw new Error(message)
  }

  return payload as Product
}

export function uploadProductImage(
  ulid: string,
  image: File,
): Promise<Product> {
  const body = new FormData()
  body.append('image', image)

  return productImageFetch(ulid, body)
}

export function deleteProductImage(
  ulid: string,
): Promise<Product> {
  return apiFetch<Product>(
    `/api/products/${ulid}/image`,
    {
      method: 'DELETE',
    },
  )
}
