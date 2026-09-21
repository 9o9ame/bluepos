import { QueryClient, QueryClientProvider, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createContext, useContext, useRef, useState, type ReactNode } from 'react'
import { ApiClientError } from '../../api/client'
import { PlatformStepUpMfaModal } from '../../components/platform/PlatformStepUpMfaModal'
import {
  fetchPlatformMe,
  platformChangePassword,
  platformConfirmMfa,
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

type StepUpState = {
  challengeUlid: string
  recoveryHint: string | null
  deliveryHint: string | null
}

type PlatformAuthContextValue = {
  user: PlatformUser | null
  isLoading: boolean
  login: (input: PlatformLoginInput) => Promise<void>
  verifyMfa: (input: PlatformMfaInput) => Promise<PlatformUser>
  resendMfa: (challengeUlid: string) => Promise<void>
  changePassword: (input: {
    current_password: string
    password: string
    password_confirmation: string
  }) => Promise<void>
  logout: () => Promise<void>
  withRecentMfa: <T>(action: () => Promise<T>) => Promise<T>
}

const PlatformAuthContext = createContext<PlatformAuthContextValue | null>(null)

function PlatformAuthState({ children }: { children: ReactNode }) {
  const client = useQueryClient()
  const meQuery = useQuery({
    queryKey: ['platform', 'me'],
    queryFn: fetchPlatformMe,
    retry: false,
  })
  const [stepUp, setStepUp] = useState<StepUpState | null>(null)
  const stepUpWaiter = useRef<{ resolve: () => void; reject: (error: Error) => void } | null>(null)

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

  function promptStepUp(err: ApiClientError): Promise<void> {
    const challengeUlid = typeof err.extra.challenge_ulid === 'string' ? err.extra.challenge_ulid : null
    if (!challengeUlid) {
      return Promise.reject(err)
    }
    setStepUp({
      challengeUlid,
      recoveryHint: typeof err.extra.recovery_hint === 'string' ? err.extra.recovery_hint : null,
      deliveryHint: typeof err.extra.delivery_hint === 'string' ? err.extra.delivery_hint : null,
    })
    return new Promise((resolve, reject) => {
      stepUpWaiter.current = { resolve, reject }
    })
  }

  const value: PlatformAuthContextValue = {
    user,
    isLoading: meQuery.isLoading,
    login: (input) => loginMutation.mutateAsync(input),
    verifyMfa: (input) => mfaMutation.mutateAsync(input),
    resendMfa: (challengeUlid) => platformResendMfa(challengeUlid),
    changePassword: async (input) => {
      await platformChangePassword(input)
      await client.invalidateQueries({ queryKey: ['platform', 'me'] })
    },
    logout: () => logoutMutation.mutateAsync(),
    withRecentMfa: async (action) => {
      try {
        return await action()
      } catch (err) {
        if (!(err instanceof ApiClientError) || err.key !== 'MFA_REQUIRED') {
          throw err
        }
        await promptStepUp(err)
        return await action()
      }
    },
  }

  return (
    <PlatformAuthContext.Provider value={value}>
      {children}
      {stepUp ? (
        <PlatformStepUpMfaModal
          recoveryHint={stepUp.recoveryHint}
          deliveryHint={stepUp.deliveryHint}
          onVerify={async (code) => {
            await platformConfirmMfa({ challenge_ulid: stepUp.challengeUlid, code })
            await client.invalidateQueries({ queryKey: ['platform', 'me'] })
            stepUpWaiter.current?.resolve()
            stepUpWaiter.current = null
            setStepUp(null)
          }}
          onResend={async () => {
            try {
              await platformResendMfa(stepUp.challengeUlid)
            } catch (err) {
              if (err instanceof ApiClientError && err.key === 'MFA_REQUIRED') {
                setStepUp({
                  challengeUlid: typeof err.extra.challenge_ulid === 'string' ? err.extra.challenge_ulid : stepUp.challengeUlid,
                  recoveryHint: typeof err.extra.recovery_hint === 'string' ? err.extra.recovery_hint : stepUp.recoveryHint,
                  deliveryHint: typeof err.extra.delivery_hint === 'string' ? err.extra.delivery_hint : stepUp.deliveryHint,
                })
                return
              }
              throw err
            }
          }}
          onCancel={() => {
            stepUpWaiter.current?.reject(new Error('Verification cancelled.'))
            stepUpWaiter.current = null
            setStepUp(null)
          }}
        />
      ) : null}
    </PlatformAuthContext.Provider>
  )
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
