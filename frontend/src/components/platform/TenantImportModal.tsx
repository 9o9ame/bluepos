import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ApiClientError } from '../../api/client'
import { fetchTenantImportWarehouses, fetchActiveTenantImport, fetchTenantImportProgress, startTenantImport, type TenantImportResult } from '../../api/platform'
import { usePlatformAuth } from '../../features/platform/PlatformAuthProvider'
import type { PlatformTenant } from '../../types/platform'
import { UiSelect } from '../ui/UiSelect'
import { UiModal } from '../ui/UiModal'
import { UiButton } from '../ui/UiButton'
import { UI_LAYER } from '../ui/uiLayers'

export function TenantImportModal({ tenant, onClose }: { tenant: PlatformTenant; onClose: () => void }) {
  const { withRecentMfa } = usePlatformAuth()
  const warehouses = useQuery({ queryKey: ['platform', 'import-warehouses', tenant.ulid],
    queryFn: () => fetchTenantImportWarehouses(tenant.ulid) })
  const [file, setFile] = useState<File | null>(null)
  const [warehouse, setWarehouse] = useState('')
  const [pending, setPending] = useState(false)
  const [batchUlid, setBatchUlid] = useState<string | null>(null)
  const [result, setResult] = useState<TenantImportResult | null>(null)
  const [error, setError] = useState<string | null>(null)
  const active = useQuery({
    queryKey: ['platform', 'active-tenant-import', tenant.ulid],
    queryFn: () => fetchActiveTenantImport(tenant.ulid),
    refetchInterval: 10000,
  })
  const progress = useQuery({
    queryKey: ['platform', 'tenant-import-progress', tenant.ulid, batchUlid],
    queryFn: () => fetchTenantImportProgress(tenant.ulid, batchUlid!),
    enabled: Boolean(batchUlid),
    refetchInterval: (query) => query.state.data?.status === 'completed' ? false : 2000,
  })
  useEffect(() => {
    if (!batchUlid && active.data?.ulid) setBatchUlid(active.data.ulid)
  }, [active.data?.ulid, batchUlid])
  useEffect(() => {
    if (progress.data) setResult(progress.data)
  }, [progress.data])

  return (
    <UiModal
      title={`Import Data — ${tenant.name} (${tenant.code})`}
      size="lg"
      zIndex={UI_LAYER.nestedModal}
      onClose={onClose}
      footer={
        <>
          <UiButton
            type="submit"
            form="tenant-import-form"
            variant="primary"
            disabled={!file || pending || result?.status === 'processing'}
          >
            {pending ? 'Importing…' : 'Import'}
          </UiButton>
          <UiButton onClick={onClose}>Close</UiButton>
        </>
      }
    >
      <form
        id="tenant-import-form"
        className="space-y-3 text-[12px]"
        onSubmit={(event) => {
          event.preventDefault()
          if (!file || pending) return
          setPending(true)
          setError(null)
          setResult(null)
          void withRecentMfa(() => startTenantImport(tenant.ulid, file, warehouse))
            .then((next: TenantImportResult) => {
              setBatchUlid(next.ulid)
              setResult(next)
            })
            .catch((err) => setError(
              err instanceof ApiClientError && [502, 503, 504].includes(err.status)
                ? 'Server timed out while importing. Some rows may already be saved. Retry the SAME file to resume safely; do not change the warehouse or source data.'
                : err instanceof ApiClientError
                  ? [err.message, ...Object.values(err.fields ?? {}).flat()].join(' ')
                  : 'Import connection failed. Some rows may already be saved. Retry the same file to resume safely.',
            ))
            .finally(() => setPending(false))
        }}
      >
        <label className="block font-semibold">
          Products XLSX or Accounts CSV / XLSX / PDF
          <input
            type="file"
            accept=".xlsx,.csv,.pdf"
            required
            disabled={pending}
            className="mt-1 block w-full"
            onChange={(event) => {
              setFile(event.target.files?.[0] ?? null)
              setResult(null)
            }}
          />
        </label>

        <label className="block font-semibold">
          Warehouse for product opening stock (optional)
          <div className="mt-1">
            <UiSelect
              value={warehouse}
              disabled={pending || warehouses.isLoading}
              aria-label="Warehouse for product opening stock"
              menuZIndex={UI_LAYER.nestedDropdown}
              options={[
                { value: '', label: 'Catalog only — skip opening stock' },
                ...(warehouses.data ?? []).map((w) => ({
                  value: w.ulid,
                  label: `${w.branch_name} / ${w.name}`,
                })),
              ]}
              onChange={setWarehouse}
            />
          </div>
        </label>

        {warehouses.isError ? (
          <p className="text-[var(--ui-danger)]">
            Unable to load warehouses. Catalog-only import is available.
          </p>
        ) : null}

        <p>
          Accounts do not require a warehouse. Existing product/account codes are left unchanged. Negative stock is skipped. PDF account imports require text extraction support on the server.
        </p>

        {pending ? (
          <p role="status">
            Uploading source once and scheduling the import…
          </p>
        ) : null}

        {result?.status === 'processing' ? (
          <p role="status">
            Background import running: {result.processed} / {result.total} rows processed.
            {' '}You may close this dialog; progress will be available when reopened.
          </p>
        ) : null}
        {progress.isError ? <p role="alert" className="text-[var(--ui-danger)]">
          Unable to refresh progress. Completed rows remain saved; reopen this dialog to check again.
        </p> : null}
                {error ? <p role="alert" className="text-[var(--ui-danger)]">{error}</p> : null}

        {result ? (
          <div role="status" className="space-y-2 rounded border border-[var(--ui-border)] p-3">
            <p className="font-semibold">
              {result.format} import {result.replayed ? 'replayed (no new changes)' : result.status}
            </p>
            <p>
              Created: {result.created} · Already present: {result.reused} · Updated: {result.updated} ·
              Skipped: {result.skipped} · Failed: {result.failed} · Warnings: {result.warnings.length}
            </p>
            {result.format === 'products' ? (
              <p>Stock posted: {result.stock.posted} · Stock skipped: {result.stock.skipped} · Zero stock: {result.stock.zero}</p>
            ) : null}
            {result.ignored_fields.map((message) => <p key={message}>{message}</p>)}
            <details>
              <summary className="cursor-pointer font-semibold">
                Row warnings ({result.warnings.length})
              </summary>
              <ul className="mt-2 max-h-64 list-disc space-y-1 overflow-auto pl-5">
                {result.warnings.map((w, index) => (
                  <li key={index}>Row {w.source_row} — {w.code || w.name}: {w.message}</li>
                ))}
              </ul>
            </details>
          </div>
        ) : null}
      </form>
    </UiModal>
  )
}
