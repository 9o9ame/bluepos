import { FormEvent, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  createPlatformTenant,
  deletePlatformTenant,
  fetchPlatformPlans,
  fetchPlatformTenants,
  updatePlatformTenant,
} from '../../api/platform'
import { ApiClientError } from '../../api/client'
import { CredentialsOnceModal } from '../../components/platform/CredentialsOnceModal'
import { usePlatformAuth } from '../../features/platform/PlatformAuthProvider'
import { usePlatformCan } from '../../features/platform/usePlatformCan'
import type { PlatformTenant } from '../../types/platform'

export function PlatformTenantsPage() {
  const queryClient = useQueryClient()
  const { withRecentMfa } = usePlatformAuth()
  const canEdit = usePlatformCan('platform.tenants.edit')
  const canDelete = usePlatformCan('platform.tenants.delete')
  const tenantsQuery = useQuery({ queryKey: ['platform', 'tenants'], queryFn: () => fetchPlatformTenants(1) })
  const plansQuery = useQuery({ queryKey: ['platform', 'plans'], queryFn: fetchPlatformPlans })
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<PlatformTenant | null>(null)
  const [credentials, setCredentials] = useState<{
    business: string
    code: string
    username: string
    password: string
  } | null>(null)
  const [form, setForm] = useState({
    tenant_name: '',
    tenant_code: '',
    legal_name: '',
    recovery_email: '',
    admin_name: '',
    admin_username: 'owner',
    timezone: 'Asia/Karachi',
    currency_code: 'PKR',
    plan_ulid: '',
    status: 'active',
    generate: true,
    password: '',
  })

  const tenants = tenantsQuery.data?.data ?? (Array.isArray(tenantsQuery.data) ? tenantsQuery.data : [])
  const plans = plansQuery.data ?? []

  async function run(action: () => Promise<unknown>) {
    setError(null)
    try {
      await action()
      await queryClient.invalidateQueries({ queryKey: ['platform', 'tenants'] })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Request failed.')
    }
  }

  async function onCreate(event: FormEvent) {
    event.preventDefault()
    await run(async () => {
      const tenant = await withRecentMfa(() =>
        createPlatformTenant({
          ...form,
          plan_ulid: form.plan_ulid || plans[0]?.ulid,
          password: form.generate ? undefined : form.password,
        }),
      )
      const password = tenant.initial_admin?.temporary_password ?? (form.generate ? '' : form.password)
      if (password) {
        setCredentials({
          business: tenant.name,
          code: tenant.code,
          username: tenant.initial_admin?.username ?? form.admin_username,
          password,
        })
      }
      setForm((current) => ({
        ...current,
        tenant_name: '',
        tenant_code: '',
        legal_name: '',
        recovery_email: '',
        admin_name: '',
        password: '',
        generate: true,
      }))
    })
  }

  return (
    <section className="space-y-4">
      <h1 className="text-lg font-semibold">Tenants</h1>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      <form className="grid grid-cols-4 gap-2 rounded border border-slate-300 bg-white p-3 text-[12px]" onSubmit={onCreate}>
        {(
          [
            ['tenant_name', 'Business name'],
            ['tenant_code', 'Tenant code'],
            ['legal_name', 'Legal name'],
            ['recovery_email', 'Recovery email'],
            ['admin_name', 'Admin name'],
            ['admin_username', 'Admin username'],
          ] as const
        ).map(([key, label]) => (
          <label key={key} className="block font-semibold">
            {label}
            <input
              required={key !== 'legal_name'}
              className="mt-1 h-8 w-full rounded border border-slate-300 px-2"
              value={form[key]}
              onChange={(event) => setForm((current) => ({ ...current, [key]: event.target.value }))}
            />
          </label>
        ))}
        <label className="block font-semibold">
          Plan
          <select
            className="mt-1 h-8 w-full rounded border border-slate-300 px-2"
            value={form.plan_ulid}
            onChange={(event) => setForm((current) => ({ ...current, plan_ulid: event.target.value }))}
          >
            <option value="">Select plan</option>
            {plans.map((plan) => (
              <option key={plan.ulid} value={plan.ulid}>
                {plan.name}
              </option>
            ))}
          </select>
        </label>
        <label className="block font-semibold">
          Tenant status
          <select
            className="mt-1 h-8 w-full rounded border border-slate-300 px-2"
            value={form.status}
            onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))}
          >
            <option value="trial">Trial</option>
            <option value="active">Active</option>
          </select>
        </label>
        <label className="flex items-center gap-2 font-semibold">
          <input
            type="checkbox"
            checked={form.generate}
            onChange={(event) => setForm((current) => ({ ...current, generate: event.target.checked }))}
          />
          Generate secure password
        </label>
        {form.generate ? null : (
          <label className="block font-semibold">
            Initial password
            <input
              className="mt-1 h-8 w-full rounded border px-2"
              value={form.password}
              onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))}
              minLength={8}
            />
          </label>
        )}
        <div className="flex items-end">
          <button type="submit" className="h-8 rounded bg-slate-950 px-3 font-semibold text-white">
            Create tenant
          </button>
        </div>
      </form>
      <table className="w-full border border-slate-300 bg-white text-left text-[12px]">
        <thead className="bg-slate-50 text-slate-500">
          <tr>
            <th className="p-2">Code</th>
            <th>Business</th>
            <th>Admin Username</th>
            <th>Plan</th>
            <th>Tenant Status</th>
            <th>Subscription Status</th>
            <th>Users</th>
            <th>Branches</th>
            <th>Devices</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {tenants.map((tenant) => (
            <tr key={tenant.ulid} className="border-t border-slate-200">
              <td className="p-2">
                <Link className="font-semibold" to={`/platform/tenants/${tenant.ulid}`}>
                  {tenant.code}
                </Link>
              </td>
              <td>{tenant.name}</td>
              <td className="font-mono">{tenant.admin_username ?? '—'}</td>
              <td>{tenant.plan?.name ?? tenant.plan?.code ?? '—'}</td>
              <td>{tenant.status}</td>
              <td>{tenant.subscription?.status ?? '—'}</td>
              <td>{tenant.users_count ?? '—'}</td>
              <td>{tenant.branches_count ?? '—'}</td>
              <td>{tenant.devices_count ?? '—'}</td>
              <td>{tenant.created_at ? tenant.created_at.slice(0, 10) : '—'}</td>
              <td className="space-x-2 whitespace-nowrap p-1">
                {canEdit ? (
                  <button type="button" className="underline" onClick={() => setEditing(tenant)}>
                    Edit
                  </button>
                ) : null}
                {canDelete && tenant.status !== 'cancelled' ? (
                  <button
                    type="button"
                    className="underline text-red-700"
                    onClick={() => {
                      if (!window.confirm(`Cancel tenant ${tenant.code}? Mart users will be signed out. Posted history is kept.`)) {
                        return
                      }
                      void run(() => withRecentMfa(() => deletePlatformTenant(tenant.ulid, 'Deleted from tenant list')))
                    }}
                  >
                    Delete
                  </button>
                ) : null}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {editing ? (
        <TenantEditModal
          tenant={editing}
          onClose={() => setEditing(null)}
          onSave={async (input) => {
            await run(() => updatePlatformTenant(editing.ulid, input))
            setEditing(null)
          }}
        />
      ) : null}
      {credentials ? (
        <CredentialsOnceModal
          title="Tenant Created Successfully"
          business={credentials.business}
          code={credentials.code}
          username={credentials.username}
          password={credentials.password}
          warning="This temporary password is shown only once. The tenant administrator must change it after first login."
          onClose={() => setCredentials(null)}
        />
      ) : null}
    </section>
  )
}

function TenantEditModal({
  tenant,
  onClose,
  onSave,
}: {
  tenant: PlatformTenant
  onClose: () => void
  onSave: (input: Record<string, unknown>) => Promise<void>
}) {
  const [tenantName, setTenantName] = useState(tenant.name)
  const [legalName, setLegalName] = useState(tenant.legal_name ?? '')
  const [timezone, setTimezone] = useState(tenant.timezone)
  const [currency, setCurrency] = useState(tenant.currency_code)
  const [error, setError] = useState<string | null>(null)

  return (
    <div className="fixed inset-0 z-40 grid place-items-center bg-slate-950/40 p-4">
      <form
        className="w-full max-w-md space-y-3 rounded border bg-white p-4 text-[12px]"
        onSubmit={(event) => {
          event.preventDefault()
          setError(null)
          void onSave({
            tenant_name: tenantName,
            legal_name: legalName || null,
            timezone,
            currency_code: currency,
          }).catch((err) => setError(err instanceof ApiClientError ? err.message : 'Unable to save tenant.'))
        }}
      >
        <h2 className="text-sm font-semibold">Edit tenant — {tenant.code}</h2>
        <label className="block font-semibold">
          Business name
          <input className="mt-1 h-8 w-full rounded border px-2" value={tenantName} onChange={(event) => setTenantName(event.target.value)} required />
        </label>
        <label className="block font-semibold">
          Legal name
          <input className="mt-1 h-8 w-full rounded border px-2" value={legalName} onChange={(event) => setLegalName(event.target.value)} />
        </label>
        <label className="block font-semibold">
          Timezone
          <input className="mt-1 h-8 w-full rounded border px-2" value={timezone} onChange={(event) => setTimezone(event.target.value)} required />
        </label>
        <label className="block font-semibold">
          Currency
          <input className="mt-1 h-8 w-full rounded border px-2" value={currency} onChange={(event) => setCurrency(event.target.value)} required maxLength={3} />
        </label>
        {error ? <p className="text-red-700">{error}</p> : null}
        <div className="flex gap-2">
          <button type="submit" className="rounded bg-slate-950 px-3 py-1 text-white">
            Save
          </button>
          <button type="button" className="rounded border px-3 py-1" onClick={onClose}>
            Cancel
          </button>
        </div>
      </form>
    </div>
  )
}
