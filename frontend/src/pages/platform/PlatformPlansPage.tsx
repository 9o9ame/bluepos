import { FormEvent, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { createPlatformPlan, fetchPlatformPlans } from '../../api/platform'
import { ApiClientError } from '../../api/client'

export function PlatformPlansPage() {
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ['platform', 'plans'], queryFn: fetchPlatformPlans })
  const [error, setError] = useState<string | null>(null)
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const createMutation = useMutation({
    mutationFn: createPlatformPlan,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['platform', 'plans'] })
      setCode('')
      setName('')
    },
  })

  async function onCreate(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await createMutation.mutateAsync({ code, name })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to create plan.')
    }
  }

  return (
    <section className="space-y-4">
      <h1 className="text-lg font-semibold">Plans</h1>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      <form className="flex gap-2 text-[12px]" onSubmit={onCreate}>
        <input className="h-8 rounded border px-2" placeholder="Code" value={code} onChange={(event) => setCode(event.target.value)} />
        <input className="h-8 rounded border px-2" placeholder="Name" value={name} onChange={(event) => setName(event.target.value)} />
        <button type="submit" className="h-8 rounded bg-slate-950 px-3 text-white">
          Create
        </button>
      </form>
      <table className="w-full border border-slate-300 bg-white text-left text-[12px]">
        <thead className="bg-slate-50 text-slate-500">
          <tr>
            <th className="p-2">Code</th>
            <th>Name</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          {(query.data ?? []).map((plan) => (
            <tr key={plan.ulid} className="border-t border-slate-200">
              <td className="p-2">
                <Link className="font-semibold" to={`/platform/plans/${plan.ulid}`}>
                  {plan.code}
                </Link>
              </td>
              <td>{plan.name}</td>
              <td>{plan.status}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
