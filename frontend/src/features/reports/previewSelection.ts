/** Session-scoped report selections stay out of browser history and shared URLs.
 * Not a security boundary: APIs must validate RBAC, tenant and branch context. */
export function savePreviewSelection<T>(key: string, selection: T): void {
  sessionStorage.setItem('bluepos:report-selection:' + key, JSON.stringify(selection))
}

export function readPreviewSelection<T>(key: string): T | null {
  try {
    const value = sessionStorage.getItem('bluepos:report-selection:' + key)
    return value ? JSON.parse(value) as T : null
  } catch {
    return null
  }
}
