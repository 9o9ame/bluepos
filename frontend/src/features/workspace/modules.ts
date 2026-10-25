import type { LucideIcon } from 'lucide-react'
import {
  BadgePercent,
  Banknote,
  BarChart3,
  Barcode,
  BookOpen,
  Boxes,
  Building2,
  Calculator,
  ClipboardList,
  FileSpreadsheet,
  FileText,
  FolderTree,
  Gift,
  HelpCircle,
  Info,
  Layers,
  LayoutGrid,
  List,
  Lock,
  Monitor,
  Package,
  RotateCcw,
  Ruler,
  Settings,
  Shield,
  ShoppingCart,
  SlidersHorizontal,
  Tag,
  Undo2,
  User,
  Users,
  Warehouse,
} from 'lucide-react'

export const RIBBON_TABS = [
  { id: 'definition', label: 'Definition' },
  { id: 'daily-entries', label: 'Daily Entries' },
  { id: 'reports', label: 'Reports' },
  { id: 'tools', label: 'Tools' },
  { id: 'administration', label: 'Administration' },
  { id: 'help', label: 'Help' },
] as const

export type RibbonTabId = (typeof RIBBON_TABS)[number]['id']

export type ModuleStatus = 'ready' | 'shell' | 'later'

export type WorkspaceModule = {
  key: string
  title: string
  path: string
  ribbon: RibbonTabId
  status: ModuleStatus
  permission?: string
  entitlement?: string
  closeable?: boolean
  multiInstance?: boolean
  match?: (pathname: string) => boolean
  keyForPath?: (pathname: string) => string
  titleForPath?: (pathname: string) => string
}

export type RibbonTone = 'blue' | 'navy' | 'orange' | 'green' | 'gold' | 'teal' | 'purple' | 'slate' | 'red'

export type RibbonMenuItem = {
  label: string
  status?: ModuleStatus
  path?: string
}

export type RibbonCommandDef = {
  id: string
  label: string
  icon: LucideIcon
  moduleKey?: string
  path?: string
  permission?: string
  entitlement?: string
  status: ModuleStatus
  shortcut?: string
  action?: 'calculator' | 'software-options' | 'recalculate-stock' | 'lock' | 'logout'
  tone?: RibbonTone
  hasMenu?: boolean
  menu?: RibbonMenuItem[]
}

export type RibbonGroupDef = {
  id: string
  caption: string
  commands: RibbonCommandDef[]
}

export const WORKSPACE_MODULES: WorkspaceModule[] = [
  {
    key: 'home',
    title: 'Home',
    path: '/',
    ribbon: 'definition',
    status: 'ready',
    closeable: false,
    match: (pathname) => pathname === '/',
  },
  {
    key: 'settings',
    title: 'Business Settings',
    path: '/administration/settings',
    ribbon: 'administration',
    status: 'ready',
    permission: 'settings.view',
  },
  {
    key: 'custom-query',
    title: 'Custom Query',
    path: '/tools/custom-query',
    ribbon: 'tools',
    status: 'ready',
    permission: 'database.query.read',
  },
  {
    key: 'report-headings',
    title: 'Report Headings',
    path: '/tools/report-headings',
    ribbon: 'tools',
    status: 'ready',
    permission: 'settings.view',
  },
  {
    key: 'products',
    title: 'Products',
    path: '/definition/products',
    ribbon: 'definition',
    status: 'ready',
    permission: 'products.view',
    entitlement: 'catalog',
    match: (pathname) => pathname === '/definition/products',
  },
  {
    key: 'product-tabular-view',
    title: 'Product View',
    path: '/definition/product-view',
    ribbon: 'definition',
    status: 'ready',
    permission: 'products.view',
    entitlement: 'catalog',
  },
  {
    key: 'opening-stock',
    title: 'Opening Stock',
    path: '/definition/opening-stock',
    ribbon: 'definition',
    status: 'ready',
    permission: 'inventory.opening_balance.view',
    entitlement: 'inventory',
  },
  {
    key: 'stock-taking',
    title: 'Stock Taking',
    path: '/definition/stock-taking',
    ribbon: 'definition',
    status: 'ready',
    permission: 'inventory.view',
    entitlement: 'inventory',
  },
  {
    key: 'price-lists',
    title: 'Price Lists',
    path: '/definition/price-lists',
    ribbon: 'definition',
    status: 'ready',
    permission: 'products.view',
    entitlement: 'catalog',
  },
  {
    key: 'barcode-printing',
    title: 'Barcode Printing',
    path: '/definition/barcode-printing',
    ribbon: 'definition',
    status: 'ready',
    permission: 'products.view',
    entitlement: 'catalog',
    match: (pathname) =>
      pathname === '/definition/barcode-printing' ||
      pathname.startsWith('/definition/barcode-printing/'),
    keyForPath: () => 'barcode-printing',
    titleForPath: () => 'Barcode Printing',
  },
  {
    key: 'categories',
    title: 'Categories',
    path: '/definition/categories',
    ribbon: 'definition',
    status: 'ready',
    permission: 'categories.view',
    entitlement: 'catalog',
  },
  {
    key: 'subcategories',
    title: 'Subcategories',
    path: '/definition/subcategories',
    ribbon: 'definition',
    status: 'ready',
    permission: 'categories.view',
    entitlement: 'catalog',
  },
  {
    key: 'brands',
    title: 'Brands',
    path: '/definition/brands',
    ribbon: 'definition',
    status: 'ready',
    permission: 'brands.view',
    entitlement: 'catalog',
  },
  {
    key: 'sale-schemes',
    title: 'Sale Schemes',
    path: '/definition/sale-schemes',
    ribbon: 'definition',
    status: 'ready',
    permission: 'sale_schemes.view',
    entitlement: 'catalog',
  },
  {
    key: 'units',
    title: 'Units',
    path: '/definition/units',
    ribbon: 'definition',
    status: 'ready',
    permission: 'units.view',
    entitlement: 'catalog',
  },
  {
    key: 'parties',
    title: 'Customer / Vendor / Accounts',
    path: '/definition/parties',
    ribbon: 'definition',
    status: 'shell',
  },
  {
    key: 'sales-invoice',
    title: 'Sales Invoice',
    path: '/daily/sales',
    ribbon: 'daily-entries',
    status: 'ready',
    multiInstance: true,
    permission: 'sales.view',
    entitlement: 'sales',
  },
  {
    key: 'quotation-estimate',
    title: 'Quotation / Estimate',
    path: '/daily/quotation-estimate',
    ribbon: 'daily-entries',
    status: 'ready',
    multiInstance: true,
    permission: 'sales.view',
    entitlement: 'sales',
  },
  {
    key: 'sales-return',
    title: 'Sales Return',
    path: '/daily/sales-return',
    ribbon: 'daily-entries',
    status: 'ready',
    multiInstance: true,
    permission: 'sales.return',
    entitlement: 'sales',
  },
  {
    key: 'purchase-invoice',
    title: 'Purchase Invoice',
    path: '/daily/purchases',
    ribbon: 'daily-entries',
    status: 'ready',
    multiInstance: true,
    permission: 'purchases.view',
    entitlement: 'purchases',
  },
  {
    key: 'purchase-order',
    title: 'Purchase Order',
    path: '/daily/purchase-orders',
    ribbon: 'daily-entries',
    status: 'ready',
    multiInstance: true,
    permission: 'purchases.view',
    entitlement: 'purchases',
  },
  {
    key: 'purchase-return',
    title: 'Purchase Return',
    path: '/daily/purchase-return',
    ribbon: 'daily-entries',
    status: 'ready',
    multiInstance: true,
  },
  {
    key: 'adjustments',
    title: 'Adjustments of Items',
    path: '/daily/adjustments',
    ribbon: 'daily-entries',
    status: 'ready',
    permission: 'inventory.view',
    entitlement: 'inventory',
  },
  {
    key: 'vouchers',
    title: 'Vouchers',
    path: '/daily/vouchers',
    ribbon: 'daily-entries',
    status: 'ready',
    permission: 'accounting.journal.view',
  },
  {
    key: 'product-view',
    title: 'Product View',
    path: '/daily/product-view',
    ribbon: 'daily-entries',
    status: 'ready',
    permission: 'products.view',
    entitlement: 'catalog',
  },
  {
    key: 'party-ledger',
    title: 'Vendor / Customer Ledger',
    path: '/daily/ledger',
    ribbon: 'daily-entries',
    status: 'ready',
  },
  {
    key: 'cash-position',
    title: 'Daily Cash Position',
    path: '/daily/cash',
    ribbon: 'daily-entries',
    status: 'ready',
    permission: 'accounting.journal.view',
  },
  {
    key: 'reports-home',
    title: 'Reports',
    path: '/reports',
    ribbon: 'reports',
    status: 'shell',
  },
  {
    key: 'report-trial-balance',
    title: 'Trial Balance',
    path: '/reports/accounts/trial-balance',
    ribbon: 'reports',
    status: 'ready',
    permission: 'reports.accounting',
  },
  {
    key: 'report-balance-sheet',
    title: 'Balance Sheet',
    path: '/reports/accounts/balance-sheet',
    ribbon: 'reports',
    status: 'ready',
    permission: 'reports.accounting',
  },
  {
    key: 'report-income-statement',
    title: 'Income Statement',
    path: '/reports/accounts/income-statement',
    ribbon: 'reports',
    status: 'ready',
    permission: 'reports.accounting',
  },
  {
    key: 'report-daily-income-statement',
    title: 'Income Statement (Daily / Monthly / Yearly)',
    path: '/reports/accounts/daily-income-statement',
    ribbon: 'reports',
    status: 'ready',
    permission: 'reports.accounting',
  },
  {
    key: 'report-total-turnover',
    title: 'Total Turnover Report',
    path: '/reports/accounts/total-turnover',
    ribbon: 'reports',
    status: 'ready',
    permission: 'reports.accounting',
  },
  {
    key: 'report-top-special',
    title: 'Top Products / Vendor / Customers',
    path: '/reports/accounts/top-special',
    ribbon: 'reports',
    status: 'ready',
    permission: 'reports.accounting',
  },
  {
    key: 'report-daily-business-activity',
    title: 'Daily Business Activity Account wise',
    path: '/reports/daily/business-activity',
    ribbon: 'reports',
    status: 'ready',
    permission: 'reports.accounting',
  },
  {
    key: 'report-cash-book',
    title: 'Cash Book',
    path: '/reports/accounts/cash-book',
    ribbon: 'reports',
    status: 'ready',
    permission: 'reports.accounting',
  },
  {
    key: 'report-stock-status',
    title: 'Stock Status',
    path: '/reports/products/stock-status',
    ribbon: 'reports',
    status: 'ready',
    permission: 'reports.inventory',
    entitlement: 'inventory',
  },
  {
    key: 'report-product-ledger',
    title: 'Product Ledger',
    path: '/reports/products/ledger',
    ribbon: 'reports',
    status: 'ready',
    permission: 'reports.inventory',
    entitlement: 'inventory',
  },
  {
    key: 'configure-users',
    title: 'Configure Users',
    path: '/tools/configure-users',
    ribbon: 'tools',
    status: 'ready',
    permission: 'users.view',
  },
  {
    key: 'users',
    title: 'Users',
    path: '/administration/users',
    ribbon: 'administration',
    status: 'ready',
    permission: 'users.view',
  },
  {
    key: 'roles',
    title: 'Manage Groups',
    path: '/administration/roles',
    ribbon: 'tools',
    status: 'ready',
    permission: 'roles.view',
    match: (pathname) => pathname === '/administration/roles',
  },
  {
    key: 'role-editor',
    title: 'Manage Groups',
    path: '/administration/roles',
    ribbon: 'tools',
    status: 'ready',
    permission: 'roles.view',
    match: (pathname) => pathname.startsWith('/administration/roles/'),
    keyForPath: (pathname) => `role:${pathname.split('/').pop() ?? 'role'}`,
    titleForPath: () => 'Manage Groups',
  },
  {
    key: 'devices',
    title: 'Devices',
    path: '/administration/devices',
    ribbon: 'administration',
    status: 'ready',
    permission: 'devices.view',
  },
  {
    key: 'branches',
    title: 'Branches',
    path: '/administration/branches',
    ribbon: 'administration',
    status: 'ready',
    permission: 'branches.view',
  },
  {
    key: 'warehouses',
    title: 'Warehouses',
    path: '/administration/warehouses',
    ribbon: 'administration',
    status: 'ready',
    permission: 'warehouses.manage',
  },
  {
    key: 'security',
    title: 'Security',
    path: '/administration/security',
    ribbon: 'administration',
    status: 'ready',
    permission: 'security.sessions.view',
  },
  {
    key: 'plan',
    title: 'Plan / Entitlements',
    path: '/administration/plan',
    ribbon: 'administration',
    status: 'ready',
  },
  {
    key: 'account',
    title: 'My Account',
    path: '/administration/account',
    ribbon: 'administration',
    status: 'ready',
  },
  {
    key: 'help-about',
    title: 'About BluePOS',
    path: '/help/about',
    ribbon: 'help',
    status: 'ready',
  },
]

const LATER = { status: 'later' as const }

export const RIBBON_GROUPS: Record<RibbonTabId, RibbonGroupDef[]> = {
  definition: [
    {
      id: 'products',
      caption: 'Definitions',
      commands: [
        { id: 'products', label: 'Define Products', icon: Package, moduleKey: 'products', permission: 'products.view', entitlement: 'catalog', status: 'ready', tone: 'green' },
        { id: 'tabular', label: 'Tabular View', icon: LayoutGrid, moduleKey: 'product-tabular-view', permission: 'products.view', entitlement: 'catalog', status: 'ready', tone: 'teal' },
        { id: 'stock-taking', label: 'Stock Taking', icon: Boxes, moduleKey: 'stock-taking', permission: 'inventory.view', entitlement: 'inventory', status: 'ready', tone: 'orange' },
        { id: 'categories', label: 'Categories', icon: FolderTree, moduleKey: 'categories', permission: 'categories.view', entitlement: 'catalog', status: 'ready', tone: 'blue' },
        { id: 'subcategories', label: 'Subcategories', icon: Layers, moduleKey: 'subcategories', permission: 'categories.view', entitlement: 'catalog', status: 'ready', tone: 'blue' },
        { id: 'brands', label: 'Brands', icon: Tag, moduleKey: 'brands', permission: 'brands.view', entitlement: 'catalog', status: 'ready', tone: 'purple' },
        { id: 'units', label: 'Units', icon: Ruler, moduleKey: 'units', permission: 'units.view', entitlement: 'catalog', status: 'ready', tone: 'slate' },
        { id: 'sale-schemes', label: 'Sale Schemes', icon: Gift, moduleKey: 'sale-schemes', permission: 'sale_schemes.view', entitlement: 'catalog', status: 'ready', tone: 'gold' },
      ],
    },
    {
      id: 'parties',
      caption: 'Parties',
      commands: [
        { id: 'parties', label: 'Vendor / Customer / Accounts', icon: Users, moduleKey: 'parties', status: 'shell', tone: 'gold' },
      ],
    },
    {
      id: 'later-catalog',
      caption: 'Printing / Lists',
      commands: [
        { id: 'opening-stock', label: 'Opening Stock', icon: Boxes, moduleKey: 'opening-stock', permission: 'inventory.opening_balance.view', entitlement: 'inventory', status: 'ready', tone: 'orange' },
        { id: 'barcodes', label: 'Barcode Printing', icon: Barcode, moduleKey: 'barcode-printing', permission: 'products.view', entitlement: 'catalog', status: 'ready', tone: 'navy' },
        { id: 'price-lists', label: 'Price Lists', icon: List, moduleKey: 'price-lists', permission: 'products.view', entitlement: 'catalog', status: 'ready', tone: 'teal' },
      ],
    },
  ],
  'daily-entries': [
    {
      id: 'sales',
      caption: 'Sales',
      commands: [
        { id: 'sales-invoice', label: 'Sales Invoice', icon: ShoppingCart, moduleKey: 'sales-invoice', permission: 'sales.view', entitlement: 'sales', status: 'ready', tone: 'blue' },
        { id: 'quotation-estimate', label: 'Quotation / Estimate', icon: FileText, moduleKey: 'quotation-estimate', permission: 'sales.view', entitlement: 'sales', status: 'ready', tone: 'gold' },
        { id: 'sales-return', label: 'Sales Return', icon: Undo2, moduleKey: 'sales-return', permission: 'sales.return', entitlement: 'sales', status: 'ready', tone: 'slate' },
      ],
    },
    {
      id: 'purchases',
      caption: 'Purchases',
      commands: [
        { id: 'purchase-invoice', label: 'Purchase Invoice', icon: ClipboardList, moduleKey: 'purchase-invoice', permission: 'purchases.view', entitlement: 'purchases', status: 'ready', tone: 'navy' },
        { id: 'purchase-order', label: 'Purchase Order', icon: FileText, moduleKey: 'purchase-order', permission: 'purchases.view', entitlement: 'purchases', status: 'ready', tone: 'gold' },
        { id: 'purchase-return', label: 'Purchase Return', icon: RotateCcw, moduleKey: 'purchase-return', permission: 'purchase_returns.view', entitlement: 'purchase_returns', status: 'ready', tone: 'orange' },
        { id: 'adjustments', label: 'Adjustments of Items', icon: SlidersHorizontal, moduleKey: 'adjustments', permission: 'inventory.view', entitlement: 'inventory', status: 'ready', tone: 'slate' },
      ],
    },
    {
      id: 'stock-pay',
      caption: 'Payment / Receiving + Stock',
      commands: [
        {
          id: 'vouchers',
          label: 'Vouchers',
          icon: FileText,
          moduleKey: 'vouchers',
          permission: 'accounting.journal.view',
          status: 'ready',
          tone: 'gold',
          hasMenu: true,
          menu: [
            { label: 'Opening Balance Voucher', status: 'ready', path: '/daily/vouchers?type=opening' },
            { label: 'Payment Voucher (Dr)', status: 'ready', path: '/daily/vouchers?type=payment' },
            { label: 'Receiving Voucher (Cr)', status: 'ready', path: '/daily/vouchers?type=receiving' },
            { label: 'Journal Voucher (JV)', status: 'ready', path: '/daily/vouchers?type=journal' },
          ],
        },
        { id: 'product-view', label: 'Product View', icon: Package, moduleKey: 'product-view', permission: 'products.view', entitlement: 'catalog', status: 'ready', tone: 'green' },
        { id: 'ledger', label: 'Vendor / Customer Ledger', icon: BookOpen, moduleKey: 'party-ledger', status: 'ready', tone: 'gold' },
        { id: 'cash', label: 'Daily Cash Position', icon: Banknote, moduleKey: 'cash-position', permission: 'accounting.journal.view', status: 'ready', tone: 'teal' },
      ],
    },
  ],
  reports: [
    {
      id: 'report-groups',
      caption: 'Reports',
      commands: [
        {
          id: 'accounts-reports',
          label: 'Accounts Reports',
          icon: BarChart3,
          permission: 'reports.accounting',
          status: 'ready',
          tone: 'red',
          hasMenu: true,
          menu: [
            { label: 'Trial Balance', status: 'ready', path: '/reports/accounts/trial-balance' },
            { label: 'Trial Balance 6 Cols / Side by Side', status: 'ready', path: '/reports/accounts/trial-balance?view=six_options' },
            { label: 'Cash Book', status: 'ready', path: '/reports/accounts/cash-book' },
            { label: 'Daily Business Summary', status: 'ready', path: '/reports/daily/business-activity' },
            { label: 'Top Products / Vendor / Customers', status: 'ready', path: '/reports/accounts/top-special' },
            { label: 'Total Turnover Report', status: 'ready', path: '/reports/accounts/total-turnover' },
            { label: 'Balance Sheet', status: 'ready', path: '/reports/accounts/balance-sheet' },
            { label: 'Income Statement', status: 'ready', path: '/reports/accounts/income-statement' },
            { label: 'Income Statement (Daily, Monthly)', status: 'ready', path: '/reports/accounts/daily-income-statement' },
            { label: 'General Journal Report', ...LATER },
          ],
        },
        {
          id: 'daily-reports',
          label: 'Daily Reports',
          icon: FileSpreadsheet,
          moduleKey: 'reports-home',
          status: 'shell',
          tone: 'navy',
          hasMenu: true,
          menu: [
            { label: 'Report workspace', status: 'shell', path: '/reports' },
            { label: 'Profit & Loss Report', ...LATER },
            { label: 'Daily Business Activity Account wise', status: 'ready', path: '/reports/daily/business-activity' },
            { label: 'Detail Sale + Stock + Profit Loss Report', ...LATER },
            { label: 'Business By Group', ...LATER },
            { label: 'Day Book (Daily Business Summary)', ...LATER },
            { label: 'Product Wise Transaction (Grid)', ...LATER },
            { label: 'Detailed Transactions Wise Report', ...LATER },
            { label: 'FBR Sales Register', ...LATER },
            { label: 'FBR Purchase Register', ...LATER },
          ],
        },
        {
          id: 'product-reports',
          label: 'Product Reports',
          icon: LayoutGrid,
          permission: 'reports.inventory',
          entitlement: 'inventory',
          status: 'ready',
          tone: 'green',
          hasMenu: true,
          menu: [
            { label: 'Product Ledger', status: 'ready', path: '/reports/products/ledger' },
            { label: 'Product GL (Grid)', ...LATER },
            { label: 'Daily / Monthly P/L', ...LATER },
            { label: 'Stock Status', status: 'ready', path: '/reports/products/stock-status' },
            { label: 'Stock Activity Between Dates', ...LATER },
            { label: 'Price List', ...LATER },
            { label: 'Dead Stock', ...LATER },
            { label: 'Expiry and Near to Expiry Stock', ...LATER },
            { label: 'Barcode Printing', ...LATER },
          ],
        },
        { id: 'advance-sales', label: 'Advance Sales View', icon: BarChart3, status: 'later', tone: 'teal' },
        {
          id: 'party-reports',
          label: 'Vendor / Customer Reports',
          icon: Users,
          status: 'later',
          tone: 'gold',
          hasMenu: true,
          menu: [
            { label: 'Customer Ledger', ...LATER },
            { label: 'Vendor Ledger', ...LATER },
            { label: 'Aging Receivables', ...LATER },
            { label: 'Aging Payables', ...LATER },
          ],
        },
      ],
    },
  ],
  tools: [
    {
      id: 'access',
      caption: 'Group / User Planning',
      commands: [
        { id: 'roles', label: 'Manage Groups', icon: Shield, moduleKey: 'roles', permission: 'roles.view', status: 'ready', tone: 'green' },
        { id: 'users', label: 'Configure Users', icon: Users, moduleKey: 'configure-users', permission: 'users.view', status: 'ready', tone: 'blue' },
        { id: 'devices', label: 'Devices', icon: Monitor, moduleKey: 'devices', permission: 'devices.view', status: 'ready', tone: 'navy' },
      ],
    },
    {
      id: 'system',
      caption: 'Others',
      commands: [
        { id: 'custom-query', label: 'Custom Query', icon: FileSpreadsheet, moduleKey: 'custom-query', permission: 'database.query.read', status: 'ready', tone: 'slate' },
        { id: 'report-headings', label: 'Report Headings', icon: List, moduleKey: 'report-headings', permission: 'settings.view', status: 'ready', tone: 'slate' },
        { id: 'options', label: 'Software Options', icon: Settings, permission: 'settings.view', status: 'ready', action: 'software-options', tone: 'navy' },
        { id: 'recalc-stock', label: 'Recalculate Stock', icon: Boxes, permission: 'inventory.recalculate', entitlement: 'inventory', status: 'ready', action: 'recalculate-stock', tone: 'orange' },
        { id: 'calculator', label: 'Calculator', icon: Calculator, status: 'ready', action: 'calculator', tone: 'gold' },
        { id: 'lock', label: 'Lock this Software', icon: Lock, status: 'ready', action: 'lock', tone: 'red' },
      ],
    },
  ],
  administration: [
    {
      id: 'business',
      caption: 'Business',
      commands: [
        { id: 'settings', label: 'Business Settings', icon: Settings, moduleKey: 'settings', permission: 'settings.view', status: 'ready', tone: 'navy' },
      ],
    },
    {
      id: 'people',
      caption: 'People',
      commands: [
        { id: 'users', label: 'Users', icon: User, moduleKey: 'users', permission: 'users.view', status: 'ready', tone: 'blue' },
        { id: 'roles', label: 'Roles', icon: Shield, moduleKey: 'roles', permission: 'roles.view', status: 'ready', tone: 'green' },
      ],
    },
    {
      id: 'locations',
      caption: 'Locations',
      commands: [
        { id: 'branches', label: 'Branches', icon: Building2, moduleKey: 'branches', permission: 'branches.view', status: 'ready', tone: 'navy' },
        { id: 'warehouses', label: 'Warehouses', icon: Warehouse, moduleKey: 'warehouses', permission: 'warehouses.manage', status: 'ready', tone: 'teal' },
      ],
    },
    {
      id: 'security',
      caption: 'Security',
      commands: [
        { id: 'devices', label: 'Devices', icon: Monitor, moduleKey: 'devices', permission: 'devices.view', status: 'ready', tone: 'navy' },
        { id: 'security', label: 'Security', icon: Shield, moduleKey: 'security', permission: 'security.sessions.view', status: 'ready', tone: 'red' },
        { id: 'plan', label: 'Plan / Entitlements', icon: BadgePercent, moduleKey: 'plan', status: 'ready', tone: 'gold' },
      ],
    },
  ],
  help: [
    {
      id: 'help',
      caption: 'Help',
      commands: [
        { id: 'about', label: 'About BluePOS', icon: Info, moduleKey: 'help-about', status: 'ready', tone: 'blue' },
        { id: 'docs', label: 'Documentation', icon: HelpCircle, status: 'later', tone: 'slate' },
      ],
    },
  ],
}

export function resolveWorkspaceModule(pathname: string): WorkspaceModule {
  const ranked = [...WORKSPACE_MODULES].sort((left, right) => right.path.length - left.path.length)
  const match = ranked.find((module) => (module.match ? module.match(pathname) : pathname === module.path))
  return match ?? WORKSPACE_MODULES[0]
}

export function moduleTabIdentity(
  module: WorkspaceModule,
  pathname: string,
  search = '',
): { key: string; title: string; path: string } {
  if (module.multiInstance) {
    const instance = new URLSearchParams(search).get('instance')
    if (instance && /^\d+$/.test(instance)) {
      return {
        key: `${module.key}:${instance}`,
        title: `${module.title} ${instance}`,
        path: pathname + search,
      }
    }
  }

  if (module.key === 'report-total-turnover' && new URLSearchParams(search).get('view') === 'preview') {
    const mode = new URLSearchParams(search).get('mode') === 'simple' ? 'simple' : 'detailed'
    return {
      key: `report-total-turnover:preview:${mode}`,
      title: mode === 'simple' ? 'Simple Turn Over' : 'Total Turnover Preview',
      path: pathname + search,
    }
  }
  if (module.key === 'report-top-special' && new URLSearchParams(search).get('view') === 'preview') {
    return { key: 'report-top-special:preview', title: 'Top Special Preview', path: pathname + search }
  }

  if (module.key === 'report-daily-business-activity' && new URLSearchParams(search).get('view') === 'preview') {
    return { key: 'report-daily-business-activity:preview', title: 'Daily Ledger Wise Activity', path: pathname + search }
  }

  if (module.key === 'report-cash-book') {
    const view = new URLSearchParams(search).get('view')
    if (view === 'preview' || view === 'cash_flow' || view === 'cash_flow_preview') {
      return {
        key: `report-cash-book:${view}`,
        title: view === 'preview' ? 'Cash Book Preview' : view === 'cash_flow_preview' ? 'Cash Flow Preview' : 'Cash Flow Statement',
        path: pathname + search,
      }
    }
  }

  if (module.key === 'report-daily-income-statement' && new URLSearchParams(search).get('view') === 'preview') {
    return { key: 'report-daily-income-statement:preview', title: 'Daily Income Statement Preview', path: pathname + search }
  }

  if (module.key === 'report-income-statement' && new URLSearchParams(search).get('view') === 'preview') {
    return { key: 'report-income-statement:preview', title: 'Income Statement Preview', path: pathname + search }
  }

  if (module.key === 'report-balance-sheet' && new URLSearchParams(search).get('view') === 'preview') {
    return { key: 'report-balance-sheet:preview', title: 'Balance Sheet Preview', path: pathname + search }
  }

  if (module.key === 'report-trial-balance') {
    const params = new URLSearchParams(search)
    const view = params.get('view')

    if (view === 'six_options') {
      return {
        key: 'report-trial-balance:six-options',
        title: 'Trial Balance (6 Columns Format)',
        path: pathname + search,
      }
    }

    if (view === 'six_columns' || view === 'dr_cr_separate') {
      return {
        key: `report-trial-balance:${view}`,
        title: view === 'six_columns' ? 'Trial Balance 6 Columns' : 'Trial Balance Dr / Cr Separate',
        path: pathname + search,
      }
    }

    if (view === 'detail') {
      return {
        key: 'report-trial-balance:detail',
        title: 'Trial Balance Detailed',
        path: pathname + search,
      }
    }

    if (view === 'major_minor') {
      return {
        key: 'report-trial-balance:major',
        title: 'Trial Balance Major Head',
        path: pathname + search,
      }
    }
  }

  if (module.key === 'vouchers') {
    const type = new URLSearchParams(search).get('type')

    if (type === 'opening') {
      return { key: 'vouchers:opening', title: 'Opening Balance Voucher', path: pathname + search }
    }
    if (type === 'payment') {
      return { key: 'vouchers:payment', title: 'Cash Payment Voucher', path: pathname + search }
    }
    if (type === 'receiving') {
      return { key: 'vouchers:receiving', title: 'Cash Receiving Voucher', path: pathname + search }
    }
    if (type === 'journal') {
      return { key: 'vouchers:journal', title: 'Journal Voucher', path: pathname + search }
    }
  }

  return {
    key: module.keyForPath ? module.keyForPath(pathname) : module.key,
    title: module.titleForPath ? module.titleForPath(pathname) : module.title,
    path: pathname + search,
  }
}

export function laterPhaseHint(): string {
  return 'Available in a later phase'
}
