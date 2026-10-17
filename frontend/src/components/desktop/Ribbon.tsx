import { Children, isValidElement, type ReactNode } from 'react'
import {
  CircleHelp,
  ClipboardList,
  FilePenLine,
  Home,
  Settings,
  Wrench,
  type LucideIcon,
} from 'lucide-react'
import { useAppearance } from '../../features/appearance/AppearanceProvider'
import { useWorkspace } from '../../features/workspace/WorkspaceProvider'
import { RIBBON_GROUPS, RIBBON_TABS, WORKSPACE_MODULES } from '../../features/workspace/modules'
import { RibbonCommand, RibbonGroup } from './RibbonCommand'
import { resolveRibbonTabIcon } from '../../features/workspace/blueposIconMap'

const RIBBON_TAB_ICONS: Record<string, LucideIcon> = {
  Definition: Home,
  'Daily Entries': FilePenLine,
  Reports: ClipboardList,
  Tools: Wrench,
  Administration: Settings,
  Help: CircleHelp,
}

type RibbonProps = {
  onCalculator: () => void
  onSoftwareOptions: () => void
  onRecalculateStock: () => void
  onLock: () => void
}

export function Ribbon({ onCalculator, onSoftwareOptions, onRecalculateStock, onLock }: RibbonProps) {
  const { ribbonTab, setRibbonTab, openModule, activeModule } = useWorkspace()
  const { skin } = useAppearance()

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
              {(() => {
                const customIconSrc = resolveRibbonTabIcon(skin, tab.id)
                const TabIcon = RIBBON_TAB_ICONS[tab.label] ?? CircleHelp
                return (
                  <>
                    <span className={`ribbon-tab-icon${customIconSrc ? ' has-custom-icon' : ''}`} aria-hidden>
                      {customIconSrc ? (
                        <img src={customIconSrc} alt="" draggable={false} />
                      ) : (
                        <TabIcon />
                      )}
                    </span>
                    <span className="ribbon-tab-text">{tab.label}</span>
                  </>
                )
              })()}
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
                  if (command.action === 'software-options') {
                    onSoftwareOptions()
                    return
                  }
                  if (command.action === 'recalculate-stock') {
                    onRecalculateStock()
                    return
                  }
                  if (command.action === 'lock') {
                    onLock()
                    return
                  }
                  const path =
                    command.path ??
                    WORKSPACE_MODULES.find((module) => module.key === command.moduleKey)?.path
                  if (path) {
                    const module = command.moduleKey
                      ? WORKSPACE_MODULES.find((row) => row.key === command.moduleKey)
                      : undefined
                    openModule(path, {
                      newInstance: module?.multiInstance === true,
                    })
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
