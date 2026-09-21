export function readCookie(name: string): string | null {
  const prefix = `${name}=`
  const match = document.cookie.split('; ').find((row) => row.startsWith(prefix))
  if (!match) {
    return null
  }

  return decodeURIComponent(match.slice(prefix.length))
}
