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

export type TenantLoginHistory = {
  events: Array<{
    ulid: string
    event: 'LOGIN_SUCCESS' | 'LOGIN_FAILURE' | 'LOGIN_BLOCKED'
    ip_address: string | null
    user_agent: string | null
    occurred_at: string
    membership_ulid: string | null
    username: string | null
    device_ulid: string | null
    device_name: string | null
  }>
  pagination: { page: number; last_page: number; total: number }
}

export function fetchTenantLoginHistory(page = 1): Promise<TenantLoginHistory> {
  return apiFetch<TenantLoginHistory>(`/api/security/login-history?page=${page}`)
}
