import {
  ChevronDown,
  CircleHelp,
  FileText,
  FolderOpen,
  Grid3X3,
  Plus,
  Printer,
  ReceiptText,
  RefreshCw,
  Save,
  StickyNote,
  WalletCards,
  XCircle,
} from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ApiClientError } from '../api/client'
import { fetchBusinessSettings, fetchProduct, fetchProductStock, fetchProducts } from '../api/catalog'
import { fetchParties, fetchParty, type Party } from '../api/parties'
import { evaluateSaleOffers } from '../api/saleSchemes'
import { createSale, createSaleHold, createSaleQuotation, deleteSaleHold, fetchSale, fetchSaleHolds, fetchSalePaymentAccounts, fetchSaleQuotation, fetchSaleQuotations, fetchSales, fetchSalesmen } from '../api/sales'
import { previewSaleReceipt, printSaleReceipt } from '../features/sales/saleReceipt'
import { PackagingPicker } from '../features/sales/PackagingPicker'
import { SaleFinalizePaymentPanel } from '../features/sales/SaleFinalizePaymentPanel'
import { PartyModal } from '../components/parties/PartyModal'
import { SchemeOfferPrompt } from '../features/sales/SchemeOfferPrompt'
import { SalesExpenses } from '../features/sales/SalesExpenses'
import { SalesInvoiceHistory } from '../features/sales/SalesInvoiceHistory'
import { SalesPendingInvoices } from '../features/sales/SalesPendingInvoices'
import { SalesProductWiseSummary } from '../features/sales/SalesProductWiseSummary'
import { ProductLookupGrid } from '../features/sales/ProductLookupGrid'
import { useSaleCart } from '../features/sales/useSaleCart'
import { useCan } from '../features/auth/useCan'
import { useAuth } from '../features/auth/AuthProvider'
import { useLocalProducts } from '../features/catalog/useLocalCatalog'
import { useBarcodeScannerTarget } from '../features/scanner/useBarcodeScanner'
import type { SaleOfferEvaluation } from '../types/saleSchemes'
import type { Sale, SaleHold, SalePaymentMethod, SalePayload } from '../types/sales'
import { ColumnCustomizationPanel } from '../features/gridLayout/ColumnCustomizationPanel'
import { UiModal } from '../components/ui/UiModal'
import { UiSelect } from '../components/ui/UiSelect'
import {
  SALES_INVOICE_COLUMNS,
  SALES_INVOICE_SCREEN,
  type ResolvedGridColumn,
} from '../features/gridLayout/columnCatalog'
import { useColumnLayout } from '../features/gridLayout/useColumnLayout'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import type { SaleDraftLine } from '../types/sales'
import type { Product } from '../types/catalog'
import './SalesInvoicePage.theme.css'
import './SalesInvoicePage.cart.css'

const SALES_COL_CLASS: Record<string, string> = {
  selector: 'col-selector',
  product: 'col-product',
  in_stock: 'col-stock',
  sales_qty: 'col-qty',
  price: 'col-price',
  amt: 'col-amt',
  disc_pct: 'col-disc',
  disc_rs: 'col-discrs',
  net_amt: 'col-net',
  delete: 'col-delete',
}

function salesColClass(col: ResolvedGridColumn): string {
  return SALES_COL_CLASS[col.key] ?? `col-${col.key}`
}

function displayEditableNumber(value: string | null | undefined): string {
  if (value === '' || value == null) return value ?? ''

  const parsed = Number.parseFloat(value)
  if (!Number.isFinite(parsed)) return value
  if (parsed === 0) return '0.00'

  const [integerPart, fractionPart = ''] = value.split('.')
  const meaningfulFraction = fractionPart.replace(/0+$/, '')
  const normalizedFraction = meaningfulFraction.padEnd(2, '0')

  return normalizedFraction
    ? `${integerPart}.${normalizedFraction}`
    : `${integerPart}.00`
}

function newSaleKey(): string {
  return `pos-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

function newHoldKey(): string {
  return `pos-hold-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

export function SalesInvoicePage({
  active = true,
  workspaceSearch,
}: {
  active?: boolean
  workspaceSearch?: string
} = {}) {
  const { closeActiveTab } = useWorkspace()
  const feedback = useFeedback()
  const [routeSearchParams, setSearchParams] = useSearchParams()
  const searchParams = useMemo(
    () =>
      workspaceSearch === undefined
        ? routeSearchParams
        : new URLSearchParams(workspaceSearch),
    [routeSearchParams, workspaceSearch],
  )

  const [view, setView] = useState<'pos' | 'history' | 'pending' | 'expenses' | 'product'>('pos')
  const [customizationOpen, setCustomizationOpen] = useState(false)

  const canSaveRoleDefault = useCan('roles.edit')
  const canCreateSale = useCan('sales.create')
  const canHoldSale = useCan('sales.hold')
  const canRecallSale = useCan('sales.recall')
  const canCollectPayment = useCan('payments.create')

  const columnLayout = useColumnLayout({
    screenKey: SALES_INVOICE_SCREEN,
    catalog: SALES_INVOICE_COLUMNS,
  })

  const cart = useSaleCart()

  const [productQuery, setProductQuery] = useState('')
  const [activeProductQuery, setActiveProductQuery] = useState('')
  const [activeProductPickerOpen, setActiveProductPickerOpen] = useState(false)
  const [productLookupMode, setProductLookupMode] = useState<'browse' | 'search'>('browse')
  const [barcodeQuery, setBarcodeQuery] = useState('')
  const [activeLineKey, setActiveLineKey] = useState<string | null>(null)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [barcodeLookupError, setBarcodeLookupError] = useState<string | null>(null)
  const barcodeLookupErrorSequenceRef = useRef(0)
  const [savedSale, setSavedSale] = useState<Sale | null>(null)
  const [paymentModalOpen, setPaymentModalOpen] = useState(false)
  const [recalledHoldUlid, setRecalledHoldUlid] = useState<string | null>(null)

  const [received, setReceived] = useState('')
  const [paymentMethod, setPaymentMethod] =
    useState<SalePaymentMethod>('cash')
  const [paymentReference, setPaymentReference] = useState('')
  const [paymentDue, setPaymentDue] = useState(false)

  const [saleDate, setSaleDate] = useState(
    () => new Date().toISOString().slice(0, 10),
  )

  const [copyFromValue, setCopyFromValue] = useState('')
  const [quotationValue, setQuotationValue] = useState('')

  const [selectedCustomer, setSelectedCustomer] = useState<Party | null>(null)
  const [customerPickerOpen, setCustomerPickerOpen] = useState(false)
  const [customerSearch, setCustomerSearch] = useState('CASH IN HAND')
  const [customerPickerSearch, setCustomerPickerSearch] = useState('')
  const [salesmanPickerOpen, setSalesmanPickerOpen] = useState(false)
  const [salesmanSearch, setSalesmanSearch] = useState('')
  const [partyModalOpen, setPartyModalOpen] = useState(false)

  const { session } = useAuth()
  const salesProductLookupQuery =
    productLookupMode === 'search'
      ? (activeLineKey ? activeProductQuery : productQuery)
      : ''
  const localProductLookup = useLocalProducts(
    session,
    salesProductLookupQuery,
    active && view === 'pos' && !savedSale,
  )
  const localProductLookupRows = localProductLookup.rows.filter(
    (product) => product.is_active && product.status === 'active',
  )
  const serverProductLookupFallback = useQuery({
    queryKey: ['sales', 'product-lookup-fallback', salesProductLookupQuery],
    queryFn: () =>
      fetchProducts(
        {
          q: salesProductLookupQuery,
          page: 1,
          per_page: 50,
          sales_lookup: true,
          active_only: true,
        },
        { busy: 'none' },
      ),
    enabled:
      active &&
      view === 'pos' &&
      !savedSale &&
      productLookupMode === 'search' &&
      salesProductLookupQuery.trim() !== '' &&
      !localProductLookup.isFetching &&
      localProductLookupRows.length === 0,
  })
  const productLookupRows =
    localProductLookupRows.length > 0
      ? localProductLookupRows
      : (serverProductLookupFallback.data?.data ?? []).filter(
          (product) => product.is_active && product.status === 'active',
        )
  const productLookupTotal =
    localProductLookupRows.length > 0
      ? localProductLookupRows.length
      : (serverProductLookupFallback.data?.meta.total ?? productLookupRows.length)

  const [selectedSalesmanUlid, setSelectedSalesmanUlid] = useState<string | null>(
    null,
  )

  const salesmenQuery = useQuery({
    queryKey: ['sales', 'salesmen', session?.branch.ulid],
    queryFn: fetchSalesmen,
    enabled: canCreateSale,
    retry: false,
  })

  const paymentAccountsQuery = useQuery({
    queryKey: ['sales', 'payment-accounts'],
    queryFn: fetchSalePaymentAccounts,
    enabled: paymentModalOpen && canCreateSale,
    retry: false,
  })

  const holdsQuery = useQuery({
    queryKey: ['sales', 'holds', session?.branch.ulid, session?.warehouse.ulid],
    queryFn: fetchSaleHolds,
    enabled: Boolean(session) && canRecallSale,
    retry: false,
  })

  const selectedSalesman = useMemo(
    () => (salesmenQuery.data ?? []).find((row) => row.ulid === selectedSalesmanUlid) ?? null,
    [salesmenQuery.data, selectedSalesmanUlid],
  )

  const filteredSalesmen = useMemo(() => {
    const query = salesmanSearch.trim().toLocaleLowerCase()
    if (!query) return salesmenQuery.data ?? []

    return (salesmenQuery.data ?? []).filter((salesman) =>
      [salesman.code, salesman.name, salesman.address, salesman.mobile]
        .filter(Boolean)
        .some((value) => String(value).toLocaleLowerCase().includes(query)),
    )
  }, [salesmenQuery.data, salesmanSearch])

  const balanceDue = useMemo(() => {
    const total = Number.parseFloat(cart.preview.grandTotal) || 0
    const paid = Number.parseFloat(received) || 0

    return Math.max(total - paid, 0).toFixed(2)
  }, [cart.preview.grandTotal, received])


  const businessSettingsQuery = useQuery({
    queryKey: ['settings', 'business', 'sales-stock'],
    queryFn: fetchBusinessSettings,
    retry: false,
  })

  const saleSoftwareOptions = businessSettingsQuery.data?.software_options?.sale
  const generalSoftwareOptions = businessSettingsQuery.data?.software_options?.general
  const receiptOptions = useMemo(
    () => ({
      heading: saleSoftwareOptions?.print_heading ?? null,
      message: saleSoftwareOptions?.message ?? null,
      fbrMessage: saleSoftwareOptions?.fbr_message ?? null,
      dottedItemLines: saleSoftwareOptions?.print_dotted_line ?? false,
      fontFamily: generalSoftwareOptions?.use_custom_font_all
        ? (generalSoftwareOptions.font_family ?? null)
        : generalSoftwareOptions?.apply_custom_font_style
          ? (generalSoftwareOptions.custom_font_family ?? null)
          : null,
      fontSize: generalSoftwareOptions?.use_custom_font_all
        ? (generalSoftwareOptions.font_size ?? 11)
        : generalSoftwareOptions?.apply_custom_font_style
          ? (generalSoftwareOptions.custom_font_size ?? null)
          : null,
    }),
    [
      saleSoftwareOptions?.print_heading,
      saleSoftwareOptions?.message,
      saleSoftwareOptions?.fbr_message,
      saleSoftwareOptions?.print_dotted_line,
      generalSoftwareOptions?.use_custom_font_all,
      generalSoftwareOptions?.font_family,
      generalSoftwareOptions?.font_size,
      generalSoftwareOptions?.apply_custom_font_style,
      generalSoftwareOptions?.custom_font_family,
      generalSoftwareOptions?.custom_font_size,
    ],
  )

  const effectiveSalesColumns = useMemo(
    () =>
      columnLayout.visibleColumns.filter((col) => {
        if (col.key === 'disc_pct' && saleSoftwareOptions?.show_discount_percent === false) {
          return false
        }
        if (col.key === 'disc_rs' && saleSoftwareOptions?.show_discount_amount === false) {
          return false
        }
        if (col.key === 'remarks' && saleSoftwareOptions?.show_remarks === false) {
          return false
        }
        return true
      }),
    [
      columnLayout.visibleColumns,
      saleSoftwareOptions?.show_discount_percent,
      saleSoftwareOptions?.show_discount_amount,
      saleSoftwareOptions?.show_remarks,
    ],
  )

  const customersQuery = useQuery({
    queryKey: ['parties', 'customers', 'pos'],
    queryFn: () => fetchParties('customer'),
    retry: false,
  })

  const filteredCustomers = useMemo(() => {
    const query = customerPickerSearch.trim().toLocaleLowerCase()
    if (!query) return customersQuery.data ?? []

    return (customersQuery.data ?? []).filter((party) =>
      [party.code, party.name, party.address, party.mobile, party.phone]
        .filter(Boolean)
        .some((value) => String(value).toLocaleLowerCase().includes(query)),
    )
  }, [customersQuery.data, customerPickerSearch])

  /*
   * IMPORTANT:
   * Do not hide schemes based on the local permission hook.
   *
   * The sale-offer endpoint already controls access on the backend.
   * sales.create is also accepted by SaleOfferController.
   *
   * Therefore eligible schemes should actually be visible here.
   */
  const offersQuery = useQuery({
    queryKey: [
      'sale-offers',
      cart.preview.qualifyingSubtotal,
      saleDate,
      cart.appliedSchemeUlids.join(','),
    ],
    queryFn: () =>
      evaluateSaleOffers({
        subtotal: cart.preview.qualifyingSubtotal,
        document_date: saleDate,
      }),
    enabled: cart.hasPaidLines,
    retry: false,
  })

  const offers: SaleOfferEvaluation | undefined = offersQuery.data

  const packagingQtyInCart = useMemo(() => {
    const map: Record<string, string> = {}

    for (const line of cart.packagingLines) {
      map[line.product_ulid] = line.quantity
    }

    return map
  }, [cart.packagingLines])

  /*
   * Show whatever the server says is eligible.
   * The backend remains the source of truth when saving.
   */
  const visibleSchemes = useMemo(
    () => offers?.schemes ?? [],
    [offers],
  )

  const visiblePackaging = useMemo(
    () => offers?.packaging ?? [],
    [offers],
  )

  const idempotencyKeyRef = useRef(newSaleKey())
  const holdIdempotencyKeyRef = useRef(newHoldKey())
  const quotationIdempotencyKeyRef = useRef(newSaleKey())
  const receivedRef = useRef<HTMLInputElement | null>(null)
  const productSearchRef = useRef<HTMLInputElement | null>(null)
  const productPickerAnchorRef = useRef<HTMLDivElement | null>(null)

  const copyFromMutation = useMutation({
    mutationFn: async () => {
      const requestedNumber = copyFromValue.trim()

      if (!requestedNumber) {
        throw new Error('Enter a posted invoice number to copy.')
      }

      if (cart.lines.length > 0) {
        throw new Error('Clear the current invoice before using Copy From.')
      }

      const lookup = await fetchSales({
        q: requestedNumber,
        status: 'posted',
        per_page: 20,
      })

      const sourceSummary = lookup.data.find(
        (sale) =>
          sale.document_number.trim().toLocaleLowerCase() ===
          requestedNumber.toLocaleLowerCase(),
      )

      if (!sourceSummary) {
        throw new Error('No posted invoice with that exact number was found in this branch.')
      }

      const source = await fetchSale(sourceSummary.ulid)
      const productUlids = Array.from(
        new Set(
          source.items
            .map((item) => item.product?.ulid)
            .filter((ulid): ulid is string => Boolean(ulid)),
        ),
      )

      const productRows = await Promise.all(
        productUlids.map(async (productUlid) => {
          const [product, stock] = await Promise.all([
            fetchProduct(productUlid),
            fetchProductStock(productUlid),
          ])

          return {
            productUlid,
            product,
            stock: stock.active_warehouse.quantity,
          }
        }),
      )

      const customer = source.customer
        ? await fetchParty(source.customer.ulid, 'customer')
        : null

      return {
        source,
        customer,
        productsByUlid: Object.fromEntries(
          productRows.map((row) => [row.productUlid, row.product]),
        ),
        stockByProduct: Object.fromEntries(
          productRows.map((row) => [row.productUlid, row.stock]),
        ),
      }
    },

    onSuccess: ({ source, customer, productsByUlid, stockByProduct }) => {
      cart.restoreSaleDocument(source, productsByUlid, stockByProduct)

      setCopyFromValue(source.document_number)
      setQuotationValue('')
      setSelectedCustomer(customer)
      setCustomerSearch(customer?.name ?? 'CASH IN HAND')
      setSelectedSalesmanUlid(source.salesman?.ulid ?? null)
      setSalesmanSearch(
        source.salesman
          ? `${source.salesman.code ?? ''}${source.salesman.code ? ' — ' : ''}${source.salesman.name}`
          : '',
      )
      setSaleDate(new Date().toISOString().slice(0, 10))
      setPaymentDue(false)
      setReceived('')
      setPaymentReference('')
      setSavedSale(null)
      setRecalledHoldUlid(null)
      setSaveError(null)
      setProductQuery('')
      setActiveProductQuery('')
      setActiveProductPickerOpen(false)
      setBarcodeQuery('')
      setActiveLineKey(null)
      idempotencyKeyRef.current = newSaleKey()

      feedback.success(
        `Copied ${source.document_number} into a new unsaved invoice. Pricing and stock will be revalidated on save.`,
        'Copy From',
      )
    },

    onError: (err) => {
      setSaveError(
        err instanceof ApiClientError || err instanceof Error
          ? err.message
          : 'Unable to copy the posted invoice.',
      )
    },
  })

  const saveQuotationMutation = useMutation({
    mutationFn: () => {
      if (!cart.hasPaidLines) {
        throw new Error('Add at least one paid product before saving a quotation.')
      }

      const payload = cart.buildPayload()

      return createSaleQuotation(
        {
          price_type: cart.priceType,
          items: payload.items,
          free_lines: payload.free_lines,
          applied_schemes: payload.applied_schemes,
          customer_ulid: cart.customerUlid,
          salesman_ulid: selectedSalesmanUlid,
          notes: cart.notes || null,
          quotation_date: saleDate,
        },
        quotationIdempotencyKeyRef.current,
      )
    },

    onSuccess: (quotation) => {
      setQuotationValue(quotation.document_number)
      setCopyFromValue('')
      setSaveError(null)
      quotationIdempotencyKeyRef.current = newSaleKey()

      feedback.success(
        `Quotation ${quotation.document_number} saved. No stock or payment was posted.`,
        'Quotation',
      )
    },

    onError: (err) => {
      setSaveError(
        err instanceof ApiClientError || err instanceof Error
          ? err.message
          : 'Unable to save the quotation.',
      )
    },
  })

  const quotationLookupMutation = useMutation({
    mutationFn: async (requestedNumberOverride?: string) => {
      const requestedNumber = (requestedNumberOverride ?? quotationValue).trim()

      if (!requestedNumber) {
        throw new Error('Enter a quotation number to load.')
      }

      if (cart.lines.length > 0) {
        throw new Error('Clear the current invoice before loading a quotation.')
      }

      const lookup = await fetchSaleQuotations({
        q: requestedNumber,
        per_page: 20,
      })

      const summary = lookup.data.find(
        (quotation) =>
          quotation.document_number.trim().toLocaleLowerCase() ===
          requestedNumber.toLocaleLowerCase(),
      )

      if (!summary) {
        throw new Error(
          'No quotation with that exact number was found in this branch and warehouse.',
        )
      }

      const source = await fetchSaleQuotation(summary.ulid)

      const productUlids = Array.from(
        new Set(
          source.items
            .map((item) => item.product?.ulid)
            .filter((ulid): ulid is string => Boolean(ulid)),
        ),
      )

      const productRows = await Promise.all(
        productUlids.map(async (productUlid) => {
          const [product, stock] = await Promise.all([
            fetchProduct(productUlid),
            fetchProductStock(productUlid),
          ])

          return {
            productUlid,
            product,
            stock: stock.active_warehouse.quantity,
          }
        }),
      )

      const customer = source.customer
        ? await fetchParty(source.customer.ulid, 'customer')
        : null

      return {
        source,
        customer,
        productsByUlid: Object.fromEntries(
          productRows.map((row) => [row.productUlid, row.product]),
        ),
        stockByProduct: Object.fromEntries(
          productRows.map((row) => [row.productUlid, row.stock]),
        ),
      }
    },

    onSuccess: ({ source, customer, productsByUlid, stockByProduct }) => {
      cart.restoreSaleDocument(source, productsByUlid, stockByProduct)

      setQuotationValue(source.document_number)
      setCopyFromValue('')
      setSelectedCustomer(customer)
      setCustomerSearch(customer?.name ?? 'CASH IN HAND')
      setSelectedSalesmanUlid(source.salesman?.ulid ?? null)
      setSalesmanSearch(
        source.salesman
          ? `${source.salesman.code ?? ''}${source.salesman.code ? ' — ' : ''}${source.salesman.name}`
          : '',
      )
      setSaleDate(new Date().toISOString().slice(0, 10))
      setPaymentDue(false)
      setReceived('')
      setPaymentReference('')
      setSavedSale(null)
      setRecalledHoldUlid(null)
      setSaveError(null)
      setProductQuery('')
      setActiveProductQuery('')
      setActiveProductPickerOpen(false)
      setBarcodeQuery('')
      setActiveLineKey(null)
      idempotencyKeyRef.current = newSaleKey()

      feedback.success(
        `Loaded ${source.document_number} into a new unsaved Sales Invoice. Pricing and stock will be revalidated on save.`,
        'Quotation',
      )

      if (workspaceSearch === undefined && searchParams.has('quotation')) {
        const nextParams = new URLSearchParams(searchParams)
        nextParams.delete('quotation')
        setSearchParams(nextParams, { replace: true })
      }
    },

    onError: (err) => {
      setSaveError(
        err instanceof ApiClientError || err instanceof Error
          ? err.message
          : 'Unable to load the quotation.',
      )
    },
  })

  const autoQuotationNumber = searchParams.get('quotation')?.trim() ?? ''
  const autoQuotationAttemptedRef = useRef('')

  useEffect(() => {
    if (!active) return
    if (!autoQuotationNumber) return
    if (autoQuotationAttemptedRef.current === autoQuotationNumber) return
    if (quotationLookupMutation.isPending) return

    autoQuotationAttemptedRef.current = autoQuotationNumber
    setQuotationValue(autoQuotationNumber)
    quotationLookupMutation.mutate(autoQuotationNumber)
  }, [autoQuotationNumber, quotationLookupMutation.isPending])

  const saveMutation = useMutation({
    mutationFn: async (
      initialPayments: NonNullable<SalePayload['initial_payments']>,
    ) => {
      return createSale(
        {
          ...cart.buildPayload(),
          sale_date: saleDate,
          notes: cart.notes || null,
          customer_ulid: cart.customerUlid,
          salesman_ulid: selectedSalesmanUlid,
          payment_due: paymentDue,
          initial_payments:
            initialPayments.length > 0 ? initialPayments : undefined,
        },
        idempotencyKeyRef.current,
      )
    },

    onSuccess: (sale) => {
      setSavedSale(sale)
      setPaymentModalOpen(false)
      setSaveError(null)

      cart.clear()
      setSelectedCustomer(null)
      setPaymentDue(false)
      setCustomerSearch('CASH IN HAND')
      setProductQuery('')
      setActiveProductQuery('')
      setActiveProductPickerOpen(false)
      setCustomerPickerOpen(false)
      setSalesmanPickerOpen(false)
      setCopyFromValue('')
      setQuotationValue('')

      idempotencyKeyRef.current = newSaleKey()
      quotationIdempotencyKeyRef.current = newSaleKey()

      if (recalledHoldUlid) {
        const recalledUlid = recalledHoldUlid
        setRecalledHoldUlid(null)

        void deleteSaleHold(recalledUlid)
          .then(() => holdsQuery.refetch())
          .catch(() => {
            feedback.error(
              'Sale posted, but its old recovery hold could not be removed. You may discard that hold manually.',
              'On Hold',
            )
          })
      }

      setReceived('')
      setPaymentReference('')
    },

    onError: (err) => {
      setSavedSale(null)

      setSaveError(
        err instanceof ApiClientError
          ? err.message
          : 'Unable to save the sale.',
      )
    },
  })

  const holdMutation = useMutation({
    mutationFn: () =>
      createSaleHold(
        {
          sale_date: saleDate,
          customer_ulid: cart.customerUlid,
          salesman_ulid: selectedSalesmanUlid,
          notes: cart.notes || null,
          price_type: cart.priceType,
          lines: cart.lines.map((line) => ({
            product_ulid: line.product_ulid,
            line_kind: line.line_kind,
            unit_ulid: line.unit_ulid ?? null,
            scheme_ulid: line.scheme_ulid ?? null,
            barcode: line.barcode ?? null,
            quantity: line.quantity,
            discount_percent: line.discount_percent || '0',
            discount_amount: line.discount_amount || '0',
            notes: line.notes ?? null,
          })),
        },
        holdIdempotencyKeyRef.current,
      ),

    onSuccess: async () => {
      holdIdempotencyKeyRef.current = newHoldKey()
      setSaveError(null)

      if (recalledHoldUlid) {
        const previousHold = recalledHoldUlid
        setRecalledHoldUlid(null)
        try {
          await deleteSaleHold(previousHold)
        } catch {
          feedback.error(
            'A new hold was saved, but the previous recovery hold could not be removed. You may discard the older copy manually.',
            'On Hold',
          )
        }
      }
      cart.clear()
      setSelectedCustomer(null)
      setPaymentDue(false)
      setCustomerSearch('CASH IN HAND')
      setSelectedSalesmanUlid(null)
      setSalesmanSearch('')
      setReceived('')
      setPaymentReference('')
      setProductQuery('')
      setActiveProductQuery('')
      setActiveProductPickerOpen(false)
      setBarcodeQuery('')
      setActiveLineKey(null)
      setCopyFromValue('')
      setQuotationValue('')
      quotationIdempotencyKeyRef.current = newSaleKey()
      await holdsQuery.refetch()
      feedback.success('Sale moved to On Hold.', 'Sales Invoice')
    },

    onError: (err) => {
      setSaveError(
        err instanceof ApiClientError
          ? err.message
          : 'Unable to place this sale on hold.',
      )
    },
  })

  async function refreshSavedSale(saleUlid: string) {
    const fresh = await fetchSale(saleUlid)
    setSavedSale(fresh)
  }

  function focusLineQuantity(lineKey: string) {
    window.setTimeout(() => {
      const quantity = document.querySelector<HTMLInputElement>(
        `[data-sale-line="${lineKey}"] [data-sale-field="sales_qty"]`,
      )
      quantity?.focus()
      quantity?.select()
    }, 0)
  }

  async function addProductFromEntry(
    productUlid: string,
    scannedValue = '',
    replaceLineKey: string | null = null,
    cachedProduct: Product | null = null,
  ) {
    try {
      let product = cachedProduct
      let availableBaseStock = cachedProduct?.sales_lookup?.in_stock ?? null

      if (!product || availableBaseStock === null) {
        const [freshProduct, stock] = await Promise.all([
          fetchProduct(productUlid),
          fetchProductStock(productUlid),
        ])
        product = freshProduct
        availableBaseStock = stock.active_warehouse.quantity
      }

      const scannedBarcode =
        scannedValue === ''
          ? null
          : product.barcodes?.find(
              (row) => row.is_active && row.barcode === scannedValue,
            ) ?? null

      if (
        !replaceLineKey &&
        saleSoftwareOptions?.disallow_duplicate_products &&
        cart.lines.some(
          (line) => line.line_kind === 'sale' && line.product_ulid === product.ulid,
        )
      ) {
        throw new Error(`${product.name} is already on this invoice.`)
      }

      const lineKey = replaceLineKey ?? cart.addProduct(
        product,
        '1.000000',
        scannedBarcode,
        availableBaseStock,
      )

      if (!lineKey) return

      if (replaceLineKey) {
        cart.replaceProduct(
          replaceLineKey,
          product,
          scannedBarcode,
          availableBaseStock,
        )
      }

      setActiveLineKey(lineKey)
      setActiveProductQuery(`${product.product_number} — ${product.name}`)
      setActiveProductPickerOpen(false)
      setProductQuery('')
      setBarcodeQuery('')
      setSaveError(null)
      focusLineQuantity(lineKey)
    } catch (err) {
      setSaveError(
        err instanceof ApiClientError || err instanceof Error
          ? err.message
          : 'Unable to load the selected product.',
      )
    }
  }

  function clearBarcodeLookupError() {
    barcodeLookupErrorSequenceRef.current += 1
    setBarcodeLookupError(null)
  }

  function showBarcodeNotAssigned() {
    const sequence = ++barcodeLookupErrorSequenceRef.current

    setBarcodeLookupError(null)

    window.setTimeout(() => {
      if (sequence !== barcodeLookupErrorSequenceRef.current) return

      setBarcodeLookupError('Barcode not assigned yet.')

      window.setTimeout(() => {
        if (sequence === barcodeLookupErrorSequenceRef.current) {
          setBarcodeLookupError(null)
        }
      }, 1500)
    }, 0)
  }

  async function addProductFromBarcode(barcodeOverride?: string) {
    const barcode = (barcodeOverride ?? barcodeQuery).trim()
    if (!barcode) return

    setBarcodeQuery(barcode)
    clearBarcodeLookupError()

    try {
      const cachedProducts = localProductLookup.data ?? []
      const localMatches = cachedProducts.filter((product) => {
        if (!product.is_active || product.status !== 'active') return false

        if (product.primary_barcode === barcode) return true

        return Boolean(
          product.barcodes?.some(
            (row) => row.is_active && row.barcode === barcode,
          ),
        )
      })

      if (localMatches.length === 1) {
        await addProductFromEntry(localMatches[0].ulid, barcode, null, localMatches[0])
        return
      }

      const page = await fetchProducts(
        { q: barcode, per_page: 20, page: 1, sales_lookup: true },
        { busy: 'none' },
      )
      const match = page.data.find((product) =>
        product.primary_barcode === barcode ||
        product.barcodes?.some(
          (row) => row.is_active && row.barcode === barcode,
        ),
      )
      if (!match) {
        showBarcodeNotAssigned()
        return
      }

      await addProductFromEntry(match.ulid, barcode, null, match)
    } catch (err) {
      setSaveError(
        err instanceof ApiClientError
          ? err.message
          : 'Unable to resolve the scanned barcode.',
      )
    }
  }

  useBarcodeScannerTarget({
    id: 'sales-invoice-barcode',
    priority: 20,
    enabled:
      active &&
      view === 'pos' &&
      canCreateSale &&
      !savedSale &&
      !partyModalOpen &&
      !paymentModalOpen &&
      !customizationOpen,
    onScan: (rawBarcode) => {
      const barcode = rawBarcode.trim()
      if (!barcode) return

      setBarcodeQuery(barcode)
      setActiveProductPickerOpen(false)
      void addProductFromBarcode(barcode)
    },
  })

  function finishActiveLine(lineKey: string) {
    if (activeLineKey !== lineKey) return

    setActiveLineKey(null)
    setActiveProductQuery('')
    setActiveProductPickerOpen(false)
    window.setTimeout(() => {
      productSearchRef.current?.focus()
      productSearchRef.current?.select()
    }, 0)
  }

  function reopenCompletedLine(line: SaleDraftLine) {
    if (line.line_kind !== 'sale') return

    setSaveError(null)
    setProductQuery('')
    setActiveLineKey(line.line_key)
    setActiveProductQuery(`${line.product_number} — ${line.product_name}`)
    setActiveProductPickerOpen(false)
    focusLineQuantity(line.line_key)
  }

  function handleActiveFieldEnter(
    event: ReactKeyboardEvent<HTMLInputElement | HTMLSelectElement | HTMLButtonElement>,
    lineKey: string,
  ) {
    if (event.key !== 'Enter') return

    event.preventDefault()
    const row = event.currentTarget.closest('tr')
    if (!row) return

    const fields = Array.from(
      row.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLButtonElement>(
        '[data-sale-editable="true"]:not(:disabled)',
      ),
    )
    const currentIndex = fields.indexOf(event.currentTarget)
    const next = fields[currentIndex + 1]

    if (next) {
      next.focus()
      if (next instanceof HTMLInputElement) next.select()
      return
    }

    const line = cart.lines.find((row) => row.line_key === lineKey)
    const quantity = Number.parseFloat(line?.quantity ?? '')

    if (!line || !Number.isFinite(quantity) || quantity <= 0) {
      setSaveError('Sale quantity must be greater than zero.')
      feedback.error('Sale quantity must be greater than zero.', 'Sales Invoice')
      const quantityInput = row.querySelector<HTMLInputElement>('[data-sale-field="sales_qty"]')
      quantityInput?.focus()
      quantityInput?.select()
      return
    }

    setSaveError(null)
    finishActiveLine(lineKey)
  }

  useEffect(() => {
    if (!activeLineKey) return
    focusLineQuantity(activeLineKey)
  }, [activeLineKey])


  function parkCurrentCart() {
    if (!canHoldSale || !cart.hasPaidLines || holdMutation.isPending) return
    holdMutation.mutate()
  }

  async function recallHeldCart(hold: SaleHold) {
    if (!hold.lines?.length) {
      throw new Error('This held sale has no lines to recall.')
    }

    const customer = hold.customer
      ? await fetchParty(hold.customer.ulid, 'customer')
      : null

    cart.restore(
      hold.lines,
      hold.notes ?? '',
      hold.customer?.ulid ?? null,
      hold.price_type,
    )

    setSelectedCustomer(customer)
    setPaymentDue(false)
    setCustomerSearch(customer?.name ?? 'CASH IN HAND')
    setSelectedSalesmanUlid(hold.salesman?.ulid ?? null)
    setSalesmanSearch(
      hold.salesman
        ? `${hold.salesman.code ?? ''}${hold.salesman.code ? ' — ' : ''}${hold.salesman.name}`
        : '',
    )
    setSaleDate(
      saleSoftwareOptions?.keep_pending_invoice_date
        ? (hold.sale_date ?? new Date().toISOString().slice(0, 10))
        : new Date().toISOString().slice(0, 10),
    )
    setCopyFromValue('')
    setQuotationValue('')
    setSavedSale(null)
    setRecalledHoldUlid(hold.ulid)
    setReceived('')
    setPaymentReference('')
    setProductQuery('')
    setActiveProductQuery('')
    setActiveProductPickerOpen(false)
    setBarcodeQuery('')
    setActiveLineKey(null)
    setSaveError(null)
    setView('pos')
  }

  function refreshScreen() {
    setSaveError(null)

    void localProductLookup.refreshFromServer().catch(() => {
      feedback.error('Unable to refresh product catalog.', 'Sales Invoice')
    })

    if (savedSale) {
      void refreshSavedSale(savedSale.ulid)
      return
    }

    cart.clear()
    setPaymentModalOpen(false)
    setRecalledHoldUlid(null)
    setSelectedCustomer(null)
    setPaymentDue(false)
    setReceived('')
    setPaymentReference('')
    setProductQuery('')
    setActiveProductQuery('')
    setActiveProductPickerOpen(false)
    setBarcodeQuery('')
    setActiveLineKey(null)
    setCustomerPickerOpen(false)
    setCustomerPickerSearch('')
    setSalesmanPickerOpen(false)
    setCopyFromValue('')
    setQuotationValue('')
    quotationIdempotencyKeyRef.current = newSaleKey()
  }

  function setReceivedAmount(value: string) {
    if (!/^(?:\d*)?(?:\.\d{0,4})?$/.test(value)) return

    setReceived(value)
  }

  async function handlePartySaved(party: Party) {
    if (party.party_type !== 'customer') return

    setSelectedCustomer(party)
    cart.setCustomerUlid(party.ulid)
    setCustomerSearch(party.name)
    setCustomerPickerSearch('')
    setCustomerPickerOpen(false)
    setPartyModalOpen(false)
    await customersQuery.refetch()
  }

  const stockIssue = useMemo(() => {
    if (businessSettingsQuery.data?.negative_stock_allowed !== false) {
      return null
    }

    const byProduct = new Map<
      string,
      {
        name: string
        requiredBase: number
        availableBase: number | null
      }
    >()

    for (const line of cart.paidLines) {
      const current = byProduct.get(line.product_ulid) ?? {
        name: line.product_name,
        requiredBase: 0,
        availableBase:
          line.available_base_stock === null ||
          line.available_base_stock === undefined
            ? null
            : Number.parseFloat(line.available_base_stock),
      }

      const quantity = Number.parseFloat(line.quantity) || 0
      const factor = Number.parseFloat(line.conversion_factor ?? '1') || 1

      current.requiredBase += quantity * factor

      if (
        current.availableBase === null &&
        line.available_base_stock !== null &&
        line.available_base_stock !== undefined
      ) {
        current.availableBase = Number.parseFloat(line.available_base_stock)
      }

      byProduct.set(line.product_ulid, current)
    }

    for (const row of byProduct.values()) {
      if (
        row.availableBase !== null &&
        row.requiredBase > row.availableBase + 0.0000005
      ) {
        return `Insufficient stock for ${row.name}.`
      }
    }

    return null
  }, [businessSettingsQuery.data?.negative_stock_allowed, cart.paidLines])

  useEffect(() => {
    if (!active) return
    if (stockIssue) {
      feedback.error(stockIssue, 'Stock warning')
    }
  }, [feedback, stockIssue])

  useEffect(() => {
    if (!active) return
    if (saveError) {
      feedback.error(saveError, 'Sales Invoice')
    }
  }, [feedback, saveError])

  useEffect(() => {
    if (!active) return
    if (barcodeLookupError) {
      feedback.errorAt(
        barcodeLookupError,
        'Sales Invoice',
        productPickerAnchorRef.current,
      )
    }
  }, [barcodeLookupError, feedback])


  useEffect(() => {
    if (!active) return

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'F9') {
        event.preventDefault()

        if (
          canCreateSale &&
          cart.hasPaidLines &&
          !stockIssue &&
          !saveMutation.isPending
        ) {
          setPaymentModalOpen(true)
        }
      } else if (event.key === 'F8') {
        event.preventDefault()
        refreshScreen()
      } else if (event.key === 'F3') {
        event.preventDefault()

        if (savedSale) {
          previewSaleReceipt(savedSale, receiptOptions)
        }
      } else if (event.key === 'F11') {
        event.preventDefault()

        if (savedSale) {
          printSaleReceipt(savedSale, receiptOptions)
        }
      } else if (event.key === 'F12') {
        event.preventDefault()
        receivedRef.current?.focus()
      }
    }

    window.addEventListener('keydown', onKeyDown)

    return () => window.removeEventListener('keydown', onKeyDown)
  }, [
    canCreateSale,
    cart.hasPaidLines,
    stockIssue,
    saveMutation.isPending,
    savedSale,
    receiptOptions,
  ])

  const isBusy = saveMutation.isPending

  return (
    <div
      className={`sales-reference-screen${view !== 'pos' ? ' is-full-workspace-view' : ''}`}
    >
      <main className="sales-reference-main">


        {view === 'history' ? (
          <SalesInvoiceHistory
            historyDays={generalSoftwareOptions?.view_history_days ?? 8}
            disableSearchDates={generalSoftwareOptions?.disable_search_dates ?? false}
          />
        ) : view === 'pending' ? (
          <SalesPendingInvoices onRecallHeld={recallHeldCart} />
        ) : view === 'expenses' ? (
          <SalesExpenses />
        ) : view === 'product' ? (
          <SalesProductWiseSummary />
        ) : (
          <>
            <section className="sales-reference-meta">
              <fieldset className="sales-reference-options">
                <legend>Invoice Options</legend>

                <div className="sales-reference-options-head">
                  <label>
                    <input
                      type="radio"
                      name="sale-type"
                      checked={cart.priceType === 'default'}
                      onChange={() => cart.setPriceType('default')}
                    />{' '}
                    Default
                  </label>

                  <label>
                    <input
                      type="radio"
                      name="sale-type"
                      checked={cart.priceType === 'wholesale'}
                      onChange={() => cart.setPriceType('wholesale')}
                    />{' '}
                    Whole Sale
                  </label>

                  <label>
                    <input
                      type="radio"
                      name="sale-type"
                      checked={cart.priceType === 'retail'}
                      onChange={() => cart.setPriceType('retail')}
                    />{' '}
                    Retail
                  </label>

                  <div className="sales-reference-copy-from">
                    <span>Copy From:</span>

                    <input
                      value={copyFromValue}
                      placeholder="Invoice #"
                      aria-label="Copy invoice number"
                      title="Enter an exact posted invoice number and press Enter"
                      disabled={!canCreateSale || copyFromMutation.isPending}
                      onChange={(event) => setCopyFromValue(event.target.value)}
                      onKeyDown={(event) => {
                        if (event.key !== 'Enter') return
                        event.preventDefault()
                        if (!copyFromMutation.isPending) {
                          copyFromMutation.mutate()
                        }
                      }}
                    />
                  </div>
                </div>

                <div className="sales-reference-option-grid">
                  <label>Inv#:</label>

                  <input
                    className="is-short"
                    placeholder="Auto"
                    readOnly
                    title="Invoice number is generated by the server"
                  />

                  <label>Date</label>

                  <input
                    type="date"
                    className="is-date"
                    value={saleDate}
                    disabled={!saleSoftwareOptions?.manual_date_change}
                    title={
                      saleSoftwareOptions?.manual_date_change
                        ? 'Invoice date can be changed manually.'
                        : 'Enable Manual Change of Date in Software Options to edit this date.'
                    }
                    onChange={(e) => setSaleDate(e.target.value)}
                  />

                  <label>Qu #:</label>

                  <div className="sales-reference-input-button sales-reference-inline-caret">
                    <input
                      value={quotationValue}
                      aria-label="Quotation number"
                      placeholder="Quotation #"
                      title="Enter a quotation number to load it, or leave blank and use the caret to save the current cart as a quotation"
                      disabled={
                        !canCreateSale ||
                        quotationLookupMutation.isPending ||
                        saveQuotationMutation.isPending
                      }
                      onChange={(event) => setQuotationValue(event.target.value)}
                      onKeyDown={(event) => {
                        if (event.key !== 'Enter') return
                        event.preventDefault()

                        if (quotationValue.trim()) {
                          if (!quotationLookupMutation.isPending) {
                            quotationLookupMutation.mutate()
                          }
                          return
                        }

                        if (cart.hasPaidLines && !saveQuotationMutation.isPending) {
                          saveQuotationMutation.mutate()
                        }
                      }}
                    />

                    <button
                      type="button"
                      className="sales-reference-field-caret"
                      disabled={
                        !canCreateSale ||
                        quotationLookupMutation.isPending ||
                        saveQuotationMutation.isPending ||
                        (!quotationValue.trim() && !cart.hasPaidLines)
                      }
                      title={
                        quotationValue.trim()
                          ? 'Load quotation'
                          : 'Save current cart as quotation'
                      }
                      aria-label={
                        quotationValue.trim()
                          ? 'Load quotation'
                          : 'Save current cart as quotation'
                      }
                      onClick={() => {
                        if (quotationValue.trim()) {
                          quotationLookupMutation.mutate()
                        } else {
                          saveQuotationMutation.mutate()
                        }
                      }}
                    >
                      {quotationValue.trim() ? (
                        <ChevronDown size={12} strokeWidth={2.75} />
                      ) : (
                        <Save size={12} strokeWidth={2.75} />
                      )}
                    </button>
                  </div>

                  {generalSoftwareOptions?.show_salesman_vcs !== false ? (
                    <>
                    <label>S.Man:</label>
  
                    <div className={`sales-reference-input-button sales-reference-salesman sales-reference-inline-caret${salesmanPickerOpen ? ' is-open' : ''}`}>
                      <input
                        value={salesmanSearch}
                        placeholder="Search salesman"
                        aria-label="Salesman"
                        autoComplete="off"
                        onFocus={() => {
                          setCustomerPickerOpen(false)
                          setSalesmanPickerOpen(true)
                          if (!salesmanSearch && selectedSalesman) {
                            setSalesmanSearch(`${selectedSalesman.code} — ${selectedSalesman.name}`)
                          }
                        }}
                        onChange={(event) => {
                          setSalesmanSearch(event.target.value)
                          setCustomerPickerOpen(false)
                          setSalesmanPickerOpen(true)
                        }}
                      />
  
                      <button
                        type="button"
                        className="sales-reference-field-caret"
                        onClick={() => {
                          setCustomerPickerOpen(false)
                          setSalesmanPickerOpen((open) => !open)
                        }}
                        title="Choose salesman"
                        aria-label="Choose salesman"
                      >
                        <ChevronDown size={12} strokeWidth={2.75} />
                      </button>
  
                      <div
                        className={`sales-salesman-dropdown-shutter${salesmanPickerOpen ? ' is-open' : ''}`}
                        aria-hidden={!salesmanPickerOpen}
                      >
                        <div className="sales-pos-salesman-grid" role="listbox" aria-label="Choose salesman">
                          <div className="sales-picker-search">
                            <input
                              value={salesmanSearch}
                              placeholder="Search salesman by code, name, address or mobile"
                              aria-label="Search salesman"
                              autoComplete="off"
                              onChange={(event) => setSalesmanSearch(event.target.value)}
                            />
                          </div>
  
                          <div className="sales-pos-salesman-grid-head" aria-hidden="true">
                            <span>Code</span>
                            <span>Name</span>
                            <span>Address</span>
                            <span>Mobile</span>
                          </div>
  
                          <div className="sales-pos-salesman-grid-body">
                            <button
                              type="button"
                              className={`sales-pos-salesman-grid-row${selectedSalesmanUlid ? '' : ' is-selected'}`}
                              onClick={() => {
                                setSelectedSalesmanUlid(null)
                                setSalesmanSearch('')
                                setSalesmanPickerOpen(false)
                              }}
                            >
                              <span>—</span>
                              <strong>NO SALESMAN</strong>
                              <span>—</span>
                              <span>—</span>
                            </button>
  
                            {filteredSalesmen.map((salesman) => (
                              <button
                                type="button"
                                className={`sales-pos-salesman-grid-row${selectedSalesmanUlid === salesman.ulid ? ' is-selected' : ''}`}
                                key={salesman.ulid}
                                onClick={() => {
                                  setSelectedSalesmanUlid(salesman.ulid)
                                  setSalesmanSearch(`${salesman.code} — ${salesman.name}`)
                                  setSalesmanPickerOpen(false)
                                }}
                              >
                                <span>{salesman.code || '—'}</span>
                                <strong>{salesman.name}</strong>
                                <span>{salesman.address || '—'}</span>
                                <span>{salesman.mobile || '—'}</span>
                              </button>
                            ))}
                          </div>
  
                          <div className="sales-pos-salesman-grid-foot">
                            {filteredSalesmen.length} of {salesmenQuery.data?.length ?? 0} Salesmen
                          </div>
                        </div>
                      </div>
                    </div>
                    </>
                  ) : null}

                  <label>To:</label>

                  <div className={`sales-reference-input-button sales-reference-to sales-reference-inline-caret${customerPickerOpen ? ' is-open' : ''}`}>
                    <input
                      value={customerSearch}
                      placeholder="Search customer"
                      aria-label="Customer"
                      autoComplete="off"
                      onFocus={() => {
                        setSalesmanPickerOpen(false)
                        setCustomerPickerSearch('')
                        setCustomerPickerOpen(true)
                      }}
                      onChange={(event) => {
                        setCustomerSearch(event.target.value)
                        setCustomerPickerSearch(event.target.value)
                        setSalesmanPickerOpen(false)
                        setCustomerPickerOpen(true)
                      }}
                    />

                    <button
                      type="button"
                      className="sales-reference-field-caret"
                      onClick={() => {
                        setSalesmanPickerOpen(false)
                        setCustomerPickerOpen((open) => {
                          if (!open) setCustomerPickerSearch('')
                          return !open
                        })
                      }}
                      title="Choose customer"
                      aria-label="Choose customer"
                    >
                      <ChevronDown size={12} strokeWidth={2.75} />
                    </button>

                    <button
                      type="button"
                      className="sales-reference-party-add"
                      onClick={() => {
                        setCustomerPickerOpen(false)
                        setPartyModalOpen(true)
                      }}
                      title="Add or manage Vendor / Customer / Account"
                      aria-label="Add or manage Vendor / Customer / Account"
                    >
                      <Plus size={12} strokeWidth={2.6} />
                    </button>

                    <div className={`sales-customer-dropdown-shutter${customerPickerOpen ? ' is-open' : ''}`} aria-hidden={!customerPickerOpen}>
                      <div className="sales-pos-customer-grid" role="listbox" aria-label="Choose customer">
                        <div className="sales-picker-search">
                          <input
                            value={customerPickerSearch}
                            placeholder="Search customer by code, name, address or mobile"
                            aria-label="Search customer"
                            autoComplete="off"
                            onChange={(event) => setCustomerPickerSearch(event.target.value)}
                          />
                        </div>

                        <div className="sales-pos-customer-grid-head" aria-hidden="true">
                          <span>Code</span>
                          <span>Party Name</span>
                          <span>Address</span>
                          <span>Mobile</span>
                        </div>

                        <div className="sales-pos-customer-grid-body">
                          <button
                            type="button"
                            className={`sales-pos-customer-grid-row${selectedCustomer ? '' : ' is-selected'}`}
                            onClick={() => {
                              setSelectedCustomer(null)
                              cart.setCustomerUlid(null)
                              setPaymentDue(false)
                              setCustomerSearch('CASH IN HAND')
                              setCustomerPickerSearch('')
                              setCustomerPickerOpen(false)
                            }}
                          >
                            <span>—</span>
                            <strong>CASH IN HAND</strong>
                            <span>Walk-in customer</span>
                            <span>—</span>
                          </button>

                          {filteredCustomers.map((party) => (
                            <button
                              type="button"
                              className={`sales-pos-customer-grid-row${selectedCustomer?.ulid === party.ulid ? ' is-selected' : ''}`}
                              key={party.ulid}
                              onClick={() => {
                                setSelectedCustomer(party)
                                cart.setCustomerUlid(party.ulid)
                                setCustomerSearch(party.name)
                                setCustomerPickerSearch('')
                                setCustomerPickerOpen(false)
                              }}
                            >
                              <span>{party.code || '—'}</span>
                              <strong>{party.name}</strong>
                              <span>{party.address || '—'}</span>
                              <span>{party.mobile || party.phone || '—'}</span>
                            </button>
                          ))}
                        </div>

                        <div className="sales-pos-customer-grid-foot">
                          {filteredCustomers.length + 1} of {(customersQuery.data?.length ?? 0) + 1} Parties
                        </div>
                      </div>
                    </div>
                  </div>

                  <label>Name:</label>

                  <input
                    value={selectedCustomer?.name ?? ''}
                    readOnly
                    placeholder="Walk-in customer"
                  />

                  <label>CNIC:</label>

                  <input
                    value={selectedCustomer?.cnic ?? ''}
                    readOnly
                  />
                </div>

              </fieldset>



              <div
                className="sales-reference-meta-spacer"
                aria-hidden
              />
            </section>

            <div className="sales-pos-status">
              {savedSale ? (
                <>
                  <span className="sales-pos-status-ok">
                    Saved {savedSale.document_number} — total{' '}
                    {savedSale.grand_total}
                  </span>

                </>
              ) : null}
            </div>

            <div className="sales-reference-entry-row">
              <div className="sales-reference-product-entry-wrap">
                <input
                  className="sales-reference-product-entry"
                  value={barcodeQuery}
                  placeholder="Scan barcode"
                  aria-label="Barcode scanner input"
                  autoComplete="off"
                  onChange={(e) => setBarcodeQuery(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault()
                      void addProductFromBarcode()
                    }
                  }}
                />
              </div>

              <div className="sales-reference-f1">
                F1 to Add New
              </div>

              <button
                type="button"
                className="sales-reference-sale-btn"
                disabled={
                  !canCreateSale ||
                  !cart.hasPaidLines ||
                  Boolean(stockIssue) ||
                  isBusy
                }
                onClick={() => setPaymentModalOpen(true)}
              >
                {isBusy ? 'Saving...' : 'Sale'}
              </button>

              <div
                className={`sales-reference-entry-status${stockIssue || barcodeLookupError || saveError ? ' is-error' : cart.hasPaidLines ? ' is-ready' : ''}`}
                role={stockIssue || barcodeLookupError || saveError ? 'alert' : 'status'}
              >
                {stockIssue ?? barcodeLookupError ?? saveError ?? (cart.hasPaidLines
                  ? `${cart.paidLines.length} line(s) ready to save`
                  : '')}
              </div>
            </div>

            <div className="sales-reference-grid-wrap">
              <table className="sales-reference-grid">
                <colgroup>
                  {effectiveSalesColumns.map((col) => (
                    <col
                      key={col.key}
                      className={salesColClass(col)}
                    />
                  ))}
                </colgroup>

                <thead>
                  <tr>
                    {effectiveSalesColumns.map((col) => (
                      <th
                        key={col.key}
                        className={salesColClass(col)}
                        draggable={!col.locked}
                        onDragStart={(event) => {
                          if (col.locked) return

                          event.dataTransfer.setData(
                            'text/bp-col',
                            col.key,
                          )

                          event.dataTransfer.effectAllowed =
                            'move'
                        }}
                        onDragOver={(event) => {
                          if (col.locked) return
                          event.preventDefault()
                        }}
                        onDrop={(event) => {
                          event.preventDefault()

                          const from =
                            event.dataTransfer.getData(
                              'text/bp-col',
                            )

                          if (from) {
                            columnLayout.moveColumn(
                              from,
                              col.key,
                            )
                          }
                        }}
                        onContextMenu={(event) => {
                          event.preventDefault()

                          if (!col.locked) {
                            columnLayout.hideColumn(col.key)
                          }
                        }}
                        title={
                          col.key === 'delete'
                            ? 'Customize columns'
                            : col.locked
                              ? col.label
                              : `${col.label} — drag to move, right-click to hide`
                        }
                      >
                        {col.key === 'delete' ? (
                          <button
                            type="button"
                            className={`sales-reference-customize-trigger${
                              customizationOpen
                                ? ' is-open'
                                : ''
                            }`}
                            title="Customize columns"
                            aria-label="Customize columns"
                            aria-expanded={
                              customizationOpen
                            }
                            onClick={() =>
                              setCustomizationOpen(
                                (open) => !open,
                              )
                            }
                          >
                            −
                          </button>
                        ) : col.key === 'selector' ? null : (
                          col.label
                        )}
                      </th>
                    ))}
                  </tr>
                </thead>

                <tbody>
                  {activeLineKey
                    ? cart.lines
                        .filter((line) => line.line_key === activeLineKey)
                        .map((line) => (
                          <tr
                            key={line.line_key}
                            className="is-entry-row sales-pos-line is-active-entry"
                            data-sale-line={line.line_key}
                          >
                            {effectiveSalesColumns.map((col) => {
                              if (col.key === 'selector') {
                                return <td key={col.key} className="sales-reference-row-arrow">›</td>
                              }

                              if (col.key === 'product') {
                                return (
                                  <td key={col.key} className="sales-reference-yellow sales-product-search-cell">
                                    <div
                                      ref={productPickerAnchorRef}
                                      className="sales-product-combo"
                                    >
                                      <input
                                        className="sales-pos-product-entry is-active-product"
                                        value={activeProductQuery}
                                        aria-label="Change product"
                                        autoComplete="off"
                                        onFocus={() => {
                                          if (!activeProductQuery) {
                                            setActiveProductQuery(`${line.product_number} — ${line.product_name}`)
                                          }
                                          setProductLookupMode('browse')
                                          setActiveProductPickerOpen(true)
                                        }}
                                        onClick={() => {
                                          setProductLookupMode('browse')
                                          setActiveProductPickerOpen(true)
                                        }}
                                        onChange={(e) => {
                                          setActiveProductQuery(e.target.value)
                                          setProductLookupMode('search')
                                          setActiveProductPickerOpen(true)
                                        }}
                                        onKeyDown={(e) => {
                                          if (e.key === 'Escape') {
                                            setActiveProductQuery(`${line.product_number} — ${line.product_name}`)
                                            setActiveProductPickerOpen(false)
                                            return
                                          }
                                          if (e.key !== 'Enter') return
                                          const first = productLookupRows[0]
                                          if (first) {
                                            e.preventDefault()
                                            void addProductFromEntry(first.ulid, '', line.line_key, first)
                                          }
                                        }}
                                      />
                                      <button
                                        type="button"
                                        className="sales-product-combo-toggle"
                                        aria-label="Open product dropdown"
                                        aria-expanded={activeProductPickerOpen}
                                        onMouseDown={(e) => e.preventDefault()}
                                        onClick={() => {
                                          setProductLookupMode('browse')
                                          setActiveProductPickerOpen((open) => !open)
                                        }}
                                      >
                                        <span aria-hidden>
                                          <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                                            <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                                          </svg>
                                        </span>
                                      </button>

                                      {activeProductPickerOpen ? (
                                        <ProductLookupGrid
                                          rows={productLookupRows}
                                          total={productLookupTotal}
                                          priceType={cart.priceType}
                                          selectedUlid={line.product_ulid}
                                          portalAnchorRef={productPickerAnchorRef}
                                          onRequestClose={() => setActiveProductPickerOpen(false)}
                                          onSelect={(product) =>
                                            void addProductFromEntry(product.ulid, '', line.line_key, product)
                                          }
                                        />
                                      ) : null}
                                    </div>
                                  </td>
                                )
                              }

                              if (col.key === 'sales_qty') {
                                return (
                                  <td key={col.key}>
                                    <input
                                      className="sales-pos-line-qty"
                                      data-sale-editable="true"
                                      data-sale-field="sales_qty"
                                      value={displayEditableNumber(line.quantity)}
                                      onChange={(e) => cart.setQuantity(line.line_key, e.target.value)}
                                      onKeyDown={(e) => handleActiveFieldEnter(e, line.line_key)}
                                    />
                                  </td>
                                )
                              }

                              if (col.key === 'delete') {
                                return (
                                  <td key={col.key} className="sales-reference-delete-cell">
                                    <button type="button" aria-label="Delete entry row" onClick={() => {
                                      cart.removeLine(line.line_key)
                                      setActiveLineKey(null)
                                      setActiveProductQuery('')
                                      setActiveProductPickerOpen(false)
                                      window.setTimeout(() => productSearchRef.current?.focus(), 0)
                                    }}>
                                      <XCircle size={16} />
                                    </button>
                                  </td>
                                )
                              }

                              const linePreview = cart.linePreview(line)

                              if (col.key === 'in_stock') {
                                const available = cart.availableStock(line)
                                return <td key={col.key} className="sales-pos-line-pending">{available === null ? '—' : Number.parseFloat(available).toFixed(3)}</td>
                              }

                              if (col.key === 'price') {
                                return <td key={col.key} className="sales-pos-line-pending">{Number.parseFloat(line.unit_price ?? '0').toFixed(2)}</td>
                              }

                              if (col.key === 'amt') {
                                return <td key={col.key} className="sales-pos-line-pending">{linePreview.grossAmount}</td>
                              }

                              if (col.key === 'disc_pct') {
                                return (
                                  <td key={col.key}>
                                    <input className="sales-pos-line-qty" data-sale-editable="true" value={
                                      line.discount_source === 'percent'
                                        ? line.discount_percent ?? ''
                                        : displayEditableNumber(line.discount_percent ?? '0')
                                    } inputMode="decimal"
                                      onChange={(e) => cart.setDiscountPercent(line.line_key, e.target.value)}
                                      onKeyDown={(e) => handleActiveFieldEnter(e, line.line_key)} />
                                  </td>
                                )
                              }

                              if (col.key === 'disc_rs') {
                                return (
                                  <td key={col.key}>
                                    <input className="sales-pos-line-qty" data-sale-editable="true" value={
                                      line.discount_source === 'amount'
                                        ? line.discount_amount ?? ''
                                        : displayEditableNumber(line.discount_amount ?? '0')
                                    } inputMode="decimal"
                                      onChange={(e) => cart.setDiscountAmount(line.line_key, e.target.value)}
                                      onKeyDown={(e) => handleActiveFieldEnter(e, line.line_key)} />
                                  </td>
                                )
                              }

                              if (col.key === 'net_amt') {
                                return <td key={col.key} className="sales-pos-line-pending">{linePreview.netAmount}</td>
                              }

                              if (col.key === 'barcode') return <td key={col.key}>{line.barcode ?? ''}</td>

                              if (col.key === 'uom') {
                                return (
                                  <td key={col.key}>
                                    {(line.available_units?.length ?? 0) > 0 ? (
                                      <UiSelect
                                        value={line.unit_ulid ?? ''}
                                        className="sales-pos-line-uom-select"
                                        triggerClassName="sales-pos-line-uom-trigger"
                                        aria-label={`Unit for ${line.product_name}`}
                                        triggerProps={{ 'data-sale-editable': 'true' }}
                                        options={(line.available_units ?? []).map((unit) => ({
                                          value: unit.unit_ulid,
                                          label: unit.code,
                                        }))}
                                        onKeyDown={(e) => handleActiveFieldEnter(e, line.line_key)}
                                        onChange={(value) => cart.setLineUnit(line.line_key, value)}
                                      />
                                    ) : line.unit_code ?? ''}
                                  </td>
                                )
                              }

                              if (col.key === 's_tax_pct') {
                                return <td key={col.key} className="sales-pos-line-pending">{linePreview.taxPercent}</td>
                              }

                              return <td key={col.key} />
                            })}
                          </tr>
                        ))
                    : (
                      <tr className="is-entry-row">
                        {effectiveSalesColumns.map((col) => {
                          if (col.key === 'selector') {
                            return <td key={col.key} className="sales-reference-row-arrow">›</td>
                          }

                          if (col.key === 'product') {
                            return (
                              <td key={col.key} className="sales-reference-yellow sales-product-search-cell">
                                <div
                                  ref={productPickerAnchorRef}
                                  className="sales-product-combo"
                                >
                                  <input
                                    ref={productSearchRef}
                                    className="sales-pos-product-entry"
                                    value={productQuery}
                                    placeholder="Search product name or number"
                                    autoComplete="off"
                                    onFocus={() => {
                                      setProductLookupMode('browse')
                                      setActiveProductPickerOpen(true)
                                    }}
                                    onChange={(e) => {
                                      setProductQuery(e.target.value)
                                      setProductLookupMode('search')
                                      setActiveProductPickerOpen(true)
                                    }}
                                    onKeyDown={(e) => {
                                      if (e.key === 'Escape') {
                                        setActiveProductPickerOpen(false)
                                        return
                                      }
                                      if (e.key !== 'Enter') return
                                      const first = productLookupRows[0]
                                      if (first) {
                                        e.preventDefault()
                                        void addProductFromEntry(first.ulid, '', null, first)
                                      }
                                    }}
                                  />
                                  <button
                                    type="button"
                                    className="sales-product-combo-toggle"
                                    aria-label="Open product dropdown"
                                    aria-expanded={activeProductPickerOpen}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={() => {
                                      setProductLookupMode('browse')
                                      setActiveProductPickerOpen((open) => !open)
                                      requestAnimationFrame(() => productSearchRef.current?.focus())
                                    }}
                                  >
                                    <span aria-hidden>
                                      <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                                        <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                                      </svg>
                                    </span>
                                  </button>

                                  {activeProductPickerOpen ? (
                                    <ProductLookupGrid
                                      rows={productLookupRows}
                                      total={productLookupTotal}
                                      priceType={cart.priceType}
                                      portalAnchorRef={productPickerAnchorRef}
                                      onRequestClose={() => setActiveProductPickerOpen(false)}
                                      onSelect={(product) => void addProductFromEntry(product.ulid, '', null, product)}
                                    />
                                  ) : null}
                                </div>
                              </td>
                            )
                          }

                          if (col.key === 'delete') {
                            return <td key={col.key} className="sales-reference-delete-cell"><button type="button" aria-label="Clear product search" onClick={() => setProductQuery('')}><XCircle size={16} /></button></td>
                          }

                          return <td key={col.key} />
                        })}
                      </tr>
                    )}

                  {cart.lines
                    .filter((line) => line.line_key !== activeLineKey)
                    .map((line) => (
                    <tr
                      key={line.line_key}
                      className={
                        line.line_kind === 'sale'
                          ? 'sales-pos-line is-completed-entry'
                          : 'sales-pos-line is-free'
                      }
                      title={line.line_kind === 'sale' ? 'Double-click to edit this line' : undefined}
                      onDoubleClick={() => reopenCompletedLine(line)}
                    >
                      {effectiveSalesColumns.map((col) => {
                        if (col.key === 'selector') {
                          return <td key={col.key} className="sales-reference-row-arrow">›</td>
                        }

                        if (col.key === 'product') {
                          return (
                            <td key={col.key}>
                              <span className="sales-pos-line-product">{line.product_number} — {line.product_name}</span>
                              {line.line_kind !== 'sale' ? <span className="sales-pos-line-badge">{line.line_kind === 'free_scheme' ? 'Free scheme' : 'Free packaging'}</span> : null}
                            </td>
                          )
                        }

                        if (col.key === 'sales_qty') {
                          if (line.line_kind === 'sale') {
                            return (
                              <td key={col.key}>
                                <input
                                  className="sales-pos-line-qty"
                                  data-sale-editable="true"
                                  data-sale-field="sales_qty"
                                  value={displayEditableNumber(line.quantity)}
                                  onChange={(e) => cart.setQuantity(line.line_key, e.target.value)}
                                />
                              </td>
                            )
                          }

                          return (
                            <td key={col.key} className="sales-pos-line-value">
                              {Number.parseFloat(line.quantity || '0').toFixed(
                                line.unit_allows_decimal === false ? 0 : 3,
                              )}
                            </td>
                          )
                        }

                        if (col.key === 'delete') {
                          return <td key={col.key} className="sales-reference-delete-cell"><button type="button" aria-label="Delete row" onClick={() => cart.removeLine(line.line_key)}><XCircle size={16} /></button></td>
                        }

                        const linePreview = cart.linePreview(line)

                        if (col.key === 'in_stock') {
                          const available = cart.availableStock(line)
                          return <td key={col.key} className="sales-pos-line-pending">{available === null ? '—' : Number.parseFloat(available).toFixed(3)}</td>
                        }

                        if (col.key === 'price') return <td key={col.key} className="sales-pos-line-pending">{line.line_kind === 'sale' ? Number.parseFloat(line.unit_price ?? '0').toFixed(2) : '0.00'}</td>
                        if (col.key === 'amt') return <td key={col.key} className="sales-pos-line-pending">{linePreview.grossAmount}</td>

                        if (col.key === 'disc_pct') {
                          if (line.line_kind === 'sale') {
                            return (
                              <td key={col.key}>
                                <input
                                  className="sales-pos-line-qty"
                                  data-sale-editable="true"
                                  value={
                                      line.discount_source === 'percent'
                                        ? line.discount_percent ?? ''
                                        : displayEditableNumber(line.discount_percent ?? '0')
                                    }
                                  inputMode="decimal"
                                  onChange={(e) => cart.setDiscountPercent(line.line_key, e.target.value)}
                                />
                              </td>
                            )
                          }

                          return (
                            <td key={col.key} className="sales-pos-line-value">
                              {Number.parseFloat(line.discount_percent ?? '0').toFixed(2)}
                            </td>
                          )
                        }

                        if (col.key === 'disc_rs') {
                          if (line.line_kind === 'sale') {
                            return (
                              <td key={col.key}>
                                <input
                                  className="sales-pos-line-qty"
                                  data-sale-editable="true"
                                  value={
                                      line.discount_source === 'amount'
                                        ? line.discount_amount ?? ''
                                        : displayEditableNumber(line.discount_amount ?? '0')
                                    }
                                  inputMode="decimal"
                                  onChange={(e) => cart.setDiscountAmount(line.line_key, e.target.value)}
                                />
                              </td>
                            )
                          }

                          return (
                            <td key={col.key} className="sales-pos-line-value">
                              {Number.parseFloat(line.discount_amount ?? '0').toFixed(2)}
                            </td>
                          )
                        }

                        if (col.key === 'net_amt') return <td key={col.key} className="sales-pos-line-pending">{linePreview.netAmount}</td>
                        if (col.key === 'barcode') return <td key={col.key}>{line.barcode ?? ''}</td>

                        if (col.key === 'uom') {
                          if (line.line_kind === 'sale' && (line.available_units?.length ?? 0) > 0) {
                            return (
                              <td key={col.key}>
                                <UiSelect
                                  value={line.unit_ulid ?? ''}
                                  className="sales-pos-line-uom-select"
                                  triggerClassName="sales-pos-line-uom-trigger"
                                  aria-label={`Unit for ${line.product_name}`}
                                  triggerProps={{ 'data-sale-editable': 'true' }}
                                  options={(line.available_units ?? []).map((unit) => ({
                                    value: unit.unit_ulid,
                                    label: unit.code,
                                  }))}
                                  onChange={(value) => cart.setLineUnit(line.line_key, value)}
                                />
                              </td>
                            )
                          }

                          return (
                            <td key={col.key} className="sales-pos-line-value">
                              {line.unit_code ?? line.unit_symbol ?? ''}
                            </td>
                          )
                        }

                        if (col.key === 's_tax_pct') return <td key={col.key} className="sales-pos-line-pending">{linePreview.taxPercent}</td>
                        return <td key={col.key} />
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>

              <div className="sales-pos-offers">
                {cart.hasPaidLines ? (
                  <>
                    <SchemeOfferPrompt
                      schemes={visibleSchemes}
                      appliedUlids={
                        cart.appliedSchemeUlids
                      }
                      onAdd={cart.addSchemeReward}
                      onSkip={cart.skipScheme}
                    />

                    <PackagingPicker
                      packaging={visiblePackaging}
                      currentQty={packagingQtyInCart}
                      onAdd={cart.addPackaging}
                    />
                  </>
                ) : (
                  <div className="sales-pos-offers-empty">
                    Add a paid product to check eligible
                    schemes and free packaging.
                  </div>
                )}

                {offersQuery.isFetching ? (
                  <div>Checking sale schemes...</div>
                ) : null}

                {offersQuery.isError ? (
                  <div className="sales-pos-status-error">
                    Unable to check sale schemes.
                  </div>
                ) : null}

                {!offersQuery.isFetching &&
                !offersQuery.isError &&
                cart.hasPaidLines &&
                visibleSchemes.length === 0 ? (
                  <div className="sales-pos-offers-empty">
                    No eligible sale schemes for this subtotal.
                  </div>
                ) : null}
              </div>

              <div className="sales-reference-grid-empty" />

              <div className="sales-reference-grid-totals">
                <div />
                <strong>{cart.preview.quantity}</strong>
                <strong>{cart.preview.subtotal}</strong>
                <strong>{cart.preview.grandTotal}</strong>
              </div>

            </div>


          </>
        )}

        <footer className="sales-reference-actions">
          <nav
            className="sales-reference-subtabs sales-reference-subtabs-bottom"
            aria-label="Sales invoice views"
          >
            <button
              type="button"
              className={`sales-reference-subtab${
                view === 'pos' ? ' is-active' : ''
              }`}
              onClick={() => setView('pos')}
            >
              <span className="sales-reference-tab-icon is-blue">
                <Grid3X3 />
              </span>
              <span>Sales Invoice</span>
            </button>
  
            <button
              type="button"
              className={`sales-reference-subtab${
                view === 'history' ? ' is-active' : ''
              }`}
              onClick={() => setView('history')}
            >
              <span className="sales-reference-tab-icon is-yellow">
                <ReceiptText />
              </span>
              <span>Posted Invoices</span>
            </button>
  
            <button
              type="button"
              className={`sales-reference-subtab${
                view === 'pending' ? ' is-active' : ''
              }`}
              onClick={() => setView('pending')}
            >
              <span className="sales-reference-tab-icon is-multi">
                <StickyNote />
              </span>
              <span>
                ({holdsQuery.data?.count ?? 0}) Pending / Due
              </span>
            </button>
  
            <button
              type="button"
              className={`sales-reference-subtab${
                view === 'expenses' ? ' is-active' : ''
              }`}
              onClick={() => setView('expenses')}
            >
              <span className="sales-reference-tab-icon is-yellow">
                <WalletCards />
              </span>
              <span>Expenses</span>
            </button>
  
            <button
              type="button"
              className={`sales-reference-subtab${
                view === 'product' ? ' is-active' : ''
              }`}
              onClick={() => setView('product')}
            >
              <span className="sales-reference-tab-icon is-blue">
                <FileText />
              </span>
              <span>Product Wise Summary</span>
            </button>
          </nav>
          {view === 'pos' ? (
            <>
                <div className="sales-reference-shortcuts">
                  <span>Ctrl+M = POS</span>
                  <span>Ctrl+G = A4</span>
                  <span>Ctrl+H = A5</span>
                </div>
  
                <div className="sales-reference-actions-center">
                  <button
                    type="button"
                    disabled={
                      !canCreateSale ||
                      !cart.hasPaidLines ||
                      isBusy
                    }
                    onClick={() => setPaymentModalOpen(true)}
                  >
                    <span>Save [F9]</span>
  
                    <span className="sales-reference-action-icon is-save">
                      <Save />
                    </span>
                  </button>
  
                  <button
                    type="button"
                    onClick={refreshScreen}
                  >
                    <span>Refresh [F8]</span>
  
                    <span className="sales-reference-action-icon is-refresh">
                      <RefreshCw />
                    </span>
                  </button>
  
                  <button
                    type="button"
                    disabled={!savedSale}
                    onClick={() =>
                      savedSale &&
                      previewSaleReceipt(savedSale, receiptOptions)
                    }
                  >
                    <span>Preview [F3]</span>
  
                    <span className="sales-reference-action-icon is-preview">
                      <FileText />
                    </span>
                  </button>
  
                  <button
                    type="button"
                    disabled={!savedSale}
                    onClick={() =>
                      savedSale &&
                      printSaleReceipt(savedSale, receiptOptions)
                    }
                  >
                    <span>Print [F11]</span>
  
                    <span className="sales-reference-action-icon is-print">
                      <Printer />
                    </span>
                  </button>
  
                  <button
                    type="button"
                    onClick={closeActiveTab}
                  >
                    <span>Close</span>
  
                    <span className="sales-reference-action-icon is-close">
                      <XCircle />
                    </span>
                  </button>
                </div>
            </>
          ) : (
            <div className="sales-reference-actions-view-label">
              {view === 'history'
                ? 'Posted Invoices'
                : view === 'pending'
                  ? 'Pending / Due Invoices'
                  : view === 'expenses'
                    ? 'Expenses'
                    : 'Product Wise Summary'}
            </div>
          )}
        </footer>
      </main>

      {view === 'pos' ? (
        <aside className="sales-reference-pay">
          <div className="sales-reference-retail-title">
            {cart.priceType === 'wholesale' ? 'WHOLESALE INVOICE' : 'RETAIL INVOICE'}
          </div>

          <fieldset className="sales-reference-amounts">
            <legend>Amounts</legend>

            <div className="sales-reference-amounts-checks">
              <label>
                <input
                  type="checkbox"
                  checked={paymentDue}
                  disabled={!selectedCustomer}
                  onChange={(event) => setPaymentDue(event.target.checked)}
                />
                {' '}Payment Due
              </label>

              <label>
                <input
                  type="checkbox"
                  disabled={!canHoldSale || !cart.hasPaidLines || holdMutation.isPending}
                  checked={false}
                  onChange={() => parkCurrentCart()}
                />
                {' '}On Hold
              </label>
            </div>

            <div className="sales-reference-amounts-grid">
              <span />

              <strong className="is-green">
                {cart.preview.grandTotal}
              </strong>

              <button
                type="button"
                onClick={() =>
                  setReceived(cart.preview.grandTotal)
                }
                disabled={!cart.hasPaidLines}
              >
                Get
              </button>

              <label>Disc (%)</label>
              <label>Sales Tax (%)</label>

              <input
                value={
                  cart.invoiceDiscountSource === 'percent'
                    ? cart.invoiceDiscountInput
                    : displayEditableNumber(cart.preview.invoiceDiscountPercent)
                }
                onChange={(e) => cart.setInvoiceDiscountPercent(e.target.value)}
                inputMode="decimal"
                aria-label="Invoice discount percent"
                title="Overall invoice discount percentage"
              />

              <input
                value={
                  Number.parseFloat(cart.preview.qualifyingSubtotal) > 0
                    ? (
                        (Number.parseFloat(cart.preview.tax) /
                          Number.parseFloat(
                            cart.preview.qualifyingSubtotal,
                          )) *
                        100
                      ).toFixed(2)
                    : '0.00'
                }
                readOnly
                title="Effective tax rate from product tax settings"
              />

              <input
                value={cart.preview.subtotal}
                readOnly
              />
            </div>
          </fieldset>

          <fieldset className="sales-reference-pay-options">
            <legend>
              <span>Amount Options</span>

              <span className="sales-reference-pay-tools">
                <button
                  type="button"
                  onClick={() => receivedRef.current?.focus()}
                  title="Focus payment amount"
                >
                  <FolderOpen size={13} />
                </button>

                <button
                  type="button"
                  onClick={() =>
                    setSaveError(
                      'Enter the amount received, select the payment method, then press Sale.',
                    )
                  }
                  title="Payment help"
                >
                  <CircleHelp size={13} />
                </button>
              </span>
            </legend>

            <div className="sales-reference-pay-head-values">
              <strong>{cart.preview.subtotal}</strong>
              <strong>{cart.preview.grandTotal}</strong>
            </div>

            <div className="sales-reference-disc-line">
              <span>Disc (Rs):</span>

              <label>
                <input
                  type="checkbox"
                  onChange={() => {
                    setSaveError(
                      'Cost-based discount is not currently supported.',
                    )
                  }}
                />{' '}
                Cost
              </label>
            </div>

            <div className="sales-reference-disc-values">
              <input
                value={
                  cart.invoiceDiscountSource === 'amount'
                    ? cart.invoiceDiscountInput
                    : displayEditableNumber(cart.preview.invoiceDiscountAmount)
                }
                onChange={(e) => cart.setInvoiceDiscountAmount(e.target.value)}
                inputMode="decimal"
                aria-label="Invoice discount amount"
              />
              <input
                value={
                  cart.invoiceDiscountSource === 'percent'
                    ? cart.invoiceDiscountInput
                    : displayEditableNumber(cart.preview.invoiceDiscountPercent)
                }
                onChange={(e) => cart.setInvoiceDiscountPercent(e.target.value)}
                inputMode="decimal"
                aria-label="Invoice discount percent"
              />
            </div>

            <label className="sales-reference-payment-label">
              Payment Method:
            </label>

            <div className="sales-reference-payment-method">
              <UiSelect
                value={paymentMethod}
                className="sales-reference-payment-select"
                triggerClassName="sales-reference-payment-select-trigger"
                aria-label="Payment method"
                options={[
                  { value: 'cash', label: 'CASH IN HAND' },
                  { value: 'card', label: 'CARD' },
                  { value: 'bank', label: 'BANK' },
                  { value: 'credit', label: 'CREDIT' },
                ]}
                onChange={(value) => setPaymentMethod(value as SalePaymentMethod)}
              />
            </div>

            {saleSoftwareOptions?.show_remarks !== false ? (
              <>
                <label className="sales-reference-remarks-label">
                  Remarks:
                </label>

                <textarea
                  value={cart.notes}
                  onChange={(e) =>
                    cart.setNotes(e.target.value)
                  }
                  placeholder="Enter invoice remarks..."
                />
              </>
            ) : null}

            <label className="sales-reference-remarks-label">
              Payment Reference:
            </label>

            <input
              value={paymentReference}
              onChange={(e) =>
                setPaymentReference(e.target.value)
              }
              placeholder="Optional reference"
            />
          </fieldset>

          <div className="sales-reference-total-block">
            <span>Total:</span>

            <div>{cart.preview.grandTotal}</div>
          </div>

          <label className="sales-reference-side-label">
            Received (F12):
          </label>

          <div className="sales-reference-received">
            <span />

            <input
              ref={receivedRef}
              value={received}
              onChange={(e) =>
                setReceivedAmount(e.target.value)
              }
              inputMode="decimal"
              aria-label="Received amount"
              placeholder="0.00"
            />
          </div>

          <label className="sales-reference-side-label">
            Payment:
          </label>

          <div className="sales-reference-card">
            <strong>
              {paymentMethod.toUpperCase()}
            </strong>

            <strong>
              {Number.parseFloat(received || '0').toFixed(2)}
            </strong>
          </div>

          <label className="sales-reference-side-label">
            Balance:
          </label>

          <div className="sales-reference-balance">
            <strong>{balanceDue}</strong>

            <strong>-</strong>
          </div>
        </aside>
      ) : null}

      {paymentModalOpen && cart.hasPaidLines ? (
        <UiModal
          open
          title="Invoice / Order Finalize Options"
          ariaLabel="Finalize sale invoice"
          size="sm"
          onClose={() => {
            if (!saveMutation.isPending) {
              setPaymentModalOpen(false)
            }
          }}
          bodyClassName="sales-payment-modal-body"
        >
          <SaleFinalizePaymentPanel
            total={cart.preview.grandTotal}
            accounts={paymentAccountsQuery.data ?? []}
            initialCash={received}
            initialReference={paymentReference}
            pending={saveMutation.isPending}
            paymentsEnabled={canCollectPayment}
            onCancel={() => setPaymentModalOpen(false)}
            onConfirm={(payments) => saveMutation.mutate(payments)}
          />
        </UiModal>
      ) : null}

      {partyModalOpen ? (
        <PartyModal
          onClose={() => setPartyModalOpen(false)}
          onSaved={(party) => void handlePartySaved(party)}
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