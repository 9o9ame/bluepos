import type { AuthSession, Branch } from '../types/auth'
import { apiFetch } from './client'

export function fetchBranches(): Promise<Branch[]> {
  return apiFetch<Branch[]>('/api/branches')
}

export function switchBranch(branchUlid: string): Promise<AuthSession> {
  return apiFetch<AuthSession>(`/api/branches/${branchUlid}/switch`, {
    method: 'POST',
  })
}


export function createBranch(payload: {
  code: string
  name: string
}): Promise<Branch> {
  return apiFetch<Branch>('/api/branches', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function updateBranch(
  branchUlid: string,
  payload: {
    code?: string
    name?: string
    status?: 'active' | 'inactive'
    is_default?: boolean
  },
): Promise<Branch> {
  return apiFetch<Branch>(`/api/branches/${branchUlid}`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  })
}
