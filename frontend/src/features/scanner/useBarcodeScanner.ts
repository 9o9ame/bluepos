import { useEffect, useRef } from 'react'

type InputSnapshot =
  | {
      element: HTMLInputElement | HTMLTextAreaElement
      value: string
      selectionStart: number | null
      selectionEnd: number | null
    }
  | null

function snapshotTarget(target: EventTarget | null): InputSnapshot {
  if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) {
    return null
  }

  return {
    element: target,
    value: target.value,
    selectionStart: target.selectionStart,
    selectionEnd: target.selectionEnd,
  }
}

function restoreSnapshot(snapshot: InputSnapshot) {
  if (!snapshot) return
  const { element, value, selectionStart, selectionEnd } = snapshot
  const prototype =
    element instanceof HTMLTextAreaElement
      ? HTMLTextAreaElement.prototype
      : HTMLInputElement.prototype
  const setter = Object.getOwnPropertyDescriptor(prototype, 'value')?.set
  setter?.call(element, value)
  element.dispatchEvent(new Event('input', { bubbles: true }))

  if (
    typeof selectionStart === 'number' &&
    typeof selectionEnd === 'number' &&
    typeof element.setSelectionRange === 'function'
  ) {
    element.setSelectionRange(selectionStart, selectionEnd)
  }
}

export function useBarcodeScanner({
  enabled,
  onScan,
  ignoreTarget,
  minLength = 6,
  maxGapMs = 45,
  maxDurationMs = 500,
}: {
  enabled: boolean
  onScan: (barcode: string) => void
  ignoreTarget?: (target: EventTarget | null) => boolean
  minLength?: number
  maxGapMs?: number
  maxDurationMs?: number
}) {
  const onScanRef = useRef(onScan)
  const ignoreTargetRef = useRef(ignoreTarget)
  const bufferRef = useRef('')
  const startedAtRef = useRef(0)
  const lastAtRef = useRef(0)
  const snapshotRef = useRef<InputSnapshot>(null)
  const ignoredRef = useRef(false)

  useEffect(() => {
    onScanRef.current = onScan
  }, [onScan])

  useEffect(() => {
    ignoreTargetRef.current = ignoreTarget
  }, [ignoreTarget])

  useEffect(() => {
    if (!enabled) return

    const reset = () => {
      bufferRef.current = ''
      startedAtRef.current = 0
      lastAtRef.current = 0
      snapshotRef.current = null
      ignoredRef.current = false
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.ctrlKey || event.altKey || event.metaKey) {
        reset()
        return
      }

      const now = performance.now()

      if (event.key === 'Enter') {
        const value = bufferRef.current
        const startedAt = startedAtRef.current
        const lastAt = lastAtRef.current
        const duration = startedAt > 0 ? now - startedAt : Number.POSITIVE_INFINITY
        const trailingGap = lastAt > 0 ? now - lastAt : Number.POSITIVE_INFINITY
        const isScanner =
          !ignoredRef.current &&
          value.length >= minLength &&
          duration <= maxDurationMs &&
          trailingGap <= maxGapMs * 2

        if (!isScanner) {
          reset()
          return
        }

        event.preventDefault()
        event.stopPropagation()
        restoreSnapshot(snapshotRef.current)
        const barcode = value
        reset()
        onScanRef.current(barcode)
        return
      }

      if (event.key.length !== 1) {
        if (event.key !== 'Shift') reset()
        return
      }

      if (lastAtRef.current > 0 && now - lastAtRef.current > maxGapMs) {
        reset()
      }

      if (bufferRef.current.length === 0) {
        startedAtRef.current = now
        snapshotRef.current = snapshotTarget(event.target)
        ignoredRef.current = Boolean(ignoreTargetRef.current?.(event.target))
      }

      bufferRef.current += event.key
      lastAtRef.current = now
    }

    window.addEventListener('keydown', onKeyDown, true)
    return () => window.removeEventListener('keydown', onKeyDown, true)
  }, [enabled, maxDurationMs, maxGapMs, minLength])
}
