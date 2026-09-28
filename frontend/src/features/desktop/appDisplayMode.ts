export type AppDisplayMode = 'browser' | 'standalone' | 'fullscreen'

export function isStandaloneDisplay(): boolean {
  if (typeof window === 'undefined') return false

  const standaloneMedia = window.matchMedia('(display-mode: standalone)').matches
  const minimalUi = window.matchMedia('(display-mode: minimal-ui)').matches
  const windowControlsOverlay = window.matchMedia(
    '(display-mode: window-controls-overlay)',
  ).matches
  const iosStandalone =
    'standalone' in window.navigator &&
    Boolean((window.navigator as Navigator & { standalone?: boolean }).standalone)

  return standaloneMedia || minimalUi || windowControlsOverlay || iosStandalone
}

export function isBrowserFullscreen(): boolean {
  if (typeof document === 'undefined') return false
  return Boolean(document.fullscreenElement)
}

export function resolveAppDisplayMode(): AppDisplayMode {
  if (isBrowserFullscreen()) return 'fullscreen'
  if (isStandaloneDisplay()) return 'standalone'
  return 'browser'
}

export function applyAppDisplayMode(mode: AppDisplayMode = resolveAppDisplayMode()) {
  if (typeof document === 'undefined') return
  document.documentElement.dataset.appMode = mode
}
