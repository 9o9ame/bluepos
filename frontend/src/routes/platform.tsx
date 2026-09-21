import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { usePlatformAuth } from '../features/platform/PlatformAuthProvider'
import { PlatformShell } from '../layouts/PlatformShell'
import { PlatformAdminsPage } from '../pages/platform/PlatformAdminsPage'
import { PlatformAuditPage } from '../pages/platform/PlatformAuditPage'
import { PlatformDashboardPage } from '../pages/platform/PlatformDashboardPage'
import { PlatformLoginPage } from '../pages/platform/PlatformLoginPage'
import { PlatformPlanEditorPage } from '../pages/platform/PlatformPlanEditorPage'
import { PlatformPlansPage } from '../pages/platform/PlatformPlansPage'
import { PlatformTenantDetailPage } from '../pages/platform/PlatformTenantDetailPage'
import { PlatformTenantsPage } from '../pages/platform/PlatformTenantsPage'

function Splash() {
  return <div className="grid h-screen place-items-center bg-slate-950 text-sm text-amber-300">Loading platform…</div>
}

function RequirePlatformAuth() {
  const { user, isLoading } = usePlatformAuth()
  if (isLoading) {
    return <Splash />
  }
  if (!user) {
    return <Navigate to="/platform/login" replace />
  }
  return <Outlet />
}

export function PlatformRoutes() {
  return (
    <Routes>
      <Route path="login" element={<PlatformLoginPage />} />
      <Route element={<RequirePlatformAuth />}>
        <Route element={<PlatformShell />}>
          <Route index element={<PlatformDashboardPage />} />
          <Route path="dashboard" element={<PlatformDashboardPage />} />
          <Route path="tenants" element={<PlatformTenantsPage />} />
          <Route path="tenants/:tenantUlid" element={<PlatformTenantDetailPage />} />
          <Route path="plans" element={<PlatformPlansPage />} />
          <Route path="plans/:planUlid" element={<PlatformPlanEditorPage />} />
          <Route path="security/audit" element={<PlatformAuditPage />} />
          <Route path="admins" element={<PlatformAdminsPage />} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/platform" replace />} />
    </Routes>
  )
}
