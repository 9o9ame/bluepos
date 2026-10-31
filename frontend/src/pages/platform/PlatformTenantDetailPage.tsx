import { FormEvent, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams, useSearchParams } from 'react-router-dom'
import {
  activatePlatformTenant,
  fetchPlatformTenantDevices,
  fetchPlatformTenantActivity,
  assignPlatformSubscription,
  deactivateTenantAdmin,
  deletePlatformTenant,
  fetchFeatureCatalog,
  fetchPlatformPlans,
  fetchPlatformTenant,
  forceLogoutTenantAdmin,
  resetTenantAdmin,
  seedPlatformTenantReferenceCoa,
  suspendPlatformTenant,
  updatePlatformTenant,
  upsertTenantFeature,
  upsertTenantLimit,
} from '../../api/platform'
import { ApiClientError } from '../../api/client'
import { askConfirm } from '../../feedback/FeedbackProvider'
import { CredentialsOnceModal } from '../../components/platform/CredentialsOnceModal'
import { UiSelect } from '../../components/ui/UiSelect'
import './platform-tenant-monitor.css'
import { usePlatformAuth } from '../../features/platform/PlatformAuthProvider'

const TABS = ['Overview', 'Admin Users', 'Devices', 'Login Activity', 'Subscription', 'Features', 'Limits'] as const

export function PlatformTenantDetailPage() {
  const { tenantUlid = '' } = useParams()
  const [searchParams, setSearchParams] = useSearchParams()
  const { withRecentMfa } = usePlatformAuth()
  const queryClient = useQueryClient()
  const requestedTab = searchParams.get('tab')
  const tab = TABS.find((item) => item === requestedTab) ?? 'Overview'
  const [hoverTab, setHoverTab] = useState<(typeof TABS)[number] | null>(null)
  const displayTab = hoverTab ?? tab
  const setTab = (value: (typeof TABS)[number]) => {
    setHoverTab(null)
    setSearchParams(value === 'Overview' ? {} : { tab: value })
  }
  const [approvalPage, setApprovalPage] = useState(1)
  const [devicesView, setDevicesView] = useState<'devices' | 'locations' | 'approvals'>('devices')
  const [hoverDevicesView, setHoverDevicesView] = useState<typeof devicesView | null>(null)
  const displayDevicesView = hoverDevicesView ?? devicesView
  const [activityView, setActivityView] = useState<'sessions' | 'events'>('sessions')
  const [hoverActivityView, setHoverActivityView] = useState<typeof activityView | null>(null)
  const displayActivityView = hoverActivityView ?? activityView
  const [approvalStatus, setApprovalStatus] = useState('all')
  const [approvalIp, setApprovalIp] = useState('')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [credentials, setCredentials] = useState<{ username: string; password: string } | null>(null)
  const tenantQuery = useQuery({
    queryKey: ['platform', 'tenant', tenantUlid],
    queryFn: () => fetchPlatformTenant(tenantUlid),
    enabled: tenantUlid !== '',
  })
  const activityQuery = useQuery({
    queryKey: ['platform', 'tenant', tenantUlid, 'login-activity'],
    queryFn: () => fetchPlatformTenantActivity(tenantUlid),
    enabled: tenantUlid !== '' && displayTab === 'Login Activity',
  })
  const devicesQuery = useQuery({
    queryKey: ['platform', 'tenant', tenantUlid, 'devices', approvalStatus, approvalIp, approvalPage],
    queryFn: () => fetchPlatformTenantDevices(tenantUlid, { status: approvalStatus, ip_address: approvalIp.trim() || undefined, page: approvalPage }),
    enabled: tenantUlid !== '' && displayTab === 'Devices',
  })
  const plansQuery = useQuery({ queryKey: ['platform', 'plans'], queryFn: fetchPlatformPlans })
  const catalogQuery = useQuery({ queryKey: ['platform', 'features'], queryFn: fetchFeatureCatalog })
  const tenant = tenantQuery.data

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ['platform', 'tenant', tenantUlid] })
  }

  async function run(action: () => Promise<unknown>) {
    setError(null)
    setNotice(null)
    try {
      await withRecentMfa(action)
      await invalidate()
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Request failed.')
    }
  }

  if (!tenant) {
    return <p className="text-[12px]">Loading tenant…</p>
  }

  return (
    <section className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold">
          {tenant.name} <span className="text-slate-500">({tenant.code})</span>
        </h1>
        <p className="text-[12px] text-slate-600">
          Tenant Status {tenant.status} · Subscription Status {tenant.subscription?.status ?? '—'} · Plan {tenant.plan?.name ?? '—'}
        </p>
      </div>
      {error ? <p className="text-[12px] text-[var(--ui-danger)]">{error}</p> : null}
      {notice ? <p className="text-[12px] text-[var(--ui-success)]">{notice}</p> : null}
      <div className="platform-tenant-primary-tabs flex gap-2" role="tablist" aria-label="Tenant detail sections" onMouseLeave={() => setHoverTab(null)}>
        {TABS.map((item) => (
          <button
            key={item}
            type="button"
            role="tab"
            aria-selected={tab === item}
            onMouseEnter={() => setHoverTab(item)}
            className={`rounded border px-2 py-1 text-[12px] ${tab === item ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white'}`}
            onClick={() => setTab(item)}
          >
            {item}
          </button>
        ))}
      </div>

      <div key={displayTab} className="platform-tenant-tab-transition" role="tabpanel">
      {displayTab === 'Overview' ? (
        <div className="space-y-3 rounded border border-slate-300 bg-white p-3 text-[12px]">
          <form
            className="grid grid-cols-2 gap-2"
            onSubmit={(event: FormEvent) => {
              event.preventDefault()
              const form = event.target as HTMLFormElement
              void run(() =>
                updatePlatformTenant(tenant.ulid, {
                  tenant_name: (form.elements.namedItem('tenant_name') as HTMLInputElement).value,
                  legal_name: (form.elements.namedItem('legal_name') as HTMLInputElement).value || null,
                  timezone: (form.elements.namedItem('timezone') as HTMLInputElement).value,
                  currency_code: (form.elements.namedItem('currency_code') as HTMLInputElement).value,
                }),
              )
            }}
          >
            <label className="font-semibold">
              Business name
              <input name="tenant_name" className="mt-1 h-8 w-full rounded border px-2" defaultValue={tenant.name} />
            </label>
            <label className="font-semibold">
              Legal name
              <input name="legal_name" className="mt-1 h-8 w-full rounded border px-2" defaultValue={tenant.legal_name ?? ''} />
            </label>
            <label className="font-semibold">
              Timezone
              <input name="timezone" className="mt-1 h-8 w-full rounded border px-2" defaultValue={tenant.timezone} />
            </label>
            <label className="font-semibold">
              Currency
              <input name="currency_code" className="mt-1 h-8 w-full rounded border px-2" defaultValue={tenant.currency_code} maxLength={3} />
            </label>
            <div className="col-span-2">
              <button type="submit" className="rounded bg-slate-950 px-3 py-1 text-white">
                Save tenant
              </button>
            </div>
          </form>
          <p>Users / branches / devices: {tenant.users_count} / {tenant.branches_count} / {tenant.devices_count}</p>
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              className="rounded border border-slate-700 px-3 py-1 text-slate-900"
              onClick={() => {
                void (async () => {
                  if (
                    !(await askConfirm(
                      `Seed / refresh reference Main Heads, Heads, and Account Types for ${tenant.code}? Safe to re-run (idempotent). Does not create leaf ledger accounts.`,
                    ))
                  ) {
                    return
                  }
                  setError(null)
                  setNotice(null)
                  try {
                    const result = await withRecentMfa(() => seedPlatformTenantReferenceCoa(tenant.ulid))
                    setNotice(
                      `Reference COA ready: ${result.main_heads} main heads, ${result.sub_heads} heads, ${result.account_types} account types.`,
                    )
                  } catch (err) {
                    setError(err instanceof ApiClientError ? err.message : 'Unable to seed reference COA.')
                  }
                })()
              }}
            >
              Seed reference COA
            </button>
          </div>
          <div className="flex gap-2">
            <input
              className="h-8 flex-1 rounded border border-slate-300 px-2"
              placeholder="Reason for suspend or delete"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
            <button
              type="button"
              className="rounded bg-red-700 px-3 text-white"
              onClick={() => void run(() => suspendPlatformTenant(tenant.ulid, reason))}
            >
              Suspend
            </button>
            <button
              type="button"
              className="rounded bg-emerald-700 px-3 text-white"
              onClick={() => void run(() => activatePlatformTenant(tenant.ulid))}
            >
              Activate
            </button>
            {tenant.status !== 'cancelled' ? (
              <button
                type="button"
                className="rounded border border-red-700 px-3 text-[var(--ui-danger)]"
                onClick={() => {
                  void (async () => {
                    if (!(await askConfirm(`Cancel tenant ${tenant.code}? Mart users will be signed out. Posted history is kept.`))) {
                      return
                    }
                    void run(() => deletePlatformTenant(tenant.ulid, reason || 'Deleted from tenant detail'))
                  })()
                }}
              >
                Delete
              </button>
            ) : null}
          </div>
        </div>
      ) : null}

      {displayTab === 'Admin Users' ? (
        <div className="rounded border border-slate-300 bg-white p-3 text-[12px]">
          <table className="w-full text-left">
            <thead>
              <tr className="text-slate-500">
                <th>Name</th>
                <th>Username</th>
                <th>Roles</th>
                <th>Status</th>
                <th>Last Login</th>
                <th>MFA / Device</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {(tenant.admins ?? []).map((admin) => (
                <tr key={admin.ulid} className="border-t border-slate-100">
                  <td>{admin.user?.name}</td>
                  <td>{admin.username}</td>
                  <td>{admin.roles.map((role) => role.code).join(', ') || (admin.is_owner ? 'owner' : '—')}</td>
                  <td>{admin.status}</td>
                  <td>{admin.user?.last_login_at ? admin.user.last_login_at.slice(0, 16).replace('T', ' ') : '—'}</td>
                  <td>{admin.user?.must_change_password ? 'Password change required' : '—'}</td>
                  <td className="space-x-2 whitespace-nowrap">
                    <button
                      type="button"
                      className="underline"
                      onClick={() =>
                        void run(async () => {
                          const result = await resetTenantAdmin(tenant.ulid, admin.ulid)
                          setCredentials({ username: result.username, password: result.temporary_password })
                        })
                      }
                    >
                      Reset Access
                    </button>
                    <button type="button" className="underline" onClick={() => void run(() => forceLogoutTenantAdmin(tenant.ulid, admin.ulid))}>
                      Force Logout
                    </button>
                    <button type="button" className="underline" onClick={() => void run(() => deactivateTenantAdmin(tenant.ulid, admin.ulid))}>
                      Deactivate
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {displayTab === 'Subscription' ? (
        <form
          className="space-y-2 rounded border border-slate-300 bg-white p-3 text-[12px]"
          onSubmit={(event: FormEvent) => {
            event.preventDefault()
            const form = event.target as HTMLFormElement
            const planUlid = (form.elements.namedItem('plan_ulid') as HTMLInputElement).value
            const status = (form.elements.namedItem('status') as HTMLInputElement).value
            void run(() => assignPlatformSubscription(tenant.ulid, { plan_ulid: planUlid, status }))
          }}
        >
          <label className="block font-semibold">
            Plan
            <UiSelect
              key={`tenant-plan-${tenant.plan?.ulid ?? 'none'}`}
              name="plan_ulid"
              defaultValue={tenant.plan?.ulid ?? ''}
              className="mt-1"
              aria-label="Plan"
              options={(plansQuery.data ?? []).map((plan) => ({
                value: plan.ulid,
                label: plan.name,
              }))}
            />
          </label>
          <label className="block font-semibold">
            Subscription status
            <UiSelect
              key={`tenant-sub-status-${tenant.subscription?.status ?? 'active'}`}
              name="status"
              defaultValue={tenant.subscription?.status ?? 'active'}
              className="mt-1"
              aria-label="Subscription status"
              options={['trial', 'active', 'past_due', 'suspended', 'cancelled', 'expired'].map((status) => ({
                value: status,
                label: status,
              }))}
            />
          </label>
          <button type="submit" className="rounded bg-slate-950 px-3 py-1 text-white">
            Save subscription
          </button>
        </form>
      ) : null}

      {displayTab === 'Features' ? (
        <div className="rounded border border-slate-300 bg-white p-3 text-[12px]">
          <table className="w-full text-left">
            <thead>
              <tr className="text-slate-500">
                <th>Feature</th>
                <th>Effective</th>
                <th>Override</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {(catalogQuery.data?.features ?? []).map((feature) => {
                const effective = tenant.entitlements?.features.includes(feature.key) ?? false
                const override = tenant.feature_overrides?.find((row) => row.feature_key === feature.key)
                return (
                  <tr key={feature.key} className="border-t border-slate-100">
                    <td className="py-1">{feature.name}</td>
                    <td>{effective ? 'Enabled' : 'Disabled'}</td>
                    <td>{override ? (override.enabled ? 'Enabled' : 'Disabled') : 'Plan default'}</td>
                    <td>
                      <button
                        type="button"
                        className="mr-1 underline"
                        onClick={() =>
                          void run(() => upsertTenantFeature(tenant.ulid, feature.key, !effective, `Toggle ${feature.key}`))
                        }
                      >
                        Override
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      ) : null}

      {displayTab === 'Devices' ? (
        <div className="platform-tenant-monitor-panel rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] p-3 text-[12px]">
          <div className="mb-3 flex items-center justify-between">
            <strong>Tenant devices — Pending approvals</strong>
            <button type="button" className="rounded border px-3 py-1"
              onClick={() => void devicesQuery.refetch()}>Refresh</button>
          </div>
          <p className="mb-2 text-[var(--ui-text-muted)]">Read-only monitoring. Tenant owners verify their own devices by email OTP and approve staff device/network requests.</p>
          <div role="tablist" aria-label="Tenant device monitoring lists" className="platform-tenant-monitor-tabs" onMouseLeave={() => setHoverDevicesView(null)}>
            {([
              ['devices', 'Registered devices'],
              ['locations', 'Approximate locations'],
              ['approvals', 'Employee approval history'],
            ] as const).map(([key, label]) => (
              <button key={key} type="button" role="tab" aria-selected={devicesView === key} onMouseEnter={() => setHoverDevicesView(key)}
                className={displayDevicesView === key ? 'is-active' : ''}
                onClick={() => { setDevicesView(key); setHoverDevicesView(null) }}>{label}</button>
            ))}
          </div>
          {devicesQuery.isLoading ? <p>Loading devices…</p> : null}
          {devicesQuery.isError ? <p role="alert">Could not load devices.</p> : null}
          {displayDevicesView === 'devices' ? <div role="tabpanel" className="platform-tenant-monitor-grid">
            <table className="w-full border-collapse text-left">
              <thead><tr className="border-b"><th className="p-2">Name</th><th>Type</th><th>Status</th><th>Registered</th><th>Last seen</th><th>Staff approvals</th></tr></thead>
              <tbody>{(devicesQuery.data?.devices ?? []).map((device) => (
                <tr key={device.ulid} className="border-b">
                  <td className="p-2">{device.name}</td><td>{device.type}</td><td>{device.status}</td>
                  <td>{device.registered_at ? new Date(device.registered_at).toLocaleString() : '—'}</td>
                  <td>{device.last_seen_at ? new Date(device.last_seen_at).toLocaleString() : '—'}</td>
                  <td>Pending: {device.employee_approvals.pending} · Approved: {device.employee_approvals.approved} · Rejected: {device.employee_approvals.rejected}</td>

                </tr>
              ))}</tbody>
            </table>
          </div> : null}
          {devicesQuery.data?.devices.length === 0 && displayDevicesView === 'devices' ? <p className="pt-3">No registered devices for this tenant.</p> : null}
          {displayDevicesView === 'locations' ? <div role="tabpanel" className="platform-tenant-monitor-section">
          <strong className="block">Approximate device locations (read-only, last 30 days)</strong>
          <p className="my-2 text-[12px] text-[var(--ui-text-muted)]">Browser-reported locations rounded for privacy. Not verified physical presence; country/city names are unavailable.</p>
          <div className="platform-tenant-monitor-grid">
            <table className="w-full text-left">
              <thead><tr className="border-b"><th>User</th><th>Device</th><th>Approximate position</th><th>Reported accuracy</th><th>Recorded</th></tr></thead>
              <tbody>{(devicesQuery.data?.locations ?? []).map(loc => (
                <tr className="border-t" key={loc.ulid}>
                  <td>{loc.username}</td><td>{loc.device_name ?? '—'}</td>
                  <td>{[loc.city, loc.region, loc.country].filter(Boolean).join(', ') || 'Unknown'} · {loc.latitude_approx}, {loc.longitude_approx}</td>
                  <td>{loc.accuracy_meters} m</td><td>{loc.observed_at}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
          </div> : null}
          {displayDevicesView === 'approvals' ? <div role="tabpanel" className="platform-tenant-monitor-section">
          <strong className="block">Employee device/network approval history (read-only, 100 per page)</strong>
          <div className="my-2 flex flex-wrap items-end gap-2">
            <label className="flex flex-col gap-1">Decision
              <UiSelect value={approvalStatus} onChange={v => { setApprovalStatus(v); setApprovalPage(1) }} options={[
                { value: 'all', label: 'All decisions' },
                { value: 'pending', label: 'Pending' },
                { value: 'approved', label: 'Approved' },
                { value: 'rejected', label: 'Rejected' },
              ]} />
            </label>
            <label className="flex flex-col gap-1">Request IP
              <input className="h-8 rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] px-2" value={approvalIp} onChange={e => { setApprovalIp(e.target.value); setApprovalPage(1) }} placeholder="Any IP" />
            </label>
          </div>
          <div className="platform-tenant-monitor-grid">
            <table className="w-full text-left">
              <thead><tr className="border-b"><th>Employee</th><th>Roles</th><th>Device</th><th>Status</th><th>IP</th><th>Approx. location</th><th>Browser</th><th>Requested</th><th>Reviewed</th><th>Reviewed by</th></tr></thead>
              <tbody>{(devicesQuery.data?.access_requests ?? []).map(item => (
                <tr className="border-t" key={item.ulid}>
                  <td>{item.username}</td><td>{item.role_names.join(', ') || '—'}</td><td>{item.device_name}</td><td>{item.status}</td>
                  <td>{item.request_ip ?? '—'}</td><td>{item.request_latitude_approx !== null && item.request_longitude_approx !== null ? `${item.request_latitude_approx}, ${item.request_longitude_approx}` : '—'}</td><td className="max-w-64 break-all">{item.request_user_agent ?? '—'}</td>
                  <td>{item.created_at ?? '—'}</td><td>{item.reviewed_at ?? '—'}</td><td>{item.reviewer_name ?? '—'}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
          {devicesQuery.data?.access_requests.length === 0 ? <p className="pt-3">No employee access requests recorded.</p> : null}
          <div className="mt-2 flex items-center gap-2">
            <span>{devicesQuery.data?.approval_pagination.total ?? 0} requests · page {approvalPage}</span>
            <button type="button" className="rounded border px-3 py-1" disabled={approvalPage <= 1} onClick={() => setApprovalPage(n => Math.max(1, n - 1))}>Previous</button>
            <button type="button" className="rounded border px-3 py-1" disabled={!devicesQuery.data || approvalPage >= devicesQuery.data.approval_pagination.last_page} onClick={() => setApprovalPage(n => n + 1)}>Next</button>
          </div>
          </div> : null}
        </div>
      ) : null}

      {displayTab === 'Login Activity' ? (
        <div className="platform-tenant-monitor-panel rounded border border-[var(--ui-border)] bg-[var(--ui-surface)] p-3 text-[12px]">
          <div className="mb-2 flex justify-between"><strong>Recent login activity (up to 100 sessions and 100 events)</strong>
            <button type="button" className="rounded border px-3 py-1" onClick={() => void activityQuery.refetch()}>Refresh</button></div>
          <p className="mb-2 text-slate-600">IP addresses and browser user agents are recorded by the server. IP is not an exact physical location.</p>
          {activityQuery.isLoading ? <p>Loading activity…</p> : null}
          {activityQuery.isError ? <p role="alert">Unable to load activity or insufficient audit permission.</p> : null}
          <div role="tablist" aria-label="Login activity lists" className="platform-tenant-monitor-tabs" onMouseLeave={() => setHoverActivityView(null)}>
            {([['sessions', 'Sessions'], ['events', 'Security events']] as const).map(([key, label]) => (
              <button key={key} type="button" role="tab" aria-selected={activityView === key} onMouseEnter={() => setHoverActivityView(key)}
                className={displayActivityView === key ? 'is-active' : ''}
                onClick={() => { setActivityView(key); setHoverActivityView(null) }}>{label}</button>
            ))}
          </div>
          {displayActivityView === 'sessions' ? <div role="tabpanel" className="platform-tenant-monitor-grid"><table className="w-full text-left">
            <thead><tr><th>Employee</th><th>Roles</th><th>Device</th><th>IP</th><th>Browser</th><th>Last seen</th><th>State</th></tr></thead>
            <tbody>{(activityQuery.data?.sessions ?? []).map(session => <tr className="border-t" key={session.ulid}>
              <td>{session.membership?.username ?? '—'}</td><td>{session.membership?.roles.map(role => role.name).join(', ') || '—'}</td><td>{session.device?.name ?? 'Unknown'}</td><td>{session.ip_address ?? '—'}</td>
              <td className="max-w-64 break-all">{session.user_agent ?? '—'}</td>
              <td>{session.last_seen_at ?? '—'}</td><td>{session.revoked_at ? 'Revoked' : 'Recorded session'}</td>
            </tr>)}</tbody></table></div> : null}
          {displayActivityView === 'events' ? <div role="tabpanel" className="platform-tenant-monitor-grid"><table className="w-full text-left">
            <thead><tr><th>Event</th><th>Employee</th><th>Device</th><th>IP</th><th>Browser</th><th>Time</th></tr></thead>
            <tbody>{(activityQuery.data?.events ?? []).map(event => <tr className="border-t" key={event.ulid}>
              <td>{event.event}</td><td>{event.membership?.username ?? 'Unknown'}</td><td>{event.device?.name ?? '—'}</td><td>{event.ip_address ?? '—'}</td>
              <td className="max-w-64 break-all">{event.user_agent ?? '—'}</td><td>{event.occurred_at ?? '—'}</td>
            </tr>)}</tbody></table></div> : null}
        </div>
      ) : null}

      {displayTab === 'Limits' ? (
        <div className="rounded border border-slate-300 bg-white p-3 text-[12px]">
          {(['max_users', 'max_branches', 'max_warehouses', 'max_devices'] as const).map((key) => (
            <form
              key={key}
              className="mb-2 flex items-end gap-2"
              onSubmit={(event: FormEvent) => {
                event.preventDefault()
                const value = Number((event.target as HTMLFormElement).limit.value)
                void run(async () => {
                  const result = await upsertTenantLimit(tenant.ulid, key, Number.isFinite(value) ? value : null, `Set ${key}`)
                  if (result.warning) {
                    setNotice(result.warning)
                  }
                })
              }}
            >
              <label className="font-semibold">
                {key} (effective {tenant.entitlements?.limits[key] ?? 'unlimited'}, usage {tenant.entitlements?.usage[key] ?? 0})
                <input name="limit" className="mt-1 block h-8 rounded border px-2" defaultValue={tenant.entitlements?.limits[key] ?? ''} />
              </label>
              <button type="submit" className="h-8 rounded border px-2">
                Save override
              </button>
            </form>
          ))}
        </div>
      ) : null}
      </div>
      {credentials ? (
        <CredentialsOnceModal
          title="Tenant admin access reset"
          username={credentials.username}
          password={credentials.password}
          warning="This temporary password is shown only once. The tenant administrator must change it after first login."
          onClose={() => setCredentials(null)}
        />
      ) : null}
    </section>
  )
}
