import { useEffect } from 'react'
import { useWorkspace } from '../../features/workspace/WorkspaceProvider'

export function useWorkspaceShortcuts(): void {
  const { closeActiveTab, triggerRefresh, triggerSave } = useWorkspace()

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      const target = event.target
      if (target instanceof HTMLElement) {
        const typing =
          target.tagName === 'INPUT' ||
          target.tagName === 'TEXTAREA' ||
          target.tagName === 'SELECT' ||
          target.isContentEditable
        if (typing && event.key !== 'Escape' && event.key !== 'F8' && event.key !== 'F9') {
          return
        }
      }

      if (event.key === 'Escape') {
        event.preventDefault()
        closeActiveTab()
        return
      }
      if (event.key === 'F8') {
        event.preventDefault()
        triggerRefresh()
        return
      }
      if (event.key === 'F9') {
        event.preventDefault()
        triggerSave()
      }
    }

    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [closeActiveTab, triggerRefresh, triggerSave])
}
