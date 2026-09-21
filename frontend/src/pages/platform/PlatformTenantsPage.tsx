import { FormEvent, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { createPlatformTenant, fetchPlatformPlans, fetchPlatformTenants } from '../../api/platform'
import { ApiClientError } from '../../api/client'

export function PlatformTenantsPage() {
  const queryClient = useQueryClient()
  const tenantsQuery = useQuery({ queryKey: ['platform', 'tenants'], queryFn: () => fetchPlatformTenants(1) })
  const plansQuery = useQuery({ queryKey: ['platform', 'plans'], queryFn: fetchPlatformPlans })
  const [error, setError] = useState<string | null>(null)
  const [temporaryPassword, setTemporaryPassword] = useState<string | null>(null)
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
  })

  const createMutation = useMutation({
    mutationFn: createPlatformTenant,
    onSuccess: async (tenant) => {
      await queryClient.invalidateQueries({ queryKey: ['platform', 'tenants'] })
      setTemporaryPassword(tenant.initial_admin?.temporary_password ?? null)
    },
  })

  const tenants = tenantsQuery.data?.data ?? (Array.isArray(tenantsQuery.data) ? tenantsQuery.data : [])
  const plans = plansQuery.data ?? []

  async function onCreate(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await createMutation.mutateAsync({
        ...form,
        plan_ulid: form.plan_ulid || plans[0]?.ulid,
      })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to create tenant.')
    }
  }

  return (
    <section className="space-y-4">
      <h1 className="text-lg font-semibold">Tenants</h1>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      {temporaryPassword ? (
        <p className="rounded border border-amber-300 bg-amber-50 p-2 text-[12px]">
          Initial admin password (shown once): <strong>{temporaryPassword}</strong>
        </p>
      ) : null}
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
          Status
          <select
            className="mt-1 h-8 w-full rounded border border-slate-300 px-2"
            value={form.status}
            onChange={(event) => setForm((current) => ({ ...current, status: event.target.value }))}
          >
            <option value="trial">Trial</option>
            <option value="active">Active</option>
          </select>
        </label>
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
            <th>Plan</th>
            <th>Subscription</th>
            <th>Status</th>
            <th>Users</th>
            <th>Branches</th>
            <th>Devices</th>
            <th>Created</th>
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
              <td>{tenant.plan?.code ?? '—'}</td>
              <td>{tenant.subscription?.status ?? '—'}</td>
              <td>{tenant.status}</td>
              <td>{tenant.users_count ?? '—'}</td>
              <td>{tenant.branches_count ?? '—'}</td>
              <td>{tenant.devices_count ?? '—'}</td>
              <td>{tenant.created_at ? tenant.created_at.slice(0, 10) : '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
