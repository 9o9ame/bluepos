import { FormEvent, SelectHTMLAttributes, useEffect, useMemo, useRef, useState } from 'react'
import {
  Barcode,
  Check,
  ChevronDown,
  ChevronFirst,
  ChevronLast,
  ChevronLeft,
  ChevronRight,
  ImagePlus,
  Plus,
  Printer,
  RefreshCw,
  Trash2,
  X,
} from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createProduct,
  deactivateProduct,
  deleteProductImage,
  fetchBarcodeGroups,
  fetchBrands,
  fetchCategories,
  fetchProduct,
  fetchProducts,
  fetchSubcategories,
  fetchSuppliers,
  fetchUnits,
  saveProductBarcodes,
  saveProductPrices,
  updateProduct,
  uploadProductImage,
} from '../api/catalog'
import { ApiClientError } from '../api/client'
import { askConfirm } from '../feedback/FeedbackProvider'
import {
  createOpeningBalance,
  createOpeningBalanceLine,
  fetchOpeningBalances,
  fetchProductStock,
  fetchWarehouses,
  postOpeningBalance,
  updateOpeningBalanceLine,
} from '../api/inventory'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { CatalogQuickEditorModal, type QuickEditorKind } from '../components/catalog/CatalogQuickEditorModal'
import { loadBarcodePrintSettings, printBarcodeLabels } from '../components/products/barcodePrint'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Product } from '../types/catalog'
import type { OpeningBalance } from '../types/inventory'
import './ProductsPage.reference.css'
import './ProductsPage.theme.css'
import './ProductsPage.functional.css'
import './ProductsPage.modern.css'

type ProductBarcodeDraft = {
  id: string
  barcode: string
  unit_ulid: string
  conversion_factor: string
  is_primary: boolean
}

let barcodeDraftSequence = 0

function createBarcodeDraft(
  unitUlid = '',
  conversionFactor = '1.00000000',
  isPrimary = false,
): ProductBarcodeDraft {
  barcodeDraftSequence += 1

  return {
    id: `barcode-draft-${barcodeDraftSequence}`,
    barcode: '',
    unit_ulid: unitUlid,
    conversion_factor: conversionFactor,
    is_primary: isPrimary,
  }
}

function PdfSelect({
  className,
  onBlur,
  onChange,
  onMouseDown,
  onKeyDown,
  ...props
}: SelectHTMLAttributes<HTMLSelectElement>) {
  const [open, setOpen] = useState(false)
  const shellRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return

    // Native <select> often skips blur when the list closes on Windows.
    // Close on outside interaction, but ignore events inside this shell so
    // the same-click mousedown can toggle the caret back down.
    const closeOutside = (event: Event) => {
      const target = event.target
      if (target instanceof Node && shellRef.current?.contains(target)) return
      setOpen(false)
    }
    const close = () => setOpen(false)
    const timer = window.setTimeout(() => {
      window.addEventListener('pointerdown', closeOutside, true)
      window.addEventListener('keydown', closeOutside, true)
      window.addEventListener('scroll', close, true)
      window.addEventListener('blur', close)
    }, 0)

    return () => {
      window.clearTimeout(timer)
      window.removeEventListener('pointerdown', closeOutside, true)
      window.removeEventListener('keydown', closeOutside, true)
      window.removeEventListener('scroll', close, true)
      window.removeEventListener('blur', close)
    }
  }, [open])

  return (
    <div
      ref={shellRef}
      className={`pdf-select-shell${open ? ' is-open' : ''}${props.disabled ? ' is-disabled' : ''}`}
    >
      <select
        {...props}
        className={['pdf-select', className].filter(Boolean).join(' ')}
        onMouseDown={(event) => {
          if (!props.disabled) {
            // Toggle: second click on the closed native list restores caret.
            setOpen((current) => !current)
          }
          onMouseDown?.(event)
        }}
        onKeyDown={(event) => {
          if (props.disabled) {
            onKeyDown?.(event)
            return
          }
          if (event.key === 'Escape') {
            setOpen(false)
          } else if (
            event.key === 'Enter' ||
            event.key === ' ' ||
            event.key === 'ArrowDown' ||
            event.key === 'ArrowUp'
          ) {
            setOpen(true)
          }
          onKeyDown?.(event)
        }}
        onBlur={(event) => {
          setOpen(false)
          onBlur?.(event)
        }}
        onChange={(event) => {
          setOpen(false)
          onChange?.(event)
        }}
      />
      <span className="pdf-select-caret" aria-hidden="true">
        <ChevronDown size={11} strokeWidth={3} />
      </span>
    </div>
  )
}

export function ProductsPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab, openModule } = useWorkspace()
  const { session } = useAuth()

  const canCreate = useCan('products.create')
  const canEdit = useCan('products.edit')
  const canDelete = useCan('products.delete')
  const canPrices = useCan('products.manage_prices')
  const canBarcodes = useCan('products.manage_barcodes')
  const canViewStock = useCan('inventory.view')
  const canOpeningView = useCan('inventory.opening_balance.view') || canViewStock
  const canOpeningCreate = useCan('inventory.opening_balance.create')
  const canOpeningEdit = useCan('inventory.opening_balance.edit')
  const canOpeningPost = useCan('inventory.opening_balance.post')

  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [listCategoryUlid, setListCategoryUlid] = useState('')
  const [creating, setCreating] = useState(false)
  const [section, setSection] = useState<'definition' | 'opening' | 'related'>('definition')
  const [error, setError] = useState<string | null>(null)
  const [quickEditor, setQuickEditor] = useState<QuickEditorKind | null>(null)
  const imageInputRef = useRef<HTMLInputElement | null>(null)
  const [pendingImage, setPendingImage] = useState<File | null>(null)
  const [pendingImagePreview, setPendingImagePreview] = useState<string | null>(null)
  const [imageRemoveRequested, setImageRemoveRequested] = useState(false)

  const productsQuery = useQuery({
    queryKey: ['products', q, page, listCategoryUlid],
    queryFn: () =>
      fetchProducts({
        q,
        page,
        per_page: 50,
        category_ulid: listCategoryUlid || undefined,
      }),
  })

  const products = productsQuery.data?.data ?? []

  const productQuery = useQuery({
    queryKey: ['product', selectedKey],
    queryFn: () => fetchProduct(selectedKey ?? ''),
    enabled: Boolean(selectedKey) && !creating,
  })

  const categories = useQuery({ queryKey: ['categories'], queryFn: fetchCategories })
  const brands = useQuery({ queryKey: ['brands'], queryFn: fetchBrands })
  const units = useQuery({ queryKey: ['units'], queryFn: fetchUnits })
  const barcodeGroups = useQuery({ queryKey: ['barcode-groups'], queryFn: fetchBarcodeGroups })
  const suppliers = useQuery({ queryKey: ['suppliers'], queryFn: fetchSuppliers })
  const warehouses = useQuery({
    queryKey: ['warehouses', session?.branch.ulid],
    queryFn: fetchWarehouses,
    enabled: Boolean(session?.branch.ulid),
  })
  const productStock = useQuery({
    queryKey: ['product-stock', selectedKey],
    queryFn: () => fetchProductStock(selectedKey ?? ''),
    enabled: Boolean(selectedKey) && !creating && canViewStock,
  })

  const selectedRow = products.find((product) => product.ulid === selectedKey) ?? null
  const selected = productQuery.data ?? selectedRow

  const [name, setName] = useState('')
  const [alternateName, setAlternateName] = useState('')
  const [sku, setSku] = useState('')
  const [categoryUlid, setCategoryUlid] = useState('')
  const [subcategoryUlid, setSubcategoryUlid] = useState('')
  const [brandUlid, setBrandUlid] = useState('')
  const [barcodeGroupUlid, setBarcodeGroupUlid] = useState('')
  const [primarySupplierUlid, setPrimarySupplierUlid] = useState('')
  const [supplierProductCode, setSupplierProductCode] = useState('')
  const [baseUnitUlid, setBaseUnitUlid] = useState('')
  const [reorderLevel, setReorderLevel] = useState('')
  const [rackLocation, setRackLocation] = useState('')
  const [taxPercent, setTaxPercent] = useState('0')
  const [isPackaging, setIsPackaging] = useState(false)
  const [maxFreeQtyPerSale, setMaxFreeQtyPerSale] = useState('')
  const [retail, setRetail] = useState('0.0000')
  const [wholesale, setWholesale] = useState('0.0000')
  const [minimumSale, setMinimumSale] = useState('0.0000')
  const [barcodeRows, setBarcodeRows] = useState<ProductBarcodeDraft[]>([])
  const [selectedBarcodeId, setSelectedBarcodeId] = useState<string | null>(null)

  const subcategories = useQuery({
    queryKey: ['subcategories', categoryUlid],
    queryFn: () => fetchSubcategories(categoryUlid),
    enabled: Boolean(categoryUlid),
  })

  const [openingWarehouseUlid, setOpeningWarehouseUlid] = useState('')
  const [openingQty, setOpeningQty] = useState('')
  const [openingUnitCost, setOpeningUnitCost] = useState('0.0000')
  const [openingDocument, setOpeningDocument] = useState<OpeningBalance | null>(null)
  const [openingBusy, setOpeningBusy] = useState(false)

  const canSave = creating ? canCreate : canEdit && Boolean(selectedKey)
  const inStockDisplay = productStock.data?.active_warehouse.quantity ?? '0.000000'
  const purchaseRateDisplay = useMemo(() => {
    const stock = productStock.data
    if (!stock) return '0.0000'
    const activeUlid = stock.active_warehouse.ulid
    const match = stock.warehouses.find((row) => row.warehouse.ulid === activeUlid)
    const avg = match?.average_cost
    if (avg == null || avg === '') return '0.0000'
    const n = Number(avg)
    return Number.isFinite(n) ? n.toFixed(4) : '0.0000'
  }, [productStock.data])

  const marginDisplay = useMemo(() => {
    const purchase = Number(purchaseRateDisplay)
    const sale = Number(retail)
    if (!Number.isFinite(purchase) || !Number.isFinite(sale) || sale <= 0) return '0.00'
    return (((sale - purchase) / sale) * 100).toFixed(2)
  }, [purchaseRateDisplay, retail])

  function applySaleDerivedPrices(saleRaw: string, purchaseRaw: string) {
    const sale = Number(saleRaw)
    const purchase = Number(purchaseRaw)
    if (!Number.isFinite(sale) || !Number.isFinite(purchase)) {
      setWholesale('0.0000')
      setMinimumSale('0.0000')
      return
    }
    const markup = sale - purchase
    setWholesale((sale - markup * 0.25).toFixed(4))
    setMinimumSale((sale - markup * 0.5).toFixed(4))
  }

  function onSaleRateChange(value: string) {
    setRetail(value)
    applySaleDerivedPrices(value, purchaseRateDisplay)
  }

  const openingTotal = useMemo(() => {
    if (!openingQty || !openingUnitCost) return '0.0000'
    const qty = Number(openingQty)
    const cost = Number(openingUnitCost)
    if (!Number.isFinite(qty) || !Number.isFinite(cost)) return '0.0000'
    return (qty * cost).toFixed(4)
  }, [openingQty, openingUnitCost])
  const openingPosted = openingDocument?.status === 'posted'
  const openingLine = openingDocument?.lines?.find((line) => line.product?.ulid === selectedKey) ?? null

  function clearPendingImageState() {
    setPendingImage(null)
    setPendingImagePreview((current) => {
      if (current?.startsWith('blob:')) {
        URL.revokeObjectURL(current)
      }

      return null
    })
    setImageRemoveRequested(false)

    if (imageInputRef.current) {
      imageInputRef.current.value = ''
    }
  }

  function openImagePicker() {
    if (!canSave || saveMutation.isPending) return
    imageInputRef.current?.click()
  }

  function onProductImageSelected(file: File | null) {
    if (!file || !canSave) return

    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp']
    if (!allowedTypes.includes(file.type)) {
      setError('Product image must be JPG, PNG, or WEBP.')
      return
    }

    if (file.size > 5 * 1024 * 1024) {
      setError('Product image must be 5 MB or smaller.')
      return
    }

    const preview = URL.createObjectURL(file)
    setPendingImage(file)
    setPendingImagePreview((current) => {
      if (current?.startsWith('blob:')) {
        URL.revokeObjectURL(current)
      }

      return preview
    })
    setImageRemoveRequested(false)
    setError(null)

    if (imageInputRef.current) {
      imageInputRef.current.value = ''
    }
  }

  async function requestProductImageRemoval() {
    const hasImage = Boolean(
      pendingImagePreview ||
      (!imageRemoveRequested && selected?.image_url),
    )

    if (!hasImage || !canSave) return

    if (!(await askConfirm('Remove this product image when you save the product?'))) {
      return
    }

    setPendingImage(null)
    setPendingImagePreview((current) => {
      if (current?.startsWith('blob:')) {
        URL.revokeObjectURL(current)
      }

      return null
    })
    setImageRemoveRequested(Boolean(selected?.image_url))
    setError(null)

    if (imageInputRef.current) {
      imageInputRef.current.value = ''
    }
  }

  function resetForm() {
    setName('')
    setAlternateName('')
    setSku('')
    setCategoryUlid('')
    setSubcategoryUlid('')
    setBrandUlid('')
    setBarcodeGroupUlid('')
    setPrimarySupplierUlid('')
    setSupplierProductCode('')
    setBaseUnitUlid(units.data?.[0]?.ulid ?? '')
    setReorderLevel('')
    setRackLocation('')
    setTaxPercent('0')
    setIsPackaging(false)
    setMaxFreeQtyPerSale('')
    setRetail('0.0000')
    setWholesale('0.0000')
    setMinimumSale('0.0000')
    setBarcodeRows([])
    setSelectedBarcodeId(null)
    clearPendingImageState()
    setOpeningQty('')
    setOpeningUnitCost('0.0000')
    setOpeningDocument(null)
    setError(null)
  }

  function startNewProduct() {
    if (!canCreate) return
    setCreating(true)
    setSelectedKey(null)
    resetForm()
    setSection('definition')
  }

  function addBarcodeRow() {
    if (!canSave || !canBarcodes) return

    const defaultUnitUlid =
      baseUnitUlid ||
      units.data?.find((unit) => unit.is_active)?.ulid ||
      units.data?.[0]?.ulid ||
      ''

    const row = createBarcodeDraft(
      defaultUnitUlid,
      '1.00000000',
      barcodeRows.length === 0,
    )

    setBarcodeRows((current) => [...current, row])
    setSelectedBarcodeId(row.id)
  }

  function updateBarcodeRow(
    id: string,
    patch: Partial<Omit<ProductBarcodeDraft, 'id'>>,
  ) {
    setBarcodeRows((current) =>
      current.map((row) => (row.id === id ? { ...row, ...patch } : row)),
    )
  }

  function makePrimaryBarcode(id: string) {
    setBarcodeRows((current) =>
      current.map((row) => ({
        ...row,
        is_primary: row.id === id,
      })),
    )
    setSelectedBarcodeId(id)
  }

  function deleteSelectedBarcode() {
    if (!canSave || !canBarcodes || !selectedBarcodeId) return

    const currentIndex = barcodeRows.findIndex(
      (row) => row.id === selectedBarcodeId,
    )
    if (currentIndex < 0) return

    const deletingPrimary = barcodeRows[currentIndex]?.is_primary ?? false
    const nextRows = barcodeRows.filter((row) => row.id !== selectedBarcodeId)

    if (
      deletingPrimary &&
      nextRows.length > 0 &&
      !nextRows.some((row) => row.is_primary)
    ) {
      nextRows[0] = { ...nextRows[0], is_primary: true }
    }

    setBarcodeRows(nextRows)
    setSelectedBarcodeId(
      nextRows[Math.min(currentIndex, nextRows.length - 1)]?.id ?? null,
    )
  }

  function selectBarcodeAt(index: number) {
    if (barcodeRows.length === 0) return
    const safeIndex = Math.min(Math.max(index, 0), barcodeRows.length - 1)
    setSelectedBarcodeId(barcodeRows[safeIndex].id)
  }

  useEffect(() => {
    if (creating) return

    if (products.length === 0) {
      setSelectedKey(null)
      return
    }

    if (!selectedKey || !products.some((product) => product.ulid === selectedKey)) {
      setSelectedKey(products[0].ulid)
    }
  }, [creating, products, selectedKey])

  useEffect(() => {
    if (creating || !selected) return

    setName(selected.name ?? '')
    setAlternateName(selected.alternate_name ?? '')
    setSku(selected.sku ?? '')
    setCategoryUlid(selected.category?.ulid ?? '')
    setSubcategoryUlid(selected.subcategory?.ulid ?? '')
    setBrandUlid(selected.brand?.ulid ?? '')
    setBarcodeGroupUlid(selected.barcode_group?.ulid ?? '')
    setPrimarySupplierUlid(selected.primary_supplier?.ulid ?? '')
    setSupplierProductCode(selected.supplier_product_code ?? '')
    setBaseUnitUlid(selected.base_unit?.ulid ?? '')
    setReorderLevel(selected.reorder_level ?? '')
    setRackLocation(selected.rack_location ?? '')
    setTaxPercent(selected.tax_percent ?? '0')
    setIsPackaging(selected.is_packaging ?? false)
    setMaxFreeQtyPerSale(selected.max_free_qty_per_sale ?? '')
    setRetail(selected.prices?.find((row) => row.price_type === 'retail')?.amount ?? '0.0000')
    setWholesale(selected.prices?.find((row) => row.price_type === 'wholesale')?.amount ?? '0.0000')
    setMinimumSale(selected.prices?.find((row) => row.price_type === 'minimum_sale')?.amount ?? '0.0000')

    clearPendingImageState()

    const loadedBarcodes: ProductBarcodeDraft[] = (selected.barcodes ?? [])
      .filter((row) => row.is_active)
      .map((row) => ({
        id: `saved-${row.ulid}`,
        barcode: row.barcode,
        unit_ulid: row.unit?.ulid ?? selected.base_unit?.ulid ?? '',
        conversion_factor: row.conversion_factor || '1.00000000',
        is_primary: row.is_primary,
      }))

    setBarcodeRows(loadedBarcodes)
    setSelectedBarcodeId(loadedBarcodes[0]?.id ?? null)
    setError(null)
  }, [creating, selected])

  useEffect(() => {
    if (!openingWarehouseUlid && session?.warehouse.ulid) {
      setOpeningWarehouseUlid(session.warehouse.ulid)
    }
  }, [openingWarehouseUlid, session?.warehouse.ulid])

  useEffect(() => {
    if (creating || !selectedKey || !openingWarehouseUlid || !canOpeningView) {
      setOpeningDocument(null)
      setOpeningQty('')
      setOpeningUnitCost('0.0000')
      return
    }

    let cancelled = false
    void (async () => {
      try {
        const docs = await fetchOpeningBalances({
          product_ulid: selectedKey,
          warehouse_ulid: openingWarehouseUlid,
        })
        if (cancelled) return
        const posted = docs.find((doc) => doc.status === 'posted')
        const draft = docs.find((doc) => doc.status === 'draft')
        const chosen = posted ?? draft ?? null
        setOpeningDocument(chosen)
        const line = chosen?.lines?.find((row) => row.product?.ulid === selectedKey)
        setOpeningQty(line?.quantity ?? '')
        setOpeningUnitCost(line?.unit_cost ?? '0.0000')
      } catch {
        if (!cancelled) {
          setOpeningDocument(null)
        }
      }
    })()

    return () => {
      cancelled = true
    }
  }, [creating, selectedKey, openingWarehouseUlid, canOpeningView])

  async function saveOpeningDraft() {
    if (!selectedKey || creating || !openingWarehouseUlid) return
    if (!canOpeningCreate && !canOpeningEdit) return
    setOpeningBusy(true)
    setError(null)
    try {
      let document = openingDocument
      if (!document || document.status === 'posted') {
        document = await createOpeningBalance({ warehouse_ulid: openingWarehouseUlid })
      }

      const existingLine = document.lines?.find((line) => line.product?.ulid === selectedKey)
      if (existingLine) {
        await updateOpeningBalanceLine(document.ulid, existingLine.ulid, {
          product_ulid: selectedKey,
          quantity: openingQty,
          unit_cost: openingUnitCost,
        })
      } else {
        await createOpeningBalanceLine(document.ulid, {
          product_ulid: selectedKey,
          quantity: openingQty,
          unit_cost: openingUnitCost,
        })
      }

      const refreshed = await fetchOpeningBalances({
        product_ulid: selectedKey,
        warehouse_ulid: openingWarehouseUlid,
      })
      const next = refreshed.find((doc) => doc.ulid === document?.ulid) ?? refreshed[0] ?? null
      setOpeningDocument(next)
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to save opening balance.')
    } finally {
      setOpeningBusy(false)
    }
  }

  async function postOpeningDraft() {
    if (!openingDocument || openingDocument.status === 'posted') return
    if (!canOpeningPost) return
    if (!(await askConfirm('Post this opening balance? Stock will update and the document becomes read-only.'))) {
      return
    }
    setOpeningBusy(true)
    setError(null)
    try {
      if (!openingLine) {
        await saveOpeningDraft()
      }
      const docs = await fetchOpeningBalances({
        product_ulid: selectedKey ?? undefined,
        warehouse_ulid: openingWarehouseUlid,
        status: 'draft',
      })
      const draft = docs.find((doc) => doc.ulid === openingDocument.ulid) ?? docs[0]
      if (!draft) {
        throw new Error('Draft opening balance not found.')
      }
      const posted = await postOpeningBalance(draft.ulid)
      setOpeningDocument(posted)
      await queryClient.invalidateQueries({ queryKey: ['product-stock', selectedKey] })
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to post opening balance.')
    } finally {
      setOpeningBusy(false)
    }
  }

  const saveMutation = useMutation({
    mutationFn: async () => {
      const current = selected
      const trimmedName = name.trim()
      if (!trimmedName) {
        throw new Error('Product name is required.')
      }

      const payload = {
        name: trimmedName,
        alternate_name: alternateName || null,
        sku: sku || null,
        category_ulid: categoryUlid || null,
        subcategory_ulid: subcategoryUlid || null,
        brand_ulid: brandUlid || null,
        barcode_group_ulid: barcodeGroupUlid || null,
        primary_supplier_ulid: primarySupplierUlid || null,
        supplier_product_code: supplierProductCode || null,
        base_unit_ulid: baseUnitUlid || units.data?.[0]?.ulid,
        secondary_unit_ulid: current?.secondary_unit?.ulid ?? null,
        secondary_conversion_factor: current?.secondary_conversion_factor ?? null,
        tax_percent: taxPercent,
        track_batch: current?.track_batch ?? false,
        track_expiry: current?.track_expiry ?? false,
        is_packaging: isPackaging,
        max_free_qty_per_sale: isPackaging && maxFreeQtyPerSale ? maxFreeQtyPerSale : null,
        reorder_level: reorderLevel || null,
        minimum_stock: current?.minimum_stock ?? null,
        maximum_stock: current?.maximum_stock ?? null,
        rack_location: rackLocation || null,
      }

      const saved = creating
        ? await createProduct(payload)
        : await updateProduct(selectedKey ?? '', payload)

      if (canPrices) {
        await saveProductPrices(saved.ulid, [
          { price_type: 'retail', amount: retail || '0.0000' },
          { price_type: 'wholesale', amount: wholesale || '0.0000' },
          { price_type: 'minimum_sale', amount: minimumSale || '0.0000' },
        ])
      }

      if (canBarcodes) {
        const barcodes = barcodeRows
          .map((row) => ({
            barcode: row.barcode.trim(),
            unit_ulid: row.unit_ulid,
            conversion_factor: row.conversion_factor.trim() || '1.00000000',
            is_primary: row.is_primary,
          }))
          .filter((row) => row.barcode !== '')

        // Name-only products are allowed — skip barcode API when nothing entered.
        // On edit, still sync an empty list so removed barcodes are cleared.
        if (barcodes.length > 0 || !creating) {
          const duplicateBarcode = barcodes.find(
            (row, index) =>
              barcodes.findIndex(
                (candidate) =>
                  candidate.barcode.toLowerCase() === row.barcode.toLowerCase(),
              ) !== index,
          )

          if (duplicateBarcode) {
            throw new Error(`Duplicate barcode: ${duplicateBarcode.barcode}`)
          }

          for (const row of barcodes) {
            if (!row.unit_ulid) {
              throw new Error(`Select a unit for barcode ${row.barcode}.`)
            }

            const factor = Number(row.conversion_factor)
            if (!Number.isFinite(factor) || factor <= 0) {
              throw new Error(
                `Factor for barcode ${row.barcode} must be greater than zero.`,
              )
            }
          }

          if (barcodes.length > 0 && !barcodes.some((row) => row.is_primary)) {
            barcodes[0] = { ...barcodes[0], is_primary: true }
          }

          await saveProductBarcodes(saved.ulid, barcodes)
        }
      }

      let finalSaved = saved

      if (pendingImage) {
        finalSaved = await uploadProductImage(saved.ulid, pendingImage)
      } else if (imageRemoveRequested && current?.image_url) {
        finalSaved = await deleteProductImage(saved.ulid)
      }

      return finalSaved
    },
    onSuccess: async (saved) => {
      clearPendingImageState()
      setCreating(false)
      setSelectedKey(saved.ulid)
      queryClient.setQueryData(['product', saved.ulid], saved)
      await queryClient.invalidateQueries({ queryKey: ['products'] })
      await queryClient.invalidateQueries({ queryKey: ['product', saved.ulid] })
    },
  })

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSave) return

    setError(null)

    try {
      await saveMutation.mutateAsync()
    } catch (err) {
      setError(
        err instanceof ApiClientError
          ? err.message
          : err instanceof Error
            ? err.message
            : 'Unable to save product.',
      )
    }
  }

  useWorkspaceHandlers({
    save: () => {
      ; (document.getElementById('inline-product-form') as HTMLFormElement | null)?.requestSubmit()
    },
    refresh: () => {
      void productsQuery.refetch()
      if (canCreate) {
        startNewProduct()
      } else if (selectedKey) {
        void productQuery.refetch()
      }
    },
  })

  const gridRows = useMemo(() => products, [products])
  const total = productsQuery.data?.meta.total ?? products.length
  const currentPage = productsQuery.data?.meta.current_page ?? 1
  const lastPage = productsQuery.data?.meta.last_page ?? 1
  const productNumber = creating ? 'NEW' : selected?.product_number ?? ''
  const selectedBarcodeIndex = barcodeRows.findIndex(
    (row) => row.id === selectedBarcodeId,
  )
  const productImagePreview =
    pendingImagePreview ||
    (!imageRemoveRequested ? selected?.image_url ?? null : null)

  const printableBarcodes = barcodeRows
    .filter((row) => row.barcode.trim() !== '')
    .map((row) => ({
      id: row.id,
      barcode: row.barcode.trim(),
      unitCode:
        units.data?.find((unit) => unit.ulid === row.unit_ulid)?.code ?? '',
      conversionFactor: row.conversion_factor,
      isPrimary: row.is_primary,
    }))

  const currentPrintableBarcode =
    printableBarcodes.find((row) => row.id === selectedBarcodeId) ??
    printableBarcodes.find((row) => row.isPrimary) ??
    printableBarcodes[0] ??
    null

  function printCurrentBarcode() {
    if (!currentPrintableBarcode) {
      setError('Select or add a barcode before printing.')
      return
    }

    try {
      const settings = loadBarcodePrintSettings()

      printBarcodeLabels({
        businessName: session?.tenant.name ?? 'BluePOS',
        productName: name || selected?.name || 'Product',
        productNumber,
        barcode: currentPrintableBarcode.barcode,
        unitCode: currentPrintableBarcode.unitCode,
        price: retail,
        currencyCode: session?.tenant.currency_code ?? '',
        copies: 1,
        labelSize: settings.labelSize,
        showPrice: settings.showPrice,
      })

      setError(null)
    } catch (err) {
      setError(
        err instanceof Error ? err.message : 'Unable to print barcode.',
      )
    }
  }

  return (
    <>
      <form id="inline-product-form" className="product-def product-reference-screen" onSubmit={onSubmit}>
        <aside className="product-def-rail" aria-label="Product actions">
          <div
            className={`product-def-photo product-def-image-loader${productImagePreview ? ' has-image' : ''}`}
            role="button"
            tabIndex={canSave ? 0 : -1}
            aria-label={productImagePreview ? 'Change product image' : 'Add product image'}
            title={
              canSave
                ? productImagePreview
                  ? 'Click or right-click to replace product image'
                  : 'Click or right-click to add product image'
                : 'Press Refresh for a new product, or select an editable product first'
            }
            onClick={openImagePicker}
            onContextMenu={(event) => {
              event.preventDefault()
              openImagePicker()
            }}
            onKeyDown={(event) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                openImagePicker()
              }
            }}
          >
            <input
              ref={imageInputRef}
              type="file"
              className="product-def-image-input"
              accept="image/jpeg,image/png,image/webp"
              tabIndex={-1}
              aria-hidden="true"
              onChange={(event) =>
                onProductImageSelected(event.target.files?.[0] ?? null)
              }
            />

            {productImagePreview ? (
              <img
                src={productImagePreview}
                alt={name ? `${name} product` : 'Product'}
                className="product-def-image-preview"
              />
            ) : (
              <span className="product-def-image-empty" aria-hidden="true">
                <span className="product-def-image-icon">
                  <ImagePlus size={28} strokeWidth={1.7} />
                </span>
              </span>
            )}

            {productImagePreview && canSave ? (
              <button
                type="button"
                className="product-def-image-remove"
                title="Remove product image"
                aria-label="Remove product image"
                onClick={(event) => {
                  event.stopPropagation()
                  void requestProductImageRemoval()
                }}
              >
                <X size={14} strokeWidth={3} />
              </button>
            ) : null}
          </div>

          <button
            type="button"
            className="product-def-rail-btn"
            disabled={
              creating ||
              !selectedKey ||
              printableBarcodes.length === 0
            }
            title={
              creating
                ? 'Save the product first'
                : printableBarcodes.length > 0
                  ? 'Open full barcode printing workspace'
                  : 'Add a barcode first'
            }
            onClick={() => {
              if (!selectedKey) return
              openModule(`/definition/barcode-printing/${selectedKey}`)
            }}
          >
            <Barcode size={18} />
            <span>Barcode Print</span>
          </button>

          <button
            type="button"
            className="product-def-rail-btn"
            disabled={!currentPrintableBarcode}
            title={
              currentPrintableBarcode
                ? `Print one label for ${currentPrintableBarcode.barcode}`
                : 'Select or add a barcode first'
            }
            onClick={printCurrentBarcode}
          >
            <Printer size={18} />
            <span>Current Print</span>
          </button>

          <div className="product-def-rail-spacer" />

          <button type="submit" className="product-def-cmd is-save" disabled={!canSave || saveMutation.isPending}>
            <span className="product-def-cmd-icon is-green"><Check size={18} strokeWidth={3} /></span>
            <span className="product-def-cmd-text">
              <span className="product-def-cmd-label">{creating ? 'Create' : 'Save'}</span>
              <span className="product-def-cmd-key">[F9]</span>
            </span>
          </button>

          <button
            type="button"
            className="product-def-cmd is-refresh"
            title={canCreate ? 'Refresh list and start a new product' : 'Refresh product list'}
            onClick={() => {
              void productsQuery.refetch()
              if (canCreate) {
                startNewProduct()
              } else if (selectedKey) {
                void productQuery.refetch()
              }
            }}
          >
            <span className="product-def-cmd-icon is-blue"><RefreshCw size={17} strokeWidth={2.6} /></span>
            <span className="product-def-cmd-text">
              <span className="product-def-cmd-label">Refresh</span>
              <span className="product-def-cmd-key">[F8]</span>
            </span>
          </button>

          <button
            type="button"
            className={`product-def-cmd ${selected && !selected.is_active ? 'is-refresh' : 'is-delete'}`}
            disabled={
              creating ||
              !selected ||
              (
                selected.is_active
                  ? !canDelete
                  : !canEdit
              )
            }
            onClick={() => {
              if (!selectedKey || !selected) {
                return
              }

              // ACTIVE PRODUCT -> DEACTIVATE
              if (selected.is_active) {
                void (async () => {
                  if (!(await askConfirm(`Deactivate ${selected.name}?`))) {
                    return
                  }
                  void deactivateProduct(selectedKey)
                    .then(async () => {
                      await queryClient.invalidateQueries({
                        queryKey: ['products'],
                      })

                      await queryClient.invalidateQueries({
                        queryKey: ['product', selectedKey],
                      })
                    })
                    .catch((err) => {
                      setError(
                        err instanceof ApiClientError
                          ? err.message
                          : 'Unable to deactivate product.',
                      )
                    })
                })()
                return
              }

              // DISCONTINUED PRODUCT -> ACTIVATE
              void updateProduct(
                selectedKey,
                {
                  is_active: true,
                },
              )
                .then(async () => {
                  await queryClient.invalidateQueries({
                    queryKey: ['products'],
                  })

                  await queryClient.invalidateQueries({
                    queryKey: ['product', selectedKey],
                  })
                })
                .catch((err) => {
                  setError(
                    err instanceof ApiClientError
                      ? err.message
                      : 'Unable to activate product.',
                  )
                })
            }}
          >
            <span
              className={
                selected && !selected.is_active
                  ? 'product-def-cmd-icon is-blue'
                  : 'product-def-cmd-icon is-red'
              }
            >
              {selected && !selected.is_active ? (
                <RefreshCw size={17} strokeWidth={2.6} />
              ) : (
                <X size={18} strokeWidth={3.2} />
              )}
            </span>
            <span className="product-def-cmd-text">
              <span className="product-def-cmd-label">
                {selected && !selected.is_active
                  ? 'Activate'
                  : 'Delete'}
              </span>
              <span className="product-def-cmd-key">[F7]</span>
            </span>
          </button>

          <button type="button" className="product-def-cmd is-close" onClick={closeActiveTab}>
            <span className="product-def-cmd-icon is-red-circle"><X size={15} strokeWidth={3.2} /></span>
            <span className="product-def-cmd-text">
              <span className="product-def-cmd-label">Close</span>
              <span className="product-def-cmd-key">[Esc]</span>
            </span>
          </button>
        </aside>

        <section className="product-def-form">
          <h1 className="product-def-title">Products Definition</h1>

          <div className="product-def-tabs">
            <button
              type="button"
              className={section === 'definition' ? 'is-active' : undefined}
              onClick={() => setSection('definition')}
            >
              Definition of Product
            </button>
            <button
              type="button"
              className={section === 'opening' ? 'is-active' : undefined}
              disabled={!canOpeningView}
              title={canOpeningView ? 'Opening Balance' : 'No inventory permission'}
              onClick={() => setSection('opening')}
            >
              Opening Balance (F1)
            </button>
            <button type="button" disabled title="Available in a later phase">Products To Be Used With This Product</button>
          </div>

          {error ? <div className="product-inline-error">{error}</div> : null}

          {section === 'opening' ? (
            <div className="product-def-fields">
              {creating || !selectedKey ? (
                <p className="text-[12px] text-[var(--ui-text-muted)]">Save the product first, then enter opening stock.</p>
              ) : (
                <>
                  <div className="pdf-row">
                    <label>Warehouse</label>
                    <PdfSelect
                      value={openingWarehouseUlid}
                      disabled={openingPosted || openingBusy}
                      onChange={(e) => setOpeningWarehouseUlid(e.target.value)}
                    >
                      {(warehouses.data ?? []).map((row) => (
                        <option key={row.ulid} value={row.ulid}>
                          {row.code} — {row.name}
                        </option>
                      ))}
                    </PdfSelect>
                  </div>
                  <div className="pdf-row pdf-row-rates">
                    <label>Opening Qty</label>
                    <input
                      className="pdf-input pdf-num"
                      value={openingQty}
                      readOnly={openingPosted || openingBusy}
                      onChange={(e) => setOpeningQty(e.target.value)}
                    />
                    <label>Unit Cost</label>
                    <input
                      className="pdf-input pdf-num"
                      value={openingUnitCost}
                      readOnly={openingPosted || openingBusy}
                      onChange={(e) => setOpeningUnitCost(e.target.value)}
                    />
                    <label>Total Cost</label>
                    <input className="pdf-input pdf-num pdf-readonly" readOnly value={openingLine?.total_cost ?? openingTotal} />
                  </div>
                  <div className="pdf-row pdf-row-split">
                    <label>Document Status</label>
                    <input
                      className="pdf-input pdf-readonly"
                      readOnly
                      value={openingDocument?.status === 'posted' ? `Posted (${openingDocument.document_number})` : openingDocument ? `Draft (${openingDocument.document_number})` : 'No draft'}
                    />
                    <label className="pdf-right-label">In Stock</label>
                    <input className="pdf-input pdf-num pdf-readonly" readOnly value={canViewStock ? inStockDisplay : '—'} />
                  </div>
                  <div className="pdf-row" style={{ gap: 8, marginTop: 8 }}>
                    <button
                      type="button"
                      className="desktop-btn"
                      disabled={openingPosted || openingBusy || (!canOpeningCreate && !canOpeningEdit) || !openingQty}
                      onClick={() => void saveOpeningDraft()}
                    >
                      Save Draft
                    </button>
                    <button
                      type="button"
                      className="desktop-btn"
                      disabled={openingPosted || openingBusy || !canOpeningPost || (!openingDocument && !openingQty)}
                      onClick={() => void postOpeningDraft()}
                    >
                      Post Opening Balance
                    </button>
                  </div>
                </>
              )}
            </div>
          ) : (
          <div className="product-def-fields">
            <div className="pdf-row pdf-row-split">
              <label>Product #</label>
              <input className="pdf-input pdf-short" readOnly value={productNumber} />
              <label className="pdf-right-label">CODE:</label>
              <input className="pdf-input" value={sku} readOnly={!canSave} onChange={(e) => setSku(e.target.value)} />
            </div>

            <div className="pdf-row">
              <label>Description</label>
              <input className="pdf-input" value={name} readOnly={!canSave} required onChange={(e) => setName(e.target.value)} />
            </div>

            <div className="pdf-row">
              <label>Alternate Desc</label>
              <input
                className="pdf-input"
                value={alternateName}
                readOnly={!canSave}
                onChange={(e) => setAlternateName(e.target.value)}
              />
            </div>

            <div className="pdf-row pdf-row-split">
              <label>Category</label>
              <div className="pdf-field-plus">
                <PdfSelect
                  value={categoryUlid}
                  disabled={!canSave}
                  onChange={(e) => {
                    setCategoryUlid(e.target.value)
                    setSubcategoryUlid('')
                  }}
                >
                  <option value="">—</option>
                  {(categories.data ?? []).filter((row) => row.is_active || row.ulid === categoryUlid).map((row) => (
                    <option key={row.ulid} value={row.ulid}>{row.name}</option>
                  ))}
                </PdfSelect>
                <button
                  type="button"
                  className="pdf-plus-button"
                  title="Edit / Define Categories"
                  aria-label="Edit or define categories"
                  onClick={() => setQuickEditor('category')}
                >
                  <Plus size={15} strokeWidth={3} />
                </button>
              </div>

              <label className="pdf-right-label">Subcategory</label>
              <div className="pdf-field-plus">
                <PdfSelect
                  value={subcategoryUlid}
                  disabled={!canSave || !categoryUlid}
                  onChange={(e) => setSubcategoryUlid(e.target.value)}
                >
                  <option value="">—</option>
                  {(subcategories.data ?? [])
                    .filter(
                      (row) =>
                        row.is_active ||
                        row.ulid === subcategoryUlid,
                    )
                    .map((row) => (
                      <option key={row.ulid} value={row.ulid}>
                        {row.name}
                      </option>
                    ))}
                </PdfSelect>
                <button
                  type="button"
                  className="pdf-plus-button"
                  title={
                    categoryUlid
                      ? 'Edit / Define Subcategories'
                      : 'Select a category first'
                  }
                  aria-label="Edit or define subcategories"
                  disabled={!canSave || !categoryUlid}
                  onClick={() => setQuickEditor('subcategory')}
                >
                  <Plus size={15} strokeWidth={3} />
                </button>
              </div>
            </div>

            <div className="pdf-row">
              <label>Supplier</label>
              <div className="pdf-field-plus">
                <PdfSelect
                  value={primarySupplierUlid}
                  disabled={!canSave}
                  onChange={(e) => setPrimarySupplierUlid(e.target.value)}
                >
                  <option value="">—</option>
                  {(suppliers.data ?? [])
                    .filter((row) => {
                      if (creating) return row.is_active
                      return row.is_active || row.ulid === primarySupplierUlid
                    })
                    .map((row) => (
                      <option key={row.ulid} value={row.ulid}>{row.name}</option>
                    ))}
                </PdfSelect>
                <button
                  type="button"
                  className="pdf-plus-button"
                  title="Open Vendor / Customer / Accounts"
                  aria-label="Open Vendor Customer Accounts"
                  disabled={!canSave}
                  onClick={() => openModule('/definition/parties')}
                >
                  <Plus size={15} strokeWidth={3} />
                </button>
              </div>
            </div>

            <div className="pdf-row">
              <label>Company</label>
              <div className="pdf-field-plus">
                <PdfSelect
                  value={brandUlid}
                  disabled={!canSave}
                  onChange={(e) => setBrandUlid(e.target.value)}
                >
                  <option value="">—</option>
                  {(brands.data ?? []).filter((row) => row.is_active || row.ulid === brandUlid).map((row) => (
                    <option key={row.ulid} value={row.ulid}>{row.name}</option>
                  ))}
                </PdfSelect>
                <button
                  type="button"
                  className="pdf-plus-button"
                  title="Edit / Define Manufacture"
                  aria-label="Edit or define manufacture"
                  onClick={() => setQuickEditor('brand')}
                >
                  <Plus size={15} strokeWidth={3} />
                </button>
              </div>
            </div>

            <div className="pdf-row pdf-row-split">
              <label>Location</label>
              <input className="pdf-input" value={rackLocation} readOnly={!canSave} onChange={(e) => setRackLocation(e.target.value)} />

              <label className="pdf-right-label">Bar.Grp</label>
              <div className="pdf-field-plus">
                <PdfSelect
                  value={barcodeGroupUlid}
                  disabled={!canSave}
                  onChange={(e) => setBarcodeGroupUlid(e.target.value)}
                >
                  <option value="">—</option>
                  {(barcodeGroups.data ?? [])
                    .filter((row) => row.is_active || row.ulid === barcodeGroupUlid)
                    .map((row) => (
                      <option key={row.ulid} value={row.ulid}>{row.name}</option>
                    ))}
                </PdfSelect>
                <button
                  type="button"
                  className="pdf-plus-button"
                  title="Edit / Define Barcode Groups"
                  aria-label="Edit or define barcode groups"
                  onClick={() => setQuickEditor('barcode_group')}
                >
                  <Plus size={15} strokeWidth={3} />
                </button>
              </div>
            </div>

            <div className="pdf-row">
              <label>Measure Unit</label>
              <div className="pdf-field-plus">
                <PdfSelect
                  value={baseUnitUlid || units.data?.[0]?.ulid || ''}
                  disabled={!canSave}
                  onChange={(e) => setBaseUnitUlid(e.target.value)}
                >
                  {(units.data ?? []).filter((unit) => unit.is_active || unit.ulid === baseUnitUlid).map((unit) => (
                    <option key={unit.ulid} value={unit.ulid}>{unit.code} — {unit.name}</option>
                  ))}
                </PdfSelect>
                <button
                  type="button"
                  className="pdf-plus-button"
                  title="Edit / Define Units"
                  aria-label="Edit or define units"
                  onClick={() => setQuickEditor('unit')}
                >
                  <Plus size={15} strokeWidth={3} />
                </button>
              </div>
            </div>

            <div className="pdf-row pdf-row-rates">
              <label>Reorder Level</label>
              <input className="pdf-input pdf-num" value={reorderLevel} readOnly={!canSave} onChange={(e) => setReorderLevel(e.target.value)} />
              <label>Purchase Rate</label>
              <input
                className="pdf-input pdf-num pdf-readonly"
                readOnly
                value={creating || !selectedKey || !canViewStock ? '0.0000' : purchaseRateDisplay}
                title={canViewStock ? 'Active warehouse average cost (read-only)' : 'Inventory view permission required'}
              />
              <label>Margin %</label>
              <input
                className="pdf-input pdf-num pdf-readonly"
                readOnly
                value={marginDisplay}
                title="(Sale Rate − Purchase Rate) ÷ Sale Rate × 100"
              />
            </div>

            <div className="pdf-row pdf-row-rates">
              <label>Sale Rate</label>
              <input
                className="pdf-input pdf-num"
                value={retail}
                readOnly={!canSave || !canPrices}
                onChange={(e) => onSaleRateChange(e.target.value)}
              />
              <label>Whole Sale</label>
              <input
                className="pdf-input pdf-num"
                value={wholesale}
                readOnly={!canSave || !canPrices}
                onChange={(e) => setWholesale(e.target.value)}
                title="Auto: Sale − 25% of (Sale − Purchase). Editable."
              />
              <label>Min Sale</label>
              <input
                className="pdf-input pdf-num"
                value={minimumSale}
                readOnly={!canSave || !canPrices}
                onChange={(e) => setMinimumSale(e.target.value)}
                title="Auto: Sale − 50% of (Sale − Purchase). Editable."
              />
            </div>

            <div className="pdf-row pdf-row-split">
              <label>In Stock</label>
              <input
                className="pdf-input pdf-num pdf-readonly"
                readOnly
                value={creating || !selectedKey || !canViewStock ? '—' : inStockDisplay}
                title={canViewStock ? 'Active warehouse stock balance' : 'Inventory view permission required'}
              />
              <label className="pdf-right-label">Supplier Code</label>
              <input
                className="pdf-input"
                value={supplierProductCode}
                readOnly={!canSave}
                onChange={(e) => setSupplierProductCode(e.target.value)}
              />
            </div>

            <div className="pdf-row pdf-row-check">
              <label />
              <label className="pdf-check">
                <input type="checkbox" disabled checked={selected ? !selected.is_active : false} readOnly />
                Discontinued
              </label>
              <label className="pdf-right-label">Tax %</label>
              <input className="pdf-input pdf-num" value={taxPercent} readOnly={!canSave} onChange={(e) => setTaxPercent(e.target.value)} />
            </div>

            <div className="pdf-row pdf-row-check">
              <label />
              <label className="pdf-check">
                <input
                  type="checkbox"
                  disabled={!canSave}
                  checked={isPackaging}
                  onChange={(e) => setIsPackaging(e.target.checked)}
                />
                Packaging (bag / box / free-issue)
              </label>
              <label className="pdf-right-label">Max free/sale</label>
              <input
                className="pdf-input pdf-num"
                value={maxFreeQtyPerSale}
                readOnly={!canSave || !isPackaging}
                placeholder={isPackaging ? 'e.g. 3' : '—'}
                onChange={(e) => setMaxFreeQtyPerSale(e.target.value)}
              />
            </div>

            <div className="pdf-barcode-box">
              <div className="pdf-barcode-title">Multi Barcode Entry</div>
              <div className="pdf-barcode-table-scroll">
                <table className="pdf-barcode-table">
                  <thead>
                  <tr>
                    <th>Barcode</th>
                    <th>Unit</th>
                    <th>Factor</th>
                    <th>Primary</th>
                  </tr>
                </thead>
                <tbody>
                  {barcodeRows.length === 0 ? (
                    <tr>
                      <td colSpan={4} className="pdf-barcode-empty">
                        {canSave && canBarcodes
                          ? 'Click + to add a barcode'
                          : 'No barcode entries'}
                      </td>
                    </tr>
                  ) : (
                    barcodeRows.map((row) => (
                      <tr
                        key={row.id}
                        className={
                          row.id === selectedBarcodeId
                            ? 'is-selected'
                            : undefined
                        }
                        onClick={() => setSelectedBarcodeId(row.id)}
                      >
                        <td>
                          <input
                            className="pdf-barcode-input"
                            value={row.barcode}
                            readOnly={!canSave || !canBarcodes}
                            placeholder="Barcode"
                            onFocus={() => setSelectedBarcodeId(row.id)}
                            onChange={(e) =>
                              updateBarcodeRow(row.id, {
                                barcode: e.target.value,
                              })
                            }
                          />
                        </td>
                        <td>
                          <PdfSelect
                            className="pdf-barcode-input"
                            value={row.unit_ulid}
                            disabled={!canSave || !canBarcodes}
                            onFocus={() => setSelectedBarcodeId(row.id)}
                            onChange={(e) =>
                              updateBarcodeRow(row.id, {
                                unit_ulid: e.target.value,
                              })
                            }
                          >
                            <option value="">—</option>
                            {(units.data ?? [])
                              .filter(
                                (unit) =>
                                  unit.is_active ||
                                  unit.ulid === row.unit_ulid,
                              )
                              .map((unit) => (
                                <option key={unit.ulid} value={unit.ulid}>
                                  {unit.code}
                                </option>
                              ))}
                          </PdfSelect>
                        </td>
                        <td>
                          <input
                            className="pdf-barcode-input pdf-barcode-factor"
                            value={row.conversion_factor}
                            readOnly={!canSave || !canBarcodes}
                            inputMode="decimal"
                            onFocus={() => setSelectedBarcodeId(row.id)}
                            onChange={(e) =>
                              updateBarcodeRow(row.id, {
                                conversion_factor: e.target.value,
                              })
                            }
                          />
                        </td>
                        <td className="is-center">
                          <input
                            type="radio"
                            name="product-primary-barcode"
                            checked={row.is_primary}
                            disabled={!canSave || !canBarcodes}
                            aria-label={`Set ${row.barcode || 'barcode'} as primary`}
                            onChange={() => makePrimaryBarcode(row.id)}
                          />
                        </td>
                      </tr>
                    ))
                  )}
                  </tbody>
                </table>
              </div>

              <div className="pdf-barcode-nav">
                <button
                  type="button"
                  title="First barcode"
                  disabled={barcodeRows.length === 0 || selectedBarcodeIndex <= 0}
                  onClick={() => selectBarcodeAt(0)}
                >
                  <ChevronFirst size={14} />
                </button>
                <button
                  type="button"
                  title="Previous barcode"
                  disabled={barcodeRows.length === 0 || selectedBarcodeIndex <= 0}
                  onClick={() => selectBarcodeAt(selectedBarcodeIndex - 1)}
                >
                  <ChevronLeft size={14} />
                </button>
                <span>
                  Record {selectedBarcodeIndex >= 0 ? selectedBarcodeIndex + 1 : 0} of {barcodeRows.length}
                </span>
                <button
                  type="button"
                  title="Next barcode"
                  disabled={
                    barcodeRows.length === 0 ||
                    selectedBarcodeIndex < 0 ||
                    selectedBarcodeIndex >= barcodeRows.length - 1
                  }
                  onClick={() => selectBarcodeAt(selectedBarcodeIndex + 1)}
                >
                  <ChevronRight size={14} />
                </button>
                <button
                  type="button"
                  title="Last barcode"
                  disabled={
                    barcodeRows.length === 0 ||
                    selectedBarcodeIndex < 0 ||
                    selectedBarcodeIndex >= barcodeRows.length - 1
                  }
                  onClick={() => selectBarcodeAt(barcodeRows.length - 1)}
                >
                  <ChevronLast size={14} />
                </button>
                <button
                  type="button"
                  title="Add barcode"
                  disabled={!canSave || !canBarcodes}
                  onClick={addBarcodeRow}
                >
                  <Plus size={14} />
                </button>
                <button
                  type="button"
                  title="Delete selected barcode"
                  disabled={!canSave || !canBarcodes || !selectedBarcodeId}
                  onClick={deleteSelectedBarcode}
                >
                  <Trash2 size={14} />
                </button>
              </div>
            </div>
          </div>
          )}
        </section>

        <section className="product-def-grid">
          <div className="product-def-grid-top">
            <div className="product-def-grid-search">
              <input
                className="pdf-input"
                placeholder="Search products..."
                value={q}
                onChange={(e) => {
                  setQ(e.target.value)
                  setPage(1)
                }}
              />
            </div>
          </div>

          <div className="product-def-groupbar">
            <span className="product-def-groupbar-label">Category / Group</span>
            <PdfSelect
              className="product-def-groupbar-select"
              value={listCategoryUlid}
              aria-label="Filter products by category"
              onChange={(e) => {
                setListCategoryUlid(e.target.value)
                setPage(1)
              }}
            >
              <option value="">All Products</option>
              {(categories.data ?? [])
                .filter((row) => row.is_active || row.ulid === listCategoryUlid)
                .map((row) => (
                  <option key={row.ulid} value={row.ulid}>
                    {row.name}
                  </option>
                ))}
            </PdfSelect>
          </div>

          <PosDataGrid
            columns={[
              { key: 'no', header: 'No', width: 64, render: (row: Product) => row.product_number },
              { key: 'name', header: 'Name', render: (row: Product) => row.name },
              {
                key: 'stock',
                header: 'In Stock',
                width: 88,
                align: 'right',
                render: (row: Product) =>
                  row.ulid === selectedKey && canViewStock && !creating ? (
                    inStockDisplay
                  ) : (
                    <span className="stock-na" title="Select product to view active warehouse stock">—</span>
                  ),
              },
            ]}
            rows={gridRows}
            rowKey={(row) => row.ulid}
            selectedKey={creating ? null : selectedKey}
            onSelect={(row) => {
              setCreating(false)
              setSelectedKey(row.ulid)
            }}
            onActivate={(row) => {
              setCreating(false)
              setSelectedKey(row.ulid)
            }}
            emptyMessage="No products found."
          />

          <div className="product-def-grid-foot">
            <span>{total} Products</span>
            <span className="product-def-pager">
              <button type="button" disabled={currentPage <= 1} onClick={() => setPage((n) => n - 1)}>&lt;</button>
              <span>{currentPage}/{lastPage}</span>
              <button type="button" disabled={currentPage >= lastPage} onClick={() => setPage((n) => n + 1)}>&gt;</button>
            </span>
          </div>
        </section>
      </form>

      <CatalogQuickEditorModal
        kind={quickEditor}
        open={quickEditor !== null}
        parentCategoryUlid={categoryUlid}
        onClose={() => setQuickEditor(null)}
        onSaved={(kind, item) => {
          if (kind === 'category') {
            setCategoryUlid(item.ulid)
            setSubcategoryUlid('')
          } else if (kind === 'subcategory') {
            const sub = item as { category_ulid?: string | null; category?: { ulid: string } | null }
            const nextCategory = sub.category_ulid ?? sub.category?.ulid
            if (nextCategory) setCategoryUlid(nextCategory)
            setSubcategoryUlid(item.ulid)
          } else if (kind === 'brand') {
            setBrandUlid(item.ulid)
          } else if (kind === 'unit') {
            setBaseUnitUlid(item.ulid)
          } else if (kind === 'barcode_group') {
            setBarcodeGroupUlid(item.ulid)
          }
        }}
      />
    </>
  )
}
