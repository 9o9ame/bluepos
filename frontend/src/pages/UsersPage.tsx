import { FormEvent, useMemo, useState } from 'react'
import { RefreshCw, Save, X } from 'lucide-react'
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
import { DesktopButton, DesktopPanel, Field, FormGroup } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Membership } from '../types/auth'

export function UsersPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
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
  const [selectedKey, setSelectedKey] = useState<string | null>(null)

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
  const rows = membershipsQuery.data ?? []

  useWorkspaceHandlers({
    save: () => {
      const form = document.getElementById('user-create-form') as HTMLFormElement | null
      form?.requestSubmit()
    },
    refresh: () => {
      void membershipsQuery.refetch()
    },
  })

  return (
    <DesktopPanel
      title="Users / Memberships"
      toolbar={
        <>
          <DesktopButton icon={<Save size={13} />} label="Save" shortcut="F9" type="submit" disabled={!canCreate} onClick={() => {
            const form = document.getElementById('user-create-form') as HTMLFormElement | null
            form?.requestSubmit()
          }} />
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" onClick={() => void membershipsQuery.refetch()} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      {error ? <p className="mb-2 text-[12px] text-[var(--danger)]">{error}</p> : null}
      {canCreate ? (
        <form id="user-create-form" onSubmit={onCreate}>
          <FormGroup title="New membership">
            <Field label="Name">
              <input className="desktop-input" value={name} onChange={(e) => setName(e.target.value)} required />
            </Field>
            <Field label="Username">
              <input className="desktop-input" value={username} onChange={(e) => setUsername(e.target.value)} required />
            </Field>
            <Field label="Recovery email">
              <input className="desktop-input" type="email" value={recoveryEmail} onChange={(e) => setRecoveryEmail(e.target.value)} />
            </Field>
            <Field label="Password">
              <input className="desktop-input" type="password" minLength={8} value={password} onChange={(e) => setPassword(e.target.value)} required />
            </Field>
            <Field label="Role">
              <select className="desktop-select" value={roleUlid || defaultRole?.ulid || ''} onChange={(e) => setRoleUlid(e.target.value)}>
                {roles.map((role) => (
                  <option key={role.ulid} value={role.ulid}>{role.name}</option>
                ))}
              </select>
            </Field>
            <Field label="Branches" span2>
              <div className="flex flex-wrap gap-2 text-[12px] text-[var(--text-main)]">
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
            </Field>
          </FormGroup>
        </form>
      ) : null}

      <div style={{ height: 360, marginTop: 6 }}>
        <PosDataGrid
          columns={[
            { key: 'name', header: 'Name', render: (row) => row.user?.name ?? '' },
            { key: 'username', header: 'Username', render: (row) => row.username },
            {
              key: 'roles',
              header: 'Roles',
              render: (row) => (
                <RoleCell membership={row} roles={roles} canRoles={canRoles} />
              ),
            },
            {
              key: 'branches',
              header: 'Branches',
              render: (row) => (
                <BranchCell membership={row} branches={branches} canBranches={canBranches} />
              ),
            },
            { key: 'status', header: 'Status', render: (row) => `${row.status}${row.is_owner ? ' / owner' : ''}` },
            {
              key: 'actions',
              header: 'Actions',
              render: (row) => (
                <MembershipActions
                  membership={row}
                  canDeactivate={canDeactivate}
                  canActivate={canActivate}
                  canReset={canReset}
                  canForceLogout={canForceLogout}
                />
              ),
            },
          ]}
          rows={rows}
          rowKey={(row) => row.ulid}
          selectedKey={selectedKey}
          onSelect={(row) => setSelectedKey(row.ulid)}
        />
      </div>
    </DesktopPanel>
  )
}

function RoleCell({
  membership,
  roles,
  canRoles,
}: {
  membership: Membership
  roles: { ulid: string; name: string }[]
  canRoles: boolean
}) {
  const queryClient = useQueryClient()
  const assignedRoles = (membership.roles ?? []).map((role) => role.ulid)
  if (!canRoles) {
    return <>{(membership.roles ?? []).map((role) => role.name).join(', ')}</>
  }
  return (
    <select
      className="desktop-select"
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
  )
}

function BranchCell({
  membership,
  branches,
  canBranches,
}: {
  membership: Membership
  branches: { ulid: string; code: string }[]
  canBranches: boolean
}) {
  const queryClient = useQueryClient()
  const assignedBranches = (membership.branches ?? []).map((branch) => branch.ulid)
  if (!canBranches) {
    return <>{(membership.branches ?? []).map((branch) => branch.code).join(', ') || 'All'}</>
  }
  return (
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
  )
}

function MembershipActions({
  membership,
  canDeactivate,
  canActivate,
  canReset,
  canForceLogout,
}: {
  membership: Membership
  canDeactivate: boolean
  canActivate: boolean
  canReset: boolean
  canForceLogout: boolean
}) {
  const queryClient = useQueryClient()
  return (
    <div className="flex flex-wrap justify-end gap-1">
      {canDeactivate && membership.status === 'active' ? (
        <DesktopButton
          label="Deactivate"
          onClick={() => {
            void deactivateMembership(membership.ulid).then(() =>
              queryClient.invalidateQueries({ queryKey: ['memberships'] }),
            )
          }}
        />
      ) : null}
      {canActivate && membership.status !== 'active' ? (
        <DesktopButton
          label="Activate"
          onClick={() => {
            void activateMembership(membership.ulid).then(() =>
              queryClient.invalidateQueries({ queryKey: ['memberships'] }),
            )
          }}
        />
      ) : null}
      {canReset ? (
        <DesktopButton
          label="Reset password"
          onClick={() => {
            const next = window.prompt('Temporary password (min 8 characters)')
            if (!next || next.length < 8) {
              return
            }
            void resetMembershipPassword(membership.ulid, next)
          }}
        />
      ) : null}
      {canForceLogout ? (
        <DesktopButton
          label="Force logout"
          onClick={() => {
            void forceLogoutMembership(membership.ulid)
          }}
        />
      ) : null}
    </div>
  )
}
