import type { AppearancePreferences, DensityPreference, FontPreference, InterfaceStyle, PrimaryTheme, RadiusPreference, ShadowPreference, ThemePreference } from '../../types/auth'
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

export type { DensityPreference, FontPreference, InterfaceStyle, PrimaryTheme, RadiusPreference, ShadowPreference, ThemePreference } from '../../types/auth'
export type ResolvedTheme = 'light' | 'dark'

type AppearanceContextValue = AppearancePreferences & {
  resolvedTheme: ResolvedTheme
  userScope: string | null
  setTheme: (theme: ThemePreference) => void
  setSkin: (skin: InterfaceStyle) => void
  setFont: (font: FontPreference) => void
  setPrimaryTheme: (primaryTheme: PrimaryTheme) => void
  setDensity: (density: DensityPreference) => void
  setRadius: (radius: RadiusPreference) => void
  setShadow: (shadow: ShadowPreference) => void
  setAnimations: (animations: boolean) => void
  setUserScope: (userUlid: string | null, serverPreferences?: AppearancePreferences | null) => void
  resetAppearance: () => void
}

const DEFAULT_PREFERENCES: AppearancePreferences = {
  theme: 'system',
  skin: 'classic',
  font: 'skin-default',
  primaryTheme: 'blue',
  density: 'comfortable',
  radius: 'medium',
  shadow: 'soft',
  animations: true,
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

function isFontPreference(value: unknown): value is FontPreference {
  return value === 'skin-default' || value === 'inter' || value === 'roboto-condensed' || value === 'segoe-ui' || value === 'tahoma' || value === 'arial'
}

function isPrimaryTheme(value: unknown): value is PrimaryTheme { return ['indigo', 'blue', 'emerald', 'teal', 'purple', 'slate'].includes(String(value)) }
function isDensity(value: unknown): value is DensityPreference { return value === 'compact' || value === 'comfortable' }
function isRadius(value: unknown): value is RadiusPreference { return value === 'small' || value === 'medium' || value === 'large' }
function isShadow(value: unknown): value is ShadowPreference { return value === 'none' || value === 'soft' || value === 'normal' || value === '3d' }

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
      font: isFontPreference(parsed.font) ? parsed.font : DEFAULT_PREFERENCES.font,
      primaryTheme: isPrimaryTheme(parsed.primaryTheme) ? parsed.primaryTheme : DEFAULT_PREFERENCES.primaryTheme,
      density: isDensity(parsed.density) ? parsed.density : DEFAULT_PREFERENCES.density,
      radius: isRadius(parsed.radius) ? parsed.radius : DEFAULT_PREFERENCES.radius,
      shadow: isShadow(parsed.shadow) ? parsed.shadow : DEFAULT_PREFERENCES.shadow,
      animations: typeof parsed.animations === 'boolean' ? parsed.animations : DEFAULT_PREFERENCES.animations,
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
    root.dataset.font = preferences.font
    root.dataset.primaryTheme = preferences.primaryTheme
    root.dataset.density = preferences.density
    root.dataset.radius = preferences.radius
    root.dataset.shadow = preferences.shadow
    root.dataset.animations = preferences.animations ? 'on' : 'off'

    const fontStacks: Record<Exclude<FontPreference, 'skin-default'>, string> = {
      inter: 'Inter, "Segoe UI", Arial, sans-serif',
      'roboto-condensed': '"Roboto Condensed", "Arial Narrow", "Segoe UI", Arial, sans-serif',
      'segoe-ui': '"Segoe UI", Arial, sans-serif',
      tahoma: 'Tahoma, Arial, "Segoe UI", sans-serif',
      arial: 'Arial, "Segoe UI", sans-serif',
    }

    if (preferences.font === 'skin-default') {
      root.style.removeProperty('--app-font-family')
    } else {
      root.style.setProperty('--app-font-family', fontStacks[preferences.font])
    }
  }, [preferences, resolvedTheme])

  useEffect(() => {
    if (typeof window === 'undefined') return

    window.localStorage.setItem(storageKey(userScope), JSON.stringify(preferences))
  }, [preferences, userScope])

  const setUserScope = useCallback(
    (userUlid: string | null, serverPreferences?: AppearancePreferences | null) => {
      setUserScopeState((current) => {
        /*
         * Leaving an authenticated tenant scope should keep the currently
         * selected appearance active on login/platform surfaces. The normal
         * persistence effect then mirrors it into the guest scope. A newly
         * authenticated user still replaces it with that user's server prefs.
         */
        if (userUlid === null && current !== null && !serverPreferences) {
          return null
        }

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

  const setFont = useCallback((font: FontPreference) => {
    setPreferences((current) => ({ ...current, font }))
  }, [])

  const setPrimaryTheme = useCallback((primaryTheme: PrimaryTheme) => setPreferences((current) => ({ ...current, primaryTheme })), [])
  const setDensity = useCallback((density: DensityPreference) => setPreferences((current) => ({ ...current, density })), [])
  const setRadius = useCallback((radius: RadiusPreference) => setPreferences((current) => ({ ...current, radius })), [])
  const setShadow = useCallback((shadow: ShadowPreference) => setPreferences((current) => ({ ...current, shadow })), [])
  const setAnimations = useCallback((animations: boolean) => setPreferences((current) => ({ ...current, animations })), [])

  const resetAppearance = useCallback(() => {
    setPreferences(DEFAULT_PREFERENCES)
  }, [])

  const value = useMemo<AppearanceContextValue>(
    () => ({
      theme: preferences.theme,
      skin: preferences.skin,
      font: preferences.font,
      primaryTheme: preferences.primaryTheme,
      density: preferences.density,
      radius: preferences.radius,
      shadow: preferences.shadow,
      animations: preferences.animations,
      resolvedTheme,
      userScope,
      setTheme,
      setSkin,
      setFont,
      setPrimaryTheme,
      setDensity,
      setRadius,
      setShadow,
      setAnimations,
      setUserScope,
      resetAppearance,
    }),
    [
      preferences.theme,
      preferences.skin,
      preferences.font,
      preferences.primaryTheme,
      preferences.density,
      preferences.radius,
      preferences.shadow,
      preferences.animations,
      resolvedTheme,
      userScope,
      setTheme,
      setSkin,
      setFont,
      setPrimaryTheme,
      setDensity,
      setRadius,
      setShadow,
      setAnimations,
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
