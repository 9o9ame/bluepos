import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { usePlatformAuth } from '../features/platform/PlatformAuthProvider'
import { PlatformShell } from '../layouts/PlatformShell'
import { PlatformAccountPasswordPage } from '../pages/platform/PlatformAccountPasswordPage'
import { PlatformAccountProfilePage } from '../pages/platform/PlatformAccountProfilePage'
import { PlatformAccountSecurityPage } from '../pages/platform/PlatformAccountSecurityPage'
import { PlatformAdminsPage } from '../pages/platform/PlatformAdminsPage'
import { PlatformAuditPage } from '../pages/platform/PlatformAuditPage'
import { PlatformChangePasswordPage } from '../pages/platform/PlatformChangePasswordPage'
import { PlatformDashboardPage } from '../pages/platform/PlatformDashboardPage'
import { PlatformLoginPage } from '../pages/platform/PlatformLoginPage'
import { PlatformPermissionsPage } from '../pages/platform/PlatformPermissionsPage'
import { PlatformPlanEditorPage } from '../pages/platform/PlatformPlanEditorPage'
import { PlatformPlansPage } from '../pages/platform/PlatformPlansPage'
import { PlatformRoleEditorPage } from '../pages/platform/PlatformRoleEditorPage'
import { PlatformRolesPage } from '../pages/platform/PlatformRolesPage'
import { PlatformSettingsPage } from '../pages/platform/PlatformSettingsPage'
import { PlatformTenantDetailPage } from '../pages/platform/PlatformTenantDetailPage'
import { PlatformTenantsPage } from '../pages/platform/PlatformTenantsPage'
import { PlatformUsersPage } from '../pages/platform/PlatformUsersPage'

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

function RequirePlatformPassword() {
  const { user } = usePlatformAuth()
  if (user?.must_change_password) {
    return <Navigate to="/platform/change-password" replace />
  }
  return <Outlet />
}

export function PlatformRoutes() {
  return (
    <Routes>
      <Route path="login" element={<PlatformLoginPage />} />
      <Route element={<RequirePlatformAuth />}>
        <Route path="change-password" element={<PlatformChangePasswordPage />} />
        <Route element={<RequirePlatformPassword />}>
          <Route element={<PlatformShell />}>
            <Route index element={<PlatformDashboardPage />} />
            <Route path="dashboard" element={<PlatformDashboardPage />} />
            <Route path="tenants" element={<PlatformTenantsPage />} />
            <Route path="tenants/:tenantUlid" element={<PlatformTenantDetailPage />} />
            <Route path="plans" element={<PlatformPlansPage />} />
            <Route path="plans/:planUlid" element={<PlatformPlanEditorPage />} />
            <Route path="security/audit" element={<PlatformAuditPage />} />
            <Route path="admins" element={<PlatformAdminsPage />} />
            <Route path="access/users" element={<PlatformUsersPage />} />
            <Route path="access/roles" element={<PlatformRolesPage />} />
            <Route path="access/roles/:roleUlid" element={<PlatformRoleEditorPage />} />
            <Route path="access/permissions" element={<PlatformPermissionsPage />} />
            <Route path="account/profile" element={<PlatformAccountProfilePage />} />
            <Route path="account/password" element={<PlatformAccountPasswordPage />} />
            <Route path="account/security" element={<PlatformAccountSecurityPage />} />
            <Route path="settings" element={<PlatformSettingsPage />} />
          </Route>
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/platform" replace />} />
    </Routes>
  )
}
