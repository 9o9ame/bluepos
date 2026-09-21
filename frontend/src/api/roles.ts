import type { Permission, RoleSummary } from '../types/auth'
import { apiFetch } from './client'

export function fetchPermissions(): Promise<Permission[]> {
  return apiFetch<Permission[]>('/api/permissions')
}

export function fetchRoles(): Promise<RoleSummary[]> {
  return apiFetch<RoleSummary[]>('/api/roles')
}

export function fetchRole(ulid: string): Promise<RoleSummary> {
  return apiFetch<RoleSummary>(`/api/roles/${ulid}`)
}

export function createRole(input: {
  name: string
  code: string
  description?: string
  branch_access?: 'all_branches' | 'selected_branches'
}): Promise<RoleSummary> {
  return apiFetch<RoleSummary>('/api/roles', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function saveRolePermissions(ulid: string, permissions: string[]): Promise<RoleSummary> {
  return apiFetch<RoleSummary>(`/api/roles/${ulid}/permissions`, {
    method: 'PUT',
    body: JSON.stringify({ permissions }),
  })
}

export function deleteRole(ulid: string): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>(`/api/roles/${ulid}`, { method: 'DELETE' })
}
