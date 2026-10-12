import { FormEvent, useEffect, useMemo, useState } from 'react'
import { RefreshCw, Save, UserRoundCog, Users, X } from 'lucide-react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
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
import { DesktopButton, DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiSelect } from '../components/ui/UiSelect'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Membership } from '../types/auth'
import './UsersPage.css'

type ConfigureUsersTab = 'administration' | 'users' | 'others' | 'salesman' | 'fp2'

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

  const [tab, setTab] = useState<ConfigureUsersTab>('administration')
  const [selectedMembershipUlid, setSelectedMembershipUlid] = useState('')
  const [name, setName] = useState('')
  const [username, setUsername] = useState('')
  const [recoveryEmail, setRecoveryEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [roleUlid, setRoleUlid] = useState('')
  const [branchUlids, setBranchUlids] = useState<string[]>([])
  const [selectedRowUlid, setSelectedRowUlid] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const roles = rolesQuery.data ?? []
  const branches = branchesQuery.data ?? []
  const rows = membershipsQuery.data ?? []
  const defaultRole = useMemo(() => roles.find((role) => role.code === 'cashier') ?? roles[0], [roles])
  const selectedMembership = useMemo(
    () => rows.find((row) => row.ulid === selectedMembershipUlid) ?? null,
    [rows, selectedMembershipUlid],
  )
  const selectedRow = useMemo(
    () => rows.find((row) => row.ulid === selectedRowUlid) ?? null,
    [rows, selectedRowUlid],
  )

  useEffect(() => {
    if (!selectedMembership) {
      setName('')
      setUsername('')
      setRecoveryEmail('')
      setPassword('')
      setPasswordConfirmation('')
      setRoleUlid(defaultRole?.ulid ?? '')
      setBranchUlids([])
      return
    }

    setName(selectedMembership.user?.name ?? '')
    setUsername(selectedMembership.username)
    setRecoveryEmail(selectedMembership.user?.email ?? '')
    setPassword('')
    setPasswordConfirmation('')
    setRoleUlid(selectedMembership.roles?.[0]?.ulid ?? '')
    setBranchUlids((selectedMembership.branches ?? []).map((branch) => branch.ulid))
  }, [defaultRole?.ulid, selectedMembership])

  async function refreshMemberships() {
    await membershipsQuery.refetch()
  }

  async function onSave(event?: FormEvent) {
    event?.preventDefault()
    setError(null)

    if (password !== passwordConfirmation) {
      setError('Password and confirmation do not match.')
      return
    }

    if (!selectedMembership && (!name.trim() || !username.trim() || password.length < 8)) {
      setError('Name, username, and a password of at least 8 characters are required.')
      return
    }

    if (selectedMembership && password && password.length < 8) {
      setError('Password must be at least 8 characters.')
      return
    }

    setSaving(true)
    try {
      if (!selectedMembership) {
        if (!canCreate) {
          throw new Error('You do not have permission to create users.')
        }

        await createMembership({
          name: name.trim(),
          username: username.trim(),
          recovery_email: recoveryEmail.trim() || undefined,
          password,
          must_change_password: true,
          roles: [roleUlid || defaultRole?.ulid || ''],
          branches: branchUlids,
        })
      } else {
        if (canRoles && roleUlid) {
          await saveMembershipRoles(selectedMembership.ulid, [roleUlid])
        }
        if (canBranches) {
          await saveMembershipBranches(selectedMembership.ulid, branchUlids)
        }
        if (password) {
          if (!canReset) {
            throw new Error('You do not have permission to reset this password.')
          }
          await resetMembershipPassword(selectedMembership.ulid, password)
        }
      }

      await queryClient.invalidateQueries({ queryKey: ['memberships'] })
      setPassword('')
      setPasswordConfirmation('')

      if (!selectedMembership) {
        setName('')
        setUsername('')
        setRecoveryEmail('')
        setBranchUlids([])
        setRoleUlid(defaultRole?.ulid ?? '')
      }
    } catch (err) {
      setError(err instanceof ApiClientError || err instanceof Error ? err.message : 'Unable to save user.')
    } finally {
      setSaving(false)
    }
  }

  async function runSelectedAction(action: 'activate' | 'deactivate' | 'logout') {
    if (!selectedRow) return
    setError(null)

    try {
      if (action === 'activate') {
        await activateMembership(selectedRow.ulid)
      } else if (action === 'deactivate') {
        await deactivateMembership(selectedRow.ulid)
      } else {
        await forceLogoutMembership(selectedRow.ulid)
      }
      await queryClient.invalidateQueries({ queryKey: ['memberships'] })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to update user.')
    }
  }

  useWorkspaceHandlers({
    save: () => {
      if (tab === 'administration') {
        const form = document.getElementById('configure-user-form') as HTMLFormElement | null
        form?.requestSubmit()
      }
    },
    refresh: () => {
      void refreshMemberships()
    },
  })

  const membershipOptions = [
    { value: '', label: '— Type / create a new user —' },
    ...rows.map((membership) => ({
      value: membership.ulid,
      label: membership.username,
    })),
  ]

  return (
    <DesktopPanel
      title="Configure Users"
      className="configure-users-page"
      flush
      toolbar={
        <>
          <DesktopButton
            icon={<RefreshCw size={13} />}
            label="Refresh"
            shortcut="F8"
            onClick={() => void refreshMemberships()}
          />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      <div className="configure-users-window">
        <div className="configure-users-title">
          <UserRoundCog size={22} aria-hidden />
          <strong>User Administration</strong>
        </div>

        <div className="configure-users-tabs" role="tablist" aria-label="Configure users sections">
          <button
            type="button"
            role="tab"
            aria-selected={tab === 'administration'}
            className={tab === 'administration' ? 'is-active' : undefined}
            onClick={() => setTab('administration')}
          >
            User Administration
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={tab === 'users'}
            className={tab === 'users' ? 'is-active' : undefined}
            onClick={() => setTab('users')}
          >
            View Users
          </button>
          <button type="button" role="tab" aria-selected={false} disabled title="Phase 2">
            Others
          </button>
          <button type="button" role="tab" aria-selected={false} disabled title="Phase 2">
            User To SO
          </button>
          <button type="button" role="tab" aria-selected={false} disabled title="Reference details required">
            FP 2 User
          </button>
        </div>

        {error ? <div className="configure-users-error">{error}</div> : null}

        {tab === 'administration' ? (
          <form id="configure-user-form" className="configure-users-form" onSubmit={onSave}>
            <div className="configure-users-form-grid">
              <label className="configure-users-field configure-users-field-wide">
                <span>Type Existing User Name or Type a New User Name</span>
                <UiSelect
                  value={selectedMembershipUlid}
                  disabled={membershipsQuery.isLoading}
                  options={membershipOptions}
                  onChange={setSelectedMembershipUlid}
                />
              </label>

              <label className="configure-users-field">
                <span>Name</span>
                <input
                  value={name}
                  disabled={Boolean(selectedMembership)}
                  onChange={(event) => setName(event.target.value)}
                  required={!selectedMembership}
                />
              </label>

              <label className="configure-users-field">
                <span>User Name</span>
                <input
                  value={username}
                  disabled={Boolean(selectedMembership)}
                  onChange={(event) => setUsername(event.target.value)}
                  required={!selectedMembership}
                />
              </label>

              <label className="configure-users-field">
                <span>Password</span>
                <input
                  type="password"
                  minLength={8}
                  value={password}
                  placeholder={selectedMembership ? 'Leave blank to keep current password' : ''}
                  onChange={(event) => setPassword(event.target.value)}
                  required={!selectedMembership}
                />
              </label>

              <label className="configure-users-field">
                <span>Confirm</span>
                <input
                  type="password"
                  minLength={8}
                  value={passwordConfirmation}
                  onChange={(event) => setPasswordConfirmation(event.target.value)}
                  required={!selectedMembership || Boolean(password)}
                />
              </label>

              <label className="configure-users-field configure-users-field-wide">
                <span>Group</span>
                <UiSelect
                  value={roleUlid || defaultRole?.ulid || ''}
                  disabled={rolesQuery.isLoading || (Boolean(selectedMembership) && !canRoles)}
                  options={roles.filter((role) => role.is_active).map((role) => ({ value: role.ulid, label: role.name }))}
                  onChange={setRoleUlid}
                />
              </label>

              <div className="configure-users-field configure-users-field-wide">
                <span>Branch Access</span>
                <div className="configure-users-branches">
                  {branches.map((branch) => (
                    <label key={branch.ulid}>
                      <input
                        type="checkbox"
                        checked={branchUlids.includes(branch.ulid)}
                        disabled={Boolean(selectedMembership) && !canBranches}
                        onChange={(event) => {
                          setBranchUlids((current) =>
                            event.target.checked
                              ? [...current, branch.ulid]
                              : current.filter((ulid) => ulid !== branch.ulid),
                          )
                        }}
                      />
                      <span>{branch.code} — {branch.name}</span>
                    </label>
                  ))}
                  {!branchesQuery.isLoading && branches.length === 0 ? <span>No branches available.</span> : null}
                </div>
              </div>
            </div>

            <div className="configure-users-form-actions">
              <DesktopButton
                icon={<Save size={13} />}
                label={selectedMembership ? 'Save Changes' : 'Create'}
                shortcut="F9"
                type="submit"
                disabled={saving || (!selectedMembership && !canCreate)}
              />
            </div>
          </form>
        ) : null}

        {tab === 'users' ? (
          <div className="configure-users-list">
            <div className="configure-users-grid">
              <PosDataGrid
                columns={[
                  { key: 'username', header: 'User Name', width: 160, render: (row) => row.username },
                  { key: 'name', header: 'Name', width: 190, render: (row) => row.user?.name ?? '—' },
                  {
                    key: 'group',
                    header: 'Group',
                    width: 180,
                    render: (row) => (row.roles ?? []).map((role) => role.name).join(', ') || '—',
                  },
                  {
                    key: 'branches',
                    header: 'Branches',
                    width: 220,
                    render: (row) => (row.branches ?? []).map((branch) => branch.code).join(', ') || 'All',
                  },
                  {
                    key: 'status',
                    header: 'Status',
                    width: 120,
                    render: (row) => `${row.status}${row.is_owner ? ' / owner' : ''}`,
                  },
                ]}
                rows={rows}
                rowKey={(row) => row.ulid}
                selectedKey={selectedRowUlid}
                onSelect={(row) => setSelectedRowUlid(row.ulid)}
                emptyMessage={membershipsQuery.isLoading ? 'Loading users…' : 'No users found.'}
              />
            </div>

            <div className="configure-users-list-footer">
              <span>{rows.length} user{rows.length === 1 ? '' : 's'}</span>
              <div>
                {selectedRow?.status === 'active' && canDeactivate ? (
                  <DesktopButton label="Deactivate" onClick={() => void runSelectedAction('deactivate')} />
                ) : null}
                {selectedRow?.status !== 'active' && selectedRow && canActivate ? (
                  <DesktopButton label="Activate" onClick={() => void runSelectedAction('activate')} />
                ) : null}
                {selectedRow && canForceLogout ? (
                  <DesktopButton label="Force logout" onClick={() => void runSelectedAction('logout')} />
                ) : null}
                <DesktopButton
                  icon={<RefreshCw size={13} />}
                  label="Refresh"
                  onClick={() => void refreshMemberships()}
                />
              </div>
            </div>
          </div>
        ) : null}
      </div>
    </DesktopPanel>
  )
}
