import { readCookie } from '../utils/cookies'
import { apiBusy } from '../feedback/apiBusy'

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

type ApiFetchOptions = RequestInit & {
  /** Override busy overlay: block = fullscreen, fetch = top bar, none = silent */
  busy?: 'block' | 'fetch' | 'none'
}

export async function apiFetch<T>(path: string, init: ApiFetchOptions = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase()
  if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
    await ensureCsrfCookie()
  }

  const { busy: busyMode, ...requestInit } = init
  const trackBusy =
    busyMode ??
    (path.includes('/sanctum/csrf-cookie') || path.includes('/api/auth/me')
      ? 'none'
      : ['GET', 'HEAD', 'OPTIONS'].includes(method)
        ? 'fetch'
        : 'block')

  if (trackBusy !== 'none') {
    apiBusy.begin(trackBusy)
  }

  try {
    const headers = new Headers(requestInit.headers)
    headers.set('Accept', 'application/json')
    headers.set('X-Requested-With', 'XMLHttpRequest')

    const xsrf = readCookie('XSRF-TOKEN')
    if (xsrf) {
      headers.set('X-XSRF-TOKEN', xsrf)
    }

    if (requestInit.body && !headers.has('Content-Type') && !(requestInit.body instanceof FormData)) {
      headers.set('Content-Type', 'application/json')
    }

    const response = await fetch(`${API_BASE}${path}`, {
      ...requestInit,
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
  } finally {
    if (trackBusy !== 'none') {
      apiBusy.end(trackBusy)
    }
  }
}


export async function apiDownload(path: string, filename: string): Promise<void> {
  apiBusy.begin('fetch')

  try {
    const headers = new Headers()
    headers.set('Accept', 'text/csv,application/octet-stream;q=0.9,*/*;q=0.8')
    headers.set('X-Requested-With', 'XMLHttpRequest')

    const response = await fetch(`${API_BASE}${path}`, {
      method: 'GET',
      credentials: 'include',
      headers,
    })

    if (!response.ok) {
      const payload = (await response.json().catch(() => ({}))) as {
        error?: { key?: string; message?: string; fields?: Record<string, string[]>; [key: string]: unknown }
      }
      const { key, message, fields, ...extra } = payload.error ?? {}
      throw new ApiClientError(
        (key as string | undefined) ?? 'SERVER_ERROR',
        (message as string | undefined) ?? 'Download failed.',
        response.status,
        fields,
        extra,
      )
    }

    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)
  } finally {
    apiBusy.end('fetch')
  }
}
