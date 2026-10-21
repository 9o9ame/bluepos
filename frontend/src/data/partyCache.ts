import type { Party, PartyListFilter } from '../api/parties'
import {
  readLocalEntity,
  replaceLocalEntity,
} from './localDb'

const partyMemory = new Map<string, Party[]>()

export function partyScopeKey(tenantUlid: string, filter: PartyListFilter) {
  return `tenant:${tenantUlid}:party-filter:${filter}`
}

export async function readCachedParties(
  tenantUlid: string,
  filter: PartyListFilter,
): Promise<Party[]> {
  const key = partyScopeKey(tenantUlid, filter)
  const memory = partyMemory.get(key)
  if (memory) return memory

  try {
    const rows = await readLocalEntity<Party>(key, 'parties')
    partyMemory.set(key, rows)
    return rows
  } catch {
    return []
  }
}

export async function replaceCachedParties(
  tenantUlid: string,
  filter: PartyListFilter,
  rows: Party[],
): Promise<void> {
  const key = partyScopeKey(tenantUlid, filter)
  partyMemory.set(key, rows)

  try {
    await replaceLocalEntity(key, 'parties', rows)
  } catch {
    // IndexedDB failure must not block the online workflow.
  }
}
