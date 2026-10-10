import { Navigate, Outlet, Route, Routes } from 'react-router-dom'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { AppShell } from '../layouts/AppShell'
import { AccountPage, PlanInfoPage } from '../pages/AccountAndPlanPages'
import { BranchesPage, SecurityStatusPage, WarehousesPage } from '../pages/AdminShellPages'
import { BrandsPage } from '../pages/BrandsPage'
import { BarcodePrintingPage } from '../pages/BarcodePrintingPage'
import { BusinessSettingsPage } from '../pages/BusinessSettingsPage'
import { CategoriesPage } from '../pages/CategoriesPage'
import { ChangePasswordPage } from '../pages/ChangePasswordPage'
import { DevicesPage } from '../pages/DevicesPage'
import { ForgotPasswordPage } from '../pages/ForgotPasswordPage'
import { HelpAboutPage } from '../pages/HelpAboutPage'
import { LoginPage } from '../pages/LoginPage'
import { OpeningStockPage } from '../pages/OpeningStockPage'
import { PriceListsPage } from '../pages/PriceListsPage'
import { QuotationEstimatePage } from '../pages/QuotationEstimatePage'
import { PartiesPlaceholderPage } from '../pages/PartiesPlaceholderPage'
import { ProductsPage } from '../pages/ProductsPage'
import { ProductTabularViewPage } from '../pages/ProductTabularViewPage'
import { PurchasesPage } from '../pages/PurchasesPage'
import { PurchaseReturnsPage } from '../pages/PurchaseReturnsPage'
import { ReportsPlaceholderPage } from '../pages/ReportsPlaceholderPage'
import { RoleEditorPage } from '../pages/RoleEditorPage'
import { RolesPage } from '../pages/RolesPage'
import { SaleSchemesPage } from '../pages/SaleSchemesPage'
import { SalesInvoicePage } from '../pages/SalesInvoicePage'
import { SalesReturnsPage } from '../pages/SalesReturnsPage'
import { StockTakingPage } from '../pages/StockTakingPage'
import { SubcategoriesPage } from '../pages/SubcategoriesPage'
import { UnitsPage } from '../pages/UnitsPage'
import { VouchersPage } from '../pages/VouchersPage'
import { UsersPage } from '../pages/UsersPage'
import { WorkspacePage } from '../pages/WorkspacePage'

function Splash() {
  return (
    <div className="grid h-screen place-items-center bg-[var(--ui-bg)] text-sm text-[var(--ui-text)]">
      Loading BluePOS…
    </div>
  )
}

function RequireAuth() {
  const { session, isLoading } = useAuth()
  if (isLoading) return <Splash />
  if (!session) return <Navigate to="/login" replace />
  if (session.must_change_password) return <Navigate to="/change-password" replace />
  return <Outlet />
}

function RequirePermission({ permission }: { permission: string }) {
  const allowed = useCan(permission)
  if (!allowed) return <Navigate to="/" replace />
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
          <Route path="/daily/sales" element={<SalesInvoicePage />} />
          <Route element={<RequirePermission permission="sales.view" />}>
            <Route path="/daily/quotation-estimate" element={<QuotationEstimatePage />} />
          </Route>
          <Route element={<RequirePermission permission="sales.return" />}>
            <Route path="/daily/sales-return" element={<SalesReturnsPage />} />
          </Route>
          <Route element={<RequirePermission permission="purchases.view" />}>
            <Route path="/daily/purchases" element={<PurchasesPage />} />
          </Route>
          <Route element={<RequirePermission permission="purchase_returns.view" />}>
            <Route path="/daily/purchase-return" element={<PurchaseReturnsPage />} />
          </Route>
          <Route element={<RequirePermission permission="accounting.journal.view" />}>
            <Route path="/daily/vouchers" element={<VouchersPage />} />
          </Route>
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

          <Route path="/definition/suppliers" element={<Navigate to="/definition/parties" replace />} />

          <Route element={<RequirePermission permission="products.view" />}>
            <Route path="/definition/products" element={<ProductsPage />} />
            <Route path="/definition/product-view" element={<ProductTabularViewPage />} />
            <Route path="/definition/products/:productUlid" element={<Navigate to="/definition/products" replace />} />
            <Route path="/definition/barcode-printing" element={<BarcodePrintingPage />} />
            <Route path="/definition/barcode-printing/:productUlid" element={<BarcodePrintingPage />} />
          </Route>

          <Route element={<RequirePermission permission="sale_schemes.view" />}>
            <Route path="/definition/sale-schemes" element={<SaleSchemesPage />} />
          </Route>

          <Route element={<RequirePermission permission="inventory.opening_balance.view" />}>
            <Route path="/definition/opening-stock" element={<OpeningStockPage />} />
          </Route>

          <Route element={<RequirePermission permission="inventory.view" />}>
            <Route path="/definition/stock-taking" element={<StockTakingPage />} />
          </Route>

          <Route element={<RequirePermission permission="products.view" />}>
            <Route path="/definition/price-lists" element={<PriceListsPage />} />
          </Route>
        </Route>
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
