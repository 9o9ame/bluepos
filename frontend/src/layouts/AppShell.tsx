import { useEffect, useState } from 'react'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { ApplicationTitleBar } from '../components/desktop/ApplicationTitleBar'
import { CalculatorDialog } from '../components/desktop/CalculatorDialog'
import { Ribbon } from '../components/desktop/Ribbon'
import { StatusBar } from '../components/desktop/StatusBar'
import { WorkspaceTabBar } from '../components/desktop/WorkspaceTabBar'
import { useAuth } from '../features/auth/AuthProvider'
import { useAppearance } from '../features/appearance/AppearanceProvider'
import { useAppMode } from '../features/desktop/useAppMode'
import { WorkspaceProvider } from '../features/workspace/WorkspaceProvider'
import { useWorkspaceShortcuts } from '../features/workspace/useWorkspaceShortcuts'

export function AppShell() {
  const { session, logout } = useAuth()
  const { setUserScope } = useAppearance()
  const navigate = useNavigate()
  const location = useLocation()
  const [loggingOut, setLoggingOut] = useState(false)
  const [calculatorOpen, setCalculatorOpen] = useState(false)
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
        onLogout={() => {
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

function AppShellFrame({
  session,
  online,
  loggingOut,
  calculatorOpen,
  setCalculatorOpen,
  onLogout,
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
  onLogout: () => void
  onChangePassword: () => void
  onAccount: () => void
  onHome: () => void
  showStatusBar: boolean
}) {
  useWorkspaceShortcuts()
  useAppMode()

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
      <Ribbon onCalculator={() => setCalculatorOpen(true)} />
      <WorkspaceTabBar />
      <div className="workspace-host">
        <div className="workspace-host-inner">
          <Outlet />
        </div>
      </div>
      {showStatusBar ? <StatusBar session={session} online={online} /> : null}
      <CalculatorDialog open={calculatorOpen} onClose={() => setCalculatorOpen(false)} />
    </div>
  )
}
