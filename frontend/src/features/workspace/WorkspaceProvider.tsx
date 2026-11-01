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
  pendingKey: string | null
  activeModule: WorkspaceModule
  ribbonTab: RibbonTabId
  setRibbonTab: (tab: RibbonTabId) => void
  openModule: (path: string, options?: { newInstance?: boolean }) => void
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
  const [pendingKey, setPendingKey] = useState<string | null>(null)
  const handlersRef = useRef<WorkspaceHandlers>({})
  const tabsRef = useRef(tabs)
  tabsRef.current = tabs

  const activeModule = useMemo(() => resolveWorkspaceModule(location.pathname), [location.pathname])
  const identity = useMemo(
    () => moduleTabIdentity(activeModule, location.pathname, location.search),
    [activeModule, location.pathname, location.search],
  )

  useEffect(() => {
    setPendingKey(null)
    setRibbonTab(activeModule.ribbon)
    setTabs((current) => {
      const existing = current.find(tab => tab.key === identity.key)
      if (existing?.path === identity.path && existing.title === identity.title) return current
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
    (path: string, options?: { newInstance?: boolean }) => {
      const open = (destination: string) => {
        const index = destination.indexOf('?')
        const pathname = index >= 0 ? destination.slice(0, index) : destination
        const module = resolveWorkspaceModule(pathname)
        const target = moduleTabIdentity(module, pathname, index >= 0 ? destination.slice(index) : '')
        // Tab feedback is urgent; router/page rendering may run in a transition.
        setPendingKey(target.key)
        setTabs(current => current.some(tab => tab.key === target.key) ? current : [...current, {
          key: target.key, title: target.title, path: target.path,
          closeable: module.closeable !== false && target.key !== 'home', dirty: false,
        }])
        navigate(destination)
      }
      if (options?.newInstance) {
        const queryIndex = path.indexOf('?')
        const pathname = queryIndex >= 0 ? path.slice(0, queryIndex) : path
        const module = resolveWorkspaceModule(pathname)

        if (module.multiInstance) {
          const keyPrefix = `${module.key}:`
          const highestInstance = tabsRef.current.reduce((highest, tab) => {
            if (!tab.key.startsWith(keyPrefix)) return highest
            const instance = Number.parseInt(tab.key.slice(keyPrefix.length), 10)
            return Number.isFinite(instance) ? Math.max(highest, instance) : highest
          }, 0)
          const instance = highestInstance + 1
          const params = new URLSearchParams(queryIndex >= 0 ? path.slice(queryIndex + 1) : '')
          params.set('instance', String(instance))
          open(`${pathname}?${params.toString()}`)
          return
        }
      }

      open(path)
    },
    [navigate],
  )

  const closeTab = useCallback(
    (key: string) => {
      void (async () => {
        const current = tabsRef.current
        const target = current.find((tab) => tab.key === key)
        if (!target || !target.closeable) return
        if (target.dirty) {
          const { askConfirm } = await import('../../feedback/FeedbackProvider')
          if (!(await askConfirm('This workspace has unsaved changes. Close anyway?'))) {
            return
          }
        }
        setTabs((latest) => {
          const remaining = latest.filter((tab) => tab.key !== key)
          if (identity.key === key) {
            const fallback = remaining[remaining.length - 1] ?? HOME_TAB
            navigate(fallback.path)
          }
          return remaining.length > 0 ? remaining : [HOME_TAB]
        })
      })()
    },
    [identity.key, navigate],
  )

  const closeActiveTab = useCallback(() => {
    closeTab(identity.key)
  }, [closeTab, identity.key])

  const markDirty = useCallback((key: string, dirty: boolean) => {
    setTabs((current) => current.some(tab => tab.key === key && tab.dirty !== dirty)
      ? current.map((tab) => (tab.key === key ? { ...tab, dirty } : tab)) : current)
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
      pendingKey,
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
      pendingKey,
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

export function useWorkspaceHandlers(
  handlers: WorkspaceHandlers,
  enabled = true,
): void {
  const { registerHandlers } = useWorkspace()
  const save = handlers.save
  const refresh = handlers.refresh

  useEffect(() => {
    if (!enabled) return
    return registerHandlers({ save, refresh })
  }, [enabled, registerHandlers, save, refresh])
}
