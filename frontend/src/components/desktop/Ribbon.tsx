import { Children, isValidElement, type ReactNode } from 'react'
import { useWorkspace } from '../../features/workspace/WorkspaceProvider'
import { RIBBON_GROUPS, RIBBON_TABS, WORKSPACE_MODULES } from '../../features/workspace/modules'
import { RibbonCommand, RibbonGroup } from './RibbonCommand'

type RibbonProps = {
  onCalculator: () => void
}

export function Ribbon({ onCalculator }: RibbonProps) {
  const { ribbonTab, setRibbonTab, openModule, activeModule } = useWorkspace()

  return (
    <div className="ribbon">
      <div className="ribbon-tabs" role="tablist" aria-label="Main ribbon">
        {RIBBON_TABS.map((tab) => {
          const selected = tab.id === ribbonTab
          return (
            <button
              key={tab.id}
              type="button"
              role="tab"
              aria-selected={selected}
              className={`ribbon-tab${selected ? ' is-active' : ''}`}
              onClick={() => setRibbonTab(tab.id)}
            >
              {tab.label}
            </button>
          )
        })}
      </div>
      <div className="ribbon-body" role="tabpanel">
        {RIBBON_GROUPS[ribbonTab].map((group) => (
          <VisibleRibbonGroup key={group.id} caption={group.caption}>
            {group.commands.map((command) => (
              <RibbonCommand
                key={command.id}
                {...command}
                active={Boolean(command.moduleKey && command.moduleKey === activeModule.key)}
                onNavigate={(path) => openModule(path)}
                onClick={() => {
                  if (command.action === 'calculator') {
                    onCalculator()
                    return
                  }
                  const path =
                    command.path ??
                    WORKSPACE_MODULES.find((module) => module.key === command.moduleKey)?.path
                  if (path) {
                    openModule(path)
                  }
                }}
              />
            ))}
          </VisibleRibbonGroup>
        ))}
      </div>
    </div>
  )
}

function VisibleRibbonGroup({ caption, children }: { caption: string; children: ReactNode }) {
  const visible = Children.toArray(children).some((child) => isValidElement(child))
  if (!visible) {
    return null
  }
  return <RibbonGroup caption={caption}>{children}</RibbonGroup>
}
