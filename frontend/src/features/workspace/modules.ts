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
  Database,
  FileSpreadsheet,
  FileText,
  FolderTree,
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
  Truck,
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
  action?: 'calculator' | 'lock' | 'logout'
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
    ribbon: 'definition',
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
    key: 'units',
    title: 'Units',
    path: '/definition/units',
    ribbon: 'definition',
    status: 'ready',
    permission: 'units.view',
    entitlement: 'catalog',
  },
  {
    key: 'suppliers',
    title: 'Suppliers',
    path: '/definition/suppliers',
    ribbon: 'definition',
    status: 'ready',
    permission: 'suppliers.view',
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
    status: 'shell',
  },
  {
    key: 'sales-return',
    title: 'Sales Return',
    path: '/daily/sales-return',
    ribbon: 'daily-entries',
    status: 'later',
  },
  {
    key: 'purchase-invoice',
    title: 'Purchase Invoice',
    path: '/daily/purchases',
    ribbon: 'daily-entries',
    status: 'shell',
  },
  {
    key: 'purchase-return',
    title: 'Purchase Return',
    path: '/daily/purchase-return',
    ribbon: 'daily-entries',
    status: 'later',
  },
  {
    key: 'adjustments',
    title: 'Adjustments',
    path: '/daily/adjustments',
    ribbon: 'daily-entries',
    status: 'later',
  },
  {
    key: 'vouchers',
    title: 'Vouchers',
    path: '/daily/vouchers',
    ribbon: 'daily-entries',
    status: 'later',
  },
  {
    key: 'product-view',
    title: 'Product View',
    path: '/definition/products',
    ribbon: 'daily-entries',
    status: 'ready',
    permission: 'products.view',
    entitlement: 'catalog',
  },
  {
    key: 'party-ledger',
    title: 'Customer / Vendor Ledger',
    path: '/daily/ledger',
    ribbon: 'daily-entries',
    status: 'later',
  },
  {
    key: 'cash-position',
    title: 'Daily Cash Position',
    path: '/daily/cash',
    ribbon: 'daily-entries',
    status: 'later',
  },
  {
    key: 'reports-home',
    title: 'Reports',
    path: '/reports',
    ribbon: 'reports',
    status: 'shell',
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
    status: 'shell',
    permission: 'settings.view',
  },
  {
    key: 'warehouses',
    title: 'Warehouses',
    path: '/administration/warehouses',
    ribbon: 'administration',
    status: 'shell',
    permission: 'settings.view',
  },
  {
    key: 'security',
    title: 'Security',
    path: '/administration/security',
    ribbon: 'administration',
    status: 'shell',
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
      id: 'application',
      caption: 'Application',
      commands: [
        { id: 'backup', label: 'Backup & Restore', icon: Database, status: 'later', tone: 'slate' },
        { id: 'settings', label: 'Business Settings', icon: Settings, moduleKey: 'settings', permission: 'settings.view', status: 'ready', tone: 'navy' },
      ],
    },
    {
      id: 'products',
      caption: 'Definitions',
      commands: [
        { id: 'products', label: 'Define Products', icon: Package, moduleKey: 'products', permission: 'products.view', entitlement: 'catalog', status: 'ready', tone: 'green' },
        { id: 'tabular', label: 'Tabular View', icon: LayoutGrid, moduleKey: 'products', permission: 'products.view', entitlement: 'catalog', status: 'ready', tone: 'teal' },
        { id: 'stock-taking', label: 'Stock Taking', icon: Boxes, status: 'later', tone: 'orange' },
        { id: 'categories', label: 'Categories', icon: FolderTree, moduleKey: 'categories', permission: 'categories.view', entitlement: 'catalog', status: 'ready', tone: 'blue' },
        { id: 'subcategories', label: 'Subcategories', icon: Layers, moduleKey: 'subcategories', permission: 'categories.view', entitlement: 'catalog', status: 'ready', tone: 'blue' },
        { id: 'brands', label: 'Brands', icon: Tag, moduleKey: 'brands', permission: 'brands.view', entitlement: 'catalog', status: 'ready', tone: 'purple' },
        { id: 'units', label: 'Units', icon: Ruler, moduleKey: 'units', permission: 'units.view', entitlement: 'catalog', status: 'ready', tone: 'slate' },
        { id: 'suppliers', label: 'Suppliers', icon: Truck, moduleKey: 'suppliers', permission: 'suppliers.view', entitlement: 'catalog', status: 'ready', tone: 'teal' },
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
        { id: 'opening-stock', label: 'Opening Stock', icon: Boxes, status: 'later', tone: 'orange' },
        { id: 'barcodes', label: 'Barcode Printing', icon: Barcode, status: 'later', tone: 'navy' },
        { id: 'price-lists', label: 'Price Lists', icon: List, status: 'later', tone: 'teal' },
      ],
    },
  ],
  'daily-entries': [
    {
      id: 'sales',
      caption: 'Sales',
      commands: [
        { id: 'sales-invoice', label: 'Sales Invoice', icon: ShoppingCart, moduleKey: 'sales-invoice', status: 'shell', tone: 'blue' },
        { id: 'sales-return', label: 'Sales Return', icon: Undo2, status: 'later', tone: 'slate' },
      ],
    },
    {
      id: 'purchases',
      caption: 'Purchases',
      commands: [
        { id: 'purchase-invoice', label: 'Purchase Invoice', icon: ClipboardList, moduleKey: 'purchase-invoice', status: 'shell', tone: 'navy' },
        { id: 'purchase-return', label: 'Purchase Return', icon: RotateCcw, status: 'later', tone: 'orange' },
        { id: 'adjustments', label: 'Adjustments of Items', icon: SlidersHorizontal, status: 'later', tone: 'slate' },
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
          status: 'later',
          tone: 'gold',
          hasMenu: true,
          menu: [
            { label: 'Payment Voucher (Dr)', ...LATER },
            { label: 'Receiving Voucher (Cr)', ...LATER },
          ],
        },
        { id: 'product-view', label: 'Product View', icon: Package, moduleKey: 'products', permission: 'products.view', entitlement: 'catalog', status: 'ready', tone: 'green' },
        { id: 'ledger', label: 'Vendor / Customer Ledger', icon: BookOpen, status: 'later', tone: 'gold' },
        { id: 'cash', label: 'Daily Cash Position', icon: Banknote, status: 'later', tone: 'teal' },
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
          status: 'later',
          tone: 'red',
          hasMenu: true,
          menu: [
            { label: 'Trial Balance', ...LATER },
            { label: 'Trial Balance 6 Cols / Side by Side', ...LATER },
            { label: 'Cash Book', ...LATER },
            { label: 'Daily Business Summary', ...LATER },
            { label: 'Top Products / Vendor / Customers', ...LATER },
            { label: 'Total Turnover Report', ...LATER },
            { label: 'Balance Sheet', ...LATER },
            { label: 'Income Statement', ...LATER },
            { label: 'Income Statement (Daily, Monthly)', ...LATER },
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
            { label: 'Daily Business Activity', ...LATER },
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
          status: 'later',
          tone: 'green',
          hasMenu: true,
          menu: [
            { label: 'Product Ledger', ...LATER },
            { label: 'Product GL (Grid)', ...LATER },
            { label: 'Daily / Monthly P/L', ...LATER },
            { label: 'Stock Status', ...LATER },
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
        { id: 'users', label: 'Configure Users', icon: Users, moduleKey: 'users', permission: 'users.view', status: 'ready', tone: 'blue' },
        { id: 'devices', label: 'Devices', icon: Monitor, moduleKey: 'devices', permission: 'devices.view', status: 'ready', tone: 'navy' },
      ],
    },
    {
      id: 'system',
      caption: 'Others',
      commands: [
        { id: 'custom-query', label: 'Custom Query', icon: FileSpreadsheet, status: 'later', tone: 'slate' },
        { id: 'report-headings', label: 'Report Headings', icon: List, status: 'later', tone: 'slate' },
        { id: 'options', label: 'Software Options', icon: Settings, moduleKey: 'settings', permission: 'settings.view', status: 'ready', tone: 'navy' },
        { id: 'recalc-stock', label: 'Recalculate Stock', icon: Boxes, status: 'later', tone: 'orange' },
        { id: 'calculator', label: 'Calculator', icon: Calculator, status: 'ready', action: 'calculator', tone: 'gold' },
        { id: 'lock', label: 'Lock this Software', icon: Lock, status: 'later', action: 'lock', tone: 'red' },
      ],
    },
  ],
  administration: [
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
        { id: 'branches', label: 'Branches', icon: Building2, moduleKey: 'branches', permission: 'settings.view', status: 'shell', tone: 'navy' },
        { id: 'warehouses', label: 'Warehouses', icon: Warehouse, moduleKey: 'warehouses', permission: 'settings.view', status: 'shell', tone: 'teal' },
      ],
    },
    {
      id: 'security',
      caption: 'Security',
      commands: [
        { id: 'devices', label: 'Devices', icon: Monitor, moduleKey: 'devices', permission: 'devices.view', status: 'ready', tone: 'navy' },
        { id: 'security', label: 'Security', icon: Shield, moduleKey: 'security', status: 'shell', tone: 'red' },
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

export function moduleTabIdentity(module: WorkspaceModule, pathname: string): { key: string; title: string; path: string } {
  return {
    key: module.keyForPath ? module.keyForPath(pathname) : module.key,
    title: module.titleForPath ? module.titleForPath(pathname) : module.title,
    path: pathname,
  }
}

export function laterPhaseHint(): string {
  return 'Available in a later phase'
}
