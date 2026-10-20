import type { Product } from '../../types/catalog'

const DB_NAME = 'bluepos-product-view'
const DB_VERSION = 1
const STORE_NAME = 'snapshots'

export type ProductViewSnapshotMeta = {
  current_page: number
  per_page: number
  total: number
  last_page: number
  stock_total?: string | null
  stock_value_total?: string | null
  retail_stock_value_total?: string | null
}

export type ProductViewSnapshot = {
  key: string
  products: Product[]
  meta: ProductViewSnapshotMeta
  saved_at: string
}

function openProductViewDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)

    request.onupgradeneeded = () => {
      const db = request.result
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'key' })
      }
    }

    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('Unable to open Product View cache.'))
  })
}

export function productViewCacheKey(
  tenantUlid: string,
  branchUlid: string,
  warehouseUlid: string,
): string {
  return `${tenantUlid}:${branchUlid}:${warehouseUlid}`
}

export async function readProductViewSnapshot(
  key: string,
): Promise<ProductViewSnapshot | null> {
  const db = await openProductViewDb()

  try {
    return await new Promise((resolve, reject) => {
      const transaction = db.transaction(STORE_NAME, 'readonly')
      const request = transaction.objectStore(STORE_NAME).get(key)

      request.onsuccess = () => resolve((request.result as ProductViewSnapshot | undefined) ?? null)
      request.onerror = () => reject(request.error ?? new Error('Unable to read Product View cache.'))
    })
  } finally {
    db.close()
  }
}

export async function writeProductViewSnapshot(
  snapshot: ProductViewSnapshot,
): Promise<void> {
  const db = await openProductViewDb()

  try {
    await new Promise<void>((resolve, reject) => {
      const transaction = db.transaction(STORE_NAME, 'readwrite')
      transaction.objectStore(STORE_NAME).put(snapshot)

      transaction.oncomplete = () => resolve()
      transaction.onerror = () => reject(transaction.error ?? new Error('Unable to save Product View cache.'))
      transaction.onabort = () => reject(transaction.error ?? new Error('Product View cache write was aborted.'))
    })
  } finally {
    db.close()
  }
}
