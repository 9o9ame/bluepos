export type LocalEntityName = 'products' | 'suppliers'

type LocalRecord<T = unknown> = {
  key: string
  scopeEntity: string
  scopeKey: string
  entity: LocalEntityName
  ulid: string
  value: T
}

type LocalMeta = {
  key: string
  value: unknown
}

const DB_NAME = 'bluepos-local-data'
const DB_VERSION = 1
const RECORDS_STORE = 'records'
const META_STORE = 'meta'

let dbPromise: Promise<IDBDatabase> | null = null

function requestResult<T>(request: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error ?? new Error('IndexedDB request failed.'))
  })
}

function transactionDone(transaction: IDBTransaction): Promise<void> {
  return new Promise((resolve, reject) => {
    transaction.oncomplete = () => resolve()
    transaction.onerror = () =>
      reject(transaction.error ?? new Error('IndexedDB transaction failed.'))
    transaction.onabort = () =>
      reject(transaction.error ?? new Error('IndexedDB transaction aborted.'))
  })
}

function openDb(): Promise<IDBDatabase> {
  if (dbPromise) return dbPromise

  if (typeof indexedDB === 'undefined') {
    return Promise.reject(new Error('IndexedDB is not available in this browser.'))
  }

  dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)

    request.onupgradeneeded = () => {
      const db = request.result

      if (!db.objectStoreNames.contains(RECORDS_STORE)) {
        const records = db.createObjectStore(RECORDS_STORE, { keyPath: 'key' })
        records.createIndex('scopeEntity', 'scopeEntity', { unique: false })
      }

      if (!db.objectStoreNames.contains(META_STORE)) {
        db.createObjectStore(META_STORE, { keyPath: 'key' })
      }
    }

    request.onsuccess = () => resolve(request.result)
    request.onerror = () =>
      reject(request.error ?? new Error('Unable to open BluePOS local database.'))
  })

  return dbPromise
}

function compositeKey(scopeKey: string, entity: LocalEntityName, ulid: string) {
  return `${scopeKey}|${entity}|${ulid}`
}

function scopeEntityKey(scopeKey: string, entity: LocalEntityName) {
  return `${scopeKey}|${entity}`
}

export async function readLocalEntity<T>(
  scopeKey: string,
  entity: LocalEntityName,
): Promise<T[]> {
  const db = await openDb()
  const transaction = db.transaction(RECORDS_STORE, 'readonly')
  const store = transaction.objectStore(RECORDS_STORE)
  const index = store.index('scopeEntity')
  const rows = await requestResult(
    index.getAll(IDBKeyRange.only(scopeEntityKey(scopeKey, entity))),
  ) as LocalRecord<T>[]
  await transactionDone(transaction)
  return rows.map((row) => row.value)
}

export async function replaceLocalEntity<T extends { ulid: string }>(
  scopeKey: string,
  entity: LocalEntityName,
  values: T[],
): Promise<void> {
  const db = await openDb()
  const transaction = db.transaction(RECORDS_STORE, 'readwrite')
  const store = transaction.objectStore(RECORDS_STORE)
  const index = store.index('scopeEntity')
  const range = IDBKeyRange.only(scopeEntityKey(scopeKey, entity))

  await new Promise<void>((resolve, reject) => {
    const cursorRequest = index.openKeyCursor(range)

    cursorRequest.onerror = () =>
      reject(cursorRequest.error ?? new Error('Unable to replace local cache.'))

    cursorRequest.onsuccess = () => {
      const cursor = cursorRequest.result
      if (!cursor) {
        resolve()
        return
      }
      store.delete(cursor.primaryKey)
      cursor.continue()
    }
  })

  for (const value of values) {
    const row: LocalRecord<T> = {
      key: compositeKey(scopeKey, entity, value.ulid),
      scopeEntity: scopeEntityKey(scopeKey, entity),
      scopeKey,
      entity,
      ulid: value.ulid,
      value,
    }
    store.put(row)
  }

  await transactionDone(transaction)
}

export async function upsertLocalEntity<T extends { ulid: string }>(
  scopeKey: string,
  entity: LocalEntityName,
  value: T,
): Promise<void> {
  const db = await openDb()
  const transaction = db.transaction(RECORDS_STORE, 'readwrite')
  const store = transaction.objectStore(RECORDS_STORE)
  const row: LocalRecord<T> = {
    key: compositeKey(scopeKey, entity, value.ulid),
    scopeEntity: scopeEntityKey(scopeKey, entity),
    scopeKey,
    entity,
    ulid: value.ulid,
    value,
  }
  store.put(row)
  await transactionDone(transaction)
}

export async function readLocalMeta<T>(key: string): Promise<T | null> {
  const db = await openDb()
  const transaction = db.transaction(META_STORE, 'readonly')
  const row = await requestResult(transaction.objectStore(META_STORE).get(key)) as
    | LocalMeta
    | undefined
  await transactionDone(transaction)
  return (row?.value as T | undefined) ?? null
}

export async function writeLocalMeta<T>(key: string, value: T): Promise<void> {
  const db = await openDb()
  const transaction = db.transaction(META_STORE, 'readwrite')
  transaction.objectStore(META_STORE).put({ key, value } satisfies LocalMeta)
  await transactionDone(transaction)
}
