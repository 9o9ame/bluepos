import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ApiClientError } from '../../api/client'
import { fetchTenantImportWarehouses, importTenantWorkbook, type TenantImportResult } from '../../api/platform'
import { usePlatformAuth } from '../../features/platform/PlatformAuthProvider'
import type { PlatformTenant } from '../../types/platform'

export function TenantImportModal({ tenant, onClose }: { tenant: PlatformTenant; onClose: () => void }) {
  const { withRecentMfa } = usePlatformAuth()
  const warehouses = useQuery({ queryKey: ['platform', 'import-warehouses', tenant.ulid],
    queryFn: () => fetchTenantImportWarehouses(tenant.ulid) })
  const [file, setFile] = useState<File | null>(null)
  const [warehouse, setWarehouse] = useState('')
  const [pending, setPending] = useState(false)
  const [result, setResult] = useState<TenantImportResult | null>(null)
  const [error, setError] = useState<string | null>(null)

  return (
    <div className="fixed inset-0 z-40 grid place-items-center bg-slate-950/40 p-4">
      <form role="dialog" aria-modal="true" aria-labelledby="tenant-import-title"
        className="max-h-[90vh] w-full max-w-2xl space-y-3 overflow-auto rounded border bg-white p-4 text-[12px] text-slate-900"
        onSubmit={(event) => {
          event.preventDefault()
          if (!file || pending) return
          setPending(true); setError(null); setResult(null)
          void (async () => {
            let next: TenantImportResult
            do {
              next = await withRecentMfa(() => importTenantWorkbook(tenant.ulid, file, warehouse))
              setResult(next)
            } while (next.status === 'processing')
          })()
            .catch((err) => setError(err instanceof ApiClientError
              ? [err.message, ...Object.values(err.fields ?? {}).flat()].join(' ') : 'Unable to import workbook. Retry the same file safely.'))
            .finally(() => setPending(false))
        }}>
        <h2 id="tenant-import-title" className="text-sm font-semibold">Import Data — {tenant.name} ({tenant.code})</h2>
        <label className="block font-semibold">Accounts or Products XLSX
          <input type="file" accept=".xlsx" required disabled={pending} className="mt-1 block w-full"
            onChange={(event) => { setFile(event.target.files?.[0] ?? null); setResult(null) }} />
        </label>
        <label className="block font-semibold">Warehouse for product opening stock (optional)
          <select className="mt-1 h-8 w-full rounded border px-2" value={warehouse} disabled={pending || warehouses.isLoading}
            onChange={(event) => setWarehouse(event.target.value)}>
            <option value="">Catalog only — skip opening stock</option>
            {(warehouses.data ?? []).map((w) => <option key={w.ulid} value={w.ulid}>{w.branch_name} / {w.name}</option>)}
          </select>
        </label>
        {warehouses.isError ? <p className="text-red-700">Unable to load warehouses. Catalog-only import is available.</p> : null}
        <p>Accounts do not require a warehouse. Negative stock is skipped. Existing movement history prevents another opening for that product/warehouse.</p>
        {pending ? <p role="status">Uploading and importing… {result ? `${result.processed} / ${result.total} rows processed.` : ''} Keep this dialog open. A retry of the same workbook resumes saved progress.</p> : null}
        {error ? <p role="alert" className="text-red-700">{error}</p> : null}
        {result ? <div role="status" className="space-y-2 rounded border p-3">
          <p className="font-semibold">{result.format} import {result.replayed ? 'replayed (no new changes)' : result.status}</p>
          <p>Created: {result.created} · Updated/reused: {result.updated + result.reused} · Skipped: {result.skipped} · Failed: {result.failed} · Warnings: {result.warnings.length}</p>
          {result.format === 'products' ? <p>Stock posted: {result.stock.posted} · Stock skipped: {result.stock.skipped} · Zero stock: {result.stock.zero}</p> : null}
          {result.ignored_fields.map((message) => <p key={message}>{message}</p>)}
          <details><summary className="cursor-pointer font-semibold">Row warnings ({result.warnings.length})</summary>
            <ul className="mt-2 max-h-64 list-disc space-y-1 overflow-auto pl-5">
              {result.warnings.map((w, index) => <li key={index}>Row {w.source_row} — {w.code || w.name}: {w.message}</li>)}
            </ul>
          </details>
        </div> : null}
        <div className="flex gap-2">
          <button type="submit" disabled={!file || pending} className="rounded bg-slate-950 px-3 py-1 text-white disabled:opacity-50">{pending ? 'Importing…' : 'Import'}</button>
          <button type="button" disabled={pending} className="rounded border px-3 py-1 disabled:opacity-50" onClick={onClose}>Close</button>
        </div>
      </form>
    </div>
  )
}
