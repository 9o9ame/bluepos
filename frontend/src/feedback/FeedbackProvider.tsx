import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import { createPortal } from 'react-dom'
import { CheckCircle2, Info, Loader2, X, XCircle } from 'lucide-react'
import { ApiClientError } from '../api/client'
import { apiBusy } from './apiBusy'
import './feedback.css'

type ToastTone = 'success' | 'error' | 'info'
type ToastItem = { id: number; tone: ToastTone; title: string; message: string }

type ConfirmState = {
  message: string
  resolve: (ok: boolean) => void
}

type FeedbackApi = {
  success: (message: string, title?: string) => void
  error: (message: string, title?: string) => void
  info: (message: string, title?: string) => void
  fromApiError: (err: unknown, fallback?: string) => void
  confirm: (message: string) => Promise<boolean>
}

const FeedbackContext = createContext<FeedbackApi | null>(null)

let toastSeq = 0

export function formatApiError(err: unknown, fallback = 'Request failed.'): string {
  if (err instanceof ApiClientError) {
    if (err.fields) {
      const first = Object.values(err.fields).flat()[0]
      if (first) return first
    }
    return err.message || fallback
  }
  if (err instanceof Error) return err.message || fallback
  return fallback
}

export function FeedbackProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([])
  const [confirmState, setConfirmState] = useState<ConfirmState | null>(null)
  const [busy, setBusy] = useState(apiBusy.getState())

  useEffect(() => apiBusy.subscribe(setBusy), [])

  const push = useCallback((tone: ToastTone, message: string, title?: string) => {
    const id = ++toastSeq
    const item: ToastItem = {
      id,
      tone,
      title: title ?? (tone === 'success' ? 'Success' : tone === 'error' ? 'Error' : 'Notice'),
      message,
    }
    setToasts((current) => [...current.slice(-4), item])
    window.setTimeout(() => {
      setToasts((current) => current.filter((row) => row.id !== id))
    }, tone === 'error' ? 6500 : 3800)
  }, [])

  const api = useMemo<FeedbackApi>(
    () => ({
      success: (message, title) => push('success', message, title),
      error: (message, title) => push('error', message, title),
      info: (message, title) => push('info', message, title),
      fromApiError: (err, fallback) => push('error', formatApiError(err, fallback), 'Request failed'),
      confirm: (message) =>
        new Promise<boolean>((resolve) => {
          setConfirmState({ message, resolve })
        }),
    }),
    [push],
  )

  useEffect(() => {
    ;(window as unknown as { __blueposFeedback?: FeedbackApi }).__blueposFeedback = api
    return () => {
      delete (window as unknown as { __blueposFeedback?: FeedbackApi }).__blueposFeedback
    }
  }, [api])

  const blocking = busy.block > 0
  const fetching = !blocking && busy.fetch > 0

  return (
    <FeedbackContext.Provider value={api}>
      {children}

      {typeof document !== 'undefined'
        ? createPortal(
            <>
              {blocking ? (
                <div className="bp-busy-overlay" role="alert" aria-live="assertive" aria-busy="true">
                  <div className="bp-busy-card">
                    <div className="bp-busy-orb" aria-hidden="true" />
                    <Loader2 className="bp-busy-spin" size={28} />
                    <div className="bp-busy-title">Working…</div>
                    <div className="bp-busy-sub">Please wait while BluePOS finishes this request.</div>
                  </div>
                </div>
              ) : null}

              {fetching ? (
                <div className="bp-busy-fetch" role="status" aria-live="polite" aria-busy="true">
                  <span />
                </div>
              ) : null}

              <div className="bp-toast-stack" aria-live="polite">
                {toasts.map((toast) => (
                  <div key={toast.id} className={`bp-toast bp-toast-${toast.tone}`}>
                    <span className="bp-toast-icon" aria-hidden="true">
                      {toast.tone === 'success' ? (
                        <CheckCircle2 size={18} />
                      ) : toast.tone === 'error' ? (
                        <XCircle size={18} />
                      ) : (
                        <Info size={18} />
                      )}
                    </span>
                    <div className="bp-toast-body">
                      <strong>{toast.title}</strong>
                      <span>{toast.message}</span>
                    </div>
                    <button
                      type="button"
                      className="bp-toast-close"
                      aria-label="Dismiss"
                      onClick={() => setToasts((current) => current.filter((row) => row.id !== toast.id))}
                    >
                      <X size={14} />
                    </button>
                  </div>
                ))}
              </div>

              {confirmState ? (
                <div className="bp-confirm-backdrop" role="presentation">
                  <div className="bp-confirm-card" role="dialog" aria-modal="true" aria-label="Confirm">
                    <p>{confirmState.message}</p>
                    <div className="bp-confirm-actions">
                      <button
                        type="button"
                        data-tone="close"
                        onClick={() => {
                          confirmState.resolve(false)
                          setConfirmState(null)
                        }}
                      >
                        Cancel
                      </button>
                      <button
                        type="button"
                        data-tone="save"
                        onClick={() => {
                          confirmState.resolve(true)
                          setConfirmState(null)
                        }}
                      >
                        Confirm
                      </button>
                    </div>
                  </div>
                </div>
              ) : null}
            </>,
            document.body,
          )
        : null}
    </FeedbackContext.Provider>
  )
}

export function useFeedback(): FeedbackApi {
  const ctx = useContext(FeedbackContext)
  if (!ctx) {
    throw new Error('useFeedback must be used within FeedbackProvider')
  }
  return ctx
}

/** Safe confirm for code that may run outside React trees. */
export async function askConfirm(message: string): Promise<boolean> {
  const api = (window as unknown as { __blueposFeedback?: FeedbackApi }).__blueposFeedback
  if (api) return api.confirm(message)
  return window.confirm(message)
}
