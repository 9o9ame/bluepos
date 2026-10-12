import { apiFetch } from './client'

export type CustomQueryDataset = {
  name: string
  scope: string
  columns: string[]
}

export type CustomQueryCatalog = {
  datasets: CustomQueryDataset[]
  syntax: string
  max_rows: number
}

export type CustomQueryRunResult = {
  dataset: string
  columns: string[]
  rows: Record<string, unknown>[]
  row_count: number
  truncated: boolean
  limit: number
  elapsed_ms: number
}

export type SavedCustomQuery = {
  ulid: string
  title: string
  query: string
  updated_at: string | null
}

export function fetchCustomQueryCatalog(): Promise<CustomQueryCatalog> {
  return apiFetch<CustomQueryCatalog>('/api/custom-query/catalog')
}

export function runCustomQuery(query: string): Promise<CustomQueryRunResult> {
  return apiFetch<CustomQueryRunResult>('/api/custom-query/run', {
    method: 'POST',
    body: JSON.stringify({ query }),
  })
}

export function fetchSavedCustomQueries(): Promise<SavedCustomQuery[]> {
  return apiFetch<SavedCustomQuery[]>('/api/custom-query/saved')
}

export function saveCustomQuery(input: { title: string; query: string }): Promise<SavedCustomQuery> {
  return apiFetch<SavedCustomQuery>('/api/custom-query/saved', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function updateCustomQuery(
  ulid: string,
  input: { title: string; query: string },
): Promise<SavedCustomQuery> {
  return apiFetch<SavedCustomQuery>(`/api/custom-query/saved/${encodeURIComponent(ulid)}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export function deleteCustomQuery(ulid: string): Promise<{ ok: true }> {
  return apiFetch<{ ok: true }>(`/api/custom-query/saved/${encodeURIComponent(ulid)}`, {
    method: 'DELETE',
  })
}
