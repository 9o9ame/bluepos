==================================================
FILE: frontend/src/layouts/AppShell.tsx
==================================================

```tsx
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
```

==================================================
FILE: frontend/src/components/BranchSelector.tsx
==================================================

```tsx
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchBranches, switchBranch } from '../api/branches'
import { ApiClientError } from '../api/client'
import type { AuthSession } from '../types/auth'

type BranchSelectorProps = {
  session: AuthSession
}

export function BranchSelector({ session }: BranchSelectorProps) {
  const queryClient = useQueryClient()
  const branchesQuery = useQuery({
    queryKey: ['branches'],
    queryFn: fetchBranches,
  })

  const switchMutation = useMutation({
    mutationFn: switchBranch,
    onSuccess: (nextSession) => {
      queryClient.setQueryData(['auth', 'me'], nextSession)
    },
  })

  const branches = branchesQuery.data ?? [session.branch]
  const error = switchMutation.error instanceof ApiClientError ? switchMutation.error.message : null

  return (
    <label className="flex items-center gap-2 text-[12px] text-white">
      <span className="text-slate-300">Branch</span>
      <select
        className="h-7 min-w-[10rem] rounded border border-slate-500 bg-slate-800 px-2 text-white"
        value={session.branch.ulid}
        onChange={(event) => {
          const next = event.target.value
          if (next !== session.branch.ulid) {
            void switchMutation.mutateAsync(next)
          }
        }}
      >
        {branches.map((branch) => (
          <option key={branch.ulid} value={branch.ulid}>
            {branch.code} — {branch.name}
          </option>
        ))}
      </select>
      {error ? <span className="text-red-300">{error}</span> : null}
    </label>
  )
}
```

==================================================
FILE: frontend/src/components/UserPanel.tsx
==================================================

```tsx
import type { AuthSession } from '../types/auth'

type UserPanelProps = {
  session: AuthSession
  onLogout: () => void
  busy: boolean
}

export function UserPanel({ session, onLogout, busy }: UserPanelProps) {
  return (
    <div className="flex items-center gap-3 text-[12px] text-white">
      <div className="text-right leading-tight">
        <div className="font-semibold">{session.user.name}</div>
        <div className="text-slate-300">{session.tenant.name}</div>
      </div>
      <button
        type="button"
        className="h-7 rounded border border-slate-400 bg-slate-700 px-3 hover:bg-slate-600 disabled:opacity-60"
        onClick={onLogout}
        disabled={busy}
      >
        Logout
      </button>
    </div>
  )
}
```

==================================================
FILE: frontend/src/components/TopRibbon.tsx
==================================================

```tsx
const RIBBON_TABS = ['Definition', 'Daily Entries', 'Reports', 'Tools', 'Administration', 'Help'] as const

export type RibbonTab = (typeof RIBBON_TABS)[number]

type TopRibbonProps = {
  activeTab: RibbonTab
  onChange: (tab: RibbonTab) => void
}

export function TopRibbon({ activeTab, onChange }: TopRibbonProps) {
  return (
    <div className="border-b border-slate-400 bg-gradient-to-b from-slate-100 to-slate-200">
      <div className="flex items-end gap-1 px-2 pt-1" role="tablist" aria-label="Main ribbon">
        {RIBBON_TABS.map((tab) => {
          const selected = tab === activeTab
          return (
            <button
              key={tab}
              type="button"
              role="tab"
              aria-selected={selected}
              className={`rounded-t px-3 py-1 text-[12px] font-semibold tracking-wide ${
                selected
                  ? 'border border-b-0 border-slate-400 bg-white text-slate-900'
                  : 'border border-transparent text-slate-600 hover:bg-white/70'
              }`}
              onClick={() => onChange(tab)}
            >
              {tab}
            </button>
          )
        })}
      </div>
    </div>
  )
}

export { RIBBON_TABS }
```

==================================================
FILE: frontend/src/components/RibbonGroups.tsx
==================================================

```tsx
import { useNavigate } from 'react-router-dom'
import type { RibbonTab } from './TopRibbon'
import { useCan, useEntitled } from '../features/auth/useCan'

const GROUPS: Record<RibbonTab, string[]> = {
  Definition: ['Business Settings', 'Categories', 'Subcategories', 'Brands', 'Units', 'Products', 'Parties'],
  'Daily Entries': ['Sales', 'Sale Return', 'Purchases', 'Stock Adjust'],
  Reports: ['Sales Report', 'Stock Report', 'Day Book'],
  Tools: ['Column Layout', 'Keyboard Shortcuts', 'Backup'],
  Administration: ['Users', 'Roles', 'Settings', 'Devices'],
  Help: ['About BluePOS', 'Documentation'],
}

export function RibbonGroups({ tab }: { tab: RibbonTab }) {
  const navigate = useNavigate()
  const canUsers = useCan('users.view')
  const canRoles = useCan('roles.view')
  const canSettings = useCan('settings.view')
  const canCategories = useCan('categories.view') || useCan('categories.manage')
  const canBrands = useCan('brands.view') || useCan('brands.manage')
  const canUnits = useCan('units.view') || useCan('units.manage')
  const canProducts = useCan('products.view')
  const canDevices = useCan('devices.view')
  const catalogEnabled = useEntitled('catalog')

  const enabledFor = (item: string): boolean => {
    if (item === 'Users') return canUsers
    if (item === 'Roles') return canRoles
    if (item === 'Settings' || item === 'Business Settings') return canSettings
    if (item === 'Categories' || item === 'Subcategories') return canCategories && catalogEnabled
    if (item === 'Brands') return canBrands && catalogEnabled
    if (item === 'Units') return canUnits && catalogEnabled
    if (item === 'Products') return canProducts && catalogEnabled
    if (item === 'Devices') return canDevices
    return false
  }

  return (
    <aside className="w-56 shrink-0 overflow-y-auto border-r border-slate-400 bg-slate-100">
      <div className="border-b border-slate-300 px-3 py-2 text-[11px] font-bold uppercase tracking-wider text-slate-500">
        {tab}
      </div>
      <div className="flex flex-col p-2">
        {GROUPS[tab].map((item) => {
          const enabled = enabledFor(item)
          return (
            <button
              key={item}
              type="button"
              disabled={!enabled}
              title={enabled ? item : 'Available in a later phase'}
              className={`mb-1 rounded border px-2 py-1.5 text-left text-[12px] ${
                enabled
                  ? 'border-slate-400 bg-white text-slate-800 hover:bg-slate-50'
                  : 'border-slate-300 bg-white text-slate-400'
              }`}
              onClick={() => {
                if (item === 'Users') navigate('/administration/users')
                if (item === 'Roles') navigate('/administration/roles')
                if (item === 'Settings' || item === 'Business Settings') navigate('/administration/settings')
                if (item === 'Categories') navigate('/definition/categories')
                if (item === 'Subcategories') navigate('/definition/subcategories')
                if (item === 'Brands') navigate('/definition/brands')
                if (item === 'Units') navigate('/definition/units')
                if (item === 'Products') navigate('/definition/products')
                if (item === 'Devices') navigate('/administration/devices')
              }}
            >
              {item}
            </button>
          )
        })}
      </div>
    </aside>
  )
}
```

==================================================
FILE: frontend/src/components/WorkspaceTabs.tsx
==================================================

```tsx
type WorkspaceTab = {
  id: string
  title: string
}

type WorkspaceTabsProps = {
  tabs: WorkspaceTab[]
  activeId: string
  onSelect: (id: string) => void
}

export function WorkspaceTabs({ tabs, activeId, onSelect }: WorkspaceTabsProps) {
  return (
    <div className="flex items-center gap-1 border-b border-slate-400 bg-slate-700 px-2 py-1">
      {tabs.map((tab) => {
        const selected = tab.id === activeId
        return (
          <button
            key={tab.id}
            type="button"
            className={`rounded-sm px-3 py-0.5 text-[12px] ${
              selected ? 'bg-white text-slate-900' : 'bg-slate-600 text-white hover:bg-slate-500'
            }`}
            onClick={() => onSelect(tab.id)}
          >
            {tab.title}
          </button>
        )
      })}
    </div>
  )
}
```

==================================================
FILE: frontend/src/pages/WorkspacePage.tsx
==================================================

```tsx
import { useOutletContext } from 'react-router-dom'
import { useAuth } from '../features/auth/AuthProvider'
import type { RibbonTab } from '../components/TopRibbon'

type ShellContext = {
  ribbonTab: RibbonTab
  workspaceTab: string
}

export function WorkspacePage() {
  const { session } = useAuth()
  const { ribbonTab } = useOutletContext<ShellContext>()

  if (!session) {
    return null
  }

  return (
    <section className="h-full rounded border border-slate-300 bg-white p-4 shadow-sm">
      <h2 className="text-base font-semibold">Welcome, {session.user.name}</h2>
      <p className="mt-1 text-[13px] text-slate-600">
        {session.tenant.name} is ready. {ribbonTab} tools are placeholders until later phases.
      </p>
      <dl className="mt-4 grid max-w-xl grid-cols-2 gap-2 text-[12px]">
        <dt className="text-slate-500">Tenant</dt>
        <dd>{session.tenant.name}</dd>
        <dt className="text-slate-500">Branch</dt>
        <dd>
          {session.branch.code} — {session.branch.name}
        </dd>
        <dt className="text-slate-500">Warehouse</dt>
        <dd>
          {session.warehouse.code} — {session.warehouse.name}
        </dd>
        <dt className="text-slate-500">Role</dt>
        <dd>{session.roles.map((role) => role.name).join(', ') || (session.membership.is_owner ? 'Owner' : 'Member')}</dd>
      </dl>
    </section>
  )
}
```

==================================================
FILE: frontend/src/index.css
==================================================

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

html,
body,
#root {
  height: 100%;
}

body {
  margin: 0;
  font-family: "Segoe UI", Tahoma, sans-serif;
}
```

==================================================
FILE: frontend/src/routes/index.tsx
==================================================

```tsx
import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { AppShell } from '../layouts/AppShell'
import { BrandsPage } from '../pages/BrandsPage'
import { BusinessSettingsPage } from '../pages/BusinessSettingsPage'
import { CategoriesPage } from '../pages/CategoriesPage'
import { ChangePasswordPage } from '../pages/ChangePasswordPage'
import { DevicesPage } from '../pages/DevicesPage'
import { ForgotPasswordPage } from '../pages/ForgotPasswordPage'
import { LoginPage } from '../pages/LoginPage'
import { ProductEditorPage } from '../pages/ProductEditorPage'
import { ProductsPage } from '../pages/ProductsPage'
import { RoleEditorPage } from '../pages/RoleEditorPage'
import { RolesPage } from '../pages/RolesPage'
import { SubcategoriesPage } from '../pages/SubcategoriesPage'
import { UnitsPage } from '../pages/UnitsPage'
import { UsersPage } from '../pages/UsersPage'
import { WorkspacePage } from '../pages/WorkspacePage'

function Splash() {
  return (
    <div className="grid h-screen place-items-center bg-[#1f4e79] text-sm text-white">
      Loading BluePOS…
    </div>
  )
}

function RequireAuth() {
  const { session, isLoading } = useAuth()
  if (isLoading) {
    return <Splash />
  }
  if (!session) {
    return <Navigate to="/login" replace />
  }
  if (session.must_change_password) {
    return <Navigate to="/change-password" replace />
  }
  return <Outlet />
}

function RequirePermission({ permission }: { permission: string }) {
  const allowed = useCan(permission)
  if (!allowed) {
    return <Navigate to="/" replace />
  }
  return <Outlet />
}

export function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      <Route path="/change-password" element={<ChangePasswordPage />} />
      <Route element={<RequireAuth />}>
        <Route element={<AppShell />}>
          <Route path="/" element={<WorkspacePage />} />
          <Route element={<RequirePermission permission="users.view" />}>
            <Route path="/administration/users" element={<UsersPage />} />
          </Route>
          <Route element={<RequirePermission permission="roles.view" />}>
            <Route path="/administration/roles" element={<RolesPage />} />
            <Route path="/administration/roles/:roleUlid" element={<RoleEditorPage />} />
          </Route>
          <Route element={<RequirePermission permission="devices.view" />}>
            <Route path="/administration/devices" element={<DevicesPage />} />
          </Route>
          <Route element={<RequirePermission permission="settings.view" />}>
            <Route path="/administration/settings" element={<BusinessSettingsPage />} />
          </Route>
          <Route element={<RequirePermission permission="categories.view" />}>
            <Route path="/definition/categories" element={<CategoriesPage />} />
            <Route path="/definition/subcategories" element={<SubcategoriesPage />} />
          </Route>
          <Route element={<RequirePermission permission="brands.view" />}>
            <Route path="/definition/brands" element={<BrandsPage />} />
          </Route>
          <Route element={<RequirePermission permission="units.view" />}>
            <Route path="/definition/units" element={<UnitsPage />} />
          </Route>
          <Route element={<RequirePermission permission="products.view" />}>
            <Route path="/definition/products" element={<ProductsPage />} />
            <Route path="/definition/products/:productUlid" element={<ProductEditorPage />} />
          </Route>
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
```
