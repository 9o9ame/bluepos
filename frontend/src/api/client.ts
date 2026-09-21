import { readCookie } from '../utils/cookies'

const API_BASE = import.meta.env.VITE_API_URL ?? ''

let csrfReady = false

export class ApiClientError extends Error {
  readonly key: string
  readonly status: number
  readonly fields?: Record<string, string[]>
  readonly extra: Record<string, unknown>

  constructor(
    key: string,
    message: string,
    status: number,
    fields?: Record<string, string[]>,
    extra: Record<string, unknown> = {},
  ) {
    super(message)
    this.name = 'ApiClientError'
    this.key = key
    this.status = status
    this.fields = fields
    this.extra = extra
  }
}

export function resetCsrf(): void {
  csrfReady = false
}

export async function ensureCsrfCookie(): Promise<void> {
  if (csrfReady) {
    return
  }

  const response = await fetch(`${API_BASE}/sanctum/csrf-cookie`, {
    method: 'GET',
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) {
    throw new ApiClientError('CSRF_INIT_FAILED', 'Unable to initialize a secure session.', response.status)
  }

  csrfReady = true
}

export async function apiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase()
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
    await ensureCsrfCookie()
  }

  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('X-Requested-With', 'XMLHttpRequest')

  const xsrf = readCookie('XSRF-TOKEN')
  if (xsrf) {
    headers.set('X-XSRF-TOKEN', xsrf)
  }

  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  const response = await fetch(`${API_BASE}${path}`, {
    ...init,
    credentials: 'include',
    headers,
  })

  if (response.status === 204) {
    return undefined as T
  }

  const payload = (await response.json().catch(() => ({}))) as {
    error?: { key?: string; message?: string; fields?: Record<string, string[]>; [key: string]: unknown }
  }

  if (!response.ok) {
    const { key, message, fields, ...extra } = payload.error ?? {}
    throw new ApiClientError(
      (key as string | undefined) ?? 'SERVER_ERROR',
      (message as string | undefined) ?? 'Request failed.',
      response.status,
      fields,
      extra,
    )
  }

  return payload as T
}
