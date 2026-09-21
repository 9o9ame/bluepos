import { FormEvent, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchBranches } from '../api/branches'
import {
  activateMembership,
  createMembership,
  deactivateMembership,
  fetchMemberships,
  forceLogoutMembership,
  resetMembershipPassword,
  saveMembershipBranches,
  saveMembershipRoles,
} from '../api/memberships'
import { fetchRoles } from '../api/roles'
import { ApiClientError } from '../api/client'
import { useCan } from '../features/auth/useCan'
import type { Membership } from '../types/auth'

export function UsersPage() {
  const queryClient = useQueryClient()
  const canCreate = useCan('users.create')
  const canRoles = useCan('users.manage_roles')
  const canBranches = useCan('users.manage_branches')
  const canDeactivate = useCan('users.deactivate')

  const canReset = useCan('users.reset_password')
  const canForceLogout = useCan('users.force_logout')
  const canActivate = useCan('users.activate')

  const membershipsQuery = useQuery({ queryKey: ['memberships'], queryFn: fetchMemberships })
  const rolesQuery = useQuery({ queryKey: ['roles'], queryFn: fetchRoles, enabled: canRoles || canCreate })
  const branchesQuery = useQuery({ queryKey: ['branches'], queryFn: fetchBranches })

  const [name, setName] = useState('')
  const [username, setUsername] = useState('')
  const [recoveryEmail, setRecoveryEmail] = useState('')
  const [password, setPassword] = useState('')
  const [roleUlid, setRoleUlid] = useState('')
  const [branchUlids, setBranchUlids] = useState<string[]>([])
  const [error, setError] = useState<string | null>(null)

  const createMutation = useMutation({
    mutationFn: createMembership,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['memberships'] })
      setName('')
      setUsername('')
      setRecoveryEmail('')
      setPassword('')
      setBranchUlids([])
    },
  })

  async function onCreate(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await createMutation.mutateAsync({
        name,
        username,
        recovery_email: recoveryEmail || undefined,
        password,
        must_change_password: true,
        roles: [roleUlid || defaultRole?.ulid || ''],
        branches: branchUlids,
      })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to create user.')
    }
  }

  const roles = rolesQuery.data ?? []
  const branches = branchesQuery.data ?? []
  const defaultRole = useMemo(() => roles.find((role) => role.code === 'cashier') ?? roles[0], [roles])

  return (
    <section className="space-y-4">
      <h2 className="text-base font-semibold">Users / Memberships</h2>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}

      {canCreate ? (
        <form className="grid gap-2 rounded border border-slate-300 bg-white p-3 md:grid-cols-2" onSubmit={onCreate}>
          <input className="h-8 rounded border px-2 text-[12px]" placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} required />
          <input className="h-8 rounded border px-2 text-[12px]" placeholder="Username" value={username} onChange={(e) => setUsername(e.target.value)} required />
          <input className="h-8 rounded border px-2 text-[12px]" placeholder="Recovery email" type="email" value={recoveryEmail} onChange={(e) => setRecoveryEmail(e.target.value)} />
          <input className="h-8 rounded border px-2 text-[12px]" placeholder="Password" type="password" minLength={8} value={password} onChange={(e) => setPassword(e.target.value)} required />
          <select className="h-8 rounded border px-2 text-[12px]" value={roleUlid || defaultRole?.ulid || ''} onChange={(e) => setRoleUlid(e.target.value)}>
            {roles.map((role) => (
              <option key={role.ulid} value={role.ulid}>{role.name}</option>
            ))}
          </select>
          <div className="md:col-span-2 flex flex-wrap gap-2 text-[12px]">
            {branches.map((branch) => (
              <label key={branch.ulid} className="flex items-center gap-1">
                <input
                  type="checkbox"
                  checked={branchUlids.includes(branch.ulid)}
                  onChange={(event) => {
                    setBranchUlids((current) => event.target.checked
                      ? [...current, branch.ulid]
                      : current.filter((id) => id !== branch.ulid))
                  }}
                />
                {branch.code}
              </label>
            ))}
          </div>
          <button type="submit" className="h-8 rounded bg-[#1f4e79] px-3 text-[12px] font-semibold text-white" disabled={createMutation.isPending}>
            Add user
          </button>
        </form>
      ) : null}

      <table className="w-full border border-slate-300 bg-white text-[12px]">
        <thead className="bg-slate-100">
          <tr>
            <th className="p-2 text-left">Name</th>
            <th className="p-2 text-left">Username</th>
            <th className="p-2 text-left">Roles</th>
            <th className="p-2 text-left">Branches</th>
            <th className="p-2 text-left">Status</th>
            <th className="p-2"></th>
          </tr>
        </thead>
        <tbody>
          {(membershipsQuery.data ?? []).map((membership) => (
            <MembershipRow
              key={membership.ulid}
              membership={membership}
              roles={roles}
              branches={branches}
              canRoles={canRoles}
              canBranches={canBranches}
              canDeactivate={canDeactivate}
              canActivate={canActivate}
              canReset={canReset}
              canForceLogout={canForceLogout}
            />
          ))}
        </tbody>
      </table>
    </section>
  )
}

function MembershipRow({
  membership,
  roles,
  branches,
  canRoles,
  canBranches,
  canDeactivate,
  canActivate,
  canReset,
  canForceLogout,
}: {
  membership: Membership
  roles: { ulid: string; name: string }[]
  branches: { ulid: string; code: string }[]
  canRoles: boolean
  canBranches: boolean
  canDeactivate: boolean
  canActivate: boolean
  canReset: boolean
  canForceLogout: boolean
}) {
  const queryClient = useQueryClient()
  const assignedRoles = (membership.roles ?? []).map((role) => role.ulid)
  const assignedBranches = (membership.branches ?? []).map((branch) => branch.ulid)

  return (
    <tr className="border-t border-slate-200">
      <td className="p-2">{membership.user?.name}</td>
      <td className="p-2">{membership.username}</td>
      <td className="p-2">
        {canRoles ? (
          <select
            className="h-7 rounded border px-1"
            value={assignedRoles[0] ?? ''}
            onChange={(event) => {
              void saveMembershipRoles(membership.ulid, [event.target.value]).then(() =>
                queryClient.invalidateQueries({ queryKey: ['memberships'] }),
              )
            }}
          >
            {roles.map((role) => (
              <option key={role.ulid} value={role.ulid}>{role.name}</option>
            ))}
          </select>
        ) : (
          (membership.roles ?? []).map((role) => role.name).join(', ')
        )}
      </td>
      <td className="p-2">
        {canBranches ? (
          <div className="flex flex-wrap gap-1">
            {branches.map((branch) => (
              <label key={branch.ulid} className="flex items-center gap-1">
                <input
                  type="checkbox"
                  checked={assignedBranches.includes(branch.ulid)}
                  onChange={(event) => {
                    const next = event.target.checked
                      ? [...assignedBranches, branch.ulid]
                      : assignedBranches.filter((id) => id !== branch.ulid)
                    void saveMembershipBranches(membership.ulid, next).then(() =>
                      queryClient.invalidateQueries({ queryKey: ['memberships'] }),
                    )
                  }}
                />
                {branch.code}
              </label>
            ))}
          </div>
        ) : (
          (membership.branches ?? []).map((branch) => branch.code).join(', ') || 'All'
        )}
      </td>
      <td className="p-2">{membership.status}{membership.is_owner ? ' / owner' : ''}</td>
      <td className="p-2 text-right">
        {canDeactivate && membership.status === 'active' ? (
          <button
            type="button"
            className="rounded border px-2 py-1"
            onClick={() => {
              void deactivateMembership(membership.ulid).then(() =>
                queryClient.invalidateQueries({ queryKey: ['memberships'] }),
              )
            }}
          >
            Deactivate
          </button>
        ) : null}
        {canActivate && membership.status !== 'active' ? (
          <button
            type="button"
            className="rounded border px-2 py-1"
            onClick={() => {
              void activateMembership(membership.ulid).then(() =>
                queryClient.invalidateQueries({ queryKey: ['memberships'] }),
              )
            }}
          >
            Activate
          </button>
        ) : null}
        {canReset ? (
          <button
            type="button"
            className="rounded border px-2 py-1"
            onClick={() => {
              const next = window.prompt('Temporary password (min 8 characters)')
              if (!next || next.length < 8) {
                return
              }
              void resetMembershipPassword(membership.ulid, next)
            }}
          >
            Reset password
          </button>
        ) : null}
        {canForceLogout ? (
          <button
            type="button"
            className="rounded border px-2 py-1"
            onClick={() => {
              void forceLogoutMembership(membership.ulid)
            }}
          >
            Force logout
          </button>
        ) : null}
      </td>
    </tr>
  )
}
