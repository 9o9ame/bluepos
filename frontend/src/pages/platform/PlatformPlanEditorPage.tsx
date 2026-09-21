import { FormEvent, useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { fetchFeatureCatalog, fetchPlatformPlan, syncPlanFeatures, syncPlanLimits, updatePlatformPlan } from '../../api/platform'
import { ApiClientError } from '../../api/client'

export function PlatformPlanEditorPage() {
  const { planUlid = '' } = useParams()
  const queryClient = useQueryClient()
  const planQuery = useQuery({ queryKey: ['platform', 'plan', planUlid], queryFn: () => fetchPlatformPlan(planUlid) })
  const catalogQuery = useQuery({ queryKey: ['platform', 'features'], queryFn: fetchFeatureCatalog })
  const [error, setError] = useState<string | null>(null)
  const [features, setFeatures] = useState<Record<string, boolean>>({})
  const [limits, setLimits] = useState<Record<string, string>>({})
  const plan = planQuery.data

  useEffect(() => {
    if (plan?.features) {
      setFeatures(plan.features)
    }
    if (plan?.limits) {
      setLimits(
        Object.fromEntries(Object.entries(plan.limits).map(([key, value]) => [key, value === null ? '' : String(value)])),
      )
    }
  }, [plan])

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (!plan) {
        return
      }
      await updatePlatformPlan(plan.ulid, { name: plan.name, status: plan.status })
      await syncPlanFeatures(plan.ulid, features)
      await syncPlanLimits(
        plan.ulid,
        Object.fromEntries(Object.entries(limits).map(([key, value]) => [key, value === '' ? null : Number(value)])),
      )
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['platform', 'plan', planUlid] })
    },
  })

  async function onSave(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await saveMutation.mutateAsync()
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to save plan.')
    }
  }

  if (!plan) {
    return <p className="text-[12px]">Loading plan…</p>
  }

  return (
    <form className="space-y-4" onSubmit={onSave}>
      <h1 className="text-lg font-semibold">
        {plan.name} <span className="text-slate-500">({plan.code})</span>
      </h1>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      <div className="rounded border border-slate-300 bg-white p-3 text-[12px]">
        <h2 className="mb-2 font-semibold">Features</h2>
        {(catalogQuery.data?.features ?? []).map((feature) => (
          <label key={feature.key} className="mb-1 flex items-center gap-2">
            <input
              type="checkbox"
              checked={Boolean(features[feature.key])}
              onChange={(event) => setFeatures((current) => ({ ...current, [feature.key]: event.target.checked }))}
            />
            {feature.name}
          </label>
        ))}
      </div>
      <div className="rounded border border-slate-300 bg-white p-3 text-[12px]">
        <h2 className="mb-2 font-semibold">Limits (blank = unlimited)</h2>
        {['max_users', 'max_branches', 'max_warehouses', 'max_devices'].map((key) => (
          <label key={key} className="mb-2 block font-semibold">
            {key}
            <input
              className="mt-1 h-8 w-40 rounded border px-2"
              value={limits[key] ?? ''}
              onChange={(event) => setLimits((current) => ({ ...current, [key]: event.target.value }))}
            />
          </label>
        ))}
      </div>
      <button type="submit" className="rounded bg-slate-950 px-3 py-1 text-[12px] text-white">
        Save plan
      </button>
    </form>
  )
}
