import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createBarcodeGroup,
  createBrand,
  createCategory,
  createSubcategory,
  createUnit,
  deactivateBarcodeGroup,
  deactivateBrand,
  deactivateCategory,
  deactivateSubcategory,
  deactivateUnit,
  fetchBarcodeGroups,
  fetchBrands,
  fetchCategories,
  fetchSubcategories,
  fetchUnits,
  updateBarcodeGroup,
  updateBrand,
  updateCategory,
  updateSubcategory,
  updateUnit,
} from '../../api/catalog'
import { useCan } from '../../features/auth/useCan'
import type { BarcodeGroup, CatalogItem, Subcategory, Unit } from '../../types/catalog'

export type CatalogMasterKind = 'category' | 'subcategory' | 'brand' | 'unit' | 'barcode_group'
export type CatalogMasterRecord = CatalogItem | Subcategory | Unit | BarcodeGroup

type UseCatalogMasterEditorOptions = {
  enabled?: boolean
  initialCode?: string
  parentCategoryUlid?: string
  onSaved?: (kind: CatalogMasterKind, item: CatalogMasterRecord, created: boolean) => void
}

const QUERY_KEYS: Record<CatalogMasterKind, string> = {
  category: 'categories',
  subcategory: 'subcategories',
  brand: 'brands',
  unit: 'units',
  barcode_group: 'barcode-groups',
}

const PERMISSION_PREFIX: Record<CatalogMasterKind, string> = {
  category: 'categories',
  subcategory: 'categories',
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
  const canActivate = canEdit

  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [code, setCode] = useState(options.initialCode ?? '')
  const [name, setName] = useState('')
  const [symbol, setSymbol] = useState('')
  const [allowsDecimal, setAllowsDecimal] = useState(false)
  const [categoryUlid, setCategoryUlid] = useState(options.parentCategoryUlid ?? '')
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (kind !== 'subcategory') return
    setCategoryUlid(options.parentCategoryUlid ?? '')
  }, [kind, options.parentCategoryUlid])

  const categoriesQuery = useQuery({
    queryKey: ['categories'],
    enabled: (options.enabled ?? true) && kind === 'subcategory',
    queryFn: fetchCategories,
  })

  const queryKey =
    kind === 'subcategory'
      ? [QUERY_KEYS[kind], categoryUlid || '']
      : [QUERY_KEYS[kind]]

  const query = useQuery({
    queryKey,
    enabled:
      (options.enabled ?? true) &&
      (kind !== 'subcategory' || Boolean(categoryUlid)),
    queryFn: (): Promise<CatalogMasterRecord[]> => {
      if (kind === 'category') return fetchCategories()
      if (kind === 'subcategory') {
        return fetchSubcategories(categoryUlid)
      }
      if (kind === 'brand') return fetchBrands()
      if (kind === 'unit') return fetchUnits()
      return fetchBarcodeGroups()
    },
  })

  const rows = useMemo(() => query.data ?? [], [query.data])
  const categories = useMemo(
    () => (categoriesQuery.data ?? []).filter((row) => row.is_active),
    [categoriesQuery.data],
  )
  const selected = rows.find((row) => row.ulid === selectedKey) ?? null

  function select(item: CatalogMasterRecord) {
    setSelectedKey(item.ulid)
    setCode(item.code)
    setName(item.name)
    setSymbol('symbol' in item ? item.symbol : '')
    setAllowsDecimal('allows_decimal' in item ? item.allows_decimal : false)
    if (kind === 'subcategory') {
      const sub = item as Subcategory
      const nextCategory = sub.category_ulid ?? sub.category?.ulid ?? categoryUlid
      if (nextCategory) setCategoryUlid(nextCategory)
    }
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
      } else if (kind === 'subcategory') {
        if (!categoryUlid) {
          throw new Error('Select a category before defining a subcategory.')
        }

        const subcategoryPayload = {
          category_ulid: categoryUlid,
          ...payload,
        }

        item = created
          ? await createSubcategory(subcategoryPayload)
          : await updateSubcategory(selectedKey, subcategoryPayload)
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
      await queryClient.invalidateQueries({ queryKey })
      select(item)
      options.onSaved?.(kind, item, created)
    },
  })

  const deactivateMutation = useMutation({
    mutationFn: async () => {
      if (!selectedKey) return
      if (kind === 'category') await deactivateCategory(selectedKey)
      else if (kind === 'subcategory') await deactivateSubcategory(selectedKey)
      else if (kind === 'brand') await deactivateBrand(selectedKey)
      else if (kind === 'unit') await deactivateUnit(selectedKey)
      else await deactivateBarcodeGroup(selectedKey)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey })
      startNew()
    },
  })

  const activateMutation = useMutation({
    mutationFn: async () => {
      if (!selectedKey) return
      if (kind === 'category') await updateCategory(selectedKey, { is_active: true })
      else if (kind === 'subcategory') await updateSubcategory(selectedKey, { is_active: true })
      else if (kind === 'brand') await updateBrand(selectedKey, { is_active: true })
      else if (kind === 'unit') await updateUnit(selectedKey, { is_active: true })
      else await updateBarcodeGroup(selectedKey, { is_active: true })
    },
    onSuccess: async () => {
      const key = selectedKey
      await queryClient.invalidateQueries({ queryKey })
      const result = await query.refetch()
      const row = (result.data ?? []).find((item) => item.ulid === key)
      if (row) select(row)
    },
  })

  async function refresh() {
    startNew()
    if (kind === 'subcategory') {
      await categoriesQuery.refetch()
    }
    if (kind !== 'subcategory' || categoryUlid) {
      await query.refetch()
    }
  }

  return {
    query,
    rows,
    categories,
    selected,
    selectedKey,
    code,
    name,
    symbol,
    allowsDecimal,
    categoryUlid,
    error,
    canCreate,
    canEdit,
    canDelete,
    canActivate,
    canSave: selectedKey ? canEdit : canCreate,
    isSaving: saveMutation.isPending,
    isDeactivating: deactivateMutation.isPending,
    isActivating: activateMutation.isPending,
    setCode,
    setName,
    setSymbol,
    setAllowsDecimal,
    setCategoryUlid: (ulid: string) => {
      setCategoryUlid(ulid)
      setSelectedKey(null)
      setCode(options.initialCode ?? '')
      setName('')
      setError(null)
    },
    setError,
    select,
    startNew,
    save: () => saveMutation.mutateAsync(),
    deactivate: () => deactivateMutation.mutateAsync(),
    activate: () => activateMutation.mutateAsync(),
    refresh,
  }
}
