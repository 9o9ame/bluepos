import { useCallback, useEffect, useState } from 'react'
import {
  applyAppDisplayMode,
  isStandaloneDisplay,
  resolveAppDisplayMode,
  type AppDisplayMode,
} from './appDisplayMode'

export function useAppMode() {
  const [mode, setMode] = useState<AppDisplayMode>(() => resolveAppDisplayMode())
  const [isStandalone, setIsStandalone] = useState(() => isStandaloneDisplay())

  useEffect(() => {
    function sync() {
      const nextMode = resolveAppDisplayMode()
      setMode(nextMode)
      setIsStandalone(isStandaloneDisplay())
      applyAppDisplayMode(nextMode)
    }

    sync()

    const mediaQueries = [
      window.matchMedia('(display-mode: standalone)'),
      window.matchMedia('(display-mode: minimal-ui)'),
      window.matchMedia('(display-mode: window-controls-overlay)'),
      window.matchMedia('(display-mode: browser)'),
    ]

    for (const media of mediaQueries) {
      media.addEventListener('change', sync)
    }

    document.addEventListener('fullscreenchange', sync)

    return () => {
      for (const media of mediaQueries) {
        media.removeEventListener('change', sync)
      }
      document.removeEventListener('fullscreenchange', sync)
    }
  }, [])

  const refresh = useCallback(() => {
    const nextMode = resolveAppDisplayMode()
    setMode(nextMode)
    setIsStandalone(isStandaloneDisplay())
    applyAppDisplayMode(nextMode)
  }, [])

  return {
    mode,
    isStandalone,
    isFullscreen: mode === 'fullscreen',
    refresh,
  }
}
