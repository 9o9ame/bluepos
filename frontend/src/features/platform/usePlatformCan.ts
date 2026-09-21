import { usePlatformAuth } from './PlatformAuthProvider'

export function usePlatformCan(permission: string): boolean {
  const { user } = usePlatformAuth()
  return user?.permissions.includes(permission) ?? false
}
