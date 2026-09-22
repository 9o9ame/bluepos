import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchBranches, switchBranch } from '../../api/branches'
import { ApiClientError } from '../../api/client'
import type { AuthSession } from '../../types/auth'

type BranchSwitcherProps = {
  session: AuthSession
}

export function BranchSwitcher({ session }: BranchSwitcherProps) {
  const queryClient = useQueryClient()
  const branchesQuery = useQuery({
    queryKey: ['branches'],
    queryFn: fetchBranches,
  })

  const switchMutation = useMutation({
    mutationFn: switchBranch,
    onSuccess: (nextSession) => {
      queryClient.setQueryData(['auth', 'me'], nextSession)
      void queryClient.invalidateQueries({ predicate: (query) => query.queryKey[0] !== 'auth' })
    },
  })

  const branches = branchesQuery.data ?? [session.branch]
  const error = switchMutation.error instanceof ApiClientError ? switchMutation.error.message : null

  return (
    <label className="titlebar-meta">
      <span>Branch</span>
      <select
        className="titlebar-select"
        aria-label="Active branch"
        value={session.branch.ulid}
        onChange={(event) => {
          const next = event.target.value
          if (next !== session.branch.ulid) {
            void switchMutation.mutateAsync(next)
          }
        }}
      >
        {branches.map((branch) => (
          <option key={branch.ulid} value={branch.ulid}>
            {branch.code} — {branch.name}
          </option>
        ))}
      </select>
      {error ? <span className="text-red-700">{error}</span> : null}
    </label>
  )
}
