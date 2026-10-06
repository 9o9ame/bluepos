import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ApiClientError } from '../../api/client'
import { fetchTenantImportWarehouses, importTenantWorkbook, type TenantImportResult } from '../../api/platform'
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
  const [result, setResult] = useState<TenantImportResult | null>(null)
  const [error, setError] = useState<string | null>(null)

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
            disabled={!file || pending}
          >
            {pending ? 'Importing…' : 'Import'}
          </UiButton>
          <UiButton disabled={pending} onClick={onClose}>Close</UiButton>
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
          void (async () => {
            let next: TenantImportResult
            do {
              next = await withRecentMfa(() => importTenantWorkbook(tenant.ulid, file, warehouse))
              setResult(next)
            } while (next.status === 'processing')
          })()
            .catch((err) => setError(
              err instanceof ApiClientError
                ? [err.message, ...Object.values(err.fields ?? {}).flat()].join(' ')
                : 'Unable to import workbook. Retry the same file safely.',
            ))
            .finally(() => setPending(false))
        }}
      >
        <label className="block font-semibold">
          Accounts or Products XLSX
          <input
            type="file"
            accept=".xlsx"
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
          Accounts do not require a warehouse. Negative stock is skipped. Existing movement
          history prevents another opening for that product/warehouse.
        </p>

        {pending ? (
          <p role="status">
            Uploading and importing… {result ? `${result.processed} / ${result.total} rows processed.` : ''}
            {' '}Keep this dialog open. A retry of the same workbook resumes saved progress.
          </p>
        ) : null}

        {error ? <p role="alert" className="text-[var(--ui-danger)]">{error}</p> : null}

        {result ? (
          <div role="status" className="space-y-2 rounded border border-[var(--ui-border)] p-3">
            <p className="font-semibold">
              {result.format} import {result.replayed ? 'replayed (no new changes)' : result.status}
            </p>
            <p>
              Created: {result.created} · Updated/reused: {result.updated + result.reused} ·
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
