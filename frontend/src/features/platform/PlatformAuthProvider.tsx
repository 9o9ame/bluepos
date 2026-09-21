import { QueryClient, QueryClientProvider, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useContext, type ReactNode } from 'react'
import { ApiClientError } from '../../api/client'
import {
  fetchPlatformMe,
  platformLogin,
  platformLogout,
  platformResendMfa,
  platformVerifyMfa,
  type PlatformLoginInput,
  type PlatformMfaInput,
} from '../../api/platform'
import type { PlatformUser } from '../../types/platform'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: false,
      refetchOnWindowFocus: false,
    },
  },
})

type PlatformAuthContextValue = {
  user: PlatformUser | null
  isLoading: boolean
  login: (input: PlatformLoginInput) => Promise<void>
  verifyMfa: (input: PlatformMfaInput) => Promise<PlatformUser>
  resendMfa: (challengeUlid: string) => Promise<void>
  logout: () => Promise<void>
}

const PlatformAuthContext = createContext<PlatformAuthContextValue | null>(null)

function PlatformAuthState({ children }: { children: ReactNode }) {
  const client = useQueryClient()
  const meQuery = useQuery({
    queryKey: ['platform', 'me'],
    queryFn: fetchPlatformMe,
    retry: false,
  })

  const loginMutation = useMutation({
    mutationFn: platformLogin,
  })

  const mfaMutation = useMutation({
    mutationFn: platformVerifyMfa,
    onSuccess: (user) => {
      client.setQueryData(['platform', 'me'], user)
    },
  })

  const logoutMutation = useMutation({
    mutationFn: platformLogout,
    onSuccess: () => {
      client.setQueryData(['platform', 'me'], null)
      client.clear()
    },
  })

  const authError = meQuery.error instanceof ApiClientError ? meQuery.error : null
  const unauthenticated = authError !== null && (authError.status === 401 || authError.key === 'SESSION_REVOKED')
  const user = unauthenticated ? null : (meQuery.data ?? null)

  const value: PlatformAuthContextValue = {
    user,
    isLoading: meQuery.isLoading,
    login: (input) => loginMutation.mutateAsync(input),
    verifyMfa: (input) => mfaMutation.mutateAsync(input),
    resendMfa: (challengeUlid) => platformResendMfa(challengeUlid),
    logout: () => logoutMutation.mutateAsync(),
  }

  return <PlatformAuthContext.Provider value={value}>{children}</PlatformAuthContext.Provider>
}

export function PlatformAuthProvider({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={queryClient}>
      <PlatformAuthState>{children}</PlatformAuthState>
    </QueryClientProvider>
  )
}

export function usePlatformAuth(): PlatformAuthContextValue {
  const value = useContext(PlatformAuthContext)
  if (!value) {
    throw new Error('usePlatformAuth must be used within PlatformAuthProvider')
  }
  return value
}
