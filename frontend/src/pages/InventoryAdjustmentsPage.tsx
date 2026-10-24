import { useDeferredValue, useEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  FileSearch,
  PackageSearch,
  Plus,
  RefreshCw,
  Save,
  Send,
  Trash2,
  X,
} from 'lucide-react'
import {
  amendInventoryAdjustment,
  createInventoryAdjustment,
  createInventoryAdjustmentLine,
  deleteInventoryAdjustment,
  deleteInventoryAdjustmentLine,
  fetchInventoryAdjustment,
  fetchInventoryAdjustmentAccounts,
  fetchNextInventoryAdjustmentDocumentNumber,
  fetchInventoryAdjustments,
  postInventoryAdjustment,
  updateInventoryAdjustment,
  updateInventoryAdjustmentLine,
} from '../api/inventory'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { PosEntryGrid } from '../components/desktop/PosEntryGrid'
import { ProductLookupGrid } from '../features/sales/ProductLookupGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect, UiSimpleDropdown, UiTableDropdown } from '../components/ui/UiSelect'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useLocalProducts } from '../features/catalog/useLocalCatalog'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import type { Product } from '../types/catalog'
import type {
  InventoryAdjustment,
  InventoryAdjustmentLine,
} from '../types/inventory'
import { formatDecimal } from '../utils/formatDecimal'
import './InventoryAdjustmentsPage.css'

function localIsoDate(date = new Date()) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function todayIso() {
  return localIsoDate()
}

function monthStartIso() {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`
}

function productLabel(product: Product) {
  return `${product.product_number} · ${product.name}`
}

function newAdjustmentKey() {
  return `inventory-adjustment-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

function signedNet(line: InventoryAdjustmentLine): number {
  const plus = Number.parseFloat(line.qty_plus || '0')
  const minus = Number.parseFloat(line.qty_minus || '0')
  const amount = Number.parseFloat(line.amount || '0')
  return plus > 0 ? amount : minus > 0 ? -amount : 0
}

type TabKey = 'invoice' | 'search' | 'product'

type ProductWiseRow = {
  key: string
  document_number: string
  date: string
  account: string
  product: string
  qty_plus: string
  qty_minus: string
  amount: string
  remarks: string | null
}

export function InventoryAdjustmentsPage() {
  const queryClient = useQueryClient()
  const feedback = useFeedback()
  const { session } = useAuth()
  const { closeActiveTab } = useWorkspace()
  const canAdjust = useCan('inventory.adjust')

  const [tab, setTab] = useState<TabKey>('invoice')
  const [documentUlid, setDocumentUlid] = useState<string | null>(null)
  const [documentNumberValue, setDocumentNumberValue] = useState<string | null>(null)
  const [amendmentLines, setAmendmentLines] = useState<InventoryAdjustmentLine[]>([])
  const [draftLines, setDraftLines] = useState<InventoryAdjustmentLine[]>([])
  const [newLines, setNewLines] = useState<InventoryAdjustmentLine[]>([])
  const [draftDirty, setDraftDirty] = useState(false)
  const [amendmentDirty, setAmendmentDirty] = useState(false)
  const amendmentKeyRef = useRef(newAdjustmentKey())
  const [adjustmentDate, setAdjustmentDate] = useState(todayIso())
  const [accountUlid, setAccountUlid] = useState('')
  const [documentNotes, setDocumentNotes] = useState('')
  const [headerDirty, setHeaderDirty] = useState(false)

  const [productSearch, setProductSearch] = useState('')
  const [productLookupQuery, setProductLookupQuery] = useState('')
  const deferredProductSearch = useDeferredValue(productLookupQuery)
  const [productUlid, setProductUlid] = useState('')
  const [qtyPlus, setQtyPlus] = useState('')
  const [qtyMinus, setQtyMinus] = useState('')
  const [lineRemarks, setLineRemarks] = useState('')
  const [selectedLineUlid, setSelectedLineUlid] = useState<string | null>(null)
  const [productPickerOpen, setProductPickerOpen] = useState(false)

  const productInputRef = useRef<HTMLInputElement | null>(null)
  const productAnchorRef = useRef<HTMLDivElement | null>(null)
  const qtyPlusRef = useRef<HTMLInputElement | null>(null)
  const qtyMinusRef = useRef<HTMLInputElement | null>(null)
  const remarksRef = useRef<HTMLInputElement | null>(null)

  const [fromDate, setFromDate] = useState(monthStartIso())
  const [toDate, setToDate] = useState(todayIso())
  const [statusFilter, setStatusFilter] = useState('')
  const [selectedSearchUlid, setSelectedSearchUlid] = useState<string | null>(null)
  const [productWiseSearch, setProductWiseSearch] = useState('')
  const deferredProductWiseSearch = useDeferredValue(productWiseSearch)

  const nextNumberQuery = useQuery({
    queryKey: ['inventory-adjustment', 'next-document-number', session?.branch.ulid, session?.warehouse.ulid],
    queryFn: fetchNextInventoryAdjustmentDocumentNumber,
    enabled: canAdjust && tab === 'invoice',
    retry: false,
  })

  const accountsQuery = useQuery({
    queryKey: ['inventory-adjustment-accounts'],
    queryFn: fetchInventoryAdjustmentAccounts,
  })

  const documentQuery = useQuery({
    queryKey: ['inventory-adjustment', documentUlid],
    queryFn: () => fetchInventoryAdjustment(documentUlid as string),
    enabled: Boolean(documentUlid),
  })

  const listQuery = useQuery({
    queryKey: ['inventory-adjustments', fromDate, toDate, statusFilter],
    queryFn: () => fetchInventoryAdjustments({
      from_date: fromDate || undefined,
      to_date: toDate || undefined,
      status: statusFilter || undefined,
    }),
  })

  const localProductLookup = useLocalProducts(
    session,
    deferredProductSearch,
    tab === 'invoice',
  )
  const productLookupRows = localProductLookup.rows.filter(
    (product) => product.is_active && product.status === 'active',
  )

  const document = documentQuery.data ?? null
  const displayedDocumentNumber =
    documentNumberValue ??
    document?.document_number ??
    nextNumberQuery.data?.document_number ??
    'New'
  const displayLines =
    document?.status === 'posted'
      ? amendmentLines
      : document?.status === 'draft'
        ? draftLines
        : newLines
  const entryEnabled = canAdjust

  useEffect(() => {
    if (!productPickerOpen) return

    const handleOutsidePointer = (event: MouseEvent) => {
      const target = event.target
      if (!(target instanceof Node)) return

      if (productAnchorRef.current?.contains(target)) return

      if (
        target instanceof Element
        && target.closest('.sales-pos-product-results-grid')
      ) {
        return
      }

      setProductPickerOpen(false)
    }

    window.document.addEventListener('mousedown', handleOutsidePointer)

    return () => {
      window.document.removeEventListener('mousedown', handleOutsidePointer)
    }
  }, [productPickerOpen])

  useEffect(() => {
    if (!document) return
    setDocumentNumberValue(document.document_number)
    setAdjustmentDate(document.adjustment_date)
    setAccountUlid(document.account?.ulid ?? '')
    setDocumentNotes(document.notes ?? '')
    setHeaderDirty(false)
    setDraftDirty(false)
    setAmendmentDirty(false)
    amendmentKeyRef.current = newAdjustmentKey()

    const normalizedLines = (document.lines ?? []).map((line) => ({
      ...line,
      qty_plus: formatDecimal(line.qty_plus),
      qty_minus: formatDecimal(line.qty_minus),
      in_stock: formatDecimal(line.in_stock),
      after_stock: formatDecimal(line.after_stock),
      price: formatDecimal(line.price),
      amount: formatDecimal(line.amount),
    }))

    setDraftLines(document.status === 'draft' ? normalizedLines : [])
    setAmendmentLines(document.status === 'posted' ? normalizedLines : [])
  }, [
    document?.ulid,
    document?.document_number,
    document?.adjustment_date,
    document?.account?.ulid,
    document?.notes,
    document?.revision_number,
  ])

  const accountOptions = (accountsQuery.data?.data ?? []).map((account) => ({
    value: account.ulid,
    label: `${account.code} — ${account.name}`,
    columns: [account.code, account.name],
  }))

  function clearLineForm(focusProduct = false) {
    setProductSearch('')
    setProductLookupQuery('')
    setProductUlid('')
    setQtyPlus('')
    setQtyMinus('')
    setLineRemarks('')
    setSelectedLineUlid(null)
    setProductPickerOpen(false)

    if (focusProduct) {
      window.setTimeout(() => {
        productInputRef.current?.focus()
        productInputRef.current?.select()
      }, 0)
    }
  }

  function newDocument() {
    setDocumentUlid(null)
    setDocumentNumberValue(null)
    setAmendmentLines([])
    setDraftLines([])
    setNewLines([])
    setDraftDirty(false)
    setAmendmentDirty(false)
    amendmentKeyRef.current = newAdjustmentKey()
    setAdjustmentDate(todayIso())
    setAccountUlid('')
    setDocumentNotes('')
    setHeaderDirty(false)
    clearLineForm()
    setTab('invoice')
    void nextNumberQuery.refetch()
  }

  function fillLineForm(
    line: InventoryAdjustmentLine,
    focusEntryQuantity = true,
  ) {
    setSelectedLineUlid(line.ulid)
    setProductUlid(line.product?.ulid ?? '')
    setProductSearch(line.product ? `${line.product.product_number} · ${line.product.name}` : '')
    setProductLookupQuery(line.product?.name ?? '')
    setQtyPlus(Number(line.qty_plus) === 0 ? '' : formatDecimal(line.qty_plus))
    setQtyMinus(Number(line.qty_minus) === 0 ? '' : formatDecimal(line.qty_minus))
    setLineRemarks(line.remarks ?? '')
    setProductPickerOpen(false)

    if (!focusEntryQuantity) return

    window.setTimeout(() => {
      qtyPlusRef.current?.focus()
      qtyPlusRef.current?.select()
    }, 0)
  }

  function incrementExistingProduct(product: Product): boolean {
    if (selectedLineUlid) return false

    const existing = displayLines.find((line) => line.product?.ulid === product.ulid)
    if (!existing) return false

    const currentPlus = Number.parseFloat(existing.qty_plus || '0') || 0
    const currentMinus = Number.parseFloat(existing.qty_minus || '0') || 0
    const nextPlus = currentPlus > 0 || currentMinus <= 0 ? currentPlus + 1 : 0
    const nextMinus = currentMinus > 0 ? currentMinus + 1 : 0
    const inStock = Number.parseFloat(existing.in_stock || '0') || 0
    const price = Number.parseFloat(existing.price || '0') || 0
    const signedQty = nextPlus > 0 ? nextPlus : -nextMinus

    const updated: InventoryAdjustmentLine = {
      ...existing,
      qty_plus: nextPlus > 0 ? formatDecimal(nextPlus) : '0.0',
      qty_minus: nextMinus > 0 ? formatDecimal(nextMinus) : '0.0',
      after_stock: formatDecimal(inStock + signedQty),
      amount: formatDecimal(Math.abs(signedQty) * price),
    }

    if (!document) {
      setNewLines((current) => current.map((line) => line.ulid === existing.ulid ? updated : line))
    } else if (document.status === 'posted') {
      setAmendmentLines((current) => current.map((line) => line.ulid === existing.ulid ? updated : line))
      setAmendmentDirty(true)
    } else {
      setDraftLines((current) => current.map((line) => line.ulid === existing.ulid ? updated : line))
      setDraftDirty(true)
    }

    clearLineForm(true)
    return true
  }

  function selectEntryProduct(product: Product) {
    if (incrementExistingProduct(product)) return

    setProductUlid(product.ulid)
    setProductSearch(productLabel(product))
    setProductLookupQuery(product.name)
    setProductPickerOpen(false)

    if (selectedLineUlid) return

    window.setTimeout(() => {
      qtyPlusRef.current?.focus()
      qtyPlusRef.current?.select()
    }, 0)
  }

  function hasCurrentAdjustmentData() {
    return Boolean(
      documentUlid ||
      displayLines.length > 0 ||
      accountUlid ||
      documentNotes.trim(),
    )
  }

  async function openDocument(row: InventoryAdjustment) {
    if (
      hasCurrentAdjustmentData() &&
      document?.ulid !== row.ulid
    ) {
      const confirmed = await feedback.confirm(
        `The current adjustment contains data. If you fetch ${row.document_number}, the current data will be lost.`,
      )
      if (!confirmed) return
    }

    setDocumentNumberValue(row.document_number)
    setDocumentUlid(row.ulid)
    setTab('invoice')
    clearLineForm()
  }

  async function lookupDocumentNumber() {
    const requested = (documentNumberValue ?? '').trim()
    if (!requested) return

    try {
      const rows = await fetchInventoryAdjustments({ q: requested })
      const exact = rows.find(
        (row) => row.document_number.trim().toLocaleLowerCase() === requested.toLocaleLowerCase(),
      )

      if (!exact) {
        feedback.info('No adjustment with that exact number was found in this branch and warehouse.', 'Adjustment')
        return
      }

      await openDocument(exact)
    } catch (error) {
      feedback.fromApiError(error, 'Unable to find adjustment.')
    }
  }

  function buildStagedLine(): InventoryAdjustmentLine | null {
    if (!productUlid) {
      feedback.info('Select a product.', 'Adjustment')
      return null
    }

    const plus = Number.parseFloat(qtyPlus || '0') || 0
    const minus = Number.parseFloat(qtyMinus || '0') || 0
    if ((plus > 0) === (minus > 0)) {
      feedback.info('Enter either Qty + or Qty - with a value greater than zero.', 'Adjustment')
      return null
    }

    const existing = displayLines.find((line) => line.ulid === selectedLineUlid)
    const selectedProduct = productLookupRows.find((row) => row.ulid === productUlid)
    const sameProduct = existing?.product?.ulid === productUlid
    const product = sameProduct
      ? existing?.product ?? null
      : selectedProduct
        ? {
            ulid: selectedProduct.ulid,
            product_number: selectedProduct.product_number,
            sku: selectedProduct.sku,
            name: selectedProduct.name,
          }
        : null

    if (!product) {
      feedback.info('Select a valid product.', 'Adjustment')
      return null
    }

    const inStock = Number.parseFloat(
      (sameProduct ? existing?.in_stock : null)
      ?? selectedProduct?.sales_lookup?.in_stock
      ?? '0',
    ) || 0
    const price = Number.parseFloat(
      (sameProduct ? existing?.price : null)
      ?? selectedProduct?.sales_lookup?.average_cost
      ?? '0',
    ) || 0
    const delta = plus - minus

    return {
      ulid:
        selectedLineUlid
        ?? displayLines.find((line) => line.product?.ulid === productUlid)?.ulid
        ?? `local-${productUlid}-${Date.now()}`,
      in_stock: formatDecimal(inStock),
      qty_plus: plus > 0 ? formatDecimal(plus) : '0.0',
      qty_minus: minus > 0 ? formatDecimal(minus) : '0.0',
      after_stock: formatDecimal(inStock + delta),
      price: formatDecimal(price),
      amount: formatDecimal(Math.abs(delta) * price),
      remarks: lineRemarks.trim() || null,
      product,
    }
  }

  function stageNewLine() {
    const staged = buildStagedLine()
    if (!staged) return

    setNewLines((current) => {
      const existing = current.find((line) =>
        line.ulid === selectedLineUlid || line.product?.ulid === staged.product?.ulid,
      )
      if (existing) {
        return current.map((line) => line.ulid === existing.ulid ? staged : line)
      }
      return [...current, staged]
    })
    clearLineForm(true)
  }

  function stageDraftLine() {
    if (!document || document.status !== 'draft') return
    const staged = buildStagedLine()
    if (!staged) return

    setDraftLines((current) => {
      const existing = current.find((line) =>
        line.ulid === selectedLineUlid || line.product?.ulid === staged.product?.ulid,
      )
      if (existing) {
        return current.map((line) => line.ulid === existing.ulid ? staged : line)
      }
      return [...current, staged]
    })
    setDraftDirty(true)
    clearLineForm(true)
  }

  function stagePostedLine() {
    if (!document || document.status !== 'posted') return
    const staged = buildStagedLine()
    if (!staged) return

    setAmendmentLines((current) => {
      const existing = current.find((line) =>
        line.ulid === selectedLineUlid || line.product?.ulid === staged.product?.ulid,
      )
      if (existing) {
        return current.map((line) => line.ulid === existing.ulid ? staged : line)
      }
      return [...current, staged]
    })
    setAmendmentDirty(true)
    clearLineForm(true)
  }

  function saveCurrentLine() {
    if (!document) {
      stageNewLine()
      return
    }
    if (document.status === 'posted') {
      stagePostedLine()
      return
    }
    stageDraftLine()
  }

  async function removeCurrentLine(lineUlid: string) {
    if (!document) {
      setNewLines((current) => current.filter((line) => line.ulid !== lineUlid))
      clearLineForm(true)
      return
    }

    if (document.status === 'posted') {
      if (!await feedback.confirm('Remove this adjustment line?')) return
      setAmendmentLines((current) => current.filter((line) => line.ulid !== lineUlid))
      setAmendmentDirty(true)
      clearLineForm(true)
      return
    }

    if (!await feedback.confirm('Remove this adjustment line?')) return
    setDraftLines((current) => current.filter((line) => line.ulid !== lineUlid))
    setDraftDirty(true)
    clearLineForm(true)
  }

  async function persistLoadedDraft() {
    if (!document || document.status !== 'draft') {
      throw new Error('Draft adjustment expected.')
    }
    if (!accountUlid) {
      throw new Error('Select an adjustment account.')
    }
    if (draftLines.length === 0) {
      throw new Error('Add at least one adjustment line.')
    }

    await updateInventoryAdjustment(document.ulid, {
      account_ulid: accountUlid,
      adjustment_date: adjustmentDate,
      adjustment_type: 'stock_adjustment',
      notes: documentNotes.trim() || null,
    })

    const originalLines = document.lines ?? []
    const originalByUlid = new Map(originalLines.map((line) => [line.ulid, line]))
    const retainedUlids = new Set<string>()

    for (const line of draftLines) {
      if (!line.product?.ulid) continue

      const payload = {
        product_ulid: line.product.ulid,
        qty_plus: Number(line.qty_plus) > 0 ? line.qty_plus : '0',
        qty_minus: Number(line.qty_minus) > 0 ? line.qty_minus : '0',
        notes: line.remarks,
      }

      if (originalByUlid.has(line.ulid)) {
        retainedUlids.add(line.ulid)
        await updateInventoryAdjustmentLine(document.ulid, line.ulid, payload)
      } else {
        await createInventoryAdjustmentLine(document.ulid, payload)
      }
    }

    for (const line of originalLines) {
      if (!retainedUlids.has(line.ulid)) {
        await deleteInventoryAdjustmentLine(document.ulid, line.ulid)
      }
    }

    return fetchInventoryAdjustment(document.ulid)
  }

  const saveHeader = useMutation({
    mutationFn: async () => {
      if (!accountUlid) {
        throw new Error('Select an adjustment account.')
      }

      const payload = {
        account_ulid: accountUlid,
        adjustment_date: adjustmentDate,
        adjustment_type: 'stock_adjustment' as const,
        notes: documentNotes.trim() || null,
      }

      if (document?.status === 'draft') {
        return persistLoadedDraft()
      }

      if (documentUlid) {
        return updateInventoryAdjustment(documentUlid, payload)
      }

      if (newLines.length === 0) {
        throw new Error('Add at least one adjustment line.')
      }

      const created = await createInventoryAdjustment(payload)

      for (const line of newLines) {
        if (!line.product?.ulid) continue
        await createInventoryAdjustmentLine(created.ulid, {
          product_ulid: line.product.ulid,
          qty_plus: Number(line.qty_plus) > 0 ? line.qty_plus : '0',
          qty_minus: Number(line.qty_minus) > 0 ? line.qty_minus : '0',
          notes: line.remarks,
        })
      }

      return fetchInventoryAdjustment(created.ulid)
    },
    onSuccess: async (saved) => {
      setDocumentUlid(saved.ulid)
      setDocumentNumberValue(saved.document_number)
      setNewLines([])
      setDraftDirty(false)
      setHeaderDirty(false)
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustment', saved.ulid] })
      feedback.success(documentUlid ? 'Adjustment header saved.' : `Adjustment ${saved.document_number} saved.`)
    },
    onError: (error) => {
      if (error instanceof Error && (
        error.message === 'Select an adjustment account.' ||
        error.message === 'Add at least one adjustment line.'
      )) {
        feedback.info(error.message, 'Adjustment')
        return
      }
      feedback.fromApiError(error, 'Unable to save adjustment.')
    },
  })

  const saveLine = useMutation({
    mutationFn: async () => {
      if (!productUlid) throw new Error('Select a product.')
      if (!qtyPlus.trim() && !qtyMinus.trim()) {
        throw new Error('Enter Qty + or Qty -.')
      }

      let targetDocumentUlid = documentUlid
      let createdDocumentNumber: string | null = null

      if (!targetDocumentUlid) {
        if (!accountUlid) throw new Error('Select an adjustment account.')

        const created = await createInventoryAdjustment({
          account_ulid: accountUlid,
          adjustment_date: adjustmentDate,
          adjustment_type: 'stock_adjustment',
          notes: documentNotes.trim() || null,
        })

        targetDocumentUlid = created.ulid
        createdDocumentNumber = created.document_number
      }

      const payload = {
        product_ulid: productUlid,
        qty_plus: qtyPlus.trim() || '0',
        qty_minus: qtyMinus.trim() || '0',
        notes: lineRemarks.trim() || null,
      }

      const line = selectedLineUlid
        ? await updateInventoryAdjustmentLine(targetDocumentUlid, selectedLineUlid, payload)
        : await createInventoryAdjustmentLine(targetDocumentUlid, payload)

      return {
        line,
        documentUlid: targetDocumentUlid,
        createdDocumentNumber,
      }
    },
    onSuccess: async (result) => {
      const wasUpdate = Boolean(selectedLineUlid)

      if (!documentUlid) {
        setDocumentUlid(result.documentUlid)
        setHeaderDirty(false)
      }

      clearLineForm(true)
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustment', result.documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })

      feedback.success(
        result.createdDocumentNumber
          ? `Adjustment ${result.createdDocumentNumber} created and line added.`
          : wasUpdate
            ? 'Adjustment line updated.'
            : 'Adjustment line added.',
      )
    },
    onError: (error) => {
      if (error instanceof Error && (
        error.message === 'Select an adjustment account.' ||
        error.message === 'Select a product.' ||
        error.message === 'Enter Qty + or Qty -.'
      )) {
        feedback.info(error.message, 'Adjustment')
        return
      }
      feedback.fromApiError(error, 'Unable to save adjustment line.')
    },
  })

  const removeLine = useMutation({
    mutationFn: (lineUlid: string) => deleteInventoryAdjustmentLine(documentUlid as string, lineUlid),
    onSuccess: async () => {
      clearLineForm(true)
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustment', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })
      feedback.success('Adjustment line removed.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to remove adjustment line.'),
  })

  const amendDocument = useMutation({
    mutationFn: () => {
      if (!document || document.status !== 'posted') {
        throw new Error('Only posted adjustments can be amended.')
      }
      if (!accountUlid) {
        throw new Error('Select an adjustment account.')
      }
      if (amendmentLines.length === 0) {
        throw new Error('Add at least one adjustment line.')
      }

      return amendInventoryAdjustment(
        document.ulid,
        {
          revision_reason: 'Posted inventory adjustment corrected from Adjustment screen',
          account_ulid: accountUlid,
          adjustment_date: adjustmentDate,
          notes: documentNotes.trim() || null,
          lines: amendmentLines.map((line) => ({
            product_ulid: line.product?.ulid ?? '',
            qty_plus: Number(line.qty_plus) > 0 ? line.qty_plus : '0',
            qty_minus: Number(line.qty_minus) > 0 ? line.qty_minus : '0',
            notes: line.remarks,
          })),
        },
        amendmentKeyRef.current,
      )
    },
    onSuccess: async (saved) => {
      setHeaderDirty(false)
      setAmendmentDirty(false)
      amendmentKeyRef.current = newAdjustmentKey()
      clearLineForm()
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustment', saved.ulid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock'] })
      await queryClient.invalidateQueries({ queryKey: ['products'] })
      feedback.success(`Adjustment ${saved.document_number} amended.`)
    },
    onError: (error) => {
      if (error instanceof Error && (
        error.message === 'Select an adjustment account.' ||
        error.message === 'Add at least one adjustment line.'
      )) {
        feedback.info(error.message, 'Adjustment')
        return
      }
      feedback.fromApiError(error, 'Unable to amend adjustment.')
    },
  })

  const saveAndPostDraft = useMutation({
    mutationFn: async () => {
      const saved = await persistLoadedDraft()
      return postInventoryAdjustment(saved.ulid)
    },
    onSuccess: async (saved) => {
      setDraftDirty(false)
      setHeaderDirty(false)
      clearLineForm()
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustment', saved.ulid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })
      await queryClient.invalidateQueries({ queryKey: ['products'] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock'] })
      feedback.success(`Adjustment ${saved.document_number} posted.`)
    },
    onError: (error) => {
      if (error instanceof Error && (
        error.message === 'Select an adjustment account.' ||
        error.message === 'Add at least one adjustment line.'
      )) {
        feedback.info(error.message, 'Adjustment')
        return
      }
      feedback.fromApiError(error, 'Unable to save and post adjustment.')
    },
  })

  const saveAndPostFresh = useMutation({
    mutationFn: async () => {
      if (document) {
        throw new Error('Fresh adjustment expected.')
      }
      if (!accountUlid) {
        throw new Error('Select an adjustment account.')
      }
      if (newLines.length === 0) {
        throw new Error('Add at least one adjustment line.')
      }

      const created = await createInventoryAdjustment({
        account_ulid: accountUlid,
        adjustment_date: adjustmentDate,
        adjustment_type: 'stock_adjustment',
        notes: documentNotes.trim() || null,
      })

      for (const line of newLines) {
        if (!line.product?.ulid) continue
        await createInventoryAdjustmentLine(created.ulid, {
          product_ulid: line.product.ulid,
          qty_plus: Number(line.qty_plus) > 0 ? line.qty_plus : '0',
          qty_minus: Number(line.qty_minus) > 0 ? line.qty_minus : '0',
          notes: line.remarks,
        })
      }

      return postInventoryAdjustment(created.ulid)
    },
    onSuccess: async (saved) => {
      setDocumentUlid(saved.ulid)
      setDocumentNumberValue(saved.document_number)
      setNewLines([])
      setHeaderDirty(false)
      clearLineForm()
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustment', saved.ulid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })
      await queryClient.invalidateQueries({ queryKey: ['products'] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock'] })
      feedback.success(`Adjustment ${saved.document_number} posted.`)
    },
    onError: (error) => {
      if (error instanceof Error && (
        error.message === 'Select an adjustment account.' ||
        error.message === 'Add at least one adjustment line.'
      )) {
        feedback.info(error.message, 'Adjustment')
        return
      }
      feedback.fromApiError(error, 'Unable to save and post adjustment.')
    },
  })

  const postDocument = useMutation({
    mutationFn: () => postInventoryAdjustment(documentUlid as string),
    onSuccess: async () => {
      clearLineForm()
      setHeaderDirty(false)
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustment', documentUlid] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })
      await queryClient.invalidateQueries({ queryKey: ['products'] })
      await queryClient.invalidateQueries({ queryKey: ['inventory-stock'] })
      feedback.success('Adjustment posted. Stock movements and accounting are final.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to post adjustment.'),
  })

  const removeDocument = useMutation({
    mutationFn: (ulid: string) => deleteInventoryAdjustment(ulid),
    onSuccess: async () => {
      newDocument()
      setSelectedSearchUlid(null)
      await queryClient.invalidateQueries({ queryKey: ['inventory-adjustments'] })
      feedback.success('Draft adjustment deleted.')
    },
    onError: (error) => feedback.fromApiError(error, 'Unable to delete adjustment.'),
  })

  const searchColumns = useMemo<PosGridColumn<InventoryAdjustment>[]>(() => [
    { key: 'number', header: 'Adj #', width: 120, render: (row) => row.document_number },
    { key: 'date', header: 'Date', width: 110, render: (row) => row.adjustment_date },
    { key: 'warehouse', header: 'Store', width: 180, render: (row) => row.warehouse?.name ?? '—' },
    { key: 'account', header: 'Account Adjusted', width: '28%', render: (row) => row.account ? `${row.account.code} — ${row.account.name}` : '—' },
    { key: 'status', header: 'Status', width: 90, render: (row) => row.status.toUpperCase() },
    { key: 'qty', header: 'Lines', width: 70, align: 'right', render: (row) => String(row.lines?.length ?? 0) },
    { key: 'net', header: 'Net', width: 110, align: 'right', render: (row) => formatDecimal(row.net_amount) },
  ], [])

  const productWiseRows = useMemo<ProductWiseRow[]>(() => {
    const needle = deferredProductWiseSearch.trim().toLowerCase()

    return (listQuery.data ?? []).flatMap((doc) =>
      (doc.lines ?? [])
        .filter((line) => {
          if (!needle) return true
          const label = `${line.product?.product_number ?? ''} ${line.product?.sku ?? ''} ${line.product?.name ?? ''}`.toLowerCase()
          return label.includes(needle)
        })
        .map((line) => ({
          key: `${doc.ulid}:${line.ulid}`,
          document_number: doc.document_number,
          date: doc.adjustment_date,
          account: doc.account ? `${doc.account.code} — ${doc.account.name}` : '—',
          product: line.product ? `${line.product.product_number} · ${line.product.name}` : '—',
          qty_plus: line.qty_plus,
          qty_minus: line.qty_minus,
          amount: formatDecimal(signedNet(line)),
          remarks: line.remarks,
        })),
    )
  }, [listQuery.data, deferredProductWiseSearch])

  const productWiseColumns = useMemo<PosGridColumn<ProductWiseRow>[]>(() => [
    { key: 'number', header: 'Adj #', width: 115, render: (row) => row.document_number },
    { key: 'date', header: 'Date', width: 105, render: (row) => row.date },
    { key: 'account', header: 'Account', width: '24%', render: (row) => row.account },
    { key: 'product', header: 'Product', width: '32%', render: (row) => row.product },
    { key: 'plus', header: 'Qty (+)', width: 100, align: 'right', render: (row) => formatDecimal(row.qty_plus) },
    { key: 'minus', header: 'Qty (-)', width: 100, align: 'right', render: (row) => formatDecimal(row.qty_minus) },
    { key: 'amount', header: 'Amount', width: 110, align: 'right', render: (row) => formatDecimal(row.amount) },
    { key: 'remarks', header: 'Remarks', render: (row) => row.remarks ?? '—' },
  ], [])

  const selectedEntryProduct = productLookupRows.find((row) => row.ulid === productUlid) ?? null
  const selectedExistingLine = displayLines.find((row) => row.ulid === selectedLineUlid) ?? null

  const entryPreview = useMemo(() => {
    const inStock = selectedExistingLine?.in_stock
      ?? selectedEntryProduct?.sales_lookup?.in_stock
      ?? '0.000000'
    const price = selectedExistingLine?.price
      ?? selectedEntryProduct?.sales_lookup?.average_cost
      ?? '0.0000'
    const plus = Number.parseFloat(qtyPlus || '0') || 0
    const minus = Number.parseFloat(qtyMinus || '0') || 0
    const stock = Number.parseFloat(inStock || '0') || 0
    const cost = Number.parseFloat(price || '0') || 0
    const delta = plus - minus

    return {
      inStock: formatDecimal(inStock),
      afterStock: formatDecimal(stock + delta),
      price: formatDecimal(price),
      amount: formatDecimal(Math.abs(delta) * cost),
    }
  }, [qtyMinus, qtyPlus, selectedEntryProduct, selectedExistingLine])

  const displayNetAmount = formatDecimal(
    displayLines.reduce((sum, line) => sum + signedNet(line), 0),
  )

    const selectedSearchDocument = (listQuery.data ?? []).find((row) => row.ulid === selectedSearchUlid) ?? null

  function previewProductWise() {
    const root = window.document.documentElement
    const cleanup = () => {
      root.removeAttribute('data-adjustment-product-print')
      window.removeEventListener('afterprint', cleanup)
    }

    root.setAttribute('data-adjustment-product-print', 'true')
    window.addEventListener('afterprint', cleanup, { once: true })
    window.requestAnimationFrame(() => window.print())
  }

  const generatedAt = new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date())

  return (
    <section className="inventory-adjustments-page">
      <div className="inventory-adjustments-tabs" role="tablist" aria-label="Adjustment views">
        <button
          type="button"
          className={tab === 'invoice' ? 'is-active' : ''}
          onClick={() => setTab('invoice')}
        >
          <Save size={15} /> Adjustment Invoice
        </button>
        <button
          type="button"
          className={tab === 'search' ? 'is-active' : ''}
          onClick={() => setTab('search')}
        >
          <FileSearch size={15} /> Search
        </button>
        <button
          type="button"
          className={tab === 'product' ? 'is-active' : ''}
          onClick={() => setTab('product')}
        >
          <PackageSearch size={15} /> Product Wise
        </button>
        <strong>Adjustment</strong>
      </div>

      {tab === 'invoice' ? (
        <>
          <div className="inventory-adjustments-header">
            <div className="inventory-adjustments-options">
              <div className="inventory-adjustments-section-title">Invoice Options</div>
              <label>
                <span>Adj#:</span>
                <input
                  value={displayedDocumentNumber}
                  readOnly={!canAdjust}
                  disabled={documentQuery.isFetching}
                  title="Type an exact Adjustment number and press Enter to load it"
                  onChange={(event) => setDocumentNumberValue(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key !== 'Enter') return
                    event.preventDefault()
                    void lookupDocumentNumber()
                  }}
                />
              </label>
              <label>
                <span>Date:</span>
                <input
                  type="date"
                  value={adjustmentDate}
                  disabled={!canAdjust}
                  onChange={(event) => {
                    setAdjustmentDate(event.target.value)
                    setHeaderDirty(true)
                    if (document?.status === 'posted') setAmendmentDirty(true)
                  }}
                />
              </label>
              <label className="inventory-adjustments-account">
                <span>Account:</span>
                <UiTableDropdown
                  value={accountUlid}
                  options={accountOptions}
                  onChange={(value) => {
                    setAccountUlid(value)
                    setHeaderDirty(true)
                    if (document?.status === 'posted') setAmendmentDirty(true)
                  }}
                  disabled={!canAdjust}
                  placeholder={accountsQuery.isLoading ? 'Loading accounts…' : 'Select account'}
                  searchPlaceholder="Search account"
                  menuColumns={[
                    { header: 'Code', width: '120px' },
                    { header: 'Account', width: 'minmax(0, 1fr)' },
                  ]}
                  menuMinWidth={420}
                />
              </label>
            </div>

            <div className="inventory-adjustments-amount-options">
              <div className="inventory-adjustments-section-title">Amount Options</div>
              <label>
                <span>Adjustment Type:</span>
                <UiSimpleDropdown
                  value="stock_adjustment"
                  options={[{ value: 'stock_adjustment', label: 'Stock Adjustment' }]}
                  onChange={() => undefined}
                  searchPlaceholder="Search adjustment type"
                />
              </label>
              <label>
                <span>Net Amount:</span>
                <input
                  className="inventory-adjustments-net-amount"
                  value={displayNetAmount}
                  readOnly
                  aria-readonly="true"
                />
              </label>
            </div>
          </div>

          <div className="inventory-adjustments-grid">
            <div className="inventory-adjustments-entry-grid-wrap">
              <PosEntryGrid
                className="inventory-adjustments-custom-table"
                tableClassName="inventory-adjustments-entry-grid"
                ariaLabel="Inventory adjustment lines"
                columns={[
                  { key: 'product', header: 'ITEM / PRODUCT DESCRIPTION', width: '30%' },
                  { key: 'stock', header: 'In Stock', width: '7%', align: 'right' },
                  { key: 'plus', header: 'Qty +', width: '7%', align: 'right' },
                  { key: 'minus', header: 'Qty -', width: '7%', align: 'right' },
                  { key: 'after', header: 'After Stock', width: '8%', align: 'right' },
                  { key: 'price', header: 'Price', width: '7%', align: 'right' },
                  { key: 'amount', header: 'Amount', width: '8%', align: 'right' },
                  { key: 'remarks', header: 'Remarks', width: '23%' },
                  { key: 'delete', header: '', width: '36px', align: 'center' },
                ]}
              >
                  {displayLines.map((line) => {
                    const editing = selectedLineUlid === line.ulid

                    return (
                      <tr
                        key={line.ulid}
                        className={editing ? 'is-selected' : ''}
                      >
                        <td className="inventory-adjustments-product-cell">
                          <div
                            ref={editing ? productAnchorRef : undefined}
                            className="inventory-adjustments-product-entry"
                          >
                            <input
                              value={
                                editing
                                  ? productSearch
                                  : (line.product ? `${line.product.product_number} · ${line.product.name}` : '')
                              }
                              autoComplete="off"
                              disabled={!canAdjust}
                              onFocus={() => {
                                if (!editing) fillLineForm(line, false)
                              }}
                              onChange={(event) => {
                                if (!editing) fillLineForm(line, false)
                                setProductSearch(event.target.value)
                                setProductLookupQuery(event.target.value)
                                setProductUlid('')
                                setProductPickerOpen(true)
                              }}
                              onKeyDown={(event) => {
                                if (event.key === 'ArrowDown') {
                                  event.preventDefault()
                                  setProductPickerOpen(true)
                                  return
                                }
                                if (event.key !== 'Enter') return
                                const first = productLookupRows[0]
                                if (first) {
                                  event.preventDefault()
                                  selectEntryProduct(first)
                                }
                              }}
                            />
                            <button
                              type="button"
                              className="inventory-adjustments-product-arrow"
                              aria-label="Open product list"
                              aria-expanded={editing && productPickerOpen}
                              disabled={!canAdjust}
                              onMouseDown={(event) => {
                                event.preventDefault()
                                event.stopPropagation()
                              }}
                              onClick={() => {
                                if (!editing) fillLineForm(line, false)
                                setProductPickerOpen((open) => !open)
                              }}
                            >
                              <span aria-hidden>
                                <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                                  <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                                </svg>
                              </span>
                            </button>
                          </div>
                        </td>
                        <td className="is-num">{formatDecimal(line.in_stock)}</td>
                        <td>
                          <input
                            className="inventory-adjustments-entry-number"
                            inputMode="decimal"
                            disabled={!canAdjust}
                            value={editing ? qtyPlus : (Number(line.qty_plus) > 0 ? formatDecimal(line.qty_plus) : '')}
                            onFocus={() => {
                              if (!editing) fillLineForm(line, false)
                            }}
                            onChange={(event) => {
                              if (!editing) fillLineForm(line, false)
                              setQtyPlus(event.target.value)
                              if (event.target.value.trim()) setQtyMinus('')
                            }}
                            onKeyDown={(event) => {
                              if (event.key !== 'Enter') return
                              event.preventDefault()
                              saveCurrentLine()
                            }}
                          />
                        </td>
                        <td>
                          <input
                            className="inventory-adjustments-entry-number"
                            inputMode="decimal"
                            disabled={!canAdjust}
                            value={editing ? qtyMinus : (Number(line.qty_minus) > 0 ? formatDecimal(line.qty_minus) : '')}
                            onFocus={() => {
                              if (!editing) fillLineForm(line, false)
                            }}
                            onChange={(event) => {
                              if (!editing) fillLineForm(line, false)
                              setQtyMinus(event.target.value)
                              if (event.target.value.trim()) setQtyPlus('')
                            }}
                            onKeyDown={(event) => {
                              if (event.key !== 'Enter') return
                              event.preventDefault()
                              saveCurrentLine()
                            }}
                          />
                        </td>
                        <td className="is-num">{formatDecimal(line.after_stock)}</td>
                        <td className="is-num">{formatDecimal(line.price)}</td>
                        <td className="is-num">{formatDecimal(line.amount)}</td>
                        <td>
                          <input
                            className="inventory-adjustments-inline-remarks"
                            disabled={!canAdjust}
                            value={editing ? lineRemarks : (line.remarks ?? '')}
                            maxLength={500}
                            onFocus={() => {
                              if (!editing) fillLineForm(line, false)
                            }}
                            onChange={(event) => {
                              if (!editing) fillLineForm(line, false)
                              setLineRemarks(event.target.value)
                            }}
                            onKeyDown={(event) => {
                              if (event.key !== 'Enter') return
                              event.preventDefault()
                              saveCurrentLine()
                            }}
                          />
                        </td>
                        <td className="inventory-adjustments-delete-cell">
                          <button
                            type="button"
                            className="inventory-adjustments-row-delete"
                            aria-label="Delete adjustment row"
                            title="Delete row"
                            disabled={!canAdjust || amendDocument.isPending || saveHeader.isPending}
                            onClick={() => void removeCurrentLine(line.ulid)}
                          >
                            <Trash2 size={13} />
                          </button>
                        </td>
                      </tr>
                    )
                  })}

                  {entryEnabled ? (
                    <tr className="is-entry-row">
                      <td className="inventory-adjustments-product-cell">
                        <div ref={selectedLineUlid ? undefined : productAnchorRef} className="inventory-adjustments-product-entry">
                          <input
                            ref={productInputRef}
                            value={productSearch}
                            autoComplete="off"
                            placeholder="Search product name, number, SKU or barcode"
                            onFocus={() => {
                              if (productSearch.trim() || productLookupRows.length > 0) {
                                setProductPickerOpen(true)
                              }
                            }}
                            onChange={(event) => {
                              setProductSearch(event.target.value)
                              setProductLookupQuery(event.target.value)
                              setProductUlid('')
                              setProductPickerOpen(true)
                                                      }}
                            onKeyDown={(event) => {
                              if (event.key === 'ArrowDown') {
                                event.preventDefault()
                                setProductPickerOpen(true)
                                return
                              }

                              if (event.key !== 'Enter') return

                              const first = productLookupRows[0]
                              if (first) {
                                event.preventDefault()
                                selectEntryProduct(first)
                              }
                            }}
                          />
                          <button
                            type="button"
                            className="inventory-adjustments-product-arrow"
                            aria-label="Open product list"
                            aria-expanded={productPickerOpen}
                            onMouseDown={(event) => {
                              event.preventDefault()
                              event.stopPropagation()
                            }}
                            onClick={() => {
                              setProductPickerOpen((open) => !open)
                              window.setTimeout(() => productInputRef.current?.focus(), 0)
                            }}
                          >
                            <span aria-hidden>
                              <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                                <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                              </svg>
                            </span>
                          </button>

                          {productPickerOpen && productLookupRows.length > 0 ? (
                            <ProductLookupGrid
                              rows={productLookupRows}
                              total={productLookupRows.length}
                              priceType="default"
                              onSelect={selectEntryProduct}
                              portalAnchorRef={productAnchorRef}
                              themed
                              selectedUlid={productUlid}
                              onRequestClose={() => setProductPickerOpen(false)}
                            />
                          ) : null}
                        </div>
                      </td>
                      <td className="is-num is-readonly">{productUlid ? entryPreview.inStock : ''}</td>
                      <td>
                        <input
                          ref={qtyPlusRef}
                          className="inventory-adjustments-entry-number"
                          inputMode="decimal"
                          value={qtyPlus}
                          placeholder="0.0"
                          onChange={(event) => {
                            setQtyPlus(event.target.value)
                            if (event.target.value.trim()) setQtyMinus('')
                                                  }}
                          onKeyDown={(event) => {
                            if (event.key !== 'Enter') return
                            event.preventDefault()
                            qtyMinusRef.current?.focus()
                            qtyMinusRef.current?.select()
                          }}
                        />
                      </td>
                      <td>
                        <input
                          ref={qtyMinusRef}
                          className="inventory-adjustments-entry-number"
                          inputMode="decimal"
                          value={qtyMinus}
                          placeholder="0.0"
                          onChange={(event) => {
                            setQtyMinus(event.target.value)
                            if (event.target.value.trim()) setQtyPlus('')
                                                  }}
                          onKeyDown={(event) => {
                            if (event.key !== 'Enter') return
                            event.preventDefault()
                            remarksRef.current?.focus()
                            remarksRef.current?.select()
                          }}
                        />
                      </td>
                      <td className="is-num is-readonly">{productUlid ? entryPreview.afterStock : ''}</td>
                      <td className="is-num is-readonly">{productUlid ? entryPreview.price : ''}</td>
                      <td className="is-num is-readonly">{productUlid ? entryPreview.amount : ''}</td>
                      <td className="inventory-adjustments-entry-remarks">
                        <input
                          ref={remarksRef}
                          value={lineRemarks}
                          maxLength={500}
                          placeholder="Remarks"
                          onChange={(event) => {
                            setLineRemarks(event.target.value)
                                                  }}
                          onKeyDown={(event) => {
                            if (event.key !== 'Enter') return
                            event.preventDefault()
                            saveCurrentLine()
                          }}
                        />
                      </td>
                      <td className="inventory-adjustments-delete-cell" />
                    </tr>
                  ) : null}
              </PosEntryGrid>
            </div>
          </div>

          <footer className="inventory-adjustments-footer">
            <div>
              <UiButton
                variant="danger"
                onClick={async () => {
                  if (!document || document.status !== 'draft') return
                  if (!await feedback.confirm(`Delete draft ${document.document_number}?`)) return
                  removeDocument.mutate(document.ulid)
                }}
                disabled={!document || document.status !== 'draft' || !canAdjust}
              >
                <Trash2 size={15} /> Delete
              </UiButton>
            </div>
            <div className="inventory-adjustments-footer-actions">
              <UiButton
                variant="primary"
                onClick={newDocument}
                disabled={!canAdjust}
              >
                <Plus size={15} /> New
              </UiButton>
              <UiButton
                variant="success"
                onClick={() => {
                  if (document?.status === 'posted') {
                    amendDocument.mutate()
                    return
                  }
                  saveHeader.mutate()
                }}
                disabled={
                  !canAdjust ||
                  (document?.status === 'posted'
                    ? (!amendmentDirty && !headerDirty) || amendDocument.isPending
                    : (document?.status === 'draft'
                        ? (!draftDirty && !headerDirty) || saveHeader.isPending
                        : ((!headerDirty && Boolean(document)) || saveHeader.isPending)))
                }
              >
                <Save size={15} /> {document?.status === 'posted' ? 'Amend Adjustment' : 'Save'}
              </UiButton>
              <UiButton
                variant="success"
                onClick={async () => {
                  if (!canAdjust) return

                  if (!document) {
                    if (!await feedback.confirm(
                      `Post ${displayedDocumentNumber}? All staged rows will be saved and posted.`,
                    )) return
                    saveAndPostFresh.mutate()
                    return
                  }

                  if (document.status !== 'draft') return
                  if (!await feedback.confirm(
                    `Post ${document.document_number}? Staged row changes will be saved first, then live stock and cost will be re-read.`,
                  )) return
                  saveAndPostDraft.mutate()
                }}
                disabled={
                  !canAdjust ||
                  document?.status === 'posted' ||
                  (!document && newLines.length === 0) ||
                  postDocument.isPending ||
                  saveAndPostDraft.isPending ||
                  saveAndPostFresh.isPending
                }
              >
                <Send size={15} /> Post
              </UiButton>
              <UiButton
                variant="info"
                onClick={() => {
                  if (documentUlid) {
                    clearLineForm()
                    void documentQuery.refetch()
                  } else {
                    newDocument()
                  }
                }}
              >
                <RefreshCw size={15} /> Refresh
              </UiButton>
              <UiButton variant="info" onClick={closeActiveTab}>
                <X size={15} /> Close
              </UiButton>
            </div>
          </footer>
        </>
      ) : null}

      {tab === 'search' ? (
        <>
          <div className="inventory-adjustments-report-toolbar">
            <label>
              <span>From:</span>
              <input type="date" value={fromDate} onChange={(event) => setFromDate(event.target.value)} />
            </label>
            <label>
              <span>To:</span>
              <input type="date" value={toDate} onChange={(event) => setToDate(event.target.value)} />
            </label>
            <UiSelect
              className="inventory-adjustments-status-filter"
              value={statusFilter}
              options={[
                { value: '', label: 'All statuses' },
                { value: 'draft', label: 'Draft' },
                { value: 'posted', label: 'Posted' },
              ]}
              onChange={setStatusFilter}
              searchable={false}
            />
            <UiButton variant="info" onClick={() => void listQuery.refetch()}>
              <FileSearch size={15} /> View
            </UiButton>
            <UiButton
              onClick={() => {
                if (selectedSearchDocument) void openDocument(selectedSearchDocument)
              }}
              disabled={!selectedSearchDocument}
            >
              Open
            </UiButton>
          </div>
          <div className="inventory-adjustments-report-grid">
            <PosDataGrid
              columns={searchColumns}
              rows={listQuery.data ?? []}
              rowKey={(row) => row.ulid}
              selectedKey={selectedSearchUlid}
              onSelect={(row) => setSelectedSearchUlid(row.ulid)}
              onActivate={(row) => void openDocument(row)}
              emptyMessage={listQuery.isLoading ? 'Loading adjustments…' : 'No adjustments found.'}
            />
          </div>
        </>
      ) : null}

      {tab === 'product' ? (
        <>
          <header className="inventory-adjustments-print-header" aria-hidden="true">
            <div>
              <h1>Product Wise Adjustments</h1>
              <p>
                {session?.tenant.name ?? 'BluePOS'} · {session?.branch.code ?? '—'} — {session?.branch.name ?? '—'} · {session?.warehouse.name ?? '—'}
              </p>
            </div>
            <div className="inventory-adjustments-print-meta">
              <span>{fromDate} to {toDate}</span>
              {deferredProductWiseSearch.trim() ? <span>Product: {deferredProductWiseSearch.trim()}</span> : null}
              <span>Generated {generatedAt}</span>
            </div>
          </header>

          <div className="inventory-adjustments-report-toolbar">
            <label>
              <span>From Date:</span>
              <input type="date" value={fromDate} onChange={(event) => setFromDate(event.target.value)} />
            </label>
            <label>
              <span>To Date:</span>
              <input type="date" value={toDate} onChange={(event) => setToDate(event.target.value)} />
            </label>
            <label className="inventory-adjustments-product-wise-search">
              <span>Product:</span>
              <input
                value={productWiseSearch}
                onChange={(event) => setProductWiseSearch(event.target.value)}
                placeholder="Search product…"
              />
            </label>
            <UiButton variant="info" onClick={() => void listQuery.refetch()}>
              <PackageSearch size={15} /> Show
            </UiButton>
            <UiButton variant="info" onClick={previewProductWise}>
              Preview
            </UiButton>
          </div>
          <div className="inventory-adjustments-report-grid">
            <PosDataGrid
              columns={productWiseColumns}
              rows={productWiseRows}
              rowKey={(row) => row.key}
              emptyMessage={listQuery.isLoading ? 'Loading adjustment products…' : 'No product-wise adjustment rows found.'}
            />
          </div>
        </>
      ) : null}
    </section>
  )
}
