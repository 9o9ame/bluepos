import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { AppShell } from '../layouts/AppShell'
import { AccountPage, PlanInfoPage } from '../pages/AccountAndPlanPages'
import { BranchesPage, SecurityStatusPage, WarehousesPage } from '../pages/AdminShellPages'
import { BrandsPage } from '../pages/BrandsPage'
import { BusinessSettingsPage } from '../pages/BusinessSettingsPage'
import { CategoriesPage } from '../pages/CategoriesPage'
import { ChangePasswordPage } from '../pages/ChangePasswordPage'
import { DevicesPage } from '../pages/DevicesPage'
import { ForgotPasswordPage } from '../pages/ForgotPasswordPage'
import { HelpAboutPage } from '../pages/HelpAboutPage'
import { LoginPage } from '../pages/LoginPage'
import { PartiesPlaceholderPage } from '../pages/PartiesPlaceholderPage'
import { ProductEditorPage } from '../pages/ProductEditorPage'
import { ProductsPage } from '../pages/ProductsPage'
import { PurchaseInvoicePlaceholderPage } from '../pages/PurchaseInvoicePlaceholderPage'
import { ReportsPlaceholderPage } from '../pages/ReportsPlaceholderPage'
import { RoleEditorPage } from '../pages/RoleEditorPage'
import { RolesPage } from '../pages/RolesPage'
import { SalesInvoicePlaceholderPage } from '../pages/SalesInvoicePlaceholderPage'
import { SubcategoriesPage } from '../pages/SubcategoriesPage'
import { UnitsPage } from '../pages/UnitsPage'
import { UsersPage } from '../pages/UsersPage'
import { WorkspacePage } from '../pages/WorkspacePage'

function Splash() {
  return (
    <div className="grid h-screen place-items-center bg-[var(--titlebar-bg)] text-sm text-white">
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
          <Route path="/definition/parties" element={<PartiesPlaceholderPage />} />
          <Route path="/daily/sales" element={<SalesInvoicePlaceholderPage />} />
          <Route path="/daily/purchases" element={<PurchaseInvoicePlaceholderPage />} />
          <Route path="/reports" element={<ReportsPlaceholderPage />} />
          <Route path="/help/about" element={<HelpAboutPage />} />
          <Route path="/administration/account" element={<AccountPage />} />
          <Route path="/administration/plan" element={<PlanInfoPage />} />
          <Route path="/administration/security" element={<SecurityStatusPage />} />
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
            <Route path="/administration/branches" element={<BranchesPage />} />
            <Route path="/administration/warehouses" element={<WarehousesPage />} />
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
