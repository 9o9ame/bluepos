import { QueryClient, QueryClientProvider, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useContext, type ReactNode } from 'react'
import { fetchMe, login, logout, resendMfa as requestResendMfa, verifyMfa, type LoginInput, type MfaVerifyInput } from '../../api/auth'
import { ApiClientError } from '../../api/client'
import type { AuthSession } from '../../types/auth'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: false,
      refetchOnWindowFocus: false,
    },
  },
})

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
  const meQuery = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: fetchMe,
    retry: false,
  })

  const loginMutation = useMutation({
    mutationFn: login,
    onSuccess: (session) => {
      client.setQueryData(['auth', 'me'], session)
    },
  })

  const mfaMutation = useMutation({
    mutationFn: verifyMfa,
    onSuccess: (session) => {
      client.setQueryData(['auth', 'me'], session)
    },
  })

  const logoutMutation = useMutation({
    mutationFn: logout,
    onSuccess: () => {
      client.setQueryData(['auth', 'me'], null)
      client.clear()
    },
  })

  const authError = meQuery.error instanceof ApiClientError ? meQuery.error : null
  const unauthenticated = authError !== null && (authError.status === 401 || authError.key === 'SESSION_REVOKED')
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
