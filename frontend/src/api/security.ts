import { apiFetch } from './client'
import type { SecuritySession } from '../types/security'

export function fetchSecuritySessions(): Promise<SecuritySession[]> {
  return apiFetch<SecuritySession[]>('/api/security/sessions')
}

export function revokeSecuritySession(sessionUlid: string): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>(`/api/security/sessions/${sessionUlid}`, {
    method: 'DELETE',
  })
}

export function revokeOtherSecuritySessions(): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>('/api/security/sessions/others', {
    method: 'DELETE',
  })
}
