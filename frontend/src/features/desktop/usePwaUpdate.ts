import { useCallback, useEffect, useRef, useState } from 'react'

type PwaUpdateState = {
  needRefresh: boolean
  applyUpdate: () => void
  dismiss: () => void
}

/**
 * Registers the shell service worker with prompt-style updates.
 * Never auto-reloads an active POS session.
 */
export function usePwaUpdate(): PwaUpdateState {
  const [needRefresh, setNeedRefresh] = useState(false)
  const waitingWorkerRef = useRef<ServiceWorker | null>(null)

  useEffect(() => {
    if (!import.meta.env.PROD || !('serviceWorker' in navigator)) {
      return
    }

    let cancelled = false
    let intervalId: number | undefined
    let registration: ServiceWorkerRegistration | undefined

    function onUpdateFound() {
      const installing = registration?.installing
      if (!installing) return

      installing.addEventListener('statechange', () => {
        if (
          installing.state === 'installed' &&
          navigator.serviceWorker.controller
        ) {
          waitingWorkerRef.current = registration?.waiting ?? installing
          if (!cancelled) {
            setNeedRefresh(true)
          }
        }
      })
    }

    void navigator.serviceWorker
      .register('/sw.js', { scope: '/' })
      .then((reg) => {
        if (cancelled) return
        registration = reg

        if (reg.waiting && navigator.serviceWorker.controller) {
          waitingWorkerRef.current = reg.waiting
          setNeedRefresh(true)
        }

        reg.addEventListener('updatefound', onUpdateFound)
        intervalId = window.setInterval(() => {
          void reg.update()
        }, 60 * 60 * 1000)
      })
      .catch(() => {
        // Installability remains available via manifest even if SW registration fails.
      })

    return () => {
      cancelled = true
      if (intervalId !== undefined) {
        window.clearInterval(intervalId)
      }
      if (registration) {
        registration.removeEventListener('updatefound', onUpdateFound)
      }
    }
  }, [])

  const applyUpdate = useCallback(() => {
    const worker = waitingWorkerRef.current
    setNeedRefresh(false)

    if (!worker) {
      window.location.reload()
      return
    }

    worker.postMessage({ type: 'SKIP_WAITING' })

    const onControllerChange = () => {
      navigator.serviceWorker.removeEventListener(
        'controllerchange',
        onControllerChange,
      )
      window.location.reload()
    }

    navigator.serviceWorker.addEventListener(
      'controllerchange',
      onControllerChange,
    )
  }, [])

  const dismiss = useCallback(() => {
    setNeedRefresh(false)
  }, [])

  return {
    needRefresh,
    applyUpdate,
    dismiss,
  }
}
