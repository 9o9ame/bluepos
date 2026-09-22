import { useAuth } from '../features/auth/AuthProvider'
import { DesktopPanel } from '../components/desktop/DesktopPanel'

export function AccountPage() {
  const { session } = useAuth()
  if (!session) {
    return null
  }
  const role =
    session.roles.map((item) => item.name).join(', ') || (session.membership.is_owner ? 'Owner' : 'Member')

  return (
    <DesktopPanel title="My Account">
      <dl className="kv-grid max-w-xl">
        <dt>Name</dt>
        <dd>{session.user.name}</dd>
        <dt>Username</dt>
        <dd>{session.membership.username}</dd>
        <dt>Email</dt>
        <dd>{session.user.email ?? '—'}</dd>
        <dt>Role</dt>
        <dd>{role}</dd>
        <dt>Tenant</dt>
        <dd>{session.tenant.name}</dd>
        <dt>Must change password</dt>
        <dd>{session.must_change_password ? 'Yes' : 'No'}</dd>
      </dl>
    </DesktopPanel>
  )
}

export function PlanInfoPage() {
  const { session } = useAuth()
  if (!session) {
    return null
  }
  const entitlements = session.entitlements
  const features = entitlements?.features ?? []
  const limits = entitlements?.limits ?? {}
  const usage = entitlements?.usage ?? {}

  return (
    <DesktopPanel title="Plan / Entitlements">
      <dl className="kv-grid max-w-xl">
        <dt>Plan</dt>
        <dd>{entitlements?.plan ? `${entitlements.plan.name} (${entitlements.plan.status})` : 'Not provided'}</dd>
        <dt>Features</dt>
        <dd>{features.length > 0 ? features.join(', ') : 'All current tenant features'}</dd>
      </dl>
      <div className="form-group" style={{ marginTop: 8, maxWidth: 560 }}>
        <div className="form-group-title">Limits / usage</div>
        <div className="p-2">
          {Object.keys(limits).length === 0 ? (
            <p className="text-[12px] text-[var(--text-muted)]">No limit snapshot on this session.</p>
          ) : (
            <dl className="kv-grid">
              {Object.entries(limits).map(([key, value]) => (
                <div key={key} className="contents">
                  <dt>{key}</dt>
                  <dd>
                    {usage[key] ?? 0} / {value ?? '∞'}
                  </dd>
                </div>
              ))}
            </dl>
          )}
        </div>
      </div>
      <p className="later-banner">Entitlement enforcement remains on the server. This screen is a read-only snapshot.</p>
    </DesktopPanel>
  )
}
