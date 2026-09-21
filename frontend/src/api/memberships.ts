import type { Membership } from '../types/auth'
import { apiFetch } from './client'

export function fetchMemberships(): Promise<Membership[]> {
  return apiFetch<Membership[]>('/api/memberships')
}

export function createMembership(input: {
  name: string
  username: string
  recovery_email?: string
  password: string
  must_change_password?: boolean
  roles: string[]
  branches: string[]
}): Promise<Membership> {
  return apiFetch<Membership>('/api/memberships', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function saveMembershipRoles(ulid: string, roles: string[]): Promise<Membership> {
  return apiFetch<Membership>(`/api/memberships/${ulid}/roles`, {
    method: 'PUT',
    body: JSON.stringify({ roles }),
  })
}

export function saveMembershipBranches(ulid: string, branches: string[]): Promise<Membership> {
  return apiFetch<Membership>(`/api/memberships/${ulid}/branches`, {
    method: 'PUT',
    body: JSON.stringify({ branches }),
  })
}

export function deactivateMembership(ulid: string): Promise<Membership> {
  return apiFetch<Membership>(`/api/memberships/${ulid}/deactivate`, {
    method: 'POST',
  })
}

export function activateMembership(ulid: string): Promise<Membership> {
  return apiFetch<Membership>(`/api/memberships/${ulid}/activate`, {
    method: 'POST',
  })
}

export function resetMembershipPassword(ulid: string, password: string): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>(`/api/memberships/${ulid}/reset-password`, {
    method: 'POST',
    body: JSON.stringify({ password }),
  })
}

export function forceLogoutMembership(ulid: string): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>(`/api/memberships/${ulid}/force-logout`, {
    method: 'POST',
  })
}
