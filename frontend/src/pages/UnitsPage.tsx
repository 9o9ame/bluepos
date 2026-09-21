import { FormEvent, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createUnit, fetchUnits } from '../api/catalog'
import { ApiClientError } from '../api/client'
import { useCan } from '../features/auth/useCan'

export function UnitsPage() {
  const queryClient = useQueryClient()
  const canCreate = useCan('units.create') || useCan('units.manage')
  const query = useQuery({ queryKey: ['units'], queryFn: fetchUnits })
  const [code, setCode] = useState('PCS')
  const [name, setName] = useState('')
  const [symbol, setSymbol] = useState('')
  const [allowsDecimal, setAllowsDecimal] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const mutation = useMutation({
    mutationFn: createUnit,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['units'] })
      setName('')
      setSymbol('')
    },
  })

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await mutation.mutateAsync({ code, name, symbol, allows_decimal: allowsDecimal })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to create unit.')
    }
  }

  return (
    <section className="space-y-3">
      <h2 className="text-base font-semibold">Units</h2>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      {canCreate ? (
        <form className="flex flex-wrap items-center gap-2 rounded border bg-white p-3" onSubmit={onSubmit}>
          <input className="h-8 rounded border px-2 text-[12px]" placeholder="Code" value={code} onChange={(e) => setCode(e.target.value)} required />
          <input className="h-8 rounded border px-2 text-[12px]" placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} required />
          <input className="h-8 w-20 rounded border px-2 text-[12px]" placeholder="Symbol" value={symbol} onChange={(e) => setSymbol(e.target.value)} required />
          <label className="text-[12px]"><input type="checkbox" checked={allowsDecimal} onChange={(e) => setAllowsDecimal(e.target.checked)} /> Decimal qty</label>
          <button type="submit" className="h-8 rounded bg-[#1f4e79] px-3 text-[12px] font-semibold text-white">Add</button>
        </form>
      ) : null}
      <table className="w-full border border-slate-300 bg-white text-[12px]">
        <thead className="bg-slate-100"><tr><th className="p-2 text-left">Code</th><th className="p-2 text-left">Name</th><th className="p-2 text-left">Symbol</th><th className="p-2 text-left">Decimal</th></tr></thead>
        <tbody>
          {(query.data ?? []).map((row) => (
            <tr key={row.ulid} className="border-t">
              <td className="p-2">{row.code}</td>
              <td className="p-2">{row.name}</td>
              <td className="p-2">{row.symbol}</td>
              <td className="p-2">{row.allows_decimal ? 'Yes' : 'No'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  )
}
