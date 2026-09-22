import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import {
  moduleTabIdentity,
  resolveWorkspaceModule,
  type RibbonTabId,
  type WorkspaceModule,
} from './modules'

export type OpenWorkspaceTab = {
  key: string
  title: string
  path: string
  closeable: boolean
  dirty: boolean
}

type WorkspaceHandlers = {
  save?: () => void
  refresh?: () => void
}

type WorkspaceContextValue = {
  tabs: OpenWorkspaceTab[]
  activeKey: string
  activeModule: WorkspaceModule
  ribbonTab: RibbonTabId
  setRibbonTab: (tab: RibbonTabId) => void
  openModule: (path: string) => void
  closeTab: (key: string) => void
  closeActiveTab: () => void
  markDirty: (key: string, dirty: boolean) => void
  registerHandlers: (handlers: WorkspaceHandlers) => () => void
  triggerSave: () => void
  triggerRefresh: () => void
}

const WorkspaceContext = createContext<WorkspaceContextValue | null>(null)

const HOME_TAB: OpenWorkspaceTab = {
  key: 'home',
  title: 'Home',
  path: '/',
  closeable: false,
  dirty: false,
}

export function WorkspaceProvider({ children }: { children: ReactNode }) {
  const location = useLocation()
  const navigate = useNavigate()
  const [tabs, setTabs] = useState<OpenWorkspaceTab[]>([HOME_TAB])
  const [ribbonTab, setRibbonTab] = useState<RibbonTabId>('definition')
  const handlersRef = useRef<WorkspaceHandlers>({})

  const activeModule = useMemo(() => resolveWorkspaceModule(location.pathname), [location.pathname])
  const identity = useMemo(
    () => moduleTabIdentity(activeModule, location.pathname),
    [activeModule, location.pathname],
  )

  useEffect(() => {
    setRibbonTab(activeModule.ribbon)
    setTabs((current) => {
      if (current.some((tab) => tab.key === identity.key)) {
        return current.map((tab) =>
          tab.key === identity.key ? { ...tab, path: identity.path, title: identity.title } : tab,
        )
      }
      return [
        ...current,
        {
          key: identity.key,
          title: identity.title,
          path: identity.path,
          closeable: activeModule.closeable !== false && identity.key !== 'home',
          dirty: false,
        },
      ]
    })
  }, [activeModule, identity])

  const openModule = useCallback(
    (path: string) => {
      navigate(path)
    },
    [navigate],
  )

  const closeTab = useCallback(
    (key: string) => {
      setTabs((current) => {
        const target = current.find((tab) => tab.key === key)
        if (!target || !target.closeable) {
          return current
        }
        if (target.dirty) {
          const proceed = window.confirm('This workspace has unsaved changes. Close anyway?')
          if (!proceed) {
            return current
          }
        }
        const remaining = current.filter((tab) => tab.key !== key)
        if (identity.key === key) {
          const fallback = remaining[remaining.length - 1] ?? HOME_TAB
          navigate(fallback.path)
        }
        return remaining.length > 0 ? remaining : [HOME_TAB]
      })
    },
    [identity.key, navigate],
  )

  const closeActiveTab = useCallback(() => {
    closeTab(identity.key)
  }, [closeTab, identity.key])

  const markDirty = useCallback((key: string, dirty: boolean) => {
    setTabs((current) => current.map((tab) => (tab.key === key ? { ...tab, dirty } : tab)))
  }, [])

  const registerHandlers = useCallback((handlers: WorkspaceHandlers) => {
    handlersRef.current = handlers
    return () => {
      handlersRef.current = {}
    }
  }, [])

  const triggerSave = useCallback(() => {
    handlersRef.current.save?.()
  }, [])

  const triggerRefresh = useCallback(() => {
    handlersRef.current.refresh?.()
  }, [])

  const value = useMemo<WorkspaceContextValue>(
    () => ({
      tabs,
      activeKey: identity.key,
      activeModule,
      ribbonTab,
      setRibbonTab,
      openModule,
      closeTab,
      closeActiveTab,
      markDirty,
      registerHandlers,
      triggerSave,
      triggerRefresh,
    }),
    [
      tabs,
      identity.key,
      activeModule,
      ribbonTab,
      openModule,
      closeTab,
      closeActiveTab,
      markDirty,
      registerHandlers,
      triggerSave,
      triggerRefresh,
    ],
  )

  return <WorkspaceContext.Provider value={value}>{children}</WorkspaceContext.Provider>
}

export function useWorkspace(): WorkspaceContextValue {
  const value = useContext(WorkspaceContext)
  if (!value) {
    throw new Error('useWorkspace must be used inside WorkspaceProvider')
  }
  return value
}

export function useWorkspaceHandlers(handlers: WorkspaceHandlers): void {
  const { registerHandlers } = useWorkspace()
  const save = handlers.save
  const refresh = handlers.refresh
  useEffect(() => registerHandlers({ save, refresh }), [registerHandlers, save, refresh])
}
