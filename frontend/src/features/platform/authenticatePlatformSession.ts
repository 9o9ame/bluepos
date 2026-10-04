import type { QueryClient } from '@tanstack/react-query'

const sessionKey = ['platform', 'me'] as const

export async function authenticatePlatformSession<T>(
  client: QueryClient,
  authenticate: () => Promise<T>,
  fetchSession: () => Promise<T>,
): Promise<T> {
  // Stop pre-login checks before rotating the server session and its cookies.
  await client.cancelQueries({ queryKey: sessionKey, exact: true })
  await authenticate()
  await client.cancelQueries({ queryKey: sessionKey, exact: true })

  // Navigation requires a session that the browser can actually send back.
  const user = await fetchSession()
  client.setQueryData(sessionKey, user)
  return user
}
