import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { Palette } from 'lucide-react'
import { AppearanceSettings } from '../../features/appearance/AppearanceSettings'

type AppearanceMenuProps = {
  open?: boolean
  onOpenChange?: (open: boolean) => void
}

export function AppearanceMenu({ open: controlledOpen, onOpenChange }: AppearanceMenuProps) {
  const [internalOpen, setInternalOpen] = useState(false)
  const [position, setPosition] = useState({ x: 0, y: 0 })
  const dragRef = useRef<{ x: number; y: number; left: number; top: number } | null>(null)
  const open = controlledOpen ?? internalOpen
  const setOpen = (next: boolean) => {
    if (controlledOpen === undefined) setInternalOpen(next)
    onOpenChange?.(next)
  }

  useEffect(() => {
    function onPointerMove(event: PointerEvent) {
      const drag = dragRef.current
      if (!drag) return
      setPosition({
        x: drag.left + event.clientX - drag.x,
        y: drag.top + event.clientY - drag.y,
      })
    }
    function onPointerUp() { dragRef.current = null }
    window.addEventListener('pointermove', onPointerMove)
    window.addEventListener('pointerup', onPointerUp)
    return () => {
      window.removeEventListener('pointermove', onPointerMove)
      window.removeEventListener('pointerup', onPointerUp)
    }
  }, [])

  function startDrag(event: ReactPointerEvent<HTMLDivElement>) {
    const panel = event.currentTarget.parentElement
    if (!panel) return
    const rect = panel.getBoundingClientRect()
    dragRef.current = { x: event.clientX, y: event.clientY, left: rect.left, top: rect.top }
    setPosition({ x: rect.left, y: rect.top })
    event.preventDefault()
  }

  return (
    <div className="appearance-menu">
      <button type="button" className="appearance-menu-trigger" title="Theme & Appearance"
        aria-label="Theme & Appearance" aria-haspopup="dialog" aria-expanded={open}
        onClick={() => setOpen(!open)}>
        <Palette size={14} aria-hidden />
      </button>
      {open ? (
        <div className="appearance-menu-panel appearance-menu-panel-draggable" role="dialog"
          aria-label="Theme & Appearance"
          style={position.x || position.y ? { left: position.x, top: position.y, right: 'auto' } : undefined}>
          <div className="appearance-menu-titlebar appearance-drag-handle" onPointerDown={startDrag}>
            <strong>Theme &amp; Appearance</strong>
            <button type="button" aria-label="Close appearance settings"
              onPointerDown={(event) => event.stopPropagation()} onClick={() => setOpen(false)}>×</button>
          </div>
          <AppearanceSettings />
        </div>
      ) : null}
    </div>
  )
}
