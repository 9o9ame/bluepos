import type { AuthSession } from '../types/auth'
import { apiFetch, resetCsrf } from './client'

export type LoginInput = {
  tenant_code: string
  username: string
  password: string
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

export type MfaVerifyInput = {
  challenge_ulid: string
  code: string
  trust_device?: boolean
}

export function fetchMe(): Promise<AuthSession> {
  return apiFetch<AuthSession>('/api/auth/me')
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

export async function logout(): Promise<void> {
  await apiFetch<{ ok: boolean }>('/api/auth/logout', { method: 'POST' })
  resetCsrf()
}
