import { usePwaUpdate } from '../../features/desktop/usePwaUpdate'

export function PwaUpdateBanner() {
  const { needRefresh, applyUpdate, dismiss } = usePwaUpdate()

  if (!needRefresh) {
    return null
  }

  return (
    <div className="pwa-update-banner" role="status" aria-live="polite">
      <span>A new BluePOS version is ready.</span>
      <div className="pwa-update-actions">
        <button type="button" className="pwa-update-btn is-primary" onClick={applyUpdate}>
          Refresh / Update
        </button>
        <button type="button" className="pwa-update-btn" onClick={dismiss}>
          Later
        </button>
      </div>
    </div>
  )
}
