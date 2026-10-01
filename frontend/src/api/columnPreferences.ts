import { apiFetch } from './client'
import type { GridColumnState } from '../features/gridLayout/columnCatalog'

export type ColumnPreferencePayload = {
  screen_key: string
  scope: 'user' | 'role' | 'default'
  role_ulid: string | null
  columns: GridColumnState[]
}

export async function fetchColumnPreferences(screenKey: string): Promise<ColumnPreferencePayload> {
  const response = await apiFetch<{ data: ColumnPreferencePayload }>(
    `/api/column-preferences/${encodeURIComponent(screenKey)}`,
    { busy: 'none' },
  )
  return response.data
}

export async function saveColumnPreferences(
  screenKey: string,
  body: {
    scope: 'user' | 'role'
    role_ulid?: string | null
    columns: GridColumnState[]
  },
): Promise<ColumnPreferencePayload> {
  const response = await apiFetch<{ data: ColumnPreferencePayload }>(
    `/api/column-preferences/${encodeURIComponent(screenKey)}`,
    {
      method: 'PUT',
      body: JSON.stringify(body),
      busy: 'none',
    },
  )
  return response.data
}
