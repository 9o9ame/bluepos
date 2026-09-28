import { useCallback, useEffect, useState } from 'react'
import { isBrowserFullscreen } from './appDisplayMode'

export function useFullscreen() {
  const [isFullscreen, setIsFullscreen] = useState(() => isBrowserFullscreen())
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    function onFullscreenChange() {
      setIsFullscreen(isBrowserFullscreen())
    }

    document.addEventListener('fullscreenchange', onFullscreenChange)
    return () => {
      document.removeEventListener('fullscreenchange', onFullscreenChange)
    }
  }, [])

  const enterFullscreen = useCallback(async () => {
    setError(null)
    const root = document.documentElement

    if (!root.requestFullscreen) {
      setError('Fullscreen is not supported in this browser.')
      return false
    }

    try {
      await root.requestFullscreen()
      setIsFullscreen(true)
      return true
    } catch {
      setError('Fullscreen was blocked by the browser.')
      return false
    }
  }, [])

  const exitFullscreen = useCallback(async () => {
    setError(null)

    if (!document.fullscreenElement) {
      setIsFullscreen(false)
      return true
    }

    if (!document.exitFullscreen) {
      setError('Exit fullscreen is not supported in this browser.')
      return false
    }

    try {
      await document.exitFullscreen()
      setIsFullscreen(false)
      return true
    } catch {
      setError('Unable to exit fullscreen.')
      return false
    }
  }, [])

  const toggleFullscreen = useCallback(async () => {
    if (isBrowserFullscreen()) {
      return exitFullscreen()
    }
    return enterFullscreen()
  }, [enterFullscreen, exitFullscreen])

  return {
    isFullscreen,
    error,
    enterFullscreen,
    exitFullscreen,
    toggleFullscreen,
  }
}
