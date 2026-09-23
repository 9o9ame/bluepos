import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createBarcodeGroup,
  createBrand,
  createCategory,
  createUnit,
  deactivateBarcodeGroup,
  deactivateBrand,
  deactivateCategory,
  deactivateUnit,
  fetchBarcodeGroups,
  fetchBrands,
  fetchCategories,
  fetchUnits,
  updateBarcodeGroup,
  updateBrand,
  updateCategory,
  updateUnit,
} from '../../api/catalog'
import { useCan } from '../../features/auth/useCan'
import type { BarcodeGroup, CatalogItem, Unit } from '../../types/catalog'

export type CatalogMasterKind = 'category' | 'brand' | 'unit' | 'barcode_group'
export type CatalogMasterRecord = CatalogItem | Unit | BarcodeGroup

type UseCatalogMasterEditorOptions = {
  enabled?: boolean
  initialCode?: string
  onSaved?: (kind: CatalogMasterKind, item: CatalogMasterRecord, created: boolean) => void
}

const QUERY_KEYS: Record<CatalogMasterKind, string> = {
  category: 'categories',
  brand: 'brands',
  unit: 'units',
  barcode_group: 'barcode-groups',
}

const PERMISSION_PREFIX: Record<CatalogMasterKind, string> = {
  category: 'categories',
  brand: 'brands',
  unit: 'units',
  barcode_group: 'barcode_groups',
}

export function useCatalogMasterEditor(
  kind: CatalogMasterKind,
  options: UseCatalogMasterEditorOptions = {},
) {
  const queryClient = useQueryClient()
  const permissionPrefix = PERMISSION_PREFIX[kind]
  const canManage = useCan(`${permissionPrefix}.manage`)
  const canCreate = useCan(`${permissionPrefix}.create`) || canManage
  const canEdit = useCan(`${permissionPrefix}.edit`) || canManage
  const canDelete = useCan(`${permissionPrefix}.delete`) || canManage

  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [code, setCode] = useState(options.initialCode ?? '')
  const [name, setName] = useState('')
  const [symbol, setSymbol] = useState('')
  const [allowsDecimal, setAllowsDecimal] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const queryKey = QUERY_KEYS[kind]
  const query = useQuery({
    queryKey: [queryKey],
    enabled: options.enabled ?? true,
    queryFn: (): Promise<CatalogMasterRecord[]> => {
      if (kind === 'category') return fetchCategories()
      if (kind === 'brand') return fetchBrands()
      if (kind === 'unit') return fetchUnits()
      return fetchBarcodeGroups()
    },
  })

  const rows = useMemo(() => query.data ?? [], [query.data])
  const selected = rows.find((row) => row.ulid === selectedKey) ?? null

  function select(item: CatalogMasterRecord) {
    setSelectedKey(item.ulid)
    setCode(item.code)
    setName(item.name)
    setSymbol('symbol' in item ? item.symbol : '')
    setAllowsDecimal('allows_decimal' in item ? item.allows_decimal : false)
    setError(null)
  }

  function startNew() {
    setSelectedKey(null)
    setCode(options.initialCode ?? '')
    setName('')
    setSymbol('')
    setAllowsDecimal(false)
    setError(null)
  }

  const saveMutation = useMutation({
    mutationFn: async (): Promise<{ item: CatalogMasterRecord; created: boolean }> => {
      const payload = { code: code.trim(), name: name.trim() }
      const created = selectedKey === null
      let item: CatalogMasterRecord

      if (kind === 'category') {
        item = created
          ? await createCategory(payload)
          : await updateCategory(selectedKey, payload)
      } else if (kind === 'brand') {
        item = created
          ? await createBrand(payload)
          : await updateBrand(selectedKey, payload)
      } else if (kind === 'unit') {
        const unitPayload = {
          ...payload,
          symbol: symbol.trim(),
          allows_decimal: allowsDecimal,
        }
        item = created
          ? await createUnit(unitPayload)
          : await updateUnit(selectedKey, unitPayload)
      } else {
        item = created
          ? await createBarcodeGroup(payload)
          : await updateBarcodeGroup(selectedKey, payload)
      }

      return { item, created }
    },
    onSuccess: async ({ item, created }) => {
      await queryClient.invalidateQueries({ queryKey: [queryKey] })
      select(item)
      options.onSaved?.(kind, item, created)
    },
  })

  const deactivateMutation = useMutation({
    mutationFn: async () => {
      if (!selectedKey) return
      if (kind === 'category') await deactivateCategory(selectedKey)
      else if (kind === 'brand') await deactivateBrand(selectedKey)
      else if (kind === 'unit') await deactivateUnit(selectedKey)
      else await deactivateBarcodeGroup(selectedKey)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: [queryKey] })
    },
  })

  return {
    query,
    rows,
    selected,
    selectedKey,
    code,
    name,
    symbol,
    allowsDecimal,
    error,
    canCreate,
    canEdit,
    canDelete,
    canSave: selectedKey ? canEdit : canCreate,
    isSaving: saveMutation.isPending,
    isDeactivating: deactivateMutation.isPending,
    setCode,
    setName,
    setSymbol,
    setAllowsDecimal,
    setError,
    select,
    startNew,
    save: () => saveMutation.mutateAsync(),
    deactivate: () => deactivateMutation.mutateAsync(),
    refresh: () => query.refetch(),
  }
}
