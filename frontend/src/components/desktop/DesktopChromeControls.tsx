import { Download, Maximize2, Minimize2 } from 'lucide-react'
import { useFullscreen } from '../../features/desktop/useFullscreen'
import { usePwaInstall } from '../../features/desktop/usePwaInstall'

export function DesktopChromeControls() {
  const { canInstall, promptInstall } = usePwaInstall()
  const { isFullscreen, toggleFullscreen } = useFullscreen()

  return (
    <div className="desktop-chrome-controls">
      {canInstall ? (
        <button
          type="button"
          className="desktop-chrome-btn desktop-chrome-install"
          title="Install BluePOS"
          aria-label="Install BluePOS"
          onClick={() => {
            void promptInstall()
          }}
        >
          <Download size={13} aria-hidden />
          <span>Install BluePOS</span>
        </button>
      ) : null}

      <button
        type="button"
        className="desktop-chrome-btn"
        title={isFullscreen ? 'Exit Full Screen' : 'Enter Full Screen'}
        aria-label={isFullscreen ? 'Exit Full Screen' : 'Enter Full Screen'}
        onClick={() => {
          void toggleFullscreen()
        }}
      >
        {isFullscreen ? (
          <Minimize2 size={14} aria-hidden />
        ) : (
          <Maximize2 size={14} aria-hidden />
        )}
      </button>
    </div>
  )
}
