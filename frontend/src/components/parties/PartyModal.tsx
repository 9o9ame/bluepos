import { useCallback, useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { X } from 'lucide-react'
import type { Party } from '../../api/parties'
import { PartiesPlaceholderPage } from '../../pages/PartiesPlaceholderPage'
import { UI_LAYER } from '../ui/uiLayers'
import './PartyModal.css'

type Point = { x: number; y: number }

function initialPosition(): Point {
  const width = Math.min(1180, Math.max(760, window.innerWidth - 32))
  const height = Math.min(760, Math.max(560, window.innerHeight - 32))

  return {
    x: Math.max(16, Math.round((window.innerWidth - width) / 2)),
    y: Math.max(16, Math.round((window.innerHeight - height) / 2)),
  }
}

export function PartyModal({
  onClose,
  onSaved,
  partyType = 'customer',
}: {
  onClose: () => void
  onSaved: (party: Party) => void
  partyType?: 'customer' | 'vendor'
}) {
  const [position, setPosition] = useState<Point>(() => initialPosition())
  const dragRef = useRef<{ pointerId: number; offsetX: number; offsetY: number } | null>(null)
  const windowRef = useRef<HTMLDivElement | null>(null)

  const requestClose = useCallback(() => {
    const closeButton = windowRef.current?.querySelector<HTMLButtonElement>('.parties-vca-action.is-close')
    if (closeButton) {
      closeButton.click()
      return
    }
    onClose()
  }, [onClose])

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') requestClose()
    }

    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [requestClose])

  function clamp(nextX: number, nextY: number): Point {
    const rect = windowRef.current?.getBoundingClientRect()
    const width = rect?.width ?? 900
    const height = rect?.height ?? 650

    return {
      x: Math.min(Math.max(0, nextX), Math.max(0, window.innerWidth - width)),
      y: Math.min(Math.max(0, nextY), Math.max(0, window.innerHeight - height)),
    }
  }

  function onPointerDown(event: ReactPointerEvent<HTMLDivElement>) {
    if (event.button !== 0) return
    const rect = windowRef.current?.getBoundingClientRect()
    if (!rect) return

    dragRef.current = {
      pointerId: event.pointerId,
      offsetX: event.clientX - rect.left,
      offsetY: event.clientY - rect.top,
    }
    event.currentTarget.setPointerCapture(event.pointerId)
  }

  function onPointerMove(event: ReactPointerEvent<HTMLDivElement>) {
    const drag = dragRef.current
    if (!drag || drag.pointerId !== event.pointerId) return

    setPosition(clamp(event.clientX - drag.offsetX, event.clientY - drag.offsetY))
  }

  function onPointerUp(event: ReactPointerEvent<HTMLDivElement>) {
    if (dragRef.current?.pointerId !== event.pointerId) return
    dragRef.current = null
    if (event.currentTarget.hasPointerCapture(event.pointerId)) {
      event.currentTarget.releasePointerCapture(event.pointerId)
    }
  }

  return (
    <div className="party-modal-layer" style={{ zIndex: UI_LAYER.modal }} aria-hidden={false}>
      <div
        ref={windowRef}
        className="party-modal-window"
        style={{ left: position.x, top: position.y }}
        role="dialog"
        aria-modal="true"
        aria-label="Vendor / Customers / Accounts"
      >
        <div
          className="party-modal-titlebar"
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={onPointerUp}
          onPointerCancel={onPointerUp}
        >
          <strong>Vendor / Customers / Accounts</strong>
          <button
            type="button"
            className="party-modal-close"
            aria-label="Close Vendor / Customers / Accounts"
            title="Close"
            onPointerDown={(event) => event.stopPropagation()}
            onClick={requestClose}
          >
            <X size={16} />
          </button>
        </div>

        <div className="party-modal-body">
          <PartiesPlaceholderPage
            embedded
            embeddedPartyType={partyType}
            onClose={onClose}
            onSaved={onSaved}
          />
        </div>
      </div>
    </div>
  )
}
