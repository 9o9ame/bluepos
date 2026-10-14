import { useEffect, useRef } from 'react'

type InputSnapshot =
  | {
      element: HTMLInputElement | HTMLTextAreaElement
      value: string
      selectionStart: number | null
      selectionEnd: number | null
    }
  | null

type ScannerTarget = {
  id: string
  priority: number
  onScan: (barcode: string) => void
}

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

class BarcodeScannerRouter {
  private targets = new Map<string, ScannerTarget>()
  private buffer = ''
  private startedAt = 0
  private lastAt = 0
  private snapshot: InputSnapshot = null
  private activeTargetId: string | null = null
  private listening = false

  private readonly minLength = 6
  private readonly maxGapMs = 45
  private readonly maxDurationMs = 500

  register(target: ScannerTarget): () => void {
    this.targets.set(target.id, target)
    this.ensureListening()

    return () => {
      this.targets.delete(target.id)
      if (this.activeTargetId === target.id) this.reset()

      if (this.targets.size === 0) {
        this.stopListening()
      }
    }
  }

  private highestPriorityTarget(): ScannerTarget | null {
    let winner: ScannerTarget | null = null

    for (const target of this.targets.values()) {
      if (
        !winner ||
        target.priority > winner.priority ||
        (target.priority === winner.priority && target.id > winner.id)
      ) {
        winner = target
      }
    }

    return winner
  }

  private ensureListening() {
    if (this.listening || typeof window === 'undefined') return
    window.addEventListener('keydown', this.onKeyDown, true)
    this.listening = true
  }

  private stopListening() {
    if (!this.listening || typeof window === 'undefined') return
    window.removeEventListener('keydown', this.onKeyDown, true)
    this.listening = false
    this.reset()
  }

  private reset() {
    this.buffer = ''
    this.startedAt = 0
    this.lastAt = 0
    this.snapshot = null
    this.activeTargetId = null
  }

  private readonly onKeyDown = (event: KeyboardEvent) => {
    if (event.ctrlKey || event.altKey || event.metaKey) {
      this.reset()
      return
    }

    const now = performance.now()

    if (event.key === 'Enter') {
      const target = this.activeTargetId
        ? this.targets.get(this.activeTargetId) ?? null
        : null
      const duration =
        this.startedAt > 0 ? now - this.startedAt : Number.POSITIVE_INFINITY
      const trailingGap =
        this.lastAt > 0 ? now - this.lastAt : Number.POSITIVE_INFINITY
      const barcode = this.buffer
      const isScanner =
        Boolean(target) &&
        barcode.length >= this.minLength &&
        duration <= this.maxDurationMs &&
        trailingGap <= this.maxGapMs * 2

      if (!isScanner || !target) {
        this.reset()
        return
      }

      event.preventDefault()
      event.stopPropagation()
      restoreSnapshot(this.snapshot)
      this.reset()
      target.onScan(barcode)
      return
    }

    if (event.key.length !== 1) {
      if (event.key !== 'Shift') this.reset()
      return
    }

    if (this.lastAt > 0 && now - this.lastAt > this.maxGapMs) {
      this.reset()
    }

    if (this.buffer.length === 0) {
      const target = this.highestPriorityTarget()
      if (!target) return

      this.startedAt = now
      this.snapshot = snapshotTarget(event.target)
      this.activeTargetId = target.id
    }

    this.buffer += event.key
    this.lastAt = now
  }
}

const barcodeScannerRouter = new BarcodeScannerRouter()

export function useBarcodeScannerTarget({
  id,
  priority = 0,
  enabled,
  onScan,
}: {
  id: string
  priority?: number
  enabled: boolean
  onScan: (barcode: string) => void
}) {
  const onScanRef = useRef(onScan)

  useEffect(() => {
    onScanRef.current = onScan
  }, [onScan])

  useEffect(() => {
    if (!enabled) return

    return barcodeScannerRouter.register({
      id,
      priority,
      onScan: (barcode) => onScanRef.current(barcode),
    })
  }, [enabled, id, priority])
}

// Backward-compatible wrapper for simple one-target pages.
export function useBarcodeScanner({
  enabled,
  onScan,
}: {
  enabled: boolean
  onScan: (barcode: string) => void
}) {
  useBarcodeScannerTarget({
    id: 'default-barcode-target',
    enabled,
    onScan,
  })
}
