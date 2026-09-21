import { FormEvent, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createBrand, fetchBrands } from '../api/catalog'
import { ApiClientError } from '../api/client'
import { useCan } from '../features/auth/useCan'

export function BrandsPage() {
  const queryClient = useQueryClient()
  const canCreate = useCan('brands.create') || useCan('brands.manage')
  const query = useQuery({ queryKey: ['brands'], queryFn: fetchBrands })
  const [code, setCode] = useState('')
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)
  const mutation = useMutation({
    mutationFn: createBrand,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['brands'] })
      setCode('')
      setName('')
    },
  })

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await mutation.mutateAsync({ code, name })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to create brand.')
    }
  }

  return (
    <section className="space-y-3">
      <h2 className="text-base font-semibold">Brands</h2>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      {canCreate ? (
        <form className="flex flex-wrap gap-2 rounded border bg-white p-3" onSubmit={onSubmit}>
          <input className="h-8 rounded border px-2 text-[12px]" placeholder="Code" value={code} onChange={(e) => setCode(e.target.value)} required />
          <input className="h-8 rounded border px-2 text-[12px]" placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} required />
          <button type="submit" className="h-8 rounded bg-[#1f4e79] px-3 text-[12px] font-semibold text-white">Add</button>
        </form>
      ) : null}
      <table className="w-full border border-slate-300 bg-white text-[12px]">
        <thead className="bg-slate-100"><tr><th className="p-2 text-left">Code</th><th className="p-2 text-left">Name</th></tr></thead>
        <tbody>
          {(query.data ?? []).map((row) => (
            <tr key={row.ulid} className="border-t"><td className="p-2">{row.code}</td><td className="p-2">{row.name}</td></tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
