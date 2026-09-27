import { useEffect, useRef, useState } from 'react'
import { Palette } from 'lucide-react'
import { AppearanceSettings } from '../../features/appearance/AppearanceSettings'

export function AppearanceMenu() {
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    function onDocumentMouseDown(event: MouseEvent) {
      if (rootRef.current && !rootRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }

    document.addEventListener('mousedown', onDocumentMouseDown)

    return () => {
      document.removeEventListener('mousedown', onDocumentMouseDown)
    }
  }, [])

  return (
    <div className="appearance-menu" ref={rootRef}>
      <button
        type="button"
        className="appearance-menu-trigger"
        title="Appearance"
        aria-label="Appearance"
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
      >
        <Palette size={14} aria-hidden />
      </button>

      {open ? (
        <div className="appearance-menu-panel" role="dialog" aria-label="Appearance">
          <div className="appearance-menu-titlebar">
            <strong>Appearance</strong>
            <button type="button" aria-label="Close appearance settings" onClick={() => setOpen(false)}>
              ×
            </button>
          </div>
          <AppearanceSettings />
        </div>
      ) : null}
    </div>
  )
}
