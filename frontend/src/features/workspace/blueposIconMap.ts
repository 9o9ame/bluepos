import type { InterfaceStyle } from '../../types/auth'

/**
 * Current working icon catalog (bluepos-v2).
 * Always used as the safe fallback when a skin pack is missing.
 */
export const BLUEPOS_RIBBON_TAB_ICONS: Record<string, string> = {
  definition: '/icons/bluepos-v2/definition.svg',
  'daily-entries': '/icons/bluepos-v2/daily-entries.svg',
  reports: '/icons/bluepos-v2/reports.svg',
  tools: '/icons/bluepos-v2/tools.svg',
  administration: '/icons/bluepos-v2/administration.svg',
  help: '/icons/bluepos-v2/help.svg',
}

export const BLUEPOS_RIBBON_COMMAND_ICONS: Record<string, string> = {
  backup: '/icons/bluepos-v2/backup-restore.svg',
  settings: '/icons/bluepos-v2/software-options.svg',
  products: '/icons/bluepos-v2/define-products.svg',
  tabular: '/icons/bluepos-v2/tabular-view.svg',
  'stock-taking': '/icons/bluepos-v2/stock-taking.svg',
  categories: '/icons/bluepos-v2/categories.svg',
  subcategories: '/icons/bluepos-v2/subcategories.svg',
  brands: '/icons/bluepos-v2/brands.svg',
  units: '/icons/bluepos-v2/units.svg',
  parties: '/icons/bluepos-v2/vendor-customer-accounts.svg',
  'opening-stock': '/icons/bluepos-v2/opening-stock.svg',
  barcodes: '/icons/bluepos-v2/barcode-printing.svg',
  'price-lists': '/icons/bluepos-v2/price-lists.svg',

  'sales-invoice': '/icons/bluepos-v2/sales-invoice.svg',
  'sales-return': '/icons/bluepos-v2/sales-return.svg',
  'purchase-invoice': '/icons/bluepos-v2/purchase-invoice.svg',
  'purchase-return': '/icons/bluepos-v2/purchase-return.svg',
  adjustments: '/icons/bluepos-v2/adjustments.svg',
  vouchers: '/icons/bluepos-v2/vouchers.svg',
  'product-view': '/icons/bluepos-v2/product-view.svg',
  ledger: '/icons/bluepos-v2/vendor-customer-ledger.svg',
  cash: '/icons/bluepos-v2/daily-cash-position.svg',

  'accounts-reports': '/icons/bluepos-v2/accounts-reports.svg',
  'daily-reports': '/icons/bluepos-v2/daily-reports.svg',
  'product-reports': '/icons/bluepos-v2/product-reports.svg',
  'advance-sales': '/icons/bluepos-v2/advance-sales-view.svg',
  'party-reports': '/icons/bluepos-v2/vendor-customer-reports.svg',

  roles: '/icons/bluepos-v2/manage-groups.svg',
  users: '/icons/bluepos-v2/configure-users.svg',
  devices: '/icons/bluepos-v2/devices.svg',
  'custom-query': '/icons/bluepos-v2/custom-query.svg',
  'report-headings': '/icons/bluepos-v2/report-headings.svg',
  options: '/icons/bluepos-v2/software-options.svg',
  'recalc-stock': '/icons/bluepos-v2/recalculate-stock.svg',
  calculator: '/icons/bluepos-v2/calculator.svg',
  lock: '/icons/bluepos-v2/lock-software.svg',

  branches: '/icons/bluepos-v2/branches.svg',
  warehouses: '/icons/bluepos-v2/warehouses.svg',
  security: '/icons/bluepos-v2/security.svg',
  plan: '/icons/bluepos-v2/plan.svg',
  about: '/icons/bluepos-v2/about.svg',
  docs: '/icons/bluepos-v2/documentation.svg',
}

/**
 * Future per-skin icon pack roots.
 * Enable a skin only after its folder is populated under /public/icons/{skin}/.
 * Until then, resolution always returns the bluepos-v2 (or catalog) fallback.
 */
export const BLUEPOS_ICON_PACK_BASE: Record<InterfaceStyle, string> = {
  classic: '/icons/classic',
  hybrid: '/icons/hybrid',
  advanced: '/icons/advanced',
}

export const BLUEPOS_ICON_PACK_ENABLED: Record<InterfaceStyle, boolean> = {
  classic: true,
  hybrid: true,
  advanced: true,
}

function filenameFromPath(path: string): string | null {
  const parts = path.split('/')
  const name = parts[parts.length - 1]
  return name || null
}

/**
 * Resolve a ribbon/custom icon for the active skin.
 * Prefer /icons/{skin}/{file} when that pack is enabled; otherwise keep current assets.
 * Callers must still fall back to Lucide when this returns null.
 */
export function resolveBlueposIcon(
  skin: InterfaceStyle,
  key: string,
  catalog: Record<string, string>,
): string | null {
  const fallback = catalog[key]
  if (!fallback) return null

  if (!BLUEPOS_ICON_PACK_ENABLED[skin]) {
    return fallback
  }

  const file = filenameFromPath(fallback)
  if (!file) return fallback

  return `${BLUEPOS_ICON_PACK_BASE[skin]}/${file}`
}

export function resolveRibbonTabIcon(skin: InterfaceStyle, tabId: string): string | null {
  return resolveBlueposIcon(skin, tabId, BLUEPOS_RIBBON_TAB_ICONS)
}

export function resolveRibbonCommandIcon(skin: InterfaceStyle, commandId: string): string | null {
  return resolveBlueposIcon(skin, commandId, BLUEPOS_RIBBON_COMMAND_ICONS)
}
