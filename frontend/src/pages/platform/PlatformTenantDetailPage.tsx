import { FormEvent, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import {
  activatePlatformTenant,
  assignPlatformSubscription,
  deactivateTenantAdmin,
  deletePlatformTenant,
  fetchFeatureCatalog,
  fetchPlatformPlans,
  fetchPlatformTenant,
  forceLogoutTenantAdmin,
  resetTenantAdmin,
  suspendPlatformTenant,
  updatePlatformTenant,
  upsertTenantFeature,
  upsertTenantLimit,
} from '../../api/platform'
import { ApiClientError } from '../../api/client'
import { CredentialsOnceModal } from '../../components/platform/CredentialsOnceModal'
import { usePlatformAuth } from '../../features/platform/PlatformAuthProvider'

const TABS = ['Overview', 'Admin Users', 'Subscription', 'Features', 'Limits'] as const

export function PlatformTenantDetailPage() {
  const { tenantUlid = '' } = useParams()
  const { withRecentMfa } = usePlatformAuth()
  const queryClient = useQueryClient()
  const [tab, setTab] = useState<(typeof TABS)[number]>('Overview')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [credentials, setCredentials] = useState<{ username: string; password: string } | null>(null)
  const tenantQuery = useQuery({
    queryKey: ['platform', 'tenant', tenantUlid],
    queryFn: () => fetchPlatformTenant(tenantUlid),
    enabled: tenantUlid !== '',
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
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      {notice ? <p className="text-[12px] text-emerald-800">{notice}</p> : null}
      <div className="flex gap-2">
        {TABS.map((item) => (
          <button
            key={item}
            type="button"
            className={`rounded border px-2 py-1 text-[12px] ${tab === item ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-300 bg-white'}`}
            onClick={() => setTab(item)}
          >
            {item}
          </button>
        ))}
      </div>

      {tab === 'Overview' ? (
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
                className="rounded border border-red-700 px-3 text-red-700"
                onClick={() => {
                  if (!window.confirm(`Cancel tenant ${tenant.code}? Mart users will be signed out. Posted history is kept.`)) {
                    return
                  }
                  void run(() => deletePlatformTenant(tenant.ulid, reason || 'Deleted from tenant detail'))
                }}
              >
                Delete
              </button>
            ) : null}
          </div>
        </div>
      ) : null}

      {tab === 'Admin Users' ? (
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

      {tab === 'Subscription' ? (
        <form
          className="space-y-2 rounded border border-slate-300 bg-white p-3 text-[12px]"
          onSubmit={(event: FormEvent) => {
            event.preventDefault()
            const form = event.target as HTMLFormElement
            const planUlid = (form.elements.namedItem('plan_ulid') as HTMLSelectElement).value
            const status = (form.elements.namedItem('status') as HTMLSelectElement).value
            void run(() => assignPlatformSubscription(tenant.ulid, { plan_ulid: planUlid, status }))
          }}
        >
          <label className="block font-semibold">
            Plan
            <select name="plan_ulid" defaultValue={tenant.plan?.ulid} className="mt-1 h-8 w-full rounded border px-2">
              {(plansQuery.data ?? []).map((plan) => (
                <option key={plan.ulid} value={plan.ulid}>
                  {plan.name}
                </option>
              ))}
            </select>
          </label>
          <label className="block font-semibold">
            Subscription status
            <select name="status" defaultValue={tenant.subscription?.status ?? 'active'} className="mt-1 h-8 w-full rounded border px-2">
              {['trial', 'active', 'past_due', 'suspended', 'cancelled', 'expired'].map((status) => (
                <option key={status} value={status}>
                  {status}
                </option>
              ))}
            </select>
          </label>
          <button type="submit" className="rounded bg-slate-950 px-3 py-1 text-white">
            Save subscription
          </button>
        </form>
      ) : null}

      {tab === 'Features' ? (
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

      {tab === 'Limits' ? (
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
