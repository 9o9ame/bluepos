import { useState } from 'react'
import { Outlet, useNavigate } from 'react-router-dom'
import { BranchSelector } from '../components/BranchSelector'
import { RibbonGroups } from '../components/RibbonGroups'
import { TopRibbon, type RibbonTab } from '../components/TopRibbon'
import { UserPanel } from '../components/UserPanel'
import { WorkspaceTabs } from '../components/WorkspaceTabs'
import { useAuth } from '../features/auth/AuthProvider'

export function AppShell() {
  const { session, logout } = useAuth()
  const navigate = useNavigate()
  const [ribbonTab, setRibbonTab] = useState<RibbonTab>('Definition')
  const [workspaceTab, setWorkspaceTab] = useState('home')
  const [loggingOut, setLoggingOut] = useState(false)

  if (!session) {
    return null
  }

  return (
    <div className="flex h-screen flex-col bg-[#d9dee6] text-slate-900">
      <header className="flex items-center justify-between bg-[#1f4e79] px-3 py-1.5">
        <div className="flex items-center gap-4">
          <div className="text-[15px] font-black tracking-[0.18em] text-white">BLUEPOS</div>
          <BranchSelector session={session} />
        </div>
        <UserPanel
          session={session}
          busy={loggingOut}
          onLogout={() => {
            setLoggingOut(true)
            void logout().finally(() => {
              setLoggingOut(false)
              navigate('/login', { replace: true })
            })
          }}
        />
      </header>

      <TopRibbon activeTab={ribbonTab} onChange={setRibbonTab} />
      <WorkspaceTabs
        tabs={[
          { id: 'home', title: 'Home' },
          { id: ribbonTab.toLowerCase().replace(' ', '-'), title: ribbonTab },
        ]}
        activeId={workspaceTab}
        onSelect={setWorkspaceTab}
      />

      <div className="flex min-h-0 flex-1">
        <RibbonGroups tab={ribbonTab} />
        <main className="min-w-0 flex-1 overflow-auto bg-[#eef2f6] p-3">
          <Outlet context={{ ribbonTab, workspaceTab }} />
        </main>
      </div>

      <footer className="flex items-center justify-between border-t border-slate-400 bg-slate-200 px-3 py-1 text-[11px] text-slate-600">
        <span>
          {session.branch.name} / {session.warehouse.name}
        </span>
        <span>Phase 2.5 account and device security</span>
      </footer>
    </div>
  )
}
