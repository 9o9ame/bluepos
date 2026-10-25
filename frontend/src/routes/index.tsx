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
import { CustomQueryPage } from '../pages/CustomQueryPage'
import { ChangePasswordPage } from '../pages/ChangePasswordPage'
import { DevicesPage } from '../pages/DevicesPage'
import { DailyCashPositionPage } from '../pages/DailyCashPositionPage'
import { ForgotPasswordPage } from '../pages/ForgotPasswordPage'
import { HelpAboutPage } from '../pages/HelpAboutPage'
import { LoginPage } from '../pages/LoginPage'
import { OpeningStockPage } from '../pages/OpeningStockPage'
import { InventoryAdjustmentsPage } from '../pages/InventoryAdjustmentsPage'
import { PriceListsPage } from '../pages/PriceListsPage'
import { QuotationEstimatePage } from '../pages/QuotationEstimatePage'
import { PartiesPlaceholderPage } from '../pages/PartiesPlaceholderPage'
import { ProductsPage } from '../pages/ProductsPage'
import { ProductTabularViewPage } from '../pages/ProductTabularViewPage'
import { ProductViewPage } from '../pages/ProductViewPage'
import { PurchasesPage } from '../pages/PurchasesPage'
import { PurchaseOrderPage } from '../pages/PurchaseOrderPage'
import { PurchaseReturnsPage } from '../pages/PurchaseReturnsPage'
import { ReportsPlaceholderPage } from '../pages/ReportsPlaceholderPage'
import { ProductStockStatusReportPage } from '../pages/ProductStockStatusReportPage'
import { ProductLedgerReportPage } from '../pages/ProductLedgerReportPage'
import { TrialBalanceReportPage } from '../pages/TrialBalanceReportPage'
import { BalanceSheetReportPage } from '../pages/BalanceSheetReportPage'
import { IncomeStatementReportPage } from '../pages/IncomeStatementReportPage'
import { DailyIncomeStatementReportPage } from '../pages/DailyIncomeStatementReportPage'
import { CashBookReportPage } from '../pages/CashBookReportPage'
import { DailyBusinessActivityPage } from '../pages/DailyBusinessActivityPage'
import { TopSpecialReportPage } from '../pages/TopSpecialReportPage'
import { TotalTurnoverReportPage } from '../pages/TotalTurnoverReportPage'
import { ReportHeadingsPage } from '../pages/ReportHeadingsPage'
import { RoleEditorPage } from '../pages/RoleEditorPage'
import { RolesPage } from '../pages/RolesPage'
import { SaleSchemesPage } from '../pages/SaleSchemesPage'
import { SalesInvoicePage } from '../pages/SalesInvoicePage'
import { SalesReturnsPage } from '../pages/SalesReturnsPage'
import { StockTakingPage } from '../pages/StockTakingPage'
import { SubcategoriesPage } from '../pages/SubcategoriesPage'
import { UnitsPage } from '../pages/UnitsPage'
import { VendorCustomerLedgerPage } from '../pages/VendorCustomerLedgerPage'
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
            <Route path="/daily/purchase-orders" element={<PurchaseOrderPage />} />
          </Route>
          <Route element={<RequirePermission permission="purchase_returns.view" />}>
            <Route path="/daily/purchase-return" element={<PurchaseReturnsPage />} />
          </Route>
          <Route element={<RequirePermission permission="inventory.view" />}>
            <Route path="/daily/adjustments" element={<InventoryAdjustmentsPage />} />
          </Route>
          <Route element={<RequirePermission permission="accounting.journal.view" />}>
            <Route path="/daily/vouchers" element={<VouchersPage />} />
          </Route>
          <Route element={<RequirePermission permission="products.view" />}>
            <Route path="/daily/product-view" element={<ProductViewPage />} />
          </Route>
          <Route path="/daily/ledger" element={<VendorCustomerLedgerPage />} />
          <Route element={<RequirePermission permission="accounting.journal.view" />}>
            <Route path="/daily/cash" element={<DailyCashPositionPage />} />
          </Route>
          <Route path="/reports" element={<ReportsPlaceholderPage />} />
          <Route element={<RequirePermission permission="reports.accounting" />}>
            <Route path="/reports/accounts/trial-balance" element={<TrialBalanceReportPage />} />
            <Route path="/reports/accounts/balance-sheet" element={<BalanceSheetReportPage />} />
            <Route path="/reports/accounts/income-statement" element={<IncomeStatementReportPage />} />
            <Route path="/reports/accounts/daily-income-statement" element={<DailyIncomeStatementReportPage />} />
            <Route path="/reports/accounts/cash-book" element={<CashBookReportPage />} />
            <Route path="/reports/daily/business-activity" element={<DailyBusinessActivityPage />} />
            <Route path="/reports/accounts/top-special" element={<TopSpecialReportPage />} />
            <Route path="/reports/accounts/total-turnover" element={<TotalTurnoverReportPage />} />
          </Route>
          <Route element={<RequirePermission permission="reports.inventory" />}>
            <Route path="/reports/products/stock-status" element={<ProductStockStatusReportPage />} />
            <Route path="/reports/products/ledger" element={<ProductLedgerReportPage />} />
          </Route>
          <Route path="/help/about" element={<HelpAboutPage />} />
          <Route path="/administration/account" element={<AccountPage />} />
          <Route path="/administration/plan" element={<PlanInfoPage />} />
          <Route element={<RequirePermission permission="security.sessions.view" />}>
            <Route path="/administration/security" element={<SecurityStatusPage />} />
          </Route>

          <Route element={<RequirePermission permission="users.view" />}>
            <Route path="/administration/users" element={<UsersPage />} />
            <Route path="/tools/configure-users" element={<UsersPage />} />
          </Route>

          <Route element={<RequirePermission permission="roles.view" />}>
            <Route path="/administration/roles" element={<RolesPage />} />
            <Route path="/administration/roles/:roleUlid" element={<RoleEditorPage />} />
          </Route>

          <Route element={<RequirePermission permission="devices.view" />}>
            <Route path="/administration/devices" element={<DevicesPage />} />
          </Route>

          <Route element={<RequirePermission permission="database.query.read" />}>
            <Route path="/tools/custom-query" element={<CustomQueryPage />} />
          </Route>

          <Route element={<RequirePermission permission="settings.view" />}>
            <Route path="/administration/settings" element={<BusinessSettingsPage />} />
            <Route path="/tools/report-headings" element={<ReportHeadingsPage />} />
          </Route>

          <Route element={<RequirePermission permission="warehouses.manage" />}>
            <Route path="/administration/warehouses" element={<WarehousesPage />} />
          </Route>

          <Route element={<RequirePermission permission="branches.view" />}>
            <Route path="/administration/branches" element={<BranchesPage />} />
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
