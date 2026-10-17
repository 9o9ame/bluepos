import { useEffect, useState } from 'react'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { ApplicationTitleBar } from '../components/desktop/ApplicationTitleBar'
import { CalculatorDialog } from '../components/desktop/CalculatorDialog'
import { RecalculateStockDialog } from '../components/desktop/RecalculateStockDialog'
import { SoftwareOptionsDialog } from '../components/desktop/SoftwareOptionsDialog'
import { Ribbon } from '../components/desktop/Ribbon'
import { StatusBar } from '../components/desktop/StatusBar'
import { WorkspaceTabBar } from '../components/desktop/WorkspaceTabBar'
import { SalesInvoicePage } from '../pages/SalesInvoicePage'
import { QuotationEstimatePage } from '../pages/QuotationEstimatePage'
import { SalesReturnsPage } from '../pages/SalesReturnsPage'
import { PurchasesPage } from '../pages/PurchasesPage'
import { PurchaseOrderPage } from '../pages/PurchaseOrderPage'
import { PurchaseReturnsPage } from '../pages/PurchaseReturnsPage'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useAppearance } from '../features/appearance/AppearanceProvider'
import { useAppMode } from '../features/desktop/useAppMode'
import { WorkspaceProvider, useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useWorkspaceShortcuts } from '../features/workspace/useWorkspaceShortcuts'

export function AppShell() {
  const { session, logout } = useAuth()
  const { setUserScope } = useAppearance()
  const navigate = useNavigate()
  const location = useLocation()
  const [loggingOut, setLoggingOut] = useState(false)
  const [calculatorOpen, setCalculatorOpen] = useState(false)
  const [recalculateStockOpen, setRecalculateStockOpen] = useState(false)
  const [softwareOptionsOpen, setSoftwareOptionsOpen] = useState(false)
  const [online, setOnline] = useState(typeof navigator === 'undefined' ? true : navigator.onLine)

  useEffect(() => {
    setUserScope(session?.user.ulid ?? null, session?.appearance ?? null)

    return () => {
      setUserScope(null)
    }
  }, [session?.user.ulid, session?.appearance, setUserScope])

  useEffect(() => {
    function onOnline() {
      setOnline(true)
    }
    function onOffline() {
      setOnline(false)
    }
    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)
    return () => {
      window.removeEventListener('online', onOnline)
      window.removeEventListener('offline', onOffline)
    }
  }, [])

  if (!session) {
    return null
  }

  return (
    <WorkspaceProvider>
      <AppShellFrame
        session={session}
        online={online}
        loggingOut={loggingOut}
        calculatorOpen={calculatorOpen}
        setCalculatorOpen={setCalculatorOpen}
        recalculateStockOpen={recalculateStockOpen}
        setRecalculateStockOpen={setRecalculateStockOpen}
        softwareOptionsOpen={softwareOptionsOpen}
        setSoftwareOptionsOpen={setSoftwareOptionsOpen}
        onLogout={() => {
          setLoggingOut(true)
          void logout().finally(() => {
            setLoggingOut(false)
            navigate('/login', { replace: true })
          })
        }}
        onLock={() => {
          setLoggingOut(true)
          void logout().finally(() => {
            setLoggingOut(false)
            navigate('/login', { replace: true })
          })
        }}
        onChangePassword={() => navigate('/change-password')}
        onAccount={() => navigate('/administration/account')}
        onHome={() => navigate('/')}
        showStatusBar={location.pathname !== '/daily/sales'}
      />
    </WorkspaceProvider>
  )
}

function launchWindowsCalculator(): boolean {
  if (typeof window === 'undefined' || typeof navigator === 'undefined') {
    return false
  }

  const isWindows = navigator.userAgent.includes('Windows') || navigator.platform.startsWith('Win')

  if (!isWindows) {
    return false
  }

  window.location.href = 'ms-calculator:'
  return true
}

function AppShellFrame({
  session,
  online,
  loggingOut,
  calculatorOpen,
  setCalculatorOpen,
  recalculateStockOpen,
  setRecalculateStockOpen,
  softwareOptionsOpen,
  setSoftwareOptionsOpen,
  onLogout,
  onLock,
  onChangePassword,
  onAccount,
  onHome,
  showStatusBar,
}: {
  session: NonNullable<ReturnType<typeof useAuth>['session']>
  online: boolean
  loggingOut: boolean
  calculatorOpen: boolean
  setCalculatorOpen: (open: boolean) => void
  recalculateStockOpen: boolean
  setRecalculateStockOpen: (open: boolean) => void
  softwareOptionsOpen: boolean
  setSoftwareOptionsOpen: (open: boolean) => void
  onLogout: () => void
  onLock: () => void
  onChangePassword: () => void
  onAccount: () => void
  onHome: () => void
  showStatusBar: boolean
}) {
  useWorkspaceShortcuts()
  useAppMode()

  const location = useLocation()
  const { tabs, activeKey } = useWorkspace()
  const canViewSales = useCan('sales.view')
  const canReturnSales = useCan('sales.return')
  const canViewPurchases = useCan('purchases.view')
  const canViewPurchaseReturns = useCan('purchase_returns.view')

  const persistentModuleKeys = new Set([
    'sales-invoice',
    'quotation-estimate',
    'sales-return',
    'purchase-invoice',
    'purchase-order',
    'purchase-return',
  ])

  const persistentTabs = tabs.filter((tab) => {
    const baseKey = tab.key.split(':', 1)[0]
    return persistentModuleKeys.has(baseKey)
  })

  const persistentPathKey: Record<string, string> = {
    '/daily/sales': 'sales-invoice',
    '/daily/quotation-estimate': 'quotation-estimate',
    '/daily/sales-return': 'sales-return',
    '/daily/purchases': 'purchase-invoice',
    '/daily/purchase-orders': 'purchase-order',
    '/daily/purchase-return': 'purchase-return',
  }

  const activePersistentKey = persistentPathKey[location.pathname] ?? null
  const persistentRouteAllowed =
    activePersistentKey === 'sales-invoice'
      ? true
      : activePersistentKey === 'quotation-estimate'
        ? canViewSales
        : activePersistentKey === 'sales-return'
          ? canReturnSales
          : activePersistentKey === 'purchase-invoice' || activePersistentKey === 'purchase-order'
            ? canViewPurchases
            : activePersistentKey === 'purchase-return'
              ? canViewPurchaseReturns
              : false

  return (
    <div className="app-shell">
      <ApplicationTitleBar
        session={session}
        busy={loggingOut}
        onHome={onHome}
        onLogout={onLogout}
        onChangePassword={onChangePassword}
        onAccount={onAccount}
      />
      <Ribbon
        onCalculator={() => {
          if (!launchWindowsCalculator()) {
            setCalculatorOpen(true)
          }
        }}
        onSoftwareOptions={() => setSoftwareOptionsOpen(true)}
        onRecalculateStock={() => setRecalculateStockOpen(true)}
        onLock={onLock}
      />
      <WorkspaceTabBar />
      <div className="workspace-host">
        <div className="workspace-host-inner">
          {persistentTabs.map((tab) => {
            const baseKey = tab.key.split(':', 1)[0]
            const queryIndex = tab.path.indexOf('?')
            const workspaceSearch = queryIndex >= 0 ? tab.path.slice(queryIndex) : ''
            const active = activePersistentKey === baseKey && tab.key === activeKey

            const content =
              baseKey === 'sales-invoice' ? (
                <SalesInvoicePage active={active} workspaceSearch={workspaceSearch} />
              ) : baseKey === 'quotation-estimate' ? (
                <QuotationEstimatePage active={active} />
              ) : baseKey === 'sales-return' ? (
                <SalesReturnsPage active={active} />
              ) : baseKey === 'purchase-invoice' ? (
                <PurchasesPage active={active} workspaceSearch={workspaceSearch} />
              ) : baseKey === 'purchase-order' ? (
                <PurchaseOrderPage />
              ) : baseKey === 'purchase-return' ? (
                <PurchaseReturnsPage active={active} workspaceSearch={workspaceSearch} />
              ) : null

            return (
              <div
                key={tab.key}
                style={{ display: active ? 'contents' : 'none' }}
                aria-hidden={!active}
              >
                {content}
              </div>
            )
          })}
          {!activePersistentKey || !persistentRouteAllowed ? <Outlet /> : null}
        </div>
      </div>
      {showStatusBar ? <StatusBar session={session} online={online} /> : null}
      <CalculatorDialog open={calculatorOpen} onClose={() => setCalculatorOpen(false)} />
      <RecalculateStockDialog
        open={recalculateStockOpen}
        onClose={() => setRecalculateStockOpen(false)}
      />
      <SoftwareOptionsDialog
        open={softwareOptionsOpen}
        onClose={() => setSoftwareOptionsOpen(false)}
      />
    </div>
  )
}
