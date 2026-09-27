import type { AppearancePreferences, InterfaceStyle, ThemePreference } from '../../types/auth'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

export type { InterfaceStyle, ThemePreference } from '../../types/auth'
export type ResolvedTheme = 'light' | 'dark'

type AppearanceContextValue = AppearancePreferences & {
  resolvedTheme: ResolvedTheme
  userScope: string | null
  setTheme: (theme: ThemePreference) => void
  setSkin: (skin: InterfaceStyle) => void
  setUserScope: (userUlid: string | null, serverPreferences?: AppearancePreferences | null) => void
  resetAppearance: () => void
}

const DEFAULT_PREFERENCES: AppearancePreferences = {
  theme: 'system',
  skin: 'classic',
}

const STORAGE_PREFIX = 'bluepos.appearance'

const AppearanceContext = createContext<AppearanceContextValue | null>(null)

function storageKey(userScope: string | null): string {
  return userScope ? `${STORAGE_PREFIX}.user.${userScope}` : `${STORAGE_PREFIX}.guest`
}

function isThemePreference(value: unknown): value is ThemePreference {
  return value === 'system' || value === 'light' || value === 'dark'
}

function isInterfaceStyle(value: unknown): value is InterfaceStyle {
  return value === 'classic' || value === 'hybrid' || value === 'advanced'
}

function readStoredPreferences(userScope: string | null): AppearancePreferences {
  if (typeof window === 'undefined') {
    return DEFAULT_PREFERENCES
  }

  try {
    const raw = window.localStorage.getItem(storageKey(userScope))
    if (!raw) return DEFAULT_PREFERENCES

    const parsed = JSON.parse(raw) as Partial<AppearancePreferences>

    return {
      theme: isThemePreference(parsed.theme) ? parsed.theme : DEFAULT_PREFERENCES.theme,
      skin: isInterfaceStyle(parsed.skin) ? parsed.skin : DEFAULT_PREFERENCES.skin,
    }
  } catch {
    return DEFAULT_PREFERENCES
  }
}

function getSystemTheme(): ResolvedTheme {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
    return 'light'
  }

  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

export function AppearanceProvider({ children }: { children: ReactNode }) {
  const [userScope, setUserScopeState] = useState<string | null>(null)
  const [preferences, setPreferences] = useState<AppearancePreferences>(() => readStoredPreferences(null))
  const [systemTheme, setSystemTheme] = useState<ResolvedTheme>(() => getSystemTheme())

  const resolvedTheme: ResolvedTheme =
    preferences.theme === 'system' ? systemTheme : preferences.theme

  useEffect(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
      return
    }

    const media = window.matchMedia('(prefers-color-scheme: dark)')

    const syncSystemTheme = () => {
      setSystemTheme(media.matches ? 'dark' : 'light')
    }

    syncSystemTheme()
    media.addEventListener('change', syncSystemTheme)

    return () => {
      media.removeEventListener('change', syncSystemTheme)
    }
  }, [])

  useEffect(() => {
    if (typeof document === 'undefined') return

    const root = document.documentElement
    root.dataset.themePreference = preferences.theme
    root.dataset.theme = resolvedTheme
    root.dataset.skin = preferences.skin
    root.style.colorScheme = resolvedTheme
  }, [preferences.theme, preferences.skin, resolvedTheme])

  useEffect(() => {
    if (typeof window === 'undefined') return

    window.localStorage.setItem(storageKey(userScope), JSON.stringify(preferences))
  }, [preferences, userScope])

  const setUserScope = useCallback(
    (userUlid: string | null, serverPreferences?: AppearancePreferences | null) => {
      setUserScopeState((current) => {
        const nextPreferences = serverPreferences ?? readStoredPreferences(userUlid)

        if (current !== userUlid || serverPreferences) {
          setPreferences(nextPreferences)
        }

        return userUlid
      })
    },
    [],
  )

  const setTheme = useCallback((theme: ThemePreference) => {
    setPreferences((current) => ({ ...current, theme }))
  }, [])

  const setSkin = useCallback((skin: InterfaceStyle) => {
    setPreferences((current) => ({ ...current, skin }))
  }, [])

  const resetAppearance = useCallback(() => {
    setPreferences(DEFAULT_PREFERENCES)
  }, [])

  const value = useMemo<AppearanceContextValue>(
    () => ({
      theme: preferences.theme,
      skin: preferences.skin,
      resolvedTheme,
      userScope,
      setTheme,
      setSkin,
      setUserScope,
      resetAppearance,
    }),
    [
      preferences.theme,
      preferences.skin,
      resolvedTheme,
      userScope,
      setTheme,
      setSkin,
      setUserScope,
      resetAppearance,
    ],
  )

  return <AppearanceContext.Provider value={value}>{children}</AppearanceContext.Provider>
}

export function useAppearance(): AppearanceContextValue {
  const value = useContext(AppearanceContext)

  if (!value) {
    throw new Error('useAppearance must be used within AppearanceProvider')
  }

  return value
}
