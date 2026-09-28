import { useCallback, useEffect, useState } from 'react'
import { isStandaloneDisplay } from './appDisplayMode'

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed'; platform: string }>
}

declare global {
  interface WindowEventMap {
    beforeinstallprompt: BeforeInstallPromptEvent
  }
}

export function usePwaInstall() {
  const [deferredPrompt, setDeferredPrompt] =
    useState<BeforeInstallPromptEvent | null>(null)
  const [isStandalone, setIsStandalone] = useState(() => isStandaloneDisplay())
  const [wasInstalled, setWasInstalled] = useState(false)
  const [promptError, setPromptError] = useState<string | null>(null)

  useEffect(() => {
    function syncStandalone() {
      setIsStandalone(isStandaloneDisplay())
    }

    function onBeforeInstallPrompt(event: BeforeInstallPromptEvent) {
      event.preventDefault()
      setDeferredPrompt(event)
      setWasInstalled(false)
      setPromptError(null)
    }

    function onAppInstalled() {
      setDeferredPrompt(null)
      setWasInstalled(true)
      setIsStandalone(true)
      setPromptError(null)
    }

    const media = window.matchMedia('(display-mode: standalone)')
    media.addEventListener('change', syncStandalone)
    window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt)
    window.addEventListener('appinstalled', onAppInstalled)
    syncStandalone()

    return () => {
      media.removeEventListener('change', syncStandalone)
      window.removeEventListener('beforeinstallprompt', onBeforeInstallPrompt)
      window.removeEventListener('appinstalled', onAppInstalled)
    }
  }, [])

  const canInstall =
    !isStandalone && !wasInstalled && deferredPrompt !== null

  const promptInstall = useCallback(async () => {
    if (!deferredPrompt) {
      setPromptError('Install is not available in this browser.')
      return false
    }

    try {
      await deferredPrompt.prompt()
      const choice = await deferredPrompt.userChoice
      setDeferredPrompt(null)

      if (choice.outcome === 'accepted') {
        setWasInstalled(true)
        setPromptError(null)
        return true
      }

      return false
    } catch {
      setPromptError('Unable to open the install prompt.')
      setDeferredPrompt(null)
      return false
    }
  }, [deferredPrompt])

  return {
    canInstall,
    isStandalone,
    wasInstalled,
    promptError,
    promptInstall,
  }
}
