/**
 * Best-effort display of browser-supplied user-agent details.
 * User-Agent headers may be reduced or spoofed; never use for authorization.
 */
export function summarizeUserAgent(userAgent: string | null | undefined): string {
  if (!userAgent) return 'Unavailable'
  const ua = userAgent.toLowerCase()
  const os = /iphone|ipad|ipod/.test(ua) ? 'iOS / iPadOS'
    : /android/.test(ua) ? 'Android'
    : /windows nt/.test(ua) ? 'Windows'
    : /mac os x|macintosh/.test(ua) ? 'macOS'
    : /linux/.test(ua) ? 'Linux'
    : 'OS unknown'
  const browser = /edg\/|edga\//.test(ua) ? 'Edge'
    : /opr\/|opera/.test(ua) ? 'Opera'
    : /firefox\/|fxios\//.test(ua) ? 'Firefox'
    : /crios\/|chrome\//.test(ua) ? 'Chrome'
    : /safari\//.test(ua) ? 'Safari'
    : 'Browser unknown'
  return `${browser} · ${os}`
}
