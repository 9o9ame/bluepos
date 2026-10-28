import { QueryClient, QueryClientProvider, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { fetchMe, login, logout, resendMfa as requestResendMfa, verifyMfa, type LoginInput, type MfaVerifyInput } from '../../api/auth'
import { ApiClientError, subscribeTenantSessionInvalidation } from '../../api/client'
import type { AuthSession } from '../../types/auth'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: false,
      refetchOnWindowFocus: false,
    },
  },
})


// Revoked users must not retain previously synchronized business records offline.
// Clear the existing local-data stores, rather than relying on React Query memory.
function clearOfflineBusinessData(): void {
  if (typeof indexedDB === 'undefined') return
  const request = indexedDB.open('bluepos-local-data')
  request.onupgradeneeded = () => {
    // Do not create an empty database merely to clear one.
    request.transaction?.abort()
  }
  request.onsuccess = () => {
    const db = request.result
    const stores = ['records', 'meta'].filter(name => db.objectStoreNames.contains(name))
    if (stores.length === 0) {
      db.close()
      return
    }
    const transaction = db.transaction(stores, 'readwrite')
    stores.forEach(name => transaction.objectStore(name).clear())
    transaction.oncomplete = () => db.close()
    transaction.onerror = () => db.close()
    transaction.onabort = () => db.close()
  }
}

type AuthContextValue = {
  session: AuthSession | null
  isLoading: boolean
  login: (input: LoginInput) => Promise<AuthSession>
  verifyMfa: (input: MfaVerifyInput) => Promise<AuthSession>
  resendMfa: (challengeUlid: string) => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

function AuthStateProvider({ children }: { children: ReactNode }) {
  const client = useQueryClient()
  const [sessionInvalidated, setSessionInvalidated] = useState(false)
  useEffect(() => subscribeTenantSessionInvalidation(() => {
    setSessionInvalidated(true)
    clearOfflineBusinessData()
    // Clearing all tenant queries prevents revoked users from browsing stale
    // sensitive records; a fresh login creates a new authenticated context.
    client.clear()
    client.setQueryData(['auth', 'me'], null)
  }), [client])
  const meQuery = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: fetchMe,
    retry: false,
  })

  const loginMutation = useMutation({
    mutationFn: login,
    onSuccess: (session) => {
      client.clear()
      setSessionInvalidated(false)
      client.setQueryData(['auth', 'me'], session)
    },
  })

  const mfaMutation = useMutation({
    mutationFn: verifyMfa,
    onSuccess: (session) => {
      client.clear()
      setSessionInvalidated(false)
      client.setQueryData(['auth', 'me'], session)
    },
  })

  const logoutMutation = useMutation({
    mutationFn: logout,
    onSuccess: () => {
      setSessionInvalidated(true)
      client.clear()
      client.setQueryData(['auth', 'me'], null)
    },
  })

  const authError = meQuery.error instanceof ApiClientError ? meQuery.error : null
  const unauthenticated = sessionInvalidated || (authError !== null && (authError.status === 401 || authError.key === 'SESSION_REVOKED' || authError.key === 'DEVICE_NOT_APPROVED' || authError.key === 'DEVICE_REVOKED'))
  const session = unauthenticated ? null : (meQuery.data ?? null)

  const value: AuthContextValue = {
    session,
    isLoading: meQuery.isLoading,
    login: (input) => loginMutation.mutateAsync(input),
    verifyMfa: (input) => mfaMutation.mutateAsync(input),
    resendMfa: (challengeUlid) => requestResendMfa(challengeUlid),
    logout: () => logoutMutation.mutateAsync(),
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function AuthProvider({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={queryClient}>
      <AuthStateProvider>{children}</AuthStateProvider>
    </QueryClientProvider>
  )
}

export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext)
  if (!value) {
    throw new Error('useAuth must be used within AuthProvider')
  }
  return value
}
