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
