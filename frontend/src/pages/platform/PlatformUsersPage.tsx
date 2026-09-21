import { FormEvent, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CredentialsOnceModal } from '../../components/platform/CredentialsOnceModal'
import { ApiClientError } from '../../api/client'
import {
  activatePlatformUser,
  createPlatformUser,
  deactivatePlatformUser,
  fetchPlatformRoles,
  fetchPlatformUsers,
  forceLogoutPlatformUser,
  resetPlatformUserPassword,
  syncPlatformUserRoles,
} from '../../api/platform'
import { usePlatformCan } from '../../features/platform/usePlatformCan'
import type { PlatformUser } from '../../types/platform'

export function PlatformUsersPage() {
  const queryClient = useQueryClient()
  const canCreate = usePlatformCan('platform.users.create')
  const canAssign = usePlatformCan('platform.users.assign_roles')
  const canReset = usePlatformCan('platform.users.reset_password')
  const canLogout = usePlatformCan('platform.users.force_logout')
  const canDeactivate = usePlatformCan('platform.users.deactivate')
  const canActivate = usePlatformCan('platform.users.activate')
  const usersQuery = useQuery({ queryKey: ['platform', 'users'], queryFn: fetchPlatformUsers })
  const rolesQuery = useQuery({ queryKey: ['platform', 'roles'], queryFn: fetchPlatformRoles })
  const [error, setError] = useState<string | null>(null)
  const [credentials, setCredentials] = useState<{ username: string; password: string } | null>(null)
  const [assigning, setAssigning] = useState<PlatformUser | null>(null)
  const [form, setForm] = useState({
    name: '',
    email: '',
    password: '',
    generate: true,
    must_change_password: true,
    status: 'active',
    role_ulids: [] as string[],
    reason: '',
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['platform', 'users'] })

  async function run(action: () => Promise<unknown>) {
    setError(null)
    try {
      await action()
      await invalidate()
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Request failed.')
    }
  }

  async function onCreate(event: FormEvent) {
    event.preventDefault()
    await run(async () => {
      const created = await createPlatformUser({
        name: form.name,
        email: form.email,
        password: form.generate ? undefined : form.password,
        must_change_password: form.must_change_password,
        status: form.status,
        role_ulids: form.role_ulids,
        reason: form.reason || undefined,
      })
      if (created.temporary_password) {
        setCredentials({ username: created.email, password: created.temporary_password })
      }
      setForm((current) => ({ ...current, name: '', email: '', password: '', role_ulids: [], reason: '' }))
    })
  }

  return (
    <section className="space-y-4">
      <h1 className="text-lg font-semibold">Platform Users</h1>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      {canCreate ? (
        <form className="grid grid-cols-4 gap-2 rounded border border-slate-300 bg-white p-3 text-[12px]" onSubmit={onCreate}>
          <label className="font-semibold">
            Name
            <input className="mt-1 h-8 w-full rounded border px-2" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          </label>
          <label className="font-semibold">
            Email
            <input type="email" className="mt-1 h-8 w-full rounded border px-2" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
          </label>
          <label className="font-semibold">
            Status
            <select className="mt-1 h-8 w-full rounded border px-2" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </label>
          <label className="font-semibold">
            Role(s)
            <select
              multiple
              className="mt-1 h-20 w-full rounded border px-2"
              value={form.role_ulids}
              onChange={(e) => setForm({ ...form, role_ulids: Array.from(e.target.selectedOptions).map((option) => option.value) })}
            >
              {(rolesQuery.data ?? []).filter((role) => role.is_active).map((role) => (
                <option key={role.ulid} value={role.ulid}>
                  {role.name} ({role.code})
                </option>
              ))}
            </select>
          </label>
          <label className="flex items-center gap-2 font-semibold">
            <input type="checkbox" checked={form.generate} onChange={(e) => setForm({ ...form, generate: e.target.checked })} />
            Generate secure password
          </label>
          {form.generate ? null : (
            <label className="font-semibold">
              Temporary password
              <input className="mt-1 h-8 w-full rounded border px-2" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} minLength={8} />
            </label>
          )}
          <label className="flex items-center gap-2 font-semibold">
            <input type="checkbox" checked={form.must_change_password} onChange={(e) => setForm({ ...form, must_change_password: e.target.checked })} />
            Force password change
          </label>
          {form.role_ulids.some((ulid) => (rolesQuery.data ?? []).find((role) => role.ulid === ulid)?.code === 'super_admin') ? (
            <label className="col-span-2 font-semibold">
              Super Admin reason
              <input className="mt-1 h-8 w-full rounded border px-2" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} required />
            </label>
          ) : null}
          <div className="flex items-end">
            <button type="submit" className="h-8 rounded bg-slate-950 px-3 font-semibold text-white">
              Add Platform User
            </button>
          </div>
        </form>
      ) : null}

      <table className="w-full border border-slate-300 bg-white text-left text-[12px]">
        <thead className="bg-slate-50 text-slate-500">
          <tr>
            <th className="p-2">Name</th>
            <th>Email</th>
            <th>Roles</th>
            <th>MFA</th>
            <th>Status</th>
            <th>Last Login</th>
            <th>Last Seen</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {(usersQuery.data ?? []).map((user) => (
            <tr key={user.ulid} className="border-t border-slate-200">
              <td className="p-2">{user.name}</td>
              <td>{user.email}</td>
              <td>{(user.roles ?? []).map((role) => role.code).join(', ') || '—'}</td>
              <td>{user.mfa?.enabled ? user.mfa.method.replace('_', ' ') : 'Off'}</td>
              <td>{user.status}</td>
              <td>{user.last_login_at ? user.last_login_at.slice(0, 16).replace('T', ' ') : '—'}</td>
              <td>{user.last_seen_at ? user.last_seen_at.slice(0, 16).replace('T', ' ') : '—'}</td>
              <td className="space-x-1 whitespace-nowrap p-1">
                {canAssign ? (
                  <button type="button" className="underline" onClick={() => setAssigning(user)}>
                    Assign Roles
                  </button>
                ) : null}
                {canReset ? (
                  <button
                    type="button"
                    className="underline"
                    onClick={() =>
                      void run(async () => {
                        const reset = await resetPlatformUserPassword(user.ulid)
                        if (reset.temporary_password) {
                          setCredentials({ username: reset.email, password: reset.temporary_password })
                        }
                      })
                    }
                  >
                    Reset Password
                  </button>
                ) : null}
                {canLogout ? (
                  <button type="button" className="underline" onClick={() => void run(() => forceLogoutPlatformUser(user.ulid))}>
                    Force Logout
                  </button>
                ) : null}
                {user.status === 'active' && canDeactivate ? (
                  <button type="button" className="underline" onClick={() => void run(() => deactivatePlatformUser(user.ulid))}>
                    Deactivate
                  </button>
                ) : null}
                {user.status !== 'active' && canActivate ? (
                  <button type="button" className="underline" onClick={() => void run(() => activatePlatformUser(user.ulid))}>
                    Activate
                  </button>
                ) : null}
              </td>
            </tr>
          ))}
        </tbody>
      </table>

      {assigning ? (
        <AssignRolesModal
          user={assigning}
          roles={rolesQuery.data ?? []}
          onClose={() => setAssigning(null)}
          onSave={async (roleUlids, reason) => {
            await run(() => syncPlatformUserRoles(assigning.ulid, roleUlids, reason))
            setAssigning(null)
          }}
        />
      ) : null}
      {credentials ? (
        <CredentialsOnceModal
          title="Platform user credentials"
          username={credentials.username}
          password={credentials.password}
          warning="This temporary password is shown only once. The user must change it after first login."
          onClose={() => setCredentials(null)}
        />
      ) : null}
    </section>
  )
}

function AssignRolesModal({
  user,
  roles,
  onClose,
  onSave,
}: {
  user: PlatformUser
  roles: Array<{ ulid: string; code: string; name: string; is_system: boolean }>
  onClose: () => void
  onSave: (roleUlids: string[], reason?: string) => Promise<void>
}) {
  const [selected, setSelected] = useState<string[]>(user.roles?.map((role) => role.ulid) ?? [])
  const [reason, setReason] = useState('')
  const elevating = selected.some((ulid) => roles.find((role) => role.ulid === ulid)?.code === 'super_admin')

  return (
    <div className="fixed inset-0 z-40 grid place-items-center bg-slate-950/40 p-4">
      <form
        className="w-full max-w-md space-y-3 rounded border bg-white p-4 text-[12px]"
        onSubmit={(event) => {
          event.preventDefault()
          void onSave(selected, reason || undefined)
        }}
      >
        <h2 className="text-sm font-semibold">Assign roles — {user.name}</h2>
        <div className="max-h-56 space-y-1 overflow-auto">
          {roles.map((role) => (
            <label key={role.ulid} className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={selected.includes(role.ulid)}
                onChange={(event) =>
                  setSelected(event.target.checked ? [...selected, role.ulid] : selected.filter((ulid) => ulid !== role.ulid))
                }
              />
              {role.name} ({role.code}){role.is_system ? ' · system' : ''}
            </label>
          ))}
        </div>
        {elevating ? (
          <label className="block font-semibold">
            Super Admin confirmation reason
            <input className="mt-1 h-8 w-full rounded border px-2" value={reason} onChange={(event) => setReason(event.target.value)} required />
          </label>
        ) : null}
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
