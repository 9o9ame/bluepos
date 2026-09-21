import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchBranches, switchBranch } from '../api/branches'
import { ApiClientError } from '../api/client'
import type { AuthSession } from '../types/auth'

type BranchSelectorProps = {
  session: AuthSession
}

export function BranchSelector({ session }: BranchSelectorProps) {
  const queryClient = useQueryClient()
  const branchesQuery = useQuery({
    queryKey: ['branches'],
    queryFn: fetchBranches,
  })

  const switchMutation = useMutation({
    mutationFn: switchBranch,
    onSuccess: (nextSession) => {
      queryClient.setQueryData(['auth', 'me'], nextSession)
    },
  })

  const branches = branchesQuery.data ?? [session.branch]
  const error = switchMutation.error instanceof ApiClientError ? switchMutation.error.message : null

  return (
    <label className="flex items-center gap-2 text-[12px] text-white">
      <span className="text-slate-300">Branch</span>
      <select
        className="h-7 min-w-[10rem] rounded border border-slate-500 bg-slate-800 px-2 text-white"
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
      {error ? <span className="text-red-300">{error}</span> : null}
    </label>
  )
}
