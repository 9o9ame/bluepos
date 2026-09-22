import { X } from 'lucide-react'
import { useWorkspace } from '../../features/workspace/WorkspaceProvider'

export function WorkspaceTabBar() {
  const { tabs, activeKey, openModule, closeTab } = useWorkspace()

  return (
    <div className="workspace-tabs" role="tablist" aria-label="Workspace documents">
      {tabs.map((tab) => {
        const selected = tab.key === activeKey
        return (
          <div key={tab.key} className={`workspace-tab${selected ? ' is-active' : ''}`}>
            <button
              type="button"
              role="tab"
              aria-selected={selected}
              className="workspace-tab-label"
              onClick={() => openModule(tab.path)}
            >
              {tab.title}
              {tab.dirty ? ' *' : ''}
            </button>
            {tab.closeable ? (
              <button
                type="button"
                className="workspace-tab-close"
                aria-label={`Close ${tab.title}`}
                onClick={() => closeTab(tab.key)}
              >
                <X size={12} aria-hidden />
              </button>
            ) : null}
          </div>
        )
      })}
    </div>
  )
}
