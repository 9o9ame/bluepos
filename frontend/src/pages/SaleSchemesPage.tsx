import { FormEvent, useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Gift, Plus, RefreshCw, Save, Trash2, X } from 'lucide-react'
import { ApiClientError } from '../api/client'
import { fetchProducts } from '../api/catalog'
import {
  createSaleScheme,
  deactivateSaleScheme,
  fetchSaleSchemes,
  updateSaleScheme,
} from '../api/saleSchemes'
import { DesktopButton, DesktopPanel, Field, FormGroup } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiSelect } from '../components/ui/UiSelect'
import { useCan } from '../features/auth/useCan'
import { askConfirm } from '../feedback/FeedbackProvider'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { SaleSchemeApplyMode } from '../types/saleSchemes'

export function SaleSchemesPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()
  const canCreateDirect = useCan('sale_schemes.create')
  const canEditDirect = useCan('sale_schemes.edit')
  const canDeleteDirect = useCan('sale_schemes.delete')
  const canManage = useCan('sale_schemes.manage')
  const canCreate = canCreateDirect || canManage
  const canEdit = canEditDirect || canManage
  const canDelete = canDeleteDirect || canManage

  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [name, setName] = useState('')
  const [applyMode, setApplyMode] = useState<SaleSchemeApplyMode>('salesman')
  const [minSaleAmount, setMinSaleAmount] = useState('5000.0000')
  const [rewardProductUlid, setRewardProductUlid] = useState('')
  const [maxRewardQty, setMaxRewardQty] = useState('1.000000')
  const [startsOn, setStartsOn] = useState('')
  const [endsOn, setEndsOn] = useState('')
  const [isStackable, setIsStackable] = useState(false)

  const schemesQuery = useQuery({
    queryKey: ['sale-schemes'],
    queryFn: fetchSaleSchemes,
  })
  const productsQuery = useQuery({
    queryKey: ['products', 'scheme-rewards'],
    queryFn: () => fetchProducts({ per_page: 100, page: 1 }),
  })

  const rows = schemesQuery.data ?? []
  const selected = useMemo(
    () => rows.find((row) => row.ulid === selectedKey) ?? null,
    [rows, selectedKey],
  )
  const products = productsQuery.data?.data ?? []

  useEffect(() => {
    if (creating) return
    if (!selected) {
      setName('')
      setApplyMode('salesman')
      setMinSaleAmount('5000.0000')
      setRewardProductUlid('')
      setMaxRewardQty('1.000000')
      setStartsOn('')
      setEndsOn('')
      setIsStackable(false)
      return
    }
    setName(selected.name)
    setApplyMode(selected.apply_mode)
    setMinSaleAmount(selected.min_sale_amount)
    setRewardProductUlid(selected.reward_product?.ulid ?? '')
    setMaxRewardQty(selected.max_reward_qty)
    setStartsOn(selected.starts_on ?? '')
    setEndsOn(selected.ends_on ?? '')
    setIsStackable(selected.is_stackable)
  }, [creating, selected])

  function startNew() {
    if (!canCreate) return
    setCreating(true)
    setSelectedKey(null)
    setName('')
    setApplyMode('salesman')
    setMinSaleAmount('5000.0000')
    setRewardProductUlid('')
    setMaxRewardQty('1.000000')
    setStartsOn('')
    setEndsOn('')
    setIsStackable(false)
    setError(null)
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        name: name.trim(),
        apply_mode: applyMode,
        min_sale_amount: minSaleAmount,
        reward_product_ulid: rewardProductUlid,
        max_reward_qty: maxRewardQty,
        starts_on: startsOn || null,
        ends_on: endsOn || null,
        is_stackable: isStackable,
        is_active: true,
      }
      if (creating || !selectedKey) {
        return createSaleScheme(payload)
      }
      return updateSaleScheme(selectedKey, payload)
    },
    onSuccess: async (saved) => {
      setCreating(false)
      setSelectedKey(saved.ulid)
      setError(null)
      await queryClient.invalidateQueries({ queryKey: ['sale-schemes'] })
    },
  })

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    if (!name.trim()) {
      setError('Scheme name is required.')
      return
    }
    if (!rewardProductUlid) {
      setError('Select a free reward product.')
      return
    }
    try {
      await saveMutation.mutateAsync()
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to save scheme.')
    }
  }

  useWorkspaceHandlers({
    save: () => (document.getElementById('sale-scheme-form') as HTMLFormElement | null)?.requestSubmit(),
    refresh: () => void schemesQuery.refetch(),
  })

  return (
    <DesktopPanel
      title="Customer Sale Schemes"
      toolbar={
        <>
          <DesktopButton icon={<Plus size={13} />} label="New" disabled={!canCreate} onClick={startNew} />
          <DesktopButton
            icon={<Save size={13} />}
            label="Save"
            shortcut="F9"
            disabled={(!canCreate && !canEdit) || saveMutation.isPending}
            onClick={() =>
              (document.getElementById('sale-scheme-form') as HTMLFormElement | null)?.requestSubmit()
            }
          />
          <DesktopButton
            icon={<Trash2 size={13} />}
            label="Delete"
            disabled={!canDelete || !selected?.is_active}
            onClick={() => {
              void (async () => {
                if (!selected || !(await askConfirm(`Deactivate ${selected.name}?`))) return
                try {
                  await deactivateSaleScheme(selected.ulid)
                  await queryClient.invalidateQueries({ queryKey: ['sale-schemes'] })
                } catch (err) {
                  setError(err instanceof ApiClientError ? err.message : 'Unable to deactivate scheme.')
                }
              })()
            }}
          />
          <DesktopButton
            icon={<RefreshCw size={13} />}
            label="Refresh"
            shortcut="F8"
            onClick={() => void schemesQuery.refetch()}
          />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      {error ? <p className="mb-2 text-[12px] text-[var(--danger)]">{error}</p> : null}
      <p className="mb-3 text-[12px] text-[var(--ui-text-muted)]">
        Admin chooses <strong>Salesman decides</strong> (Add/Skip) or <strong>Auto</strong> (apply when eligible).
        Packaging bags/boxes are separate — mark products as Packaging in Define Products.
      </p>

      {(canCreate || canEdit) && (creating || selected) ? (
        <form id="sale-scheme-form" onSubmit={onSubmit}>
          <FormGroup title={creating ? 'New scheme' : 'Edit scheme'}>
            <Field label="Scheme name">
              <input triggerClassName="desktop-input" value={name} onChange={(e) => setName(e.target.value)} required />
            </Field>
            <Field label="Apply mode">
              <UiSelect
                triggerClassName="desktop-input"
                aria-label="Apply mode"
                value={applyMode}
                options={[
                  { value: 'salesman', label: 'Salesman decides (Add / Skip)' },
                  { value: 'auto', label: 'Auto apply when eligible' },
                ]}
                onChange={(value) => setApplyMode(value as SaleSchemeApplyMode)}
              />
            </Field>
            <Field label="Min sale amount">
              <input
                triggerClassName="desktop-input"
                value={minSaleAmount}
                onChange={(e) => setMinSaleAmount(e.target.value)}
                required
              />
            </Field>
            <Field label="Free product">
              <UiSelect
                triggerClassName="desktop-input"
                aria-label="Free product"
                value={rewardProductUlid}
                options={[
                  { value: '', label: '— Select product —' },
                  ...products
                    .filter((row) => row.is_active || row.ulid === rewardProductUlid)
                    .map((row) => ({
                      value: row.ulid,
                      label: `${row.product_number} — ${row.name}`,
                    })),
                ]}
                onChange={setRewardProductUlid}
              />
            </Field>
            <Field label="Max free qty">
              <input
                triggerClassName="desktop-input"
                value={maxRewardQty}
                onChange={(e) => setMaxRewardQty(e.target.value)}
                required
              />
            </Field>
            <Field label="Starts on">
              <input
                triggerClassName="desktop-input"
                type="date"
                value={startsOn}
                onChange={(e) => setStartsOn(e.target.value)}
              />
            </Field>
            <Field label="Ends on">
              <input
                triggerClassName="desktop-input"
                type="date"
                value={endsOn}
                onChange={(e) => setEndsOn(e.target.value)}
              />
            </Field>
            <Field label="Stackable">
              <label className="inline-flex items-center gap-2 text-[12px]">
                <input
                  type="checkbox"
                  checked={isStackable}
                  onChange={(e) => setIsStackable(e.target.checked)}
                />
                Can combine with other schemes
              </label>
            </Field>
          </FormGroup>
        </form>
      ) : null}

      <div style={{ height: 360, marginTop: 6 }}>
        <PosDataGrid
          columns={[
            {
              key: 'name',
              header: 'Scheme',
              render: (row) => (
                <span className="inline-flex items-center gap-1">
                  <Gift size={12} /> {row.name}
                </span>
              ),
            },
            {
              key: 'mode',
              header: 'Mode',
              width: 120,
              render: (row) => (row.apply_mode === 'auto' ? 'Auto' : 'Salesman'),
            },
            {
              key: 'min',
              header: 'Min amount',
              width: 110,
              render: (row) => row.min_sale_amount,
            },
            {
              key: 'reward',
              header: 'Free product',
              render: (row) => row.reward_product?.name ?? '—',
            },
            {
              key: 'qty',
              header: 'Max qty',
              width: 80,
              render: (row) => row.max_reward_qty,
            },
            {
              key: 'status',
              header: 'Status',
              width: 90,
              render: (row) => (row.is_active ? 'Active' : 'Inactive'),
            },
          ]}
          rows={rows}
          rowKey={(row) => row.ulid}
          selectedKey={selectedKey}
          onSelect={(row) => {
            setCreating(false)
            setSelectedKey(row.ulid)
          }}
        />
      </div>
    </DesktopPanel>
  )
}
