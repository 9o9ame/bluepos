import { apiFetch } from './client'

export type PartyTypeApi = 'vendor' | 'customer'

export type Party = {
  ulid: string
  party_type: PartyTypeApi
  code: string
  name: string
  deals_in: string | null
  contact_person: string | null
  mobile: string | null
  mobile_secondary: string | null
  phone: string | null
  phone_secondary: string | null
  email: string | null
  address: string | null
  billing_address: string | null
  is_active: boolean
}

export type PartyListFilter = 'all' | 'vendor' | 'customer' | 'account' | 'salesman'

export type PartyPayload = {
  party_type: PartyTypeApi
  code: string
  name: string
  deals_in?: string | null
  contact_person?: string | null
  mobile?: string | null
  mobile_secondary?: string | null
  phone?: string | null
  phone_secondary?: string | null
  email?: string | null
  address?: string | null
  billing_address?: string | null
  is_active?: boolean
}

export function fetchParties(type: PartyListFilter = 'all') {
  const query = type && type !== 'all' ? `?type=${encodeURIComponent(type)}` : '?type=all'
  return apiFetch<{ data: Party[] }>(`/api/parties${query}`).then((res) => res.data)
}

export function fetchParty(ulid: string, type: PartyTypeApi) {
  return apiFetch<Party>(`/api/parties/${ulid}?type=${encodeURIComponent(type)}`)
}

export function createParty(payload: PartyPayload) {
  return apiFetch<Party>('/api/parties', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateParty(ulid: string, payload: Partial<PartyPayload> & { party_type: PartyTypeApi }) {
  return apiFetch<Party>(`/api/parties/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}

export function deactivateParty(ulid: string, type: PartyTypeApi) {
  return apiFetch<{ ok: boolean; archived: boolean }>(
    `/api/parties/${ulid}?type=${encodeURIComponent(type)}`,
    { method: 'DELETE' },
  )
}
