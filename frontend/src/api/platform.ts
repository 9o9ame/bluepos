import { apiFetch, resetCsrf } from './client'
import type {
  Paginated,
  PlatformAuditEvent,
  PlatformDashboard,
  PlatformDevice,
  PlatformPermission,
  PlatformPlan,
  PlatformRole,
  PlatformSession,
  PlatformSettings,
  PlatformTenant,
  PlatformUser,
} from '../types/platform'

export type PlatformLoginInput = {
  email: string
  password: string
}

export type PlatformMfaInput = {
  challenge_ulid: string
  code: string
  trust_device?: boolean
}

export function platformResendMfa(challengeUlid: string): Promise<void> {
  return apiFetch<void>('/api/platform/auth/mfa/resend', {
    method: 'POST',
    body: JSON.stringify({ challenge_ulid: challengeUlid }),
  })
}

export function fetchPlatformMe(): Promise<PlatformUser> {
  return apiFetch<PlatformUser>('/api/platform/auth/me')
}

export function platformLogin(input: PlatformLoginInput): Promise<void> {
  return apiFetch<void>('/api/platform/auth/login', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function platformVerifyMfa(input: PlatformMfaInput): Promise<PlatformUser> {
  return apiFetch<PlatformUser>('/api/platform/auth/mfa/verify', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function platformConfirmMfa(input: PlatformMfaInput): Promise<PlatformUser> {
  return apiFetch<PlatformUser>('/api/platform/auth/mfa/confirm', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export async function platformLogout(): Promise<void> {
  await apiFetch<{ ok: boolean }>('/api/platform/auth/logout', { method: 'POST' })
  resetCsrf()
}

export function platformChangePassword(input: {
  current_password: string
  password: string
  password_confirmation: string
}): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>('/api/platform/auth/change-password', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function fetchPlatformDashboard(): Promise<PlatformDashboard> {
  return apiFetch<PlatformDashboard>('/api/platform/dashboard')
}

export function fetchPlatformTenants(page = 1): Promise<Paginated<PlatformTenant>> {
  return apiFetch<Paginated<PlatformTenant>>(`/api/platform/tenants?page=${page}`)
}

export function fetchPlatformTenant(ulid: string): Promise<PlatformTenant> {
  return apiFetch<PlatformTenant>(`/api/platform/tenants/${ulid}`)
}

export function createPlatformTenant(input: Record<string, unknown>): Promise<PlatformTenant> {
  return apiFetch<PlatformTenant>('/api/platform/tenants', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function updatePlatformTenant(ulid: string, input: Record<string, unknown>): Promise<PlatformTenant> {
  return apiFetch<PlatformTenant>(`/api/platform/tenants/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export function deletePlatformTenant(ulid: string, reason?: string): Promise<PlatformTenant> {
  return apiFetch<PlatformTenant>(`/api/platform/tenants/${ulid}`, {
    method: 'DELETE',
    body: JSON.stringify({ reason }),
  })
}

export function suspendPlatformTenant(ulid: string, reason: string): Promise<PlatformTenant> {
  return apiFetch<PlatformTenant>(`/api/platform/tenants/${ulid}/suspend`, {
    method: 'POST',
    body: JSON.stringify({ reason }),
  })
}

export function activatePlatformTenant(ulid: string): Promise<PlatformTenant> {
  return apiFetch<PlatformTenant>(`/api/platform/tenants/${ulid}/activate`, { method: 'POST' })
}

export function assignPlatformSubscription(
  tenantUlid: string,
  input: { plan_ulid: string; status: string },
): Promise<unknown> {
  return apiFetch(`/api/platform/tenants/${tenantUlid}/subscription`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })
}

export function upsertTenantFeature(
  tenantUlid: string,
  featureKey: string,
  enabled: boolean,
  reason: string,
): Promise<unknown> {
  return apiFetch(`/api/platform/tenants/${tenantUlid}/features/${featureKey}`, {
    method: 'PUT',
    body: JSON.stringify({ enabled, reason }),
  })
}

export function upsertTenantLimit(
  tenantUlid: string,
  limitKey: string,
  value: number | null,
  reason: string,
): Promise<{ warning?: string | null }> {
  return apiFetch(`/api/platform/tenants/${tenantUlid}/limits/${limitKey}`, {
    method: 'PUT',
    body: JSON.stringify({ value, reason }),
  })
}

export function fetchPlatformPlans(): Promise<PlatformPlan[]> {
  return apiFetch<PlatformPlan[]>('/api/platform/plans')
}

export function fetchPlatformPlan(ulid: string): Promise<PlatformPlan> {
  return apiFetch<PlatformPlan>(`/api/platform/plans/${ulid}`)
}

export function createPlatformPlan(input: { code: string; name: string; description?: string }): Promise<PlatformPlan> {
  return apiFetch<PlatformPlan>('/api/platform/plans', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function updatePlatformPlan(ulid: string, input: Record<string, unknown>): Promise<PlatformPlan> {
  return apiFetch<PlatformPlan>(`/api/platform/plans/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export function syncPlanFeatures(ulid: string, features: Record<string, boolean>): Promise<PlatformPlan> {
  return apiFetch<PlatformPlan>(`/api/platform/plans/${ulid}/features`, {
    method: 'PUT',
    body: JSON.stringify({ features }),
  })
}

export function syncPlanLimits(ulid: string, limits: Record<string, number | null>): Promise<PlatformPlan> {
  return apiFetch<PlatformPlan>(`/api/platform/plans/${ulid}/limits`, {
    method: 'PUT',
    body: JSON.stringify({ limits }),
  })
}

export function fetchFeatureCatalog(): Promise<{
  features: Array<{ key: string; name: string; module: string; description: string | null }>
}> {
  return apiFetch('/api/platform/features')
}

export function fetchPlatformAudit(page = 1): Promise<Paginated<PlatformAuditEvent>> {
  return apiFetch<Paginated<PlatformAuditEvent>>(`/api/platform/security/audit?page=${page}`)
}

export function fetchPlatformUsers(): Promise<PlatformUser[]> {
  return apiFetch<PlatformUser[]>('/api/platform/users')
}

export function fetchPlatformUser(ulid: string): Promise<PlatformUser> {
  return apiFetch<PlatformUser>(`/api/platform/users/${ulid}`)
}

export function createPlatformUser(input: {
  name: string
  email: string
  password?: string
  must_change_password?: boolean
  status?: string
  role_ulids?: string[]
  reason?: string
}): Promise<PlatformUser> {
  return apiFetch('/api/platform/users', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function updatePlatformUser(ulid: string, input: Record<string, unknown>): Promise<PlatformUser> {
  return apiFetch(`/api/platform/users/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export function syncPlatformUserRoles(
  ulid: string,
  roleUlids: string[],
  reason?: string,
): Promise<PlatformUser> {
  return apiFetch(`/api/platform/users/${ulid}/roles`, {
    method: 'PUT',
    body: JSON.stringify({ role_ulids: roleUlids, reason }),
  })
}

export function activatePlatformUser(ulid: string): Promise<PlatformUser> {
  return apiFetch(`/api/platform/users/${ulid}/activate`, { method: 'POST' })
}

export function deactivatePlatformUser(ulid: string): Promise<PlatformUser> {
  return apiFetch(`/api/platform/users/${ulid}/deactivate`, { method: 'POST' })
}

export function resetPlatformUserPassword(ulid: string): Promise<PlatformUser> {
  return apiFetch(`/api/platform/users/${ulid}/reset-password`, { method: 'POST' })
}

export function forceLogoutPlatformUser(ulid: string): Promise<PlatformUser> {
  return apiFetch(`/api/platform/users/${ulid}/force-logout`, { method: 'POST' })
}

export function fetchPlatformRoles(): Promise<PlatformRole[]> {
  return apiFetch<PlatformRole[]>('/api/platform/roles')
}

export function fetchPlatformRole(ulid: string): Promise<PlatformRole> {
  return apiFetch<PlatformRole>(`/api/platform/roles/${ulid}`)
}

export function createPlatformRole(input: {
  code: string
  name: string
  description?: string
}): Promise<PlatformRole> {
  return apiFetch('/api/platform/roles', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function updatePlatformRole(ulid: string, input: Record<string, unknown>): Promise<PlatformRole> {
  return apiFetch(`/api/platform/roles/${ulid}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export function deletePlatformRole(ulid: string): Promise<{ ok: boolean }> {
  return apiFetch(`/api/platform/roles/${ulid}`, { method: 'DELETE' })
}

export function syncPlatformRolePermissions(ulid: string, permissions: string[]): Promise<PlatformRole> {
  return apiFetch(`/api/platform/roles/${ulid}/permissions`, {
    method: 'PUT',
    body: JSON.stringify({ permissions }),
  })
}

export function fetchPlatformPermissions(query?: { q?: string; module?: string }): Promise<PlatformPermission[]> {
  const params = new URLSearchParams()
  if (query?.q) {
    params.set('q', query.q)
  }
  if (query?.module) {
    params.set('module', query.module)
  }
  const suffix = params.size > 0 ? `?${params.toString()}` : ''
  return apiFetch<PlatformPermission[]>(`/api/platform/permissions${suffix}`)
}

export function fetchPlatformSettings(): Promise<PlatformSettings> {
  return apiFetch<PlatformSettings>('/api/platform/settings')
}

export function updatePlatformProfile(input: {
  name?: string
  email?: string
  current_password?: string
}): Promise<PlatformUser> {
  return apiFetch('/api/platform/auth/profile', {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
}

export function fetchPlatformSessions(): Promise<PlatformSession[]> {
  return apiFetch<PlatformSession[]>('/api/platform/auth/sessions')
}

export function revokePlatformSession(ulid: string): Promise<{ ok: boolean }> {
  return apiFetch(`/api/platform/auth/sessions/${ulid}`, { method: 'DELETE' })
}

export function revokeOtherPlatformSessions(): Promise<{ ok: boolean }> {
  return apiFetch('/api/platform/auth/sessions/others', { method: 'DELETE' })
}

export function fetchPlatformDevices(): Promise<PlatformDevice[]> {
  return apiFetch<PlatformDevice[]>('/api/platform/auth/devices')
}

export function revokePlatformDevice(ulid: string): Promise<{ ok: boolean }> {
  return apiFetch(`/api/platform/auth/devices/${ulid}`, { method: 'DELETE' })
}

export function resetTenantAdmin(
  tenantUlid: string,
  membershipUlid?: string,
): Promise<{
  membership_ulid: string
  username: string
  must_change_password: boolean
  temporary_password: string
}> {
  return apiFetch(`/api/platform/tenants/${tenantUlid}/admins/reset`, {
    method: 'POST',
    body: JSON.stringify(membershipUlid ? { membership_ulid: membershipUlid } : {}),
  })
}

export function forceLogoutTenantAdmin(tenantUlid: string, membershipUlid: string): Promise<{ ok: boolean }> {
  return apiFetch(`/api/platform/tenants/${tenantUlid}/admins/${membershipUlid}/force-logout`, {
    method: 'POST',
  })
}

export function deactivateTenantAdmin(tenantUlid: string, membershipUlid: string): Promise<{ ok: boolean }> {
  return apiFetch(`/api/platform/tenants/${tenantUlid}/admins/${membershipUlid}/deactivate`, {
    method: 'POST',
  })
}
