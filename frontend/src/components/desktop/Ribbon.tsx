import { Children, isValidElement, type ReactNode } from 'react'
import {
  CircleHelp,
  ClipboardList,
  FilePenLine,
  FolderTree,
  Home,
  List,
  Settings,
  Wrench,
  type LucideIcon,
} from 'lucide-react'
import { useAppearance } from '../../features/appearance/AppearanceProvider'
import { useAuth } from '../../features/auth/AuthProvider'
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
  const { session } = useAuth()

  const canUse = (permission?: string, entitlement?: string) => {
    const permissionAllowed = !permission || Boolean(session?.permissions.includes(permission))
    const features = session?.entitlements?.features
    const entitlementAllowed = !entitlement || !features || features.includes(entitlement)
    return permissionAllowed && entitlementAllowed
  }

  const openRibbonPath = (path: string) => {
    const module = WORKSPACE_MODULES.find((row) => row.path === path)
    openModule(path, { newInstance: module?.multiInstance === true })
  }

  const definitionProducts = RIBBON_GROUPS.definition
    .find((group) => group.id === 'products')
    ?.commands ?? []

  const compactDefinitionPrimary = definitionProducts
    .filter((command) => ['products', 'tabular', 'stock-taking'].includes(command.id))
    .filter((command) => canUse(command.permission, command.entitlement))

  const compactDefinitionMasters = definitionProducts
    .filter((command) => ['categories', 'subcategories', 'brands', 'units', 'sale-schemes'].includes(command.id))
    .filter((command) => canUse(command.permission, command.entitlement))
    .map((command) => ({
      label: command.label,
      status: command.status,
      path: command.path ?? WORKSPACE_MODULES.find((row) => row.key === command.moduleKey)?.path,
    }))
    .filter((item): item is { label: string; status: 'ready' | 'shell' | 'later'; path: string } => Boolean(item.path))

  const compactDefinitionParties = RIBBON_GROUPS.definition
    .find((group) => group.id === 'parties')
    ?.commands
    .find((command) => canUse(command.permission, command.entitlement))

  const compactDefinitionLists = RIBBON_GROUPS.definition
    .find((group) => group.id === 'later-catalog')
    ?.commands
    .filter((command) => canUse(command.permission, command.entitlement))
    .map((command) => ({
      label: command.label,
      status: command.status,
      path: command.path ?? WORKSPACE_MODULES.find((row) => row.key === command.moduleKey)?.path,
    }))
    .filter((item): item is { label: string; status: 'ready' | 'shell' | 'later'; path: string } => Boolean(item.path)) ?? []

  const compactSalesMenu = RIBBON_GROUPS['daily-entries']
    .find((group) => group.id === 'sales')
    ?.commands
    .filter((command) => canUse(command.permission, command.entitlement))
    .map((command) => ({ label: command.label, status: command.status, path: command.path ?? WORKSPACE_MODULES.find((row) => row.key === command.moduleKey)?.path }))
    .filter((item): item is { label: string; status: 'ready' | 'shell' | 'later'; path: string } => Boolean(item.path)) ?? []

  const compactAdjustment = RIBBON_GROUPS['daily-entries']
    .find((group) => group.id === 'purchases')
    ?.commands
    .find((command) => command.id === 'adjustments')

  const compactPurchaseMenu = RIBBON_GROUPS['daily-entries']
    .find((group) => group.id === 'purchases')
    ?.commands
    .filter((command) => command.id !== 'adjustments')
    .filter((command) => canUse(command.permission, command.entitlement))
    .map((command) => ({ label: command.label, status: command.status, path: command.path ?? WORKSPACE_MODULES.find((row) => row.key === command.moduleKey)?.path }))
    .filter((item): item is { label: string; status: 'ready' | 'shell' | 'later'; path: string } => Boolean(item.path)) ?? []

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
        {ribbonTab === 'definition' ? (
          <div className="ribbon-definition-compact" aria-label="Compact definition navigation">
            <RibbonGroup caption="Definitions">
              {compactDefinitionPrimary.map((command) => (
                <RibbonCommand
                  key={command.id}
                  {...command}
                  active={Boolean(command.moduleKey && command.moduleKey === activeModule.key)}
                  onNavigate={openRibbonPath}
                  onClick={() => {
                    const path =
                      command.path ??
                      WORKSPACE_MODULES.find((module) => module.key === command.moduleKey)?.path
                    if (path) openRibbonPath(path)
                  }}
                />
              ))}

              {compactDefinitionMasters.length ? (
                <RibbonCommand
                  id="definition-masters-compact"
                  label="Masters"
                  icon={FolderTree}
                  status="ready"
                  tone="blue"
                  hasMenu
                  menu={compactDefinitionMasters}
                  active={['categories', 'subcategories', 'brands', 'units', 'sale-schemes'].includes(activeModule.key)}
                  onNavigate={openRibbonPath}
                />
              ) : null}
            </RibbonGroup>

            {compactDefinitionParties ? (
              <RibbonGroup caption="Parties">
                <RibbonCommand
                  {...compactDefinitionParties}
                  active={compactDefinitionParties.moduleKey === activeModule.key}
                  onNavigate={openRibbonPath}
                  onClick={() => {
                    const path =
                      compactDefinitionParties.path ??
                      WORKSPACE_MODULES.find((module) => module.key === compactDefinitionParties.moduleKey)?.path
                    if (path) openRibbonPath(path)
                  }}
                />
              </RibbonGroup>
            ) : null}

            {compactDefinitionLists.length ? (
              <RibbonGroup caption="Printing / Lists">
                <RibbonCommand
                  id="definition-lists-compact"
                  label="Lists"
                  icon={List}
                  status="ready"
                  tone="teal"
                  hasMenu
                  menu={compactDefinitionLists}
                  active={['opening-stock', 'barcode-printing', 'price-lists'].includes(activeModule.key)}
                  onNavigate={openRibbonPath}
                />
              </RibbonGroup>
            ) : null}
          </div>
        ) : null}

        {ribbonTab === 'daily-entries' ? (
          <div className="ribbon-daily-compact" aria-label="Compact daily entries navigation">
            {compactSalesMenu.length ? (
              <RibbonGroup caption="Sales">
                <RibbonCommand
                  id="sales-compact"
                  label="Sales"
                  icon={FilePenLine}
                  status="ready"
                  tone="blue"
                  hasMenu
                  menu={compactSalesMenu}
                  active={['sales-invoice', 'quotation-estimate', 'sales-return'].includes(activeModule.key)}
                  onNavigate={openRibbonPath}
                />
              </RibbonGroup>
            ) : null}
            {compactPurchaseMenu.length ? (
              <RibbonGroup caption="Purchases">
                <RibbonCommand
                  id="purchase-compact"
                  label="Purchase"
                  icon={ClipboardList}
                  status="ready"
                  tone="gold"
                  hasMenu
                  menu={compactPurchaseMenu}
                  active={['purchase-invoice', 'purchase-order', 'purchase-return'].includes(activeModule.key)}
                  onNavigate={openRibbonPath}
                />
                {compactAdjustment && canUse(compactAdjustment.permission, compactAdjustment.entitlement) ? (
                  <RibbonCommand
                    {...compactAdjustment}
                    active={compactAdjustment.moduleKey === activeModule.key}
                    onNavigate={openRibbonPath}
                    onClick={() => {
                      const path = compactAdjustment.path ?? WORKSPACE_MODULES.find((row) => row.key === compactAdjustment.moduleKey)?.path
                      if (path) openRibbonPath(path)
                    }}
                  />
                ) : null}
              </RibbonGroup>
            ) : null}
          </div>
        ) : null}
        {RIBBON_GROUPS[ribbonTab].map((group) => (
          <VisibleRibbonGroup key={group.id} caption={group.caption} className={`ribbon-group-${group.id}`}>
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

function VisibleRibbonGroup({ caption, children, className }: { caption: string; children: ReactNode; className?: string }) {
  const visible = Children.toArray(children).some((child) => isValidElement(child))
  if (!visible) {
    return null
  }
  return (
    <div className={className}>
      <RibbonGroup caption={caption}>{children}</RibbonGroup>
    </div>
  )
}
