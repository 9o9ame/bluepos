import type { ReactNode } from 'react'
import {
  Banknote,
  BarChart3,
  Package,
  ShoppingCart,
  Truck,
  Users,
} from 'lucide-react'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan, useEntitled } from '../features/auth/useCan'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { DesktopPanel } from '../components/desktop/DesktopPanel'

export function WorkspacePage() {
  const { session } = useAuth()
  const { openModule } = useWorkspace()
  const canProducts = useCan('products.view')
  const catalogEnabled = useEntitled('catalog')

  if (!session) {
    return null
  }

  const role =
    session.roles.map((item) => item.name).join(', ') || (session.membership.is_owner ? 'Owner' : 'Member')
  const deviceStatus = session.device?.status ?? 'not registered'
  const plan = session.entitlements?.plan

  return (
    <DesktopPanel title={`${session.tenant.name} — Home`}>
      <div className="grid gap-2 lg:grid-cols-[minmax(280px,360px)_minmax(0,1fr)]">
        <div>
          <div className="form-group">
            <div className="form-group-title">Session</div>
            <dl className="kv-grid" style={{ padding: 8 }}>
              <dt>Business</dt>
              <dd>{session.tenant.name}</dd>
              <dt>Branch</dt>
              <dd>
                {session.branch.code} — {session.branch.name}
              </dd>
              <dt>Warehouse</dt>
              <dd>
                {session.warehouse.code} — {session.warehouse.name}
              </dd>
              <dt>User</dt>
              <dd>{session.user.name}</dd>
              <dt>Role</dt>
              <dd>{role}</dd>
            </dl>
          </div>
          <div className="form-group" style={{ marginTop: 6 }}>
            <div className="form-group-title">Security / account</div>
            <dl className="kv-grid" style={{ padding: 8 }}>
              <dt>Device</dt>
              <dd>
                <span className="status-pill">{deviceStatus}</span>
              </dd>
              <dt>Connection</dt>
              <dd>{typeof navigator === 'undefined' || navigator.onLine ? 'Online' : 'Offline'}</dd>
              <dt>Plan</dt>
              <dd>{plan ? `${plan.name} (${plan.status})` : 'Current tenant plan'}</dd>
            </dl>
          </div>
        </div>
        <div>
          <div className="form-group">
            <div className="form-group-title">Quick commands</div>
            <div className="flex flex-wrap gap-2 p-2">
              <HomeCommand icon={<ShoppingCart size={22} />} label="New Sale" onClick={() => openModule('/daily/sales')} />
              <HomeCommand icon={<Truck size={22} />} label="New Purchase" onClick={() => openModule('/daily/purchases')} />
              <HomeCommand
                icon={<Package size={22} />}
                label="Products"
                disabled={!canProducts || !catalogEnabled}
                title={!catalogEnabled ? 'Not included in the current plan' : 'Products'}
                onClick={() => openModule('/definition/products')}
              />
              <HomeCommand icon={<Users size={22} />} label="Customers" onClick={() => openModule('/definition/parties')} />
              <HomeCommand icon={<Banknote size={22} />} label="Daily Cash" disabled title="Available in a later phase" />
              <HomeCommand icon={<BarChart3 size={22} />} label="Reports" onClick={() => openModule('/reports')} />
            </div>
          </div>
          <p className="later-banner">
            Operational activity will appear here once daily entries are posted. No sample sales or stock figures are
            shown.
          </p>
        </div>
      </div>
    </DesktopPanel>
  )
}

function HomeCommand({
  icon,
  label,
  disabled,
  title,
  onClick,
}: {
  icon: ReactNode
  label: string
  disabled?: boolean
  title?: string
  onClick?: () => void
}) {
  return (
    <button type="button" className="quick-command" disabled={disabled} title={title ?? label} onClick={onClick}>
      {icon}
      <span>{label}</span>
    </button>
  )
}
