import { apiFetch, resetCsrf } from './client'
import type {
  Paginated,
  PlatformAuditEvent,
  PlatformDashboard,
  PlatformPlan,
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

export async function platformLogout(): Promise<void> {
  await apiFetch<{ ok: boolean }>('/api/platform/auth/logout', { method: 'POST' })
  resetCsrf()
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

export function fetchPlatformAdmins(): Promise<PlatformUser[]> {
  return apiFetch<PlatformUser[]>('/api/platform/admins')
}

export function createPlatformAdmin(input: {
  name: string
  email: string
  password?: string
}): Promise<PlatformUser & { temporary_password: string | null }> {
  return apiFetch('/api/platform/admins', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function resetTenantAdmin(tenantUlid: string): Promise<{
  username: string
  temporary_password: string
}> {
  return apiFetch(`/api/platform/tenants/${tenantUlid}/admins/reset`, { method: 'POST' })
}
