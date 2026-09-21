import { useAuth } from './AuthProvider'

export function useCan(permission: string): boolean {
  const { session } = useAuth()
  return Boolean(session?.permissions.includes(permission))
}

export function useEntitled(feature: string): boolean {
  const { session } = useAuth()
  const features = session?.entitlements?.features
  if (!features) {
    return true
  }
  return features.includes(feature)
}
