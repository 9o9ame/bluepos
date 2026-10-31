import type { AppearancePreferences, AuthSession } from '../types/auth'
import { apiFetch, resetCsrf } from './client'

export type BrowserLocationInput = { location_latitude: number; location_longitude: number; location_accuracy: number }

export type LoginInput = BrowserLocationInput & {
  tenant_code: string
  username: string
  password: string
  remember?: boolean
}

export type ForgotPasswordInput = {
  tenant_code: string
  username: string
}

export type ResetPasswordInput = {
  tenant_code: string
  username: string
  token: string
  password: string
  password_confirmation: string
}

export type ChangePasswordInput = {
  current_password: string
  password: string
  password_confirmation: string
}

export type MfaVerifyInput = BrowserLocationInput & {
  challenge_ulid: string
  code: string
  trust_device?: boolean
  remember?: boolean
}

export type UpdateAppearanceInput = AppearancePreferences

export type UpdateAppearanceResponse = {
  appearance: AppearancePreferences
}

export function fetchMe(): Promise<AuthSession> {
  return apiFetch<AuthSession>('/api/auth/me')
}

export function updateAppearance(input: UpdateAppearanceInput): Promise<UpdateAppearanceResponse> {
  return apiFetch<UpdateAppearanceResponse>('/api/auth/appearance', {
    method: 'PATCH',
    body: JSON.stringify(input),
    busy: 'none',
  })
}

export function login(input: LoginInput): Promise<AuthSession> {
  return apiFetch<AuthSession>('/api/auth/login', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function forgotPassword(input: ForgotPasswordInput): Promise<{ ok: boolean; message: string }> {
  return apiFetch<{ ok: boolean; message: string }>('/api/auth/forgot-password', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function resetPassword(input: ResetPasswordInput): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>('/api/auth/reset-password', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function changePassword(input: ChangePasswordInput): Promise<{ ok: boolean }> {
  return apiFetch<{ ok: boolean }>('/api/auth/change-password', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function verifyMfa(input: MfaVerifyInput): Promise<AuthSession> {
  return apiFetch<AuthSession>('/api/auth/mfa/verify', {
    method: 'POST',
    body: JSON.stringify(input),
  })
}

export function resendMfa(challengeUlid: string): Promise<void> {
  return apiFetch<void>('/api/auth/mfa/resend', {
    method: 'POST',
    body: JSON.stringify({ challenge_ulid: challengeUlid }),
  })
}

export async function logout(): Promise<void> {
  await apiFetch<{ ok: boolean }>('/api/auth/logout', { method: 'POST' })
  resetCsrf()
}
