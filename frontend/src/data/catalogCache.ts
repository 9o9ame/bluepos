import { fetchProducts, fetchSuppliers } from '../api/catalog'
import type { AuthSession } from '../types/auth'
import type { Product, Supplier } from '../types/catalog'
import {
  readLocalEntity,
  readLocalMeta,
  replaceLocalEntity,
  upsertLocalEntity,
  writeLocalMeta,
} from './localDb'

const PRODUCT_REFRESH_MS = 5 * 60 * 1000
const SUPPLIER_REFRESH_MS = 10 * 60 * 1000

const productMemory = new Map<string, Product[]>()
const supplierMemory = new Map<string, Supplier[]>()
const productRefreshes = new Map<string, Promise<Product[]>>()
const supplierRefreshes = new Map<string, Promise<Supplier[]>>()

export type CatalogScope = {
  tenantUlid: string
  branchUlid: string
  warehouseUlid: string
}

export function catalogScopeFromSession(session: AuthSession): CatalogScope {
  return {
    tenantUlid: session.tenant.ulid,
    branchUlid: session.branch.ulid,
    warehouseUlid: session.warehouse.ulid,
  }
}

export function productScopeKey(scope: CatalogScope) {
  return `tenant:${scope.tenantUlid}:branch:${scope.branchUlid}:warehouse:${scope.warehouseUlid}`
}

export function supplierScopeKey(scope: CatalogScope) {
  return `tenant:${scope.tenantUlid}`
}

function metaKey(scopeKey: string, entity: 'products' | 'suppliers') {
  return `refresh:${scopeKey}:${entity}`
}

async function isFresh(
  scopeKey: string,
  entity: 'products' | 'suppliers',
  maxAgeMs: number,
) {
  try {
    const refreshedAt = await readLocalMeta<number>(metaKey(scopeKey, entity))
    return refreshedAt !== null && Date.now() - refreshedAt < maxAgeMs
  } catch {
    return false
  }
}

export async function readCachedProducts(scope: CatalogScope): Promise<Product[]> {
  const key = productScopeKey(scope)
  const memory = productMemory.get(key)
  if (memory) return memory

  try {
    const rows = await readLocalEntity<Product>(key, 'products')
    productMemory.set(key, rows)
    return rows
  } catch {
    return []
  }
}

export async function refreshProducts(
  scope: CatalogScope,
  options?: { force?: boolean },
): Promise<Product[]> {
  const key = productScopeKey(scope)
  const existing = productRefreshes.get(key)
  if (existing) return existing

  const refresh = (async () => {
    if (!options?.force && await isFresh(key, 'products', PRODUCT_REFRESH_MS)) {
      const cached = await readCachedProducts(scope)
      if (cached.length > 0) return cached
    }

    const payload = await fetchProducts(
      {
        lookup_all: true,
        sales_lookup: true,
      },
      { busy: 'none' },
    )
    const rows = payload.data

    productMemory.set(key, rows)
    try {
      await replaceLocalEntity(key, 'products', rows)
      await writeLocalMeta(metaKey(key, 'products'), Date.now())
    } catch {
      // IndexedDB failure must not block the online workflow.
    }

    return rows
  })().finally(() => {
    productRefreshes.delete(key)
  })

  productRefreshes.set(key, refresh)
  return refresh
}

export async function upsertCachedProduct(
  scope: CatalogScope,
  product: Product,
): Promise<void> {
  const key = productScopeKey(scope)
  const current = await readCachedProducts(scope)
  const next = current.some((row) => row.ulid === product.ulid)
    ? current.map((row) => row.ulid === product.ulid ? product : row)
    : [...current, product]

  next.sort((a, b) => a.product_number.localeCompare(b.product_number, undefined, { numeric: true }))
  productMemory.set(key, next)

  try {
    await upsertLocalEntity(key, 'products', product)
  } catch {
    // Keep the in-memory cache usable when persistent storage is unavailable.
  }
}

export async function readCachedSuppliers(scope: CatalogScope): Promise<Supplier[]> {
  const key = supplierScopeKey(scope)
  const memory = supplierMemory.get(key)
  if (memory) return memory

  try {
    const rows = await readLocalEntity<Supplier>(key, 'suppliers')
    supplierMemory.set(key, rows)
    return rows
  } catch {
    return []
  }
}

export async function refreshSuppliers(
  scope: CatalogScope,
  options?: { force?: boolean },
): Promise<Supplier[]> {
  const key = supplierScopeKey(scope)
  const existing = supplierRefreshes.get(key)
  if (existing) return existing

  const refresh = (async () => {
    if (!options?.force && await isFresh(key, 'suppliers', SUPPLIER_REFRESH_MS)) {
      const cached = await readCachedSuppliers(scope)
      if (cached.length > 0) return cached
    }

    const rows = await fetchSuppliers()
    supplierMemory.set(key, rows)

    try {
      await replaceLocalEntity(key, 'suppliers', rows)
      await writeLocalMeta(metaKey(key, 'suppliers'), Date.now())
    } catch {
      // IndexedDB failure must not block the online workflow.
    }

    return rows
  })().finally(() => {
    supplierRefreshes.delete(key)
  })

  supplierRefreshes.set(key, refresh)
  return refresh
}

export async function upsertCachedSupplier(
  scope: CatalogScope,
  supplier: Supplier,
): Promise<void> {
  const key = supplierScopeKey(scope)
  const current = await readCachedSuppliers(scope)
  const next = current.some((row) => row.ulid === supplier.ulid)
    ? current.map((row) => row.ulid === supplier.ulid ? supplier : row)
    : [...current, supplier]

  next.sort((a, b) => a.name.localeCompare(b.name))
  supplierMemory.set(key, next)

  try {
    await upsertLocalEntity(key, 'suppliers', supplier)
  } catch {
    // Keep the in-memory cache usable when persistent storage is unavailable.
  }
}

function productSearchText(product: Product) {
  const barcodes = product.barcodes?.map((row) => row.barcode).join(' ') ?? ''
  return [
    product.product_number,
    product.sku,
    product.name,
    product.category?.name,
    product.brand?.name,
    barcodes,
  ]
    .filter(Boolean)
    .join(' ')
    .toLocaleLowerCase()
}

export function filterCachedProducts(products: Product[], query: string) {
  const normalized = query.trim().toLocaleLowerCase()
  if (!normalized) return products
  return products.filter((product) => productSearchText(product).includes(normalized))
}
