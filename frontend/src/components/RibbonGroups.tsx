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
