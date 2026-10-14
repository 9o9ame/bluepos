import { FormEvent, useEffect, useMemo, useRef, useState, type CSSProperties, type KeyboardEvent as ReactKeyboardEvent, type ReactNode } from 'react'
import {
  Plus,
  Printer,
  RefreshCw,
  RotateCcw,
  Save,
  Send,
  Settings2,
  Trash2,
  X,
  XCircle,
} from 'lucide-react'
import { useInfiniteQuery, useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { fetchBusinessSettings, fetchProduct } from '../api/catalog'
import { ApiClientError } from '../api/client'
import { askConfirm } from '../feedback/FeedbackProvider'
import { fetchProductStock, fetchWarehouses } from '../api/inventory'
import {
  createPurchase,
  createPurchaseLine,
  createPurchasePayment,
  deletePurchaseLine,
  fetchPurchase,
  fetchPurchaseOrder,
  fetchPurchases,
  postPurchase,
  updatePurchase,
  updatePurchaseLine,
} from '../api/purchases'
import { DesktopButton, DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiModal } from '../components/ui/UiModal'
import { UiSelect } from '../components/ui/UiSelect'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { ColumnCustomizationPanel } from '../features/gridLayout/ColumnCustomizationPanel'
import {
  PURCHASE_INVOICE_COLUMNS,
  PURCHASE_INVOICE_SCREEN,
  type ResolvedGridColumn,
} from '../features/gridLayout/columnCatalog'
import { useColumnLayout } from '../features/gridLayout/useColumnLayout'
import { useLocalProducts, useLocalSuppliers } from '../features/catalog/useLocalCatalog'
import { refreshSuppliers, upsertCachedProduct } from '../data/catalogCache'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Product } from '../types/catalog'
import type {
  PurchaseInvoice,
  PurchaseInvoiceLine,
  PurchasePaymentMethod,
} from '../types/purchases'
import { PartyModal } from '../components/parties/PartyModal'
import { ProductModal } from '../components/products/ProductModal'
import { ProductLookupGrid } from '../features/sales/ProductLookupGrid'
import { SupplierLookupGrid } from '../features/catalog/SupplierLookupGrid'
import {
  applyCalcToLine,
  computeLine,
  sumHeaderCharges,
  type DualMode,
  type PurchaseCalcSettings,
} from './purchaseLineCalc'
import './PurchasesPage.theme.css'
import './PurchasesPage.entry.css'

type UnitOption = {
  ulid: string
  code: string
  conversion_factor: string
}

type DraftLine = {
  key: string
  ulid?: string
  purchase_order_line_ulid?: string | null
  product_ulid: string
  product_label: string
  item_code: string
  brand_label: string
  hs_code: string
  pack_size: string
  qty_ctn: string
  free_pcs: string
  price_type: string
  mrp: string
  trade_disc_pct: string
  regular_disc_pct: string
  special_disc_pct: string
  further_tax_pct: string
  tax_pct: string
  unit_ulid: string
  unit_label: string
  unit_options: UnitOption[]
  quantity: string
  conversion_factor: string
  unit_cost: string
  discount_amount: string
  tax_amount: string
  supplier_product_code: string
  batch_number: string
  expiry_date: string
  notes: string
  location_label: string
  track_batch: boolean
  track_expiry: boolean
  line_total?: string
  base_quantity?: string
  further_tax_amount?: string
  disc_after_gst_pct?: string
  disc_after_gst_rs?: string
  advance_tax_pct?: string
  advance_tax_amount?: string
  regular_disc_rs?: string
  special_disc_rs?: string
  sale_price?: string
  category_ulid?: string
}

function emptyShellFields(): Pick<
  DraftLine,
  | 'item_code'
  | 'brand_label'
  | 'hs_code'
  | 'pack_size'
  | 'qty_ctn'
  | 'free_pcs'
  | 'price_type'
  | 'mrp'
  | 'trade_disc_pct'
  | 'regular_disc_pct'
  | 'special_disc_pct'
  | 'further_tax_pct'
  | 'tax_pct'
> {
  return {
    item_code: '',
    brand_label: '',
    hs_code: '',
    pack_size: '',
    qty_ctn: '0',
    free_pcs: '0',
    price_type: 'trade',
    mrp: '0.0000',
    trade_disc_pct: '0',
    regular_disc_pct: '0',
    special_disc_pct: '0',
    further_tax_pct: '0',
    tax_pct: '0',
  }
}

function todayIso() {
  return new Date().toISOString().slice(0, 10)
}

function normalizeCalcMethod(value: string | null | undefined): string {
  const legacy: Record<string, string> = {
    gst_on_retail: 'mrp_incl_gst',
    gst_on_trade: 'trade_after_disc',
    disc_then_gst: 'trade_after_disc',
    gst_inclusive: 'mrp_incl_gst',
    no_gst: 'mrp_ex_gst',
  }
  const v = value ?? 'trade_after_disc'
  return legacy[v] ?? v
}

function money(value: string | number | null | undefined) {
  const n = Number(value ?? 0)
  return Number.isFinite(n) ? n.toFixed(4) : '0.0000'
}

function pctFromAmount(base: number, amount: number) {
  if (!Number.isFinite(base) || base <= 0) return '0'
  return ((amount / base) * 100).toFixed(4)
}

function amountFromPct(base: number, pct: number) {
  if (!Number.isFinite(base)) return '0.0000'
  return ((base * pct) / 100).toFixed(4)
}

const DEFAULT_DUAL_MODES: Record<string, DualMode> = {
  regular_disc: 'rs',
  special_disc: 'rs',
  gst: 'rs',
  disc_after_gst: 'rs',
  further_tax: 'rs',
  advance_tax: 'rs',
}

function buildUnitOptions(product: Product): UnitOption[] {
  const map = new Map<string, UnitOption>()
  const push = (ulid: string | undefined, code: string | undefined, factor: string) => {
    if (!ulid || !code) return
    if (!map.has(ulid)) {
      map.set(ulid, { ulid, code, conversion_factor: factor || '1.00000000' })
    }
  }
  push(product.base_unit?.ulid, product.base_unit?.code, '1.00000000')
  push(
    product.secondary_unit?.ulid,
    product.secondary_unit?.code,
    product.secondary_conversion_factor || '1.00000000',
  )
  for (const barcode of product.barcodes ?? []) {
    if (!barcode.is_active || !barcode.unit?.ulid) continue
    push(barcode.unit.ulid, barcode.unit.code, barcode.conversion_factor || '1.00000000')
  }
  return [...map.values()]
}

function preferPcsUnit(
  product: Product,
  query: string,
): { unit_ulid: string; unit_label: string; conversion_factor: string; unit_options: UnitOption[] } {
  const options = buildUnitOptions(product)
  const q = query.trim().toLowerCase()
  const barcodeHit = product.barcodes?.find(
    (b) => b.is_active && b.barcode.toLowerCase() === q && b.unit?.ulid,
  )
  if (barcodeHit?.unit) {
    return {
      unit_ulid: barcodeHit.unit.ulid,
      unit_label: barcodeHit.unit.code,
      conversion_factor: barcodeHit.conversion_factor || '1.00000000',
      unit_options: options,
    }
  }
  const pcs = options.find((option) => option.code.toUpperCase() === 'PCS')
  if (pcs) {
    return {
      unit_ulid: pcs.ulid,
      unit_label: pcs.code,
      conversion_factor: pcs.conversion_factor,
      unit_options: options,
    }
  }
  const base = options[0]
  return {
    unit_ulid: base?.ulid ?? product.base_unit?.ulid ?? '',
    unit_label: base?.code ?? product.base_unit?.code ?? '',
    conversion_factor: base?.conversion_factor ?? '1.00000000',
    unit_options: options,
  }
}

function lineFromServer(line: PurchaseInvoiceLine): DraftLine {
  const unitOptions: UnitOption[] = line.unit
    ? [{ ulid: line.unit.ulid, code: line.unit.code, conversion_factor: line.conversion_factor }]
    : []
  return {
    key: line.ulid,
    ulid: line.ulid,
    purchase_order_line_ulid: line.purchase_order_line_ulid ?? null,
    product_ulid: line.product?.ulid ?? '',
    product_label: line.product
      ? `${line.product.product_number} · ${line.product.name}`
      : '',
    ...emptyShellFields(),
    item_code: line.product?.product_number ?? '',
    brand_label: line.brand_label ?? '',
    hs_code: line.hs_code ?? '',
    pack_size: line.pack_size ?? '',
    qty_ctn: line.qty_ctn ?? '0',
    free_pcs: line.free_pcs ?? '0',
    price_type: line.price_type ?? 'trade',
    mrp: line.mrp ?? '0.0000',
    trade_disc_pct: line.trade_disc_pct ?? '0',
    regular_disc_pct: line.regular_disc_pct ?? '0',
    special_disc_pct: line.special_disc_pct ?? '0',
    further_tax_pct: line.further_tax_pct ?? '0',
    tax_pct: line.tax_pct ?? '0',
    unit_ulid: line.unit?.ulid ?? '',
    unit_label: line.unit?.code ?? '',
    unit_options: unitOptions,
    quantity: line.quantity,
    conversion_factor: line.conversion_factor,
    unit_cost: line.unit_cost,
    discount_amount: line.discount_amount,
    tax_amount: line.tax_amount,
    supplier_product_code: line.supplier_product_code ?? '',
    batch_number: line.batch_number ?? '',
    expiry_date: line.expiry_date ?? '',
    notes: line.notes ?? '',
    location_label: '',
    track_batch: Boolean(line.product?.track_batch),
    track_expiry: Boolean(line.product?.track_expiry),
    line_total: line.line_total,
    base_quantity: line.base_quantity,
    further_tax_amount: line.further_tax_amount ?? '0.0000',
    disc_after_gst_pct: line.disc_after_gst_pct ?? '0',
    disc_after_gst_rs: line.disc_after_gst_amount ?? '0.0000',
    advance_tax_pct: '0',
    advance_tax_amount: '0',
    regular_disc_rs: line.regular_disc_amount ?? '0.0000',
    special_disc_rs: line.special_disc_amount ?? '0.0000',
    sale_price: line.mrp ?? '0.0000',
  }
}

function activeRetailAmount(product: Product): string {
  const retail = product.prices?.find((price) => price.price_type === 'retail' && price.is_active)
  if (retail && Number(retail.amount) > 0) return retail.amount
  return '0.0000'
}

function moneyOrZero(value: string | null | undefined): string {
  const n = Number(value)
  return Number.isFinite(n) && n > 0 ? n.toFixed(4) : '0.0000'
}

export function PurchasesPage() {
  const queryClient = useQueryClient()
  const [searchParams, setSearchParams] = useSearchParams()
  const { closeActiveTab, openModule } = useWorkspace()
  const { session } = useAuth()

  const canCreate = useCan('purchases.create')
  const canEdit = useCan('purchases.edit')
  const canPost = useCan('purchases.post')
  const canCreatePayment = useCan('payments.create')
  const canCreateReturn = useCan('purchase_returns.create')
  const canCreateProduct = useCan('products.create')

  const [mode, setMode] = useState<'list' | 'editor'>('list')
  const [q, setQ] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [supplierFilter, setSupplierFilter] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedListKey, setSelectedListKey] = useState<string | null>(null)

  const [invoiceUlid, setInvoiceUlid] = useState<string | null>(null)
  const [supplierUlid, setSupplierUlid] = useState('')
  const [supplierQuery, setSupplierQuery] = useState('')
  const [supplierMenuOpen, setSupplierMenuOpen] = useState(false)
  const [supplierLookupMode, setSupplierLookupMode] = useState<'browse' | 'search'>('browse')
  const [warehouseUlid, setWarehouseUlid] = useState(session?.warehouse.ulid ?? '')
  const [invoiceDate, setInvoiceDate] = useState(todayIso())
  const [dueDate, setDueDate] = useState('')
  const [supplierInvoiceNumber, setSupplierInvoiceNumber] = useState('')
  const [freightAmount, setFreightAmount] = useState('0.0000')
  const [otherCharges, setOtherCharges] = useState('0.0000')
  const [notes, setNotes] = useState('')
  const [documentNumber, setDocumentNumber] = useState('')
  const [status, setStatus] = useState<'draft' | 'posted' | 'cancelled'>('draft')
  const [lines, setLines] = useState<DraftLine[]>([])
  const [productQuery, setProductQuery] = useState('')
  const [productEditRowKey, setProductEditRowKey] = useState<string | 'new' | null>(null)
  const [productMenuOpen, setProductMenuOpen] = useState(false)
  const [productLookupMode, setProductLookupMode] = useState<'browse' | 'search'>('search')
  const [productModalOpen, setProductModalOpen] = useState(false)
  const [productModalTargetRowKey, setProductModalTargetRowKey] = useState<string | 'new'>('new')
  const [error, setError] = useState<string | null>(null)
  const [customizationOpen, setCustomizationOpen] = useState(false)
  const [supplierModalOpen, setSupplierModalOpen] = useState(false)
  const [paymentModalOpen, setPaymentModalOpen] = useState(false)
  const [paidAmount, setPaidAmount] = useState('0.0000')
  const [serverBalancePayable, setServerBalancePayable] = useState('0.0000')
  const [paymentAmount, setPaymentAmount] = useState('')
  const [paymentMethod, setPaymentMethod] = useState<PurchasePaymentMethod>('cash')
  const [paymentReference, setPaymentReference] = useState('')
  const [paymentIdempotencyKey, setPaymentIdempotencyKey] = useState(() => crypto.randomUUID())
  const [selectedLineKey, setSelectedLineKey] = useState<string | null>(null)
  const [calcMethod, setCalcMethod] = useState('trade_after_disc')
  const [shellPriceType, setShellPriceType] = useState('trade')
  const [shellTaxType, setShellTaxType] = useState('standard')
  const [shellPaymentTerms, setShellPaymentTerms] = useState('credit')
  const [shellPoNo, setShellPoNo] = useState('')
  const [purchaseOrderUlid, setPurchaseOrderUlid] = useState<string | null>(null)
  const [shellInvoiceType, setShellInvoiceType] = useState('tax_gst')
  const [shellCurrency, setShellCurrency] = useState('PKR')
  const [shellBrand, setShellBrand] = useState('')
  const [shellLoading, setShellLoading] = useState('0.0000')
  const [shellOtherDiscount, setShellOtherDiscount] = useState('0.0000')
  const [shellTradeOffer, setShellTradeOffer] = useState('0.0000')
  const [shellAdvanceTax, setShellAdvanceTax] = useState('0.0000')
  const [shellRoundOff, setShellRoundOff] = useState('0.0000')
  const [shellDefaultSalesTax, setShellDefaultSalesTax] = useState('18')
  const [shellDefaultFurtherTax, setShellDefaultFurtherTax] = useState('0')
  const [shellDefaultAdvanceTax, setShellDefaultAdvanceTax] = useState('0')
  const [discountApplyOn, setDiscountApplyOn] = useState('trade')
  const [discInputType, setDiscInputType] = useState<'pct' | 'rs'>('rs')
  const [autoCalcMrp, setAutoCalcMrp] = useState(true)
  const [withholdingIsPct, setWithholdingIsPct] = useState(false)
  const [dualModes, setDualModes] = useState<Record<string, DualMode>>({ ...DEFAULT_DUAL_MODES })
  const autoPurchaseOrderAttemptedRef = useRef('')
  const productSearchRef = useRef<HTMLInputElement | null>(null)
  const productPickerAnchorRef = useRef<HTMLDivElement | null>(null)
  const supplierPickerAnchorRef = useRef<HTMLDivElement | null>(null)

  const calcSettings: PurchaseCalcSettings = useMemo(
    () => ({ calcMethod, discountApplyOn, dualModes }),
    [calcMethod, discountApplyOn, dualModes],
  )
  const canSaveRoleDefault = useCan('roles.edit')
  const columnLayout = useColumnLayout({
    screenKey: PURCHASE_INVOICE_SCREEN,
    catalog: PURCHASE_INVOICE_COLUMNS,
  })

  const readOnly = status === 'posted' || status === 'cancelled'

  const listQuery = useInfiniteQuery({
    queryKey: ['purchases', q, statusFilter, supplierFilter, dateFrom, dateTo],
    initialPageParam: 1,
    queryFn: ({ pageParam }) =>
      fetchPurchases({
        q: q || undefined,
        status: statusFilter || undefined,
        supplier_ulid: supplierFilter || undefined,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        page: pageParam,
        per_page: 50,
      }),
    getNextPageParam: (lastPage) =>
      lastPage.meta.current_page < lastPage.meta.last_page
        ? lastPage.meta.current_page + 1
        : undefined,
    enabled: mode === 'list',
  })

  const businessSettingsQuery = useQuery({
    queryKey: ['settings', 'business', 'purchase-options'],
    queryFn: fetchBusinessSettings,
    retry: false,
  })

  const suppliersQuery = useLocalSuppliers(
    session,
    true,
  )

  const warehousesQuery = useQuery({
    queryKey: ['warehouses'],
    queryFn: fetchWarehouses,
  })

  const productLookupQuery =
    productLookupMode === 'search'
      ? productQuery.includes(' · ')
        ? productQuery.split(' · ').slice(1).join(' · ')
        : productQuery
      : ''

  const productLookup = useLocalProducts(
    session,
    productLookupQuery,
    mode === 'editor' && !readOnly,
  )

  const productLookupRows = productLookup.rows
  const productLookupTotal = productLookup.total

  const listRows = listQuery.data?.pages.flatMap((page) => page.data) ?? []
  const listTotal = listQuery.data?.pages[0]?.meta.total ?? 0
  const suppliers = suppliersQuery.rows.filter((s) => s.is_active)
  const warehouses = (warehousesQuery.data ?? []).filter(
    (w) => w.status === 'active' && w.ulid === session?.warehouse.ulid,
  )
  const selectedSupplier = suppliers.find((supplier) => supplier.ulid === supplierUlid)
  const supplierLookupRows = useMemo(() => {
    const query =
      supplierLookupMode === 'search'
        ? supplierQuery.trim().toLocaleLowerCase()
        : ''
    if (!query) return suppliers

    return suppliers.filter((supplier) =>
      [
        supplier.code,
        supplier.name,
        supplier.address,
        supplier.billing_address,
        supplier.mobile,
        supplier.phone,
      ]
        .filter(Boolean)
        .join(' ')
        .toLocaleLowerCase()
        .includes(query),
    )
  }, [supplierLookupMode, supplierQuery, suppliers])
  const selectedWarehouse = warehouses.find((warehouse) => warehouse.ulid === warehouseUlid)
  const purchaseSoftwareOptions = businessSettingsQuery.data?.software_options?.purchase
  const warehouseLabel = selectedWarehouse
    ? `${selectedWarehouse.code} — ${selectedWarehouse.name}`
    : ''

  const lineProductUlids = useMemo(
    () => [...new Set(lines.map((line) => line.product_ulid).filter(Boolean))],
    [lines],
  )

  const stockQueries = useQueries({
    queries: lineProductUlids.map((productUlid) => ({
      queryKey: ['purchase-line-stock', productUlid, warehouseUlid] as const,
      queryFn: () => fetchProductStock(productUlid),
      enabled: mode === 'editor' && Boolean(productUlid && warehouseUlid),
      staleTime: 30_000,
    })),
  })

  const stockByProduct = useMemo(() => {
    const map = new Map<string, string>()
    lineProductUlids.forEach((productUlid, index) => {
      const payload = stockQueries[index]?.data
      if (!payload) return
      const match = payload.warehouses.find((row) => row.warehouse.ulid === warehouseUlid)
      map.set(productUlid, match?.quantity ?? '0.000000')
    })
    return map
  }, [lineProductUlids, stockQueries, warehouseUlid])

  const effectivePurchaseColumns = useMemo(() => {
    const requireBatch = lines.some((line) => line.track_batch)
    const requireExpiry = lines.some((line) => line.track_expiry)
    const showExpiryBatch = purchaseSoftwareOptions?.show_expiry_batch ?? false
    const showSalesTax = purchaseSoftwareOptions?.show_sales_tax ?? true
    const showBonus = purchaseSoftwareOptions?.show_bonus ?? false

    return columnLayout.columns.filter((col) => {
      if (col.key === 'free_pcs') return showBonus
      if (!col.visible) return false
      if (col.key === 'batch' && !showExpiryBatch && !requireBatch) return false
      if (col.key === 'expiry' && !showExpiryBatch && !requireExpiry) return false
      if (['gst', 'further_tax', 'advance_tax'].includes(col.key) && !showSalesTax) return false
      return true
    })
  }, [
    columnLayout.columns,
    lines,
    purchaseSoftwareOptions?.show_bonus,
    purchaseSoftwareOptions?.show_expiry_batch,
    purchaseSoftwareOptions?.show_sales_tax,
  ])

  function patchLine(key: string, patch: Partial<DraftLine>) {
    setLines((prev) =>
      prev.map((line) => {
        if (line.key !== key) return line
        const merged = { ...line, ...patch }
        return applyCalcToLine(merged, calcSettings)
      }),
    )
  }

  function recalculateAllLines(nextSettings?: PurchaseCalcSettings) {
    const settings = nextSettings ?? calcSettings
    setLines((prev) => prev.map((line) => applyCalcToLine(line, settings)))
  }

  function changeCalcMethod(value: string) {
    const next = { ...calcSettings, calcMethod: value }
    setCalcMethod(value)
    recalculateAllLines(next)
  }

  function changeDiscountApplyOn(value: string) {
    const next = { ...calcSettings, discountApplyOn: value }
    setDiscountApplyOn(value)
    recalculateAllLines(next)
  }

  function setDualMode(key: string, mode: DualMode) {
    const nextModes = { ...dualModes, [key]: mode }
    setDualModes(nextModes)
    recalculateAllLines({ ...calcSettings, dualModes: nextModes })
  }

  function focusNewProductSearch() {
    setProductEditRowKey('new')
    setProductLookupMode('search')
    setProductQuery('')
    setProductMenuOpen(true)
    requestAnimationFrame(() => {
      productSearchRef.current?.focus()
      productSearchRef.current?.select()
    })
  }

  function beginProductEdit(row: DraftLine | null) {
    setProductEditRowKey(row?.key ?? 'new')
    setProductQuery(row?.product_label ?? '')

    if (!row) {
        setProductLookupMode('search')
      setProductMenuOpen(true)
      return
    }

    setProductLookupMode('browse')
    setProductMenuOpen(true)
  }

  function focusFirstEditableInRow(rowKey: string) {
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        const row = document.querySelector<HTMLElement>(`tr[data-purchase-row-key="${rowKey}"]`)
        const first = row?.querySelector<HTMLElement>(
          '[data-purchase-edit="true"]:not([data-purchase-product="true"]):not(:disabled)',
        )
        first?.focus()
        if (first instanceof HTMLInputElement) first.select()
      })
    })
  }

  function handleLineEnter(event: ReactKeyboardEvent<HTMLElement>) {
    if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) return

    const row = event.currentTarget.closest('tr')
    if (!row) return

    const editable = [...row.querySelectorAll<HTMLElement>('[data-purchase-edit="true"]')]
      .filter((element) => !element.hasAttribute('disabled'))
    const currentIndex = editable.indexOf(event.currentTarget)
    if (currentIndex < 0) return

    event.preventDefault()
    const next = editable[currentIndex + 1]
    if (next) {
      next.focus()
      if (next instanceof HTMLInputElement) next.select()
      return
    }

    focusNewProductSearch()
  }

  function renderDualCell(
    colKey: string,
    cls: string,
    widthStyle: CSSProperties | undefined,
    _row: DraftLine,
    readOnly: boolean,
    base: number,
    pctValue: string,
    rsValue: string,
    onPct: (pct: string, rs: string) => void,
    onRs: (rs: string, pct: string) => void,
  ): ReactNode {
    const mode = dualModes[colKey] ?? 'pct'
    const pctEnabled = !readOnly && mode === 'pct'
    const rsEnabled = !readOnly && mode === 'rs'
    return (
      <td className={`${cls} pie-dual-cell`} style={widthStyle}>
        <div className="pie-dual-inputs">
          <input
            className={!pctEnabled ? 'is-disabled' : undefined}
            value={pctValue}
            disabled={!pctEnabled}
            title="%"
            data-purchase-edit="true"
            onKeyDown={handleLineEnter}
            onClick={(e) => e.stopPropagation()}
            onChange={(e) => {
              const pct = e.target.value
              const rs = amountFromPct(base, Number(pct) || 0)
              onPct(pct, rs)
            }}
          />
          <input
            className={!rsEnabled ? 'is-disabled' : undefined}
            value={rsValue}
            disabled={!rsEnabled}
            title="Rs"
            data-purchase-edit="true"
            onKeyDown={handleLineEnter}
            onClick={(e) => e.stopPropagation()}
            onChange={(e) => {
              const rs = e.target.value
              const pct = pctFromAmount(base, Number(rs) || 0)
              onRs(rs, pct)
            }}
          />
        </div>
      </td>
    )
  }

  function renderPurchaseCell(
    col: ResolvedGridColumn,
    row: DraftLine | null,
    ctx: {
      readOnly: boolean
      canEdit: boolean
      supplierName: string
      warehouseLabel: string
      stockQty?: string
      rowIndex?: number
      onRemove?: () => void
      openCustomization: () => void
      customizationOpen: boolean
    },
  ): ReactNode {
    const num = col.align === 'right' ? 'is-num' : col.align === 'center' ? 'is-center' : ''
    const cls = [num, col.cellClass, `col-${col.key}`].filter(Boolean).join(' ')
    const widthStyle = col.width ? { width: col.width, minWidth: col.width } : undefined

    if (!row) {
      if (col.key === 'line_no') return <td className={cls} style={widthStyle}>*</td>
      if (col.key === 'product') {
        if (ctx.readOnly || !ctx.canEdit) {
          return (
            <td className={cls} style={widthStyle}>
              <span className="purchase-reference-empty-dots">....</span>
            </td>
          )
        }

        const isActive = productEditRowKey === 'new'

        return (
          <td className={cls} style={widthStyle}>
            <div
              ref={isActive ? productPickerAnchorRef : undefined}
              className="pie-product-combo"
            >
              <input
                ref={productSearchRef}
                className="pie-product-combo-input"
                value={isActive ? productQuery : ''}
                disabled={!ctx.canEdit}
                onFocus={() => {
                  if (!isActive) beginProductEdit(null)
                }}
                onChange={(event) => {
                  setProductEditRowKey('new')
                  setProductLookupMode('search')
                                setProductQuery(event.target.value)
                  setProductMenuOpen(true)
                }}
                onKeyDown={(event) => {
                  if (event.key !== 'Enter') return
                  const first = productLookupRows[0]
                  if (!first) return
                  event.preventDefault()
                  void addProduct(first)
                }}
                placeholder="Search item / SKU / barcode..."
                aria-label="Search product for new purchase line"
                autoComplete="off"
              />
              <div className="pie-product-combo-actions">
                <button
                  type="button"
                  className="pie-product-combo-toggle"
                  aria-label="Open product dropdown"
                  aria-expanded={isActive && productMenuOpen}
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={() => {
                    if (!isActive) beginProductEdit(null)
                    else setProductMenuOpen((open) => !open)
                    requestAnimationFrame(() => productSearchRef.current?.focus())
                  }}
                >
                  <span className="pie-product-combo-arrow" aria-hidden>
                    <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                      <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                    </svg>
                  </span>
                </button>
                <button
                  type="button"
                  className="pie-product-combo-add"
                  aria-label="Add new product"
                  title="Add new product"
                  disabled={!canCreateProduct}
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={() => {
                    setProductModalTargetRowKey('new')
                    setProductModalOpen(true)
                    setProductMenuOpen(false)
                  }}
                >
                  +
                </button>
              </div>
              {isActive && productMenuOpen ? (
                <ProductLookupGrid
                  rows={productLookupRows}
                  total={productLookupTotal}
                  priceType="retail"
                  mode="purchase"
                  themed
                  portalAnchorRef={productPickerAnchorRef}
                  onRequestClose={() => setProductMenuOpen(false)}
                  onSelect={(product) => void addProduct(product)}
                />
              ) : null}
            </div>
          </td>
        )
      }
      if (col.key === 'delete') {
        return (
          <td className={cls} style={widthStyle}>
            <button
              type="button"
              className={`purchase-reference-customize-trigger${ctx.customizationOpen ? ' is-open' : ''}`}
              title="Customize columns"
              aria-label="Customize columns"
              aria-expanded={ctx.customizationOpen}
              onClick={ctx.openCustomization}
            >
              −
            </button>
          </td>
        )
      }
      return <td className={cls} style={widthStyle} />
    }

    const calc = computeLine(row, calcSettings)
    const {
      afterGst,
      furtherBase,
      costPerUnit,
      mrpEx,
      mrpIn,
    } = calc
    const salePrice = Number(row.sale_price ?? row.mrp) || 0
    const marginRs = salePrice - costPerUnit
    const marginPct = salePrice > 0 ? (marginRs / salePrice) * 100 : 0

    const editableInput = (
      value: string,
      onChange: (value: string) => void,
      opts?: { type?: string; placeholder?: string },
    ) =>
      ctx.readOnly ? (
        value
      ) : (
        <input
          type={opts?.type ?? 'text'}
          value={value}
          placeholder={opts?.placeholder}
          data-purchase-edit="true"
          onKeyDown={handleLineEnter}
          onChange={(e) => onChange(e.target.value)}
          onClick={(e) => e.stopPropagation()}
        />
      )

    switch (col.key) {
      case 'line_no':
        return <td className={cls} style={widthStyle}>{(ctx.rowIndex ?? 0) + 1}</td>
      case 'item_code':
        return <td className={cls} style={widthStyle}>{row.item_code || '—'}</td>
      case 'product': {
        const isActive = productEditRowKey === row.key
        return (
          <td className={cls} style={widthStyle}>
            {ctx.readOnly ? (
              row.product_label
            ) : (
              <div
                ref={isActive ? productPickerAnchorRef : undefined}
                className="pie-product-combo"
              >
                <input
                  className="pie-product-combo-input"
                  value={isActive ? productQuery : row.product_label}
                  data-purchase-edit="true"
                  data-purchase-product="true"
                  onFocus={() => {
                    if (!isActive) beginProductEdit(row)
                  }}
                  onChange={(event) => {
                    setProductEditRowKey(row.key)
                    setProductLookupMode('search')
                                    setProductQuery(event.target.value)
                    setProductMenuOpen(true)
                  }}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter' && productMenuOpen) {
                      const first = productLookupRows[0]
                      if (first) {
                        event.preventDefault()
                        void addProduct(first, row.key)
                        return
                      }
                    }
                    handleLineEnter(event)
                  }}
                  aria-label="Edit purchase product"
                  autoComplete="off"
                />
                <div className="pie-product-combo-actions">
                  <button
                    type="button"
                    className="pie-product-combo-toggle"
                    aria-label="Open product dropdown"
                    aria-expanded={isActive && productMenuOpen}
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={() => {
                      if (!isActive) beginProductEdit(row)
                      else if (productMenuOpen) setProductMenuOpen(false)
                      else beginProductEdit(row)
                    }}
                  >
                    <span className="pie-product-combo-arrow" aria-hidden>
                      <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                        <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                      </svg>
                    </span>
                  </button>
                  <button
                    type="button"
                    className="pie-product-combo-add"
                    aria-label="Add new product"
                    title="Add new product"
                    disabled={!canCreateProduct}
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={() => {
                      setProductModalTargetRowKey(row.key)
                      setProductModalOpen(true)
                      setProductMenuOpen(false)
                    }}
                  >
                    +
                  </button>
                </div>
                {isActive && productMenuOpen ? (
                  <ProductLookupGrid
                    rows={productLookupRows}
                    total={productLookupTotal}
                    priceType="retail"
                    mode="purchase"
                    themed
                    selectedUlid={row.product_ulid}
                    portalAnchorRef={productPickerAnchorRef}
                    onRequestClose={() => setProductMenuOpen(false)}
                    onSelect={(product) => void addProduct(product, row.key)}
                  />
                ) : null}
              </div>
            )}
          </td>
        )
      }
      case 'quantity':
      case 'qty':
        return (
          <td className={cls} style={widthStyle}>
            {editableInput(row.quantity, (value) => patchLine(row.key, { quantity: value }))}
          </td>
        )
      case 'free_pcs':
        return (
          <td className={cls} style={widthStyle}>
            {editableInput(row.free_pcs, (value) => patchLine(row.key, { free_pcs: value }))}
          </td>
        )
      case 'uom':
        return (
          <td className={cls} style={widthStyle}>
            {ctx.readOnly ? (
              row.unit_label
            ) : (
              <div onClick={(event) => event.stopPropagation()}>
                <UiSelect menuMotion="reveal"
                  className="pie-line-select"
                  aria-label="Unit"
                  value={row.unit_ulid}
                  triggerProps={{ 'data-purchase-edit': 'true' }}
                  onKeyDown={handleLineEnter}
                  options={(row.unit_options.length > 0
                    ? row.unit_options
                    : row.unit_ulid
                      ? [{ ulid: row.unit_ulid, code: row.unit_label || 'UNIT', conversion_factor: row.conversion_factor }]
                      : []
                  ).map((unit) => ({ value: unit.ulid, label: unit.code }))}
                  onChange={(unitUlid) => {
                    const option =
                      row.unit_options.find((unit) => unit.ulid === unitUlid) ?? null
                    if (!option) return
                    patchLine(row.key, {
                      unit_ulid: option.ulid,
                      unit_label: option.code,
                      conversion_factor: option.conversion_factor,
                    })
                  }}
                />
              </div>
            )}
          </td>
        )
      case 'mrp_ex_gst':
        return (
          <td className={cls} style={widthStyle}>
            {calcMethod === 'mrp_incl_gst'
              ? money(mrpEx)
              : editableInput(row.mrp, (value) => patchLine(row.key, { mrp: value, sale_price: value }))}
          </td>
        )
      case 'mrp_in_gst':
        return (
          <td className={cls} style={widthStyle}>
            {calcMethod === 'mrp_incl_gst'
              ? editableInput(row.mrp, (value) => patchLine(row.key, { mrp: value, sale_price: value }))
              : money(mrpIn)}
          </td>
        )
      case 'trade_price':
        return (
          <td className={cls} style={widthStyle}>
            {editableInput(row.unit_cost, (value) => {
              const patch: Partial<DraftLine> = { unit_cost: value }
              if (autoCalcMrp && Number(row.mrp) <= 0) {
                patch.mrp = value
                patch.sale_price = value
              }
              patchLine(row.key, patch)
            })}
          </td>
        )
      case 'regular_disc':
        return renderDualCell(
          'regular_disc',
          cls,
          widthStyle,
          row,
          ctx.readOnly,
          calc.discountBase,
          calc.regular_disc_pct,
          calc.regular_disc_rs,
          (pct, rs) => {
            patchLine(row.key, {
              regular_disc_pct: pct,
              regular_disc_rs: rs,
            })
          },
          (rs, pct) => {
            patchLine(row.key, {
              regular_disc_pct: pct,
              regular_disc_rs: rs,
            })
          },
        )
      case 'special_disc':
        return renderDualCell(
          'special_disc',
          cls,
          widthStyle,
          row,
          ctx.readOnly,
          calc.discountBase,
          calc.special_disc_pct,
          calc.special_disc_rs,
          (pct, rs) => {
            patchLine(row.key, {
              special_disc_pct: pct,
              special_disc_rs: rs,
            })
          },
          (rs, pct) => {
            patchLine(row.key, {
              special_disc_pct: pct,
              special_disc_rs: rs,
            })
          },
        )
      case 'gst':
        return renderDualCell(
          'gst',
          cls,
          widthStyle,
          row,
          ctx.readOnly,
          calc.gstBase,
          calc.tax_pct,
          calc.tax_amount,
          (pct, rs) => patchLine(row.key, { tax_pct: pct, tax_amount: rs }),
          (rs, pct) => patchLine(row.key, { tax_pct: pct, tax_amount: rs }),
        )
      case 'disc_after_gst':
        return renderDualCell(
          'disc_after_gst',
          cls,
          widthStyle,
          row,
          ctx.readOnly,
          afterGst,
          calc.disc_after_gst_pct,
          calc.disc_after_gst_rs,
          (pct, rs) => patchLine(row.key, { disc_after_gst_pct: pct, disc_after_gst_rs: rs }),
          (rs, pct) => patchLine(row.key, { disc_after_gst_pct: pct, disc_after_gst_rs: rs }),
        )
      case 'further_tax':
        return renderDualCell(
          'further_tax',
          cls,
          widthStyle,
          row,
          ctx.readOnly,
          furtherBase,
          calc.further_tax_pct,
          calc.further_tax_amount,
          (pct, rs) => patchLine(row.key, { further_tax_pct: pct, further_tax_amount: rs }),
          (rs, pct) => patchLine(row.key, { further_tax_pct: pct, further_tax_amount: rs }),
        )
      case 'advance_tax':
        return renderDualCell(
          'advance_tax',
          cls,
          widthStyle,
          row,
          ctx.readOnly,
          furtherBase,
          calc.advance_tax_pct,
          calc.advance_tax_amount,
          (pct, rs) => patchLine(row.key, { advance_tax_pct: pct, advance_tax_amount: rs }),
          (rs, pct) => patchLine(row.key, { advance_tax_pct: pct, advance_tax_amount: rs }),
        )
      case 'cost_price':
        return <td className={cls} style={widthStyle}>{money(costPerUnit)}</td>
      case 'sale_price':
        return (
          <td className={cls} style={widthStyle}>
            {editableInput(row.sale_price ?? row.mrp, (value) =>
              patchLine(row.key, { sale_price: value }),
            )}
          </td>
        )
      case 'margin':
        return (
          <td className={cls} style={widthStyle}>
            <div className="pie-dual-inputs is-readonly">
              <span>{marginPct.toFixed(1)}%</span>
              <span>{money(marginRs)}</span>
            </div>
          </td>
        )
      case 'total_amount':
        return <td className={cls} style={widthStyle}>{money(calc.line_total)}</td>
      case 'batch':
        return (
          <td className={cls} style={widthStyle}>
            {editableInput(row.batch_number, (value) => patchLine(row.key, { batch_number: value }), {
              placeholder: row.track_batch ? 'Req' : '',
            })}
          </td>
        )
      case 'expiry':
        return (
          <td className={cls} style={widthStyle}>
            {editableInput(row.expiry_date, (value) => patchLine(row.key, { expiry_date: value }), {
              type: 'date',
            })}
          </td>
        )
      case 'delete':
        return (
          <td className={cls} style={widthStyle}>
            {ctx.readOnly || !ctx.canEdit ? null : (
              <button
                type="button"
                title="Remove"
                onClick={(e) => {
                  e.stopPropagation()
                  ctx.onRemove?.()
                }}
              >
                <XCircle size={16} />
              </button>
            )}
          </td>
        )
      default:
        return <td className={cls} style={widthStyle} />
    }
  }

  function applyInvoice(invoice: PurchaseInvoice) {
    setInvoiceUlid(invoice.ulid)
    setDocumentNumber(invoice.document_number)
    setStatus(invoice.status)
    setSupplierUlid(invoice.supplier?.ulid ?? '')
    setWarehouseUlid(invoice.warehouse?.ulid ?? session?.warehouse.ulid ?? '')
    setInvoiceDate(invoice.invoice_date)
    setDueDate(invoice.due_date ?? '')
    setSupplierInvoiceNumber(invoice.supplier_invoice_number ?? '')
    setFreightAmount(invoice.freight_amount)
    setOtherCharges(invoice.other_charges)
    setNotes(invoice.notes ?? '')
    setShellPoNo(invoice.po_number ?? '')
    setPurchaseOrderUlid(invoice.purchase_order?.ulid ?? null)
    setShellInvoiceType(invoice.invoice_type ?? 'tax_gst')
    setShellCurrency(invoice.currency_code ?? 'PKR')
    setShellDefaultSalesTax(invoice.default_sales_tax_pct ?? '18')
    setShellDefaultFurtherTax(invoice.default_further_tax_pct ?? '0')
    setShellDefaultAdvanceTax(invoice.default_advance_tax_pct ?? '0')
    setShellPriceType(invoice.default_price_type ?? 'trade')
    setShellBrand(invoice.brand_label ?? '')
    setShellLoading(invoice.loading_amount ?? '0.0000')
    setShellOtherDiscount(invoice.other_discount ?? '0.0000')
    setShellTradeOffer(invoice.trade_offer ?? '0.0000')
    const nextDiscountApplyOn = invoice.discount_apply_on ?? 'trade'
    const nextModes = {
      ...DEFAULT_DUAL_MODES,
      ...(invoice.calculation_modes ?? {}),
    }
    const nextWithholdingIsPct = Boolean(invoice.withholding_is_pct)
    setDiscountApplyOn(nextDiscountApplyOn)
    setDualModes(nextModes)
    setDiscInputType(nextModes.regular_disc ?? 'rs')
    setAutoCalcMrp(invoice.auto_calc_mrp ?? true)
    setWithholdingIsPct(nextWithholdingIsPct)
    setShellAdvanceTax(
      nextWithholdingIsPct
        ? invoice.withholding_pct ?? '0'
        : invoice.advance_tax_amount ?? '0.0000',
    )
    setShellRoundOff(invoice.round_off ?? '0.0000')
    setShellTaxType(invoice.tax_type ?? 'standard')
    setShellPaymentTerms(invoice.payment_terms ?? 'credit')
    setPaidAmount(invoice.paid_amount ?? '0.0000')
    setServerBalancePayable(invoice.balance_payable ?? invoice.grand_total)
    const mapped = (invoice.lines ?? []).map(lineFromServer)
    const method = normalizeCalcMethod(invoice.calculation_method)
    setCalcMethod(method)
    setLines(mapped.map((line) => applyCalcToLine(line, {
      calcMethod: method,
      discountApplyOn: nextDiscountApplyOn,
      dualModes: nextModes,
    })))
    void enrichLineUnits(mapped)
  }

  async function enrichLineUnits(rows: DraftLine[]) {
    const unique = [...new Set(rows.map((row) => row.product_ulid).filter(Boolean))]
    if (unique.length === 0) return
    const productByUlid = new Map<string, Product>()
    await Promise.all(
      unique.map(async (ulid) => {
        try {
          const product = await fetchProduct(ulid)
          productByUlid.set(ulid, product)
        } catch {
          // Keep the single unit from the invoice line when product fetch fails.
        }
      }),
    )
    setLines((prev) =>
      prev.map((line) => {
        const product = productByUlid.get(line.product_ulid)
        if (!product) return line
        const options = buildUnitOptions(product)
        if (options.length === 0) return {
          ...line,
          brand_label: product.brand?.name ?? line.brand_label,
          location_label: product.rack_location ?? line.location_label,
          track_batch: product.track_batch,
          track_expiry: product.track_expiry,
        }
        const merged = [...options]
        if (line.unit_ulid && !merged.some((unit) => unit.ulid === line.unit_ulid)) {
          merged.unshift({
            ulid: line.unit_ulid,
            code: line.unit_label || 'UNIT',
            conversion_factor: line.conversion_factor,
          })
        }
        return {
          ...line,
          unit_options: merged,
          brand_label: product.brand?.name ?? line.brand_label,
          location_label: product.rack_location ?? line.location_label,
          track_batch: product.track_batch,
          track_expiry: product.track_expiry,
        }
      }),
    )
  }

  function resetEditor() {
    setInvoiceUlid(null)
    setDocumentNumber('')
    setStatus('draft')
    setSupplierUlid('')
    setWarehouseUlid(session?.warehouse.ulid ?? '')
    setInvoiceDate(todayIso())
    setDueDate('')
    setSupplierInvoiceNumber('')
    setFreightAmount('0.0000')
    setOtherCharges('0.0000')
    setNotes('')
    setLines([])
    setProductQuery('')
    setProductEditRowKey(null)
    setProductMenuOpen(false)
    setError(null)
    setPaidAmount('0.0000')
    setServerBalancePayable('0.0000')
    setPaymentAmount('')
    setPaymentMethod('cash')
    setPaymentReference('')
    setPaymentIdempotencyKey(crypto.randomUUID())
    setSelectedLineKey(null)
    setCalcMethod('trade_after_disc')
    setShellPriceType('trade')
    setShellTaxType('standard')
    setShellPaymentTerms('credit')
    setShellPoNo('')
    setPurchaseOrderUlid(null)
    setShellInvoiceType('tax_gst')
    setShellCurrency('PKR')
    setShellBrand('')
    setShellLoading('0.0000')
    setShellOtherDiscount('0.0000')
    setShellTradeOffer('0.0000')
    setShellAdvanceTax('0.0000')
    setShellRoundOff('0.0000')
    setShellDefaultSalesTax('18')
    setShellDefaultFurtherTax('0')
    setShellDefaultAdvanceTax('0')
    setDiscountApplyOn('trade')
    setDiscInputType('pct')
    setAutoCalcMrp(true)
    setWithholdingIsPct(false)
    setDualModes({ ...DEFAULT_DUAL_MODES })
  }

  async function openInvoice(ulid: string) {
    setError(null)
    try {
      const invoice = await fetchPurchase(ulid)
      applyInvoice(invoice)
      setMode('editor')
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to open purchase.')
    }
  }

  function startNew() {
    resetEditor()

    const defaultVendorUlid = purchaseSoftwareOptions?.default_vendor_ulid ?? ''
    if (defaultVendorUlid && suppliers.some((supplier) => supplier.ulid === defaultVendorUlid)) {
      setSupplierUlid(defaultVendorUlid)
    }

    setMode('editor')
  }

  function backToList() {
    setMode('list')
    setError(null)
    void queryClient.invalidateQueries({ queryKey: ['purchases'] })
  }

  async function loadPurchaseOrderIntoDraft(orderUlid: string) {
    if (invoiceUlid || lines.length > 0) {
      throw new Error('Clear or save the current Purchase Invoice draft before loading a Purchase Order.')
    }

    const order = await fetchPurchaseOrder(orderUlid)

    if (!order.supplier?.ulid) {
      throw new Error('Purchase Order has no supplier.')
    }
    if (order.warehouse?.ulid && order.warehouse.ulid !== (session?.warehouse.ulid ?? '')) {
      throw new Error('Purchase Order warehouse must match the active warehouse.')
    }

    const mapped = (order.items ?? [])
      .filter((line) => line.product?.ulid && line.unit?.ulid)
      .map((line): DraftLine => {
        const discount = line.discount_amount ?? '0.0000'
        return applyCalcToLine(
          {
            key: `po-${line.ulid}`,
            purchase_order_line_ulid: line.ulid,
            product_ulid: line.product?.ulid ?? '',
            product_label: line.product
              ? `${line.product.product_number} · ${line.product.name}`
              : '',
            ...emptyShellFields(),
            item_code: line.product?.product_number ?? '',
            unit_ulid: line.unit?.ulid ?? '',
            unit_label: line.unit?.code ?? '',
            unit_options: line.unit
              ? [{
                  ulid: line.unit.ulid,
                  code: line.unit.code,
                  conversion_factor: line.conversion_factor,
                }]
              : [],
            quantity: line.quantity,
            conversion_factor: line.conversion_factor,
            unit_cost: line.unit_price,
            discount_amount: discount,
            regular_disc_rs: discount,
            regular_disc_pct: line.discount_percent ?? '0',
            tax_amount: '0.0000',
            supplier_product_code: '',
            batch_number: '',
            expiry_date: '',
            notes: line.notes ?? '',
            location_label: '',
            track_batch: false,
            track_expiry: false,
            further_tax_amount: '0.0000',
            disc_after_gst_pct: '0',
            disc_after_gst_rs: '0',
            advance_tax_pct: '0',
            advance_tax_amount: '0',
            special_disc_rs: '0.0000',
            sale_price: '0.0000',
          },
          calcSettings,
        )
      })

    resetEditor()
    setMode('editor')
    setSupplierUlid(order.supplier.ulid)
    setWarehouseUlid(session?.warehouse.ulid ?? order.warehouse?.ulid ?? '')
    setShellPoNo(order.document_number)
    setPurchaseOrderUlid(order.ulid)
    setNotes(order.notes ?? '')
    setLines(mapped)
    setSelectedLineKey(mapped[0]?.key ?? null)
    void enrichLineUnits(mapped)
  }

  const autoPurchaseOrderUlid = searchParams.get('purchase_order')?.trim() ?? ''

  useEffect(() => {
    if (!autoPurchaseOrderUlid) {
      autoPurchaseOrderAttemptedRef.current = ''
      return
    }
    if (autoPurchaseOrderAttemptedRef.current === autoPurchaseOrderUlid) return

    autoPurchaseOrderAttemptedRef.current = autoPurchaseOrderUlid

    void loadPurchaseOrderIntoDraft(autoPurchaseOrderUlid)
      .then(() => {
        const next = new URLSearchParams(searchParams)
        next.delete('purchase_order')
        setSearchParams(next, { replace: true })
        setError(null)
      })
      .catch((err) => {
        setError(
          err instanceof ApiClientError
            ? err.message
            : err instanceof Error
              ? err.message
              : 'Unable to load Purchase Order.',
        )
      })
  }, [autoPurchaseOrderUlid])

  const saveMutation = useMutation({
    mutationFn: async () => {
      if (!supplierUlid || !warehouseUlid) {
        throw new Error('Supplier and warehouse are required.')
      }
      const authoritativePreview = sumHeaderCharges({
        lines,
        settings: calcSettings,
        freight: freightAmount,
        loading: shellLoading,
        otherCharges,
        otherDiscount: shellOtherDiscount,
        tradeOffer: shellTradeOffer,
        advanceTax: shellAdvanceTax,
        withholdingIsPct,
        roundOff: shellRoundOff,
      })

      const header = {
        supplier_ulid: supplierUlid,
        warehouse_ulid: warehouseUlid,
        invoice_date: invoiceDate,
        due_date: dueDate || null,
        supplier_invoice_number: supplierInvoiceNumber || null,
        purchase_order_ulid: !invoiceUlid ? purchaseOrderUlid : undefined,
        po_number: shellPoNo || null,
        invoice_type: shellInvoiceType,
        currency_code: shellCurrency,
        calculation_method: calcMethod,
        discount_apply_on: discountApplyOn,
        calculation_modes: dualModes,
        auto_calc_mrp: autoCalcMrp,
        withholding_is_pct: withholdingIsPct,
        withholding_pct: withholdingIsPct ? shellAdvanceTax || '0' : '0',
        default_sales_tax_pct: shellDefaultSalesTax || '0',
        default_further_tax_pct: shellDefaultFurtherTax || '0',
        default_advance_tax_pct: shellDefaultAdvanceTax || '0',
        default_price_type: shellPriceType,
        brand_label: shellBrand || null,
        freight_amount: freightAmount || '0',
        loading_amount: shellLoading || '0',
        other_charges: otherCharges || '0',
        other_discount: shellOtherDiscount || '0',
        trade_offer: shellTradeOffer || '0',
        advance_tax_amount: authoritativePreview.advance_tax_amount,
        round_off: shellRoundOff || '0',
        notes: notes || null,
        tax_type: shellTaxType,
        payment_terms: shellPaymentTerms,
      }

      let invoice = invoiceUlid
        ? await updatePurchase(invoiceUlid, header)
        : await createPurchase(header)

      for (const line of lines) {
        if (!line.product_ulid || !line.unit_ulid) continue
        const calc = computeLine(line, calcSettings)
        const payload = {
          purchase_order_line_ulid: line.purchase_order_line_ulid ?? null,
          product_ulid: line.product_ulid,
          unit_ulid: line.unit_ulid,
          quantity: line.quantity || '0',
          conversion_factor: line.conversion_factor || '1',
          unit_cost: line.unit_cost || '0',
          discount_amount: calc.discount_amount,
          tax_amount: calc.tax_amount,
          supplier_product_code: line.supplier_product_code || null,
          batch_number: line.batch_number || null,
          expiry_date: line.expiry_date || null,
          notes: line.notes || null,
          brand_label: line.brand_label || null,
          hs_code: line.hs_code || null,
          pack_size: line.pack_size || null,
          qty_ctn: line.qty_ctn || '0',
          free_pcs: line.free_pcs || '0',
          price_type: line.price_type || 'trade',
          mrp: line.mrp || '0',
          trade_disc_pct: line.trade_disc_pct || '0',
          regular_disc_pct: calc.regular_disc_pct || '0',
          regular_disc_amount: calc.regular_disc_rs || '0',
          special_disc_pct: calc.special_disc_pct || '0',
          special_disc_amount: calc.special_disc_rs || '0',
          disc_after_gst_pct: calc.disc_after_gst_pct || '0',
          disc_after_gst_amount: calc.disc_after_gst_rs || '0',
          tax_pct: calc.tax_pct || '0',
          further_tax_pct: calc.further_tax_pct || '0',
          further_tax_amount: calc.further_tax_amount || '0',
        }
        if (line.ulid) {
          await updatePurchaseLine(invoice.ulid, line.ulid, payload)
        } else {
          await createPurchaseLine(invoice.ulid, payload)
        }
      }

      invoice = await fetchPurchase(invoice.ulid)
      return invoice
    },
    onSuccess: (invoice) => {
      applyInvoice(invoice)
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['purchases'] })
      void queryClient.invalidateQueries({ queryKey: ['purchase-line-stock'] })
    },
  })

  const postMutation = useMutation({
    mutationFn: async () => {
      if (!invoiceUlid) {
        const saved = await saveMutation.mutateAsync()
        return postPurchase(saved.ulid)
      }
      if (canEdit && status === 'draft') {
        await saveMutation.mutateAsync()
      }
      return postPurchase(invoiceUlid)
    },
    onSuccess: (invoice) => {
      applyInvoice(invoice)
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['purchases'] })
      void queryClient.invalidateQueries({ queryKey: ['purchase-line-stock'] })
      void queryClient.invalidateQueries({ queryKey: ['products'] })
      void queryClient.invalidateQueries({ queryKey: ['posted-purchases-lookup'] })
    },
  })

  const paymentMutation = useMutation({
    mutationFn: async () => {
      if (!invoiceUlid || status !== 'posted') {
        throw new Error('Post the purchase before recording payment.')
      }

      return createPurchasePayment(
        invoiceUlid,
        {
          amount: paymentAmount,
          method: paymentMethod,
          reference: paymentReference.trim() || null,
        },
        paymentIdempotencyKey,
      )
    },
    onSuccess: async () => {
      if (!invoiceUlid) return
      const invoice = await fetchPurchase(invoiceUlid)
      applyInvoice(invoice)
      setPaymentModalOpen(false)
      setPaymentAmount('')
      setPaymentReference('')
      setPaymentIdempotencyKey(crypto.randomUUID())
      setError(null)
      void queryClient.invalidateQueries({ queryKey: ['purchases'] })
    },
  })

  async function onRecordPayment() {
    if (!canCreatePayment || status !== 'posted' || !invoiceUlid) return
    setError(null)
    try {
      await paymentMutation.mutateAsync()
    } catch (err) {
      setError(
        err instanceof ApiClientError
          ? err.message
          : err instanceof Error
            ? err.message
            : 'Unable to record payment.',
      )
    }
  }

  async function onSave(event?: FormEvent) {
    event?.preventDefault()
    if (readOnly || (invoiceUlid ? !canEdit : !canCreate)) return
    setError(null)
    try {
      await saveMutation.mutateAsync()
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : err instanceof Error ? err.message : 'Unable to save.')
    }
  }

  async function onPost() {
    if (!canPost || readOnly) return
    if (!(await askConfirm('Post this purchase? Stock will be updated and the document becomes immutable.'))) return
    setError(null)
    try {
      await postMutation.mutateAsync()
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to post purchase.')
    }
  }

  async function addProduct(product: Product, replaceRowKey?: string) {
    const existingLine = replaceRowKey
      ? lines.find((line) => line.key === replaceRowKey) ?? null
      : null

    if (existingLine?.product_ulid === product.ulid) {
      setLines((prev) =>
        prev.map((line) =>
          line.key === replaceRowKey
            ? {
                ...line,
                product_label: `${product.product_number} · ${product.name}`,
                item_code: product.product_number,
                brand_label: product.brand?.name ?? line.brand_label,
                supplier_product_code: product.supplier_product_code ?? line.supplier_product_code,
                track_batch: product.track_batch,
                track_expiry: product.track_expiry,
                category_ulid: product.category?.ulid ?? line.category_ulid,
              }
            : line,
        ),
      )
      setSelectedLineKey(replaceRowKey ?? null)
      setProductEditRowKey(replaceRowKey ?? null)
      setProductQuery(`${product.product_number} · ${product.name}`)
      setProductMenuOpen(false)
      focusFirstEditableInRow(replaceRowKey ?? '')
      return
    }

    const unit = preferPcsUnit(product, productQuery)
    if (!unit.unit_ulid) {
      setError('Selected product has no base unit.')
      return
    }

    let unitCost = '0.0000'
    if (warehouseUlid) {
      try {
        const stock = await fetchProductStock(product.ulid)
        const match = stock.warehouses.find((row) => row.warehouse.ulid === warehouseUlid)
        unitCost = moneyOrZero(match?.average_cost)
      } catch {
        // Keep zero trade price when stock lookup fails; operator can type cost.
      }
    }

    const retail = activeRetailAmount(product)
    const productTax = moneyOrZero(product.tax_percent)
    const taxPct =
      productTax !== '0.0000'
        ? product.tax_percent
        : shellDefaultSalesTax || '0'
    const furtherTaxPct = shellDefaultFurtherTax || '0'
    const key = replaceRowKey ?? `new-${Date.now()}-${lines.length}`

    const draft = applyCalcToLine(
      {
        key,
        purchase_order_line_ulid: null,
        product_ulid: product.ulid,
        product_label: `${product.product_number} · ${product.name}`,
        ...emptyShellFields(),
        item_code: product.product_number,
        brand_label: product.brand?.name ?? '',
        price_type: shellPriceType || 'trade',
        tax_pct: taxPct,
        further_tax_pct: furtherTaxPct,
        supplier_product_code: product.supplier_product_code ?? '',
        unit_ulid: unit.unit_ulid,
        unit_label: unit.unit_label,
        unit_options: unit.unit_options,
        quantity: '1.000000',
        conversion_factor: unit.conversion_factor,
        unit_cost: unitCost,
        discount_amount: '0.0000',
        tax_amount: '0.0000',
        batch_number: '',
        expiry_date: '',
        notes: '',
        location_label: product.rack_location ?? '',
        track_batch: product.track_batch,
        track_expiry: product.track_expiry,
        further_tax_amount: '0.0000',
        disc_after_gst_pct: '0',
        disc_after_gst_rs: '0',
        advance_tax_pct: shellDefaultAdvanceTax || '0',
        advance_tax_amount: '0',
        regular_disc_rs: '0.0000',
        special_disc_rs: '0.0000',
        mrp: retail,
        sale_price: retail,
        category_ulid: product.category?.ulid ?? '',
      },
      calcSettings,
    )

    setLines((prev) =>
      replaceRowKey
        ? prev.map((line) =>
            line.key === replaceRowKey
              ? { ...draft, key: replaceRowKey, ulid: line.ulid }
              : line,
          )
        : [...prev, draft],
    )
    setSelectedLineKey(key)
    setProductEditRowKey(key)
    setProductQuery(draft.product_label)
    setProductMenuOpen(false)

    if (!replaceRowKey) {
      focusFirstEditableInRow(key)
    } else {
      focusFirstEditableInRow(key)
    }
  }

  function openPurchaseReturn() {
    if (!invoiceUlid || status !== 'posted') return
    openModule(`/daily/purchase-return?purchase=${encodeURIComponent(invoiceUlid)}`)
  }

  async function removeLine(line: DraftLine) {
    if (readOnly || !canEdit) return
    if (line.ulid && invoiceUlid) {
      try {
        await deletePurchaseLine(invoiceUlid, line.ulid)
        const refreshed = await fetchPurchase(invoiceUlid)
        applyInvoice(refreshed)
      } catch (err) {
        setError(err instanceof ApiClientError ? err.message : 'Unable to remove line.')
      }
      return
    }
    setLines((prev) => prev.filter((row) => row.key !== line.key))
  }

  useWorkspaceHandlers({
    save: () => void onSave(),
    refresh: () => {
      if (mode === 'list') void listQuery.refetch()
      else if (invoiceUlid) void openInvoice(invoiceUlid)
    },
  })

  const liveTotals = useMemo(
    () =>
      sumHeaderCharges({
        lines,
        settings: calcSettings,
        freight: freightAmount,
        loading: shellLoading,
        otherCharges,
        otherDiscount: shellOtherDiscount,
        tradeOffer: shellTradeOffer,
        advanceTax: shellAdvanceTax,
        withholdingIsPct,
        roundOff: shellRoundOff,
      }),
    [
      lines,
      calcSettings,
      freightAmount,
      shellLoading,
      otherCharges,
      shellOtherDiscount,
      shellTradeOffer,
      shellAdvanceTax,
      withholdingIsPct,
      shellRoundOff,
    ],
  )

  if (mode === 'list') {
    return (
      <DesktopPanel
        className="purchase-list-page"
        title="Purchase Invoices"
        toolbar={
          <>
            <DesktopButton icon={<Plus size={13} />} label="New" disabled={!canCreate} onClick={startNew} />
            <DesktopButton
              icon={<Save size={13} />}
              label="Open"
              disabled={!selectedListKey}
              onClick={() => selectedListKey && void openInvoice(selectedListKey)}
            />
            <DesktopButton
              icon={<RefreshCw size={13} />}
              label="Refresh"
              shortcut="F8"
              onClick={() => void listQuery.refetch()}
            />
            <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
          </>
        }
      >
        <div className="purchase-list-filters">
          <input
            className="desktop-input purchase-list-search"
            placeholder="Search…"
            value={q}
            onChange={(e) => setQ(e.target.value)}
          />
          <input
            className="desktop-input"
            type="date"
            value={dateFrom}
            onChange={(e) => setDateFrom(e.target.value)}
            title="From"
          />
          <input
            className="desktop-input"
            type="date"
            value={dateTo}
            onChange={(e) => setDateTo(e.target.value)}
            title="To"
          />
          <UiSelect menuMotion="reveal"
            aria-label="Supplier filter"
            value={supplierFilter}
            options={[
              { value: '', label: 'All suppliers' },
              ...suppliers.map((s) => ({ value: s.ulid, label: `${s.code} — ${s.name}` })),
            ]}
            onChange={setSupplierFilter}
          />
          <UiSelect menuMotion="reveal"
            aria-label="Purchase status"
            value={statusFilter}
            options={[
              { value: '', label: 'All statuses' },
              { value: 'draft', label: 'Draft' },
              { value: 'posted', label: 'Posted' },
            ]}
            onChange={setStatusFilter}
          />
        </div>
        {error ? <div className="text-[12px] text-[var(--ui-danger)] mb-2">{error}</div> : null}
        <div className="purchase-list-grid">
          <PosDataGrid
            columns={[
              { key: 'document_number', header: 'Document #', width: 110 },
              { key: 'invoice_date', header: 'Date', width: 100 },
              {
                key: 'supplier',
                header: 'Supplier',
                render: (row) => row.supplier?.name ?? '—',
              },
              {
                key: 'supplier_invoice_number',
                header: 'Supplier Invoice #',
                width: 130,
                render: (row) => row.supplier_invoice_number ?? '—',
              },
              {
                key: 'warehouse',
                header: 'Warehouse',
                width: 120,
                render: (row) => row.warehouse?.name ?? '—',
              },
              {
                key: 'grand_total',
                header: 'Total',
                align: 'right',
                width: 100,
                render: (row) => money(row.grand_total),
              },
              {
                key: 'status',
                header: 'Status',
                width: 90,
                render: (row) => row.status.toUpperCase(),
              },
            ]}
            rows={listRows}
            rowKey={(row) => row.ulid}
            selectedKey={selectedListKey}
            onSelect={(row) => setSelectedListKey(row.ulid)}
            onActivate={(row) => void openInvoice(row.ulid)}
            emptyMessage="No purchase invoices"
            loading={listQuery.isLoading || listQuery.isFetchingNextPage}
            loadingLabel={
              listQuery.isFetchingNextPage
                ? 'Loading more purchase invoices…'
                : 'Loading purchase invoices…'
            }
            onEndReached={() => {
              if (listQuery.hasNextPage && !listQuery.isFetchingNextPage) {
                void listQuery.fetchNextPage()
              }
            }}
          />
        </div>
        <div className="purchase-list-foot">
          <span>{listTotal} {listTotal === 1 ? 'record' : 'records'}</span>
          <span>
            {listQuery.isFetchingNextPage
              ? 'Loading more…'
              : listQuery.hasNextPage
                ? `Loaded ${listRows.length}`
                : 'All records loaded'}
          </span>
        </div>
      </DesktopPanel>
    )
  }

  const editable = !readOnly && (invoiceUlid ? canEdit : canCreate)
  const selectedLine = lines.find((line) => line.key === selectedLineKey) ?? null
  const displaySubtotal = liveTotals.subtotal
  const displayDiscount = liveTotals.discount_amount
  const displayTax = liveTotals.tax_amount
  const displayFurther = liveTotals.further_tax_amount
  const displayGrand = liveTotals.grand_total
  const balancePayable = status === 'posted' ? serverBalancePayable : displayGrand

  async function removeSelectedLine() {
    if (!selectedLine) return
    await removeLine(selectedLine)
    setSelectedLineKey(null)
  }

  return (
    <div className="purchase-reference-screen purchase-entry-screen pie-fit-screen">
      {error ? <div className="pie-error">{error}</div> : null}

      <section className="pie-info-row">
        <div className="pie-panel pie-panel-invoice">
          <div className="pie-panel-head">Supplier / Invoice Information</div>
          <div className="pie-field-grid">
            <label className="pie-field pie-field-span-2">
              <span>Supplier</span>
              <div className="pie-field-row">
                <div
                  ref={supplierPickerAnchorRef}
                  className="pie-supplier-combo"
                >
                  <input
                    className="pie-supplier-combo-input"
                    value={supplierMenuOpen ? supplierQuery : (selectedSupplier?.name ?? supplierQuery)}
                    disabled={!editable}
                    placeholder="Select supplier..."
                    aria-label="Search supplier"
                    autoComplete="off"
                    onFocus={() => {
                      if (!editable) return
                      setSupplierLookupMode('browse')
                      setSupplierQuery(selectedSupplier?.name ?? '')
                      setSupplierMenuOpen(true)
                    }}
                    onChange={(event) => {
                      setSupplierLookupMode('search')
                      setSupplierQuery(event.target.value)
                      setSupplierMenuOpen(true)
                    }}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter' && supplierMenuOpen) {
                        const first = supplierLookupRows[0]
                        if (first) {
                          event.preventDefault()
                          setSupplierUlid(first.ulid)
                          setSupplierLookupMode('browse')
                          setSupplierQuery(first.name)
                          setSupplierMenuOpen(false)
                        }
                      }
                    }}
                  />
                  <button
                    type="button"
                    className="pie-supplier-combo-toggle"
                    aria-label="Open supplier dropdown"
                    aria-expanded={supplierMenuOpen}
                    disabled={!editable}
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={() => {
                      if (!editable) return
                      setSupplierLookupMode('browse')
                      setSupplierQuery(selectedSupplier?.name ?? '')
                      setSupplierMenuOpen((open) => !open)
                    }}
                  >
                    <span className="pie-supplier-combo-arrow" aria-hidden>
                      <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                        <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                      </svg>
                    </span>
                  </button>

                  {supplierMenuOpen ? (
                    <SupplierLookupGrid
                      rows={supplierLookupRows}
                      total={suppliers.length}
                      selectedUlid={supplierUlid}
                      portalAnchorRef={supplierPickerAnchorRef}
                      onRequestClose={() => {
                        setSupplierMenuOpen(false)
                      }}
                      onSelect={(supplier) => {
                        setSupplierUlid(supplier.ulid)
                        setSupplierLookupMode('browse')
                        setSupplierQuery(supplier.name)
                        setSupplierMenuOpen(false)
                      }}
                    />
                  ) : null}
                </div>
                <div className="pie-supplier-actions">
                  <button
                    type="button"
                    className="pie-btn is-ghost"
                    disabled={!editable}
                    title="Create supplier"
                    onClick={() => setSupplierModalOpen(true)}
                  >
                    + New
                  </button>
                </div>
              </div>
            </label>
            <label className="pie-field">
              <span>Document #</span>
              <input value={documentNumber || 'Auto'} disabled />
            </label>
            <label className="pie-field">
              <span>Supplier Inv #</span>
              <input
                value={supplierInvoiceNumber}
                disabled={!editable}
                onChange={(e) => setSupplierInvoiceNumber(e.target.value)}
              />
            </label>
            <label className="pie-field">
              <span>Invoice Date</span>
              <input
                type="date"
                value={invoiceDate}
                disabled={!editable}
                onChange={(e) => setInvoiceDate(e.target.value)}
              />
            </label>
            <label className="pie-field">
              <span>Warehouse</span>
              <UiSelect menuMotion="reveal"
                aria-label="Warehouse"
                value={warehouseUlid}
                disabled
                options={[
                  { value: '', label: 'Select warehouse...' },
                  ...warehouses.map((warehouse) => ({
                    value: warehouse.ulid,
                    label: `${warehouse.code} — ${warehouse.name}`,
                  })),
                ]}
                onChange={setWarehouseUlid}
              />
            </label>
            <label className="pie-field">
              <span>Invoice Type</span>
              <UiSelect menuMotion="reveal"
                aria-label="Invoice Type"
                value={shellInvoiceType}
                disabled={!editable}
                options={[
                  { value: 'tax_gst', label: 'Tax Invoice (GST)' },
                  { value: 'commercial', label: 'Commercial Invoice' },
                  { value: 'proforma', label: 'Proforma' },
                ]}
                onChange={setShellInvoiceType}
              />
            </label>
            <label className="pie-field">
              <span>Currency</span>
              <UiSelect menuMotion="reveal"
                aria-label="Currency"
                value={shellCurrency}
                disabled={!editable}
                options={[
                  { value: 'PKR', label: 'PKR' },
                  { value: 'USD', label: 'USD' },
                  { value: 'AED', label: 'AED' },
                ]}
                onChange={setShellCurrency}
              />
            </label>
          </div>
        </div>

        <div className="pie-panel pie-panel-gst">
          <div className="pie-panel-head">GST Apply On (This Bill)</div>
          <div className="pie-radio-grid">
            {[
              ['mrp_incl_gst', 'MRP (Incl. GST)'],
              ['mrp_ex_gst', 'MRP (Without GST)'],
              ['trade_before_disc', 'Trade Price (Before Disc.)'],
              ['trade_after_disc', 'Trade Price (After Disc.)'],
              ['manual', 'Manual (As per Bill)'],
            ].map(([value, label]) => (
              <label key={value}>
                <input
                  type="radio"
                  name="pie-calc-method"
                  checked={calcMethod === value}
                  disabled={!editable}
                  onChange={() => changeCalcMethod(value)}
                />
                <span>{label}</span>
              </label>
            ))}
          </div>
          <div className="pie-option-block pie-discount-apply">
            <strong>Discount Apply On</strong>
            <div className="pie-discount-apply-row">
              <div className="pie-radio-row">
                {[
                  ['mrp_ex_gst', 'MRP w/o GST'],
                  ['mrp_incl_gst', 'MRP Incl. GST'],
                  ['trade', 'Trade price'],
                ].map(([value, label]) => (
                  <label key={value}>
                    <input
                      type="radio"
                      name="pie-discount-apply"
                      checked={discountApplyOn === value}
                      disabled={!editable}
                      onChange={() => changeDiscountApplyOn(value)}
                    />
                    <span>{label}</span>
                  </label>
                ))}
              </div>
              <label className="pie-inline-check pie-inline-check-inline">
                <input
                  type="checkbox"
                  checked={autoCalcMrp}
                  disabled={!editable}
                  onChange={(e) => setAutoCalcMrp(e.target.checked)}
                />
                <span>Auto calculate MRP &amp; MRP w/o GST</span>
              </label>
            </div>
          </div>
        </div>

        <div className="pie-panel pie-panel-charges">
          <div className="pie-panel-head">Discount Type &amp; Other Charges</div>
          <div className=" pie-disc-input-row">
            <strong>Discount input</strong>
            <div className="pie-chips pie-chips-inline">
              <label>
                <input
                  type="radio"
                  name="pie-disc-input"
                  checked={discInputType === 'pct'}
                  disabled={!editable}
                  onChange={() => {
                    setDiscInputType('pct')
                    const nextModes = {
                      ...dualModes,
                      regular_disc: 'pct' as DualMode,
                      special_disc: 'pct' as DualMode,
                      disc_after_gst: 'pct' as DualMode,
                    }
                    setDualModes(nextModes)
                    recalculateAllLines({ ...calcSettings, dualModes: nextModes })
                  }}
                />
                Percentage (%)
              </label>
              <label>
                <input
                  type="radio"
                  name="pie-disc-input"
                  checked={discInputType === 'rs'}
                  disabled={!editable}
                  onChange={() => {
                    setDiscInputType('rs')
                    const nextModes = {
                      ...dualModes,
                      regular_disc: 'rs' as DualMode,
                      special_disc: 'rs' as DualMode,
                      disc_after_gst: 'rs' as DualMode,
                    }
                    setDualModes(nextModes)
                    recalculateAllLines({ ...calcSettings, dualModes: nextModes })
                  }}
                />
                Amount (Rs.)
              </label>
            </div>
          </div>
          <div className="pie-charge-triple">
            <label>
              <span>Freight (+)</span>
              <input value={freightAmount} disabled={!editable} onChange={(e) => setFreightAmount(e.target.value)} />
            </label>
            <label>
              <span>Loading (+)</span>
              <input value={shellLoading} disabled={!editable} onChange={(e) => setShellLoading(e.target.value)} />
            </label>
            <label>
              <span>Other Chg (+)</span>
              <input value={otherCharges} disabled={!editable} onChange={(e) => setOtherCharges(e.target.value)} />
            </label>
            <label>
              <span>Other Disc (−)</span>
              <input value={shellOtherDiscount} disabled={!editable} onChange={(e) => setShellOtherDiscount(e.target.value)} />
            </label>
                        <label>
              <span>Trade Offer (−)</span>
              <input value={shellTradeOffer} disabled={!editable} onChange={(e) => setShellTradeOffer(e.target.value)} />
            </label>
            <label>
              <span className="pie-charge-with-check">
                Withholding
                <span>
                  <input
                    type="checkbox"
                    checked={withholdingIsPct}
                    disabled={!editable}
                    onChange={(e) => setWithholdingIsPct(e.target.checked)}
                  />
                  %
                </span>
              </span>
              <input
                value={shellAdvanceTax}
                disabled={!editable}
                onChange={(e) => setShellAdvanceTax(e.target.value)}
                title={withholdingIsPct ? 'Withholding %' : 'Withholding amount'}
              />
            </label>
            <label>
              <span>Round Off</span>
              <input value={shellRoundOff} disabled={!editable} onChange={(e) => setShellRoundOff(e.target.value)} />
            </label>
          </div>
        </div>
      </section>

      <section className="pie-items">
        <div className="pie-items-toolbar">
          <strong className="pie-items-title">Item Details</strong>
          <button
            type="button"
            className="pie-btn is-new"
            disabled={!editable}
            onClick={focusNewProductSearch}
            title="Focus new item row"
          >
            <Plus /> Add Item
          </button>
          <button
            type="button"
            className="pie-btn is-danger-ghost"
            disabled={!editable || !selectedLine}
            onClick={() => void removeSelectedLine()}
          >
            <Trash2 /> Delete
          </button>
          <div className="pie-toolbar-actions">
            <button type="button" className="pie-btn is-new" disabled={!canCreate} onClick={startNew}>
              <Plus /> New
            </button>
            <button
              type="button"
              className="pie-btn is-save"
              disabled={readOnly || saveMutation.isPending || (invoiceUlid ? !canEdit : !canCreate)}
              onClick={() => void onSave()}
            >
              <Save /> Save
            </button>
            <button
              type="button"
              className="pie-btn is-post"
              disabled={readOnly || !canPost || postMutation.isPending}
              onClick={() => void onPost()}
            >
              <Send /> Post
            </button>
            <button
              type="button"
              className="pie-btn is-ghost"
              disabled={status !== 'posted' || !invoiceUlid || !canCreateReturn}
              onClick={openPurchaseReturn}
              title="Create purchase return from this invoice"
            >
              <RotateCcw /> Return
            </button>
            <button type="button" className="pie-btn is-print" disabled>
              <Printer /> Print
            </button>
            <button type="button" className="pie-btn is-close" onClick={backToList}>
              <X /> Close
            </button>
          </div>
          <button
            type="button"
            className="pie-btn is-ghost"
            disabled={!invoiceUlid}
            onClick={() => invoiceUlid && void openInvoice(invoiceUlid)}
          >
            <RefreshCw /> Refresh
          </button>
          <button
            type="button"
            className={`pie-btn is-settings${customizationOpen ? ' is-open' : ''}`}
            onClick={() => setCustomizationOpen((open) => !open)}
          >
            <Settings2 /> Item Wise Setting
          </button>
        </div>

        <div className="purchase-reference-grid-wrap">
          <table className="purchase-reference-grid">
            <thead>
              <tr>
                {effectivePurchaseColumns.map((col) => (
                  <th
                    key={col.key}
                    className={`col-${col.key}${col.locked ? ' is-locked' : ''}`}
                    style={col.width ? { width: col.width, minWidth: col.width } : undefined}
                    draggable={!col.locked}
                    onDragStart={(event) => {
                      if (col.locked) return
                      event.dataTransfer.setData('text/bp-col', col.key)
                      event.dataTransfer.effectAllowed = 'move'
                    }}
                    onDragOver={(event) => {
                      if (col.locked) return
                      event.preventDefault()
                    }}
                    onDrop={(event) => {
                      event.preventDefault()
                      const from = event.dataTransfer.getData('text/bp-col')
                      if (from) columnLayout.moveColumn(from, col.key)
                    }}
                    onContextMenu={(event) => {
                      event.preventDefault()
                      if (!col.locked) columnLayout.hideColumn(col.key)
                    }}
                    title={col.locked ? col.label : `${col.label} — drag to move, right-click to hide`}
                  >
                    {col.key === 'delete' ? (
                      <button
                        type="button"
                        className={`purchase-reference-customize-trigger${customizationOpen ? ' is-open' : ''}`}
                        title="Customize columns"
                        aria-label="Customize columns"
                        aria-expanded={customizationOpen}
                        onClick={() => setCustomizationOpen((open) => !open)}
                      >
                        −
                      </button>
                    ) : col.dualPctRs ? (
                      <div className="pie-dual-head">
                        <span>{col.label}</span>
                        <div className="pie-dual-toggles">
                          <button
                            type="button"
                            className={dualModes[col.key] === 'pct' ? 'is-active' : undefined}
                            onClick={(e) => {
                              e.stopPropagation()
                              setDualMode(col.key, 'pct')
                            }}
                          >
                            %
                          </button>
                          <button
                            type="button"
                            className={dualModes[col.key] === 'rs' ? 'is-active' : undefined}
                            onClick={(e) => {
                              e.stopPropagation()
                              setDualMode(col.key, 'rs')
                            }}
                          >
                            Rs
                          </button>
                        </div>
                      </div>
                    ) : (
                      col.label
                    )}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {lines.map((row, rowIndex) => (
                <tr
                  key={row.key}
                  data-purchase-row-key={row.key}
                  className={selectedLineKey === row.key ? 'is-selected' : undefined}
                  onClick={() => setSelectedLineKey(row.key)}
                >
                  {effectivePurchaseColumns.map((col) =>
                    renderPurchaseCell(col, row, {
                      readOnly,
                      canEdit,
                      supplierName: selectedSupplier?.name ?? '',
                      warehouseLabel,
                      stockQty: stockByProduct.get(row.product_ulid),
                      rowIndex,
                      onRemove: () => void removeLine(row),
                      openCustomization: () => setCustomizationOpen((open) => !open),
                      customizationOpen,
                    }),
                  )}
                </tr>
              ))}
              {editable ? (
                <tr className="is-empty is-new-line">
                  {effectivePurchaseColumns.map((col) =>
                    renderPurchaseCell(col, null, {
                      readOnly,
                      canEdit: editable,
                      supplierName: selectedSupplier?.name ?? '',
                      warehouseLabel,
                      openCustomization: () => setCustomizationOpen((open) => !open),
                      customizationOpen,
                    }),
                  )}
                </tr>
              ) : lines.length === 0 ? (
                <tr className="is-empty">
                  {effectivePurchaseColumns.map((col) =>
                    renderPurchaseCell(col, null, {
                      readOnly,
                      canEdit: false,
                      supplierName: selectedSupplier?.name ?? '',
                      warehouseLabel,
                      openCustomization: () => setCustomizationOpen((open) => !open),
                      customizationOpen,
                    }),
                  )}
                </tr>
              ) : null}
            </tbody>
          </table>
        </div>
      </section>

      <section className="pie-bottom pie-bottom-mock">
        <div className="pie-panel pie-final">
          <div className="pie-panel-head">Sub Total / Grand Total</div>
          <div className="pie-summary-grid">
            <div><span>Sub Total</span><strong>{displaySubtotal}</strong></div>
            <div><span>Total Discount</span><strong>{displayDiscount}</strong></div>
            <div><span>Total Tax</span><strong>{displayTax}</strong></div>
            <div><span>Further Tax</span><strong>{displayFurther}</strong></div>
            <div><span>Freight (+)</span><strong>{money(freightAmount)}</strong></div>
            <div><span>Loading (+)</span><strong>{money(shellLoading)}</strong></div>
            <div><span>Other Chg (+)</span><strong>{money(otherCharges)}</strong></div>
            <div><span>Other Disc (−)</span><strong>{money(shellOtherDiscount)}</strong></div>
            <div><span>Trade Offer (−)</span><strong>{money(shellTradeOffer)}</strong></div>
            <div><span>Withholding</span><strong>{liveTotals.advance_tax_amount}</strong></div>
            <div><span>Round Off</span><strong>{money(shellRoundOff)}</strong></div>
          </div>
          <div className="pie-grand">
            <span>Grand Total</span>
            <strong>{displayGrand}</strong>
          </div>
        </div>

        <div className="pie-panel pie-panel-payment">
          <div className="pie-panel-head">Payment Information</div>
          <div className="pie-payment-grid">
            <label className="pie-field">
              <span>Terms</span>
              <UiSelect menuMotion="reveal"
                aria-label="Payment Terms"
                value={shellPaymentTerms}
                disabled={!editable}
                options={[
                  { value: 'credit', label: 'Credit' },
                  { value: 'cash', label: 'Cash' },
                  { value: 'advance', label: 'Advance' },
                ]}
                onChange={setShellPaymentTerms}
              />
            </label>
            <label className="pie-field">
              <span>Paid</span>
              <div className="pie-field-row">
                <input
                  value={status === 'posted' ? paidAmount : '0.0000'}
                  disabled
                  aria-label="Purchase paid amount"
                />
                <UiButton
                  variant="success"
                  label="Record"
                  disabled={
                    status !== 'posted' ||
                    !invoiceUlid ||
                    !canCreatePayment ||
                    Number(balancePayable) <= 0
                  }
                  onClick={() => {
                    setPaymentAmount(balancePayable)
                    setPaymentReference('')
                    setPaymentMethod('cash')
                    setPaymentIdempotencyKey(crypto.randomUUID())
                    setPaymentModalOpen(true)
                  }}
                />
              </div>
            </label>
            <label className="pie-field">
              <span>Due Date</span>
              <input
                type="date"
                value={dueDate}
                disabled={!editable || shellPaymentTerms === 'cash'}
                onChange={(e) => setDueDate(e.target.value)}
              />
            </label>
            <div className="pie-balance-payable">
              <span>Balance Payable</span>
              <strong>{balancePayable}</strong>
            </div>
          </div>
          <label className="pie-field pie-notes-inline">
            <span>Notes</span>
            <textarea
              className="pie-inline-input pie-notes-fit"
              value={notes}
              disabled={!editable}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="Notes / narration"
              rows={2}
            />
          </label>
        </div>
      </section>

      <UiModal
        open={paymentModalOpen}
        onClose={() => {
          if (!paymentMutation.isPending) setPaymentModalOpen(false)
        }}
        size="sm"
        ariaLabel="Record purchase payment"
      >
        <div className="pie-panel-payment">
          <div className="pie-payment-grid">
            <label className="pie-field">
              <span>Amount</span>
              <input
                value={paymentAmount}
                inputMode="decimal"
                disabled={paymentMutation.isPending}
                onChange={(event) => setPaymentAmount(event.target.value)}
              />
            </label>
            <label className="pie-field">
              <span>Method</span>
              <UiSelect menuMotion="reveal"
                aria-label="Purchase payment method"
                value={paymentMethod}
                disabled={paymentMutation.isPending}
                options={[
                  { value: 'cash', label: 'Cash' },
                  { value: 'card', label: 'Card' },
                  { value: 'bank', label: 'Bank' },
                ]}
                onChange={(value) => setPaymentMethod(value as PurchasePaymentMethod)}
              />
            </label>
            <label className="pie-field">
              <span>Reference</span>
              <input
                value={paymentReference}
                maxLength={100}
                disabled={paymentMutation.isPending}
                onChange={(event) => setPaymentReference(event.target.value)}
                placeholder="Cheque / transaction / reference"
              />
            </label>
            <div className="pie-balance-payable">
              <span>Balance Payable</span>
              <strong>{balancePayable}</strong>
            </div>
          </div>
          <div className="pie-field-row">
            <UiButton
              variant="success"
              label={paymentMutation.isPending ? 'Recording…' : 'Record Payment'}
              disabled={
                paymentMutation.isPending ||
                !paymentAmount ||
                Number(paymentAmount) <= 0 ||
                Number(paymentAmount) > Number(balancePayable)
              }
              onClick={() => void onRecordPayment()}
            />
            <UiButton
              variant="info"
              label="Close"
              disabled={paymentMutation.isPending}
              onClick={() => setPaymentModalOpen(false)}
            />
          </div>
        </div>
      </UiModal>

      {supplierModalOpen ? (
        <PartyModal
          partyType="vendor"
          onClose={() => setSupplierModalOpen(false)}
          onSaved={(party) => {
            const vendorUlid =
              party.vendor_ulid ?? (party.party_type === 'vendor' ? party.ulid : '')
            const scope = suppliersQuery.scope
            if (!scope) {
              setSupplierModalOpen(false)
              return
            }

            void refreshSuppliers(scope, { force: true }).then(async () => {
              const { data } = await suppliersQuery.refetch()
              const savedSupplier =
                data?.find((supplier) => supplier.ulid === vendorUlid) ??
                data?.find((supplier) => supplier.code === party.code)
              if (savedSupplier) setSupplierUlid(savedSupplier.ulid)
              setSupplierModalOpen(false)
            })
          }}
        />
      ) : null}

      {productModalOpen ? (
        <ProductModal
          onClose={() => setProductModalOpen(false)}
          onSaved={(product) => {
            setProductModalOpen(false)
            const scope = productLookup.scope
            if (scope) {
              void upsertCachedProduct(scope, product).then(() => productLookup.refetch())
            }
            if (productModalTargetRowKey === 'new') {
              void addProduct(product)
            } else {
              void addProduct(product, productModalTargetRowKey)
            }
          }}
        />
      ) : null}

      <ColumnCustomizationPanel
        open={customizationOpen}
        hiddenColumns={columnLayout.hiddenColumns}
        visibleColumns={columnLayout.visibleColumns}
        onClose={() => setCustomizationOpen(false)}
        onShow={columnLayout.showColumn}
        onHide={columnLayout.hideColumn}
        onToggleLock={columnLayout.toggleLock}
        onMove={columnLayout.moveColumn}
        onReset={columnLayout.resetToDefaults}
        onSaveRoleDefault={columnLayout.saveAsRoleDefault}
        canSaveRoleDefault={canSaveRoleDefault}
      />
    </div>
  )
}
