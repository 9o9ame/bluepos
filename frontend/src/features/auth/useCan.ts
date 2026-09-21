import { useAuth } from './AuthProvider'

export function useCan(permission: string): boolean {
  const { session } = useAuth()
  return Boolean(session?.permissions.includes(permission))
}
