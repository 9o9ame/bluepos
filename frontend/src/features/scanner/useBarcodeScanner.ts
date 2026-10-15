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
  private scannerConfirmed = false
  private rapidCharacterCount = 0

  private readonly minLength = 6
  private readonly slowNumericMinLength = 8
  private readonly confirmAfterCharacters = 4
  private readonly confirmGapMs = 45
  private readonly maxGapMs = 140
  private readonly slowNumericMaxGapMs = 750
  private readonly maxDurationMs = 3000
  private readonly slowNumericMaxDurationMs = 10000

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
    this.scannerConfirmed = false
    this.rapidCharacterCount = 0
  }

  private consume(event: KeyboardEvent) {
    event.preventDefault()
    event.stopPropagation()
    event.stopImmediatePropagation()
  }

  private confirmScanner(event: KeyboardEvent) {
    if (this.scannerConfirmed) return
    this.scannerConfirmed = true

    // The first couple of characters may already have reached the focused
    // controlled input before the burst was distinguishable from human typing.
    // Restore that field once, then suppress every remaining scanner key.
    restoreSnapshot(this.snapshot)
    this.consume(event)
  }

  private readonly onKeyDown = (event: KeyboardEvent) => {
    if (event.ctrlKey || event.altKey || event.metaKey) {
      this.reset()
      return
    }

    const now = performance.now()

    if (event.key === 'Enter' || event.key === 'Tab') {
      const target = this.activeTargetId
        ? this.targets.get(this.activeTargetId) ?? null
        : null
      const duration =
        this.startedAt > 0 ? now - this.startedAt : Number.POSITIVE_INFINITY
      const trailingGap =
        this.lastAt > 0 ? now - this.lastAt : Number.POSITIVE_INFINITY
      const barcode = this.buffer
      const averageGap =
        barcode.length > 1
          ? duration / (barcode.length - 1)
          : Number.POSITIVE_INFINITY
      const isFastScanner =
        Boolean(target) &&
        barcode.length >= this.minLength &&
        duration <= this.maxDurationMs &&
        trailingGap <= this.maxGapMs * 2 &&
        (this.scannerConfirmed || averageGap <= 80)
      const isSlowNumericScanner =
        Boolean(target) &&
        this.snapshot === null &&
        /^\d+$/.test(barcode) &&
        barcode.length >= this.slowNumericMinLength &&
        duration <= this.slowNumericMaxDurationMs &&
        trailingGap <= this.slowNumericMaxGapMs
      const isScanner = isFastScanner || isSlowNumericScanner

      if (!isScanner || !target) {
        this.reset()
        return
      }

      this.consume(event)
      restoreSnapshot(this.snapshot)

      const completedBarcode = barcode
      this.reset()
      target.onScan(completedBarcode)
      return
    }

    if (event.key.length !== 1) {
      if (this.scannerConfirmed) {
        this.consume(event)
      } else if (event.key !== 'Shift') {
        this.reset()
      }
      return
    }

    const gap = this.lastAt > 0 ? now - this.lastAt : 0
    const currentCandidateIsNumeric = /^\d*$/.test(this.buffer)
    const nextCandidateIsNumeric = currentCandidateIsNumeric && /^\d$/.test(event.key)
    const allowedCandidateGap =
      nextCandidateIsNumeric
        ? this.slowNumericMaxGapMs
        : this.maxGapMs

    if (this.lastAt > 0 && gap > allowedCandidateGap) {
      this.reset()
    }

    if (this.buffer.length === 0) {
      const target = this.highestPriorityTarget()
      if (!target) return

      this.startedAt = now
      this.snapshot = snapshotTarget(event.target)
      this.activeTargetId = target.id
      this.rapidCharacterCount = 1
    } else if (gap <= this.confirmGapMs) {
      this.rapidCharacterCount += 1
    } else {
      this.rapidCharacterCount = 1
    }

    this.buffer += event.key
    this.lastAt = now

    if (
      !this.scannerConfirmed &&
      this.rapidCharacterCount >= this.confirmAfterCharacters
    ) {
      this.confirmScanner(event)
      return
    }

    if (this.scannerConfirmed) {
      this.consume(event)
    }
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
