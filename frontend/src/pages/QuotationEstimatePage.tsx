import { FileSearch, Plus, Printer, ReceiptText, RefreshCw, Save, X, XCircle } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ApiClientError } from '../api/client'
import { fetchProduct, fetchProductStock, fetchProducts } from '../api/catalog'
import { fetchParties } from '../api/parties'
import {
  createSaleQuotation,
  fetchNextSaleQuotationDocumentNumber,
  fetchSaleQuotation,
  fetchSaleQuotations,
  fetchSalesmen,
  updateSaleQuotation,
} from '../api/sales'
import type { Product } from '../types/catalog'
import type { SaleQuotation } from '../types/sales'
import { DesktopButton, DesktopPanel } from '../components/desktop/DesktopPanel'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { PosEntryGrid } from '../components/desktop/PosEntryGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiModal } from '../components/ui/UiModal'
import { UiSimpleDropdown, UiTableDropdown } from '../components/ui/UiSelect'
import { ProductLookupGrid } from '../features/sales/ProductLookupGrid'
import { printSaleQuotation } from '../features/sales/quotationPrint'
import { useSaleCart } from '../features/sales/useSaleCart'
import { useAuth } from '../features/auth/AuthProvider'
import { useCan } from '../features/auth/useCan'
import { useLocalProducts } from '../features/catalog/useLocalCatalog'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import { formatDecimal } from '../utils/formatDecimal'
import './QuotationEstimatePage.css'

function newQuotationKey(): string {
  return `quotation-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

function errorMessage(error: unknown): string {
  if (error instanceof ApiClientError || error instanceof Error) return error.message
  return 'Unable to complete the quotation request.'
}

export function QuotationEstimatePage({ active = true }: { active?: boolean } = {}) {
  const { closeActiveTab, openModule } = useWorkspace()
  const { session } = useAuth()
  const feedback = useFeedback()
  const cart = useSaleCart()
  const idempotencyKeyRef = useRef(newQuotationKey())
  const productSearchRef = useRef<HTMLInputElement>(null)
  const productPickerAnchorRef = useRef<HTMLDivElement>(null)
  const activeProductSearchRef = useRef<HTMLInputElement>(null)
  const activeProductPickerAnchorRef = useRef<HTMLDivElement>(null)

  const [tab, setTab] = useState<'entry' | 'search'>('entry')
  const [quotationDate, setQuotationDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [salesmanUlid, setSalesmanUlid] = useState<string | null>(null)
  const [productQuery, setProductQuery] = useState('')
  const [productPickerOpen, setProductPickerOpen] = useState(false)
  const [productLookupMode, setProductLookupMode] = useState<'browse' | 'search'>('browse')
  const [activeLineKey, setActiveLineKey] = useState<string | null>(null)
  const [activeProductQuery, setActiveProductQuery] = useState('')
  const [activeNumericEditor, setActiveNumericEditor] = useState<string | null>(null)
  const [searchText, setSearchText] = useState('')
  const [quotationNumberValue, setQuotationNumberValue] = useState<string | null>(null)
  const [pendingQuotationLookupNumber, setPendingQuotationLookupNumber] = useState<string | null>(null)
  const [savedQuotation, setSavedQuotation] = useState<SaleQuotation | null>(null)
  const [openedQuotation, setOpenedQuotation] = useState<SaleQuotation | null>(null)

  const canViewQuotation = useCan('sales.view')
  const canCreateQuotation = useCan('sales.create')
  const canEditQuotation = useCan('sales.quotation.edit')

  const activeQuotation = openedQuotation ?? savedQuotation
  const readOnly = activeQuotation ? !canEditQuotation : !canCreateQuotation

  const quotationOtherColumnCount = readOnly ? 8 : 9
  const quotationOtherColumnWidth = `${70 / quotationOtherColumnCount}%`

  const nextQuotationNumberQuery = useQuery({
    queryKey: ['quotation-estimate', 'next-document-number', session?.branch.ulid, session?.warehouse.ulid],
    queryFn: fetchNextSaleQuotationDocumentNumber,
    enabled:
      Boolean(session) &&
      active &&
      tab === 'entry' &&
      (canCreateQuotation || canEditQuotation),
    retry: false,
  })

  const displayedQuotationNumber =
    quotationNumberValue ??
    activeQuotation?.document_number ??
    nextQuotationNumberQuery.data?.document_number ??
    'Auto'

  const customersQuery = useQuery({
    queryKey: ['quotation-estimate', 'customers'],
    queryFn: () => fetchParties('customer'),
    retry: false,
  })

  const salesmenQuery = useQuery({
    queryKey: ['quotation-estimate', 'salesmen'],
    queryFn: fetchSalesmen,
    retry: false,
  })

  const quotationProductLookupQuery =
    productLookupMode === 'search'
      ? (activeLineKey ? activeProductQuery : productQuery)
      : ''

  const localProductLookup = useLocalProducts(
    session,
    quotationProductLookupQuery,
    tab === 'entry' && !readOnly,
  )

  const localProductLookupRows = localProductLookup.rows.filter(
    (product) => product.is_active && product.status === 'active',
  )

  const serverProductLookupFallback = useQuery({
    queryKey: ['quotation-estimate', 'product-lookup-fallback', quotationProductLookupQuery],
    queryFn: () => fetchProducts(
      {
        q: quotationProductLookupQuery,
        per_page: 50,
        page: 1,
        active_only: true,
        sales_lookup: true,
      },
      { busy: 'none' },
    ),
    enabled:
      !readOnly &&
      productLookupMode === 'search' &&
      quotationProductLookupQuery.trim() !== '' &&
      !localProductLookup.isFetching &&
      localProductLookupRows.length === 0,
    retry: false,
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

  const quotationsQuery = useQuery({
    queryKey: ['quotation-estimate', 'search', searchText],
    queryFn: () => fetchSaleQuotations({
      q: searchText.trim() || undefined,
      per_page: 50,
      page: 1,
    }),
    enabled: tab === 'search',
    retry: false,
  })

  async function fetchQuotationEditorData(ulid: string) {
    const quotation = await fetchSaleQuotation(ulid)
    const productUlids = Array.from(
      new Set(
        quotation.items
          .map((item) => item.product?.ulid)
          .filter((productUlid): productUlid is string => Boolean(productUlid)),
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

    return {
      quotation,
      productsByUlid: Object.fromEntries(
        productRows.map((row) => [row.productUlid, row.product]),
      ),
      stockByProduct: Object.fromEntries(
        productRows.map((row) => [row.productUlid, row.stock]),
      ),
    }
  }

  function loadQuotationEditor(
    quotation: SaleQuotation,
    productsByUlid: Record<string, Product>,
    stockByProduct: Record<string, string>,
  ) {
    cart.restoreSaleDocument(quotation, productsByUlid, stockByProduct)
    setOpenedQuotation(quotation)
    setSavedQuotation(null)
    setQuotationNumberValue(quotation.document_number)
    setQuotationDate(quotation.quotation_date)
    setSalesmanUlid(quotation.salesman?.ulid ?? null)
    setPendingQuotationLookupNumber(null)
    setProductQuery('')
    setActiveProductQuery('')
    setActiveLineKey(null)
    setProductPickerOpen(false)
    setTab('entry')
  }

  const saveMutation = useMutation({
    mutationFn: () => {
      if (!cart.hasPaidLines) {
        throw new Error('Add at least one product before saving the estimate.')
      }

      const payload = cart.buildPayload()
      const quotationPayload = {
        price_type: cart.priceType,
        items: payload.items,
        free_lines: payload.free_lines,
        applied_schemes: payload.applied_schemes,
        customer_ulid: cart.customerUlid,
        salesman_ulid: salesmanUlid,
        notes: cart.notes || null,
        quotation_date: quotationDate,
      }

      if (activeQuotation) {
        if (!canEditQuotation) {
          throw new Error('You do not have permission to edit quotations.')
        }

        return updateSaleQuotation(activeQuotation.ulid, {
          ...quotationPayload,
          revision_reason: 'Quotation updated from Quotation / Estimate',
        })
      }

      if (!canCreateQuotation) {
        throw new Error('You do not have permission to create quotations.')
      }

      return createSaleQuotation(
        quotationPayload,
        idempotencyKeyRef.current,
      )
    },
    onSuccess: (quotation) => {
      const wasUpdate = Boolean(activeQuotation)
      setOpenedQuotation(quotation)
      setSavedQuotation(null)
      setQuotationNumberValue(quotation.document_number)

      feedback.success(
        wasUpdate
          ? `Estimate ${quotation.document_number} updated with revision history.`
          : `Estimate ${quotation.document_number} saved. No stock, payment or accounting entry was posted.`,
        'Quotation / Estimate',
      )
    },
    onError: (error) => feedback.error(errorMessage(error), 'Quotation / Estimate'),
  })

  const openMutation = useMutation({
    mutationFn: (ulid: string) => fetchQuotationEditorData(ulid),
    onSuccess: ({ quotation, productsByUlid, stockByProduct }) => {
      loadQuotationEditor(quotation, productsByUlid, stockByProduct)
    },
    onError: (error) => feedback.error(errorMessage(error), 'Quotation / Estimate'),
  })

  const quotationLookupMutation = useMutation({
    mutationFn: async (requestedNumber: string) => {
      const lookup = await fetchSaleQuotations({
        q: requestedNumber,
        per_page: 20,
        page: 1,
      })

      const summary = lookup.data.find(
        (quotation) =>
          quotation.document_number.trim().toLocaleLowerCase() ===
          requestedNumber.trim().toLocaleLowerCase(),
      )

      if (!summary) {
        throw new Error('No quotation with that exact number was found in this branch and warehouse.')
      }

      return fetchQuotationEditorData(summary.ulid)
    },
    onSuccess: ({ quotation, productsByUlid, stockByProduct }) => {
      loadQuotationEditor(quotation, productsByUlid, stockByProduct)
    },
    onError: (error) => {
      setPendingQuotationLookupNumber(null)
      feedback.error(errorMessage(error), 'Quotation / Estimate')
    },
  })

  function requestQuotationLookup() {
    const requestedNumber = (quotationNumberValue ?? '').trim()
    if (!requestedNumber || quotationLookupMutation.isPending || !canViewQuotation) return

    const currentNumber = activeQuotation?.document_number.trim().toLocaleLowerCase() ?? null
    const requestedKey = requestedNumber.toLocaleLowerCase()

    if (cart.lines.length > 0 && currentNumber !== requestedKey) {
      setPendingQuotationLookupNumber(requestedNumber)
      return
    }

    quotationLookupMutation.mutate(requestedNumber)
  }

  function cancelPendingQuotationLookup() {
    setPendingQuotationLookupNumber(null)
    setQuotationNumberValue(activeQuotation?.document_number ?? null)
  }

  function requestQuotationOpen(quotation: SaleQuotation) {
    setQuotationNumberValue(quotation.document_number)

    const currentNumber = activeQuotation?.document_number.trim().toLocaleLowerCase() ?? null
    const requestedKey = quotation.document_number.trim().toLocaleLowerCase()

    if (cart.lines.length > 0 && currentNumber !== requestedKey) {
      setPendingQuotationLookupNumber(quotation.document_number)
      return
    }

    openMutation.mutate(quotation.ulid)
  }

  const customerOptions = useMemo(
    () => [
      {
        value: '',
        label: 'Walk-in / No customer',
        columns: ['—', 'Walk-in / No customer', '—'],
      },
      ...(customersQuery.data ?? []).map((customer) => ({
        value: customer.ulid,
        label: customer.name,
        columns: [
          customer.code || '—',
          customer.name,
          customer.mobile || customer.phone || '—',
        ],
      })),
    ],
    [customersQuery.data],
  )

  const salesmanOptions = useMemo(
    () => [
      {
        value: '',
        label: 'No salesman',
        columns: ['—', 'No salesman', '—', '—'],
      },
      ...(salesmenQuery.data ?? []).map((salesman) => ({
        value: salesman.ulid,
        label: salesman.name,
        columns: [
          salesman.code || '—',
          salesman.name,
          salesman.address || '—',
          salesman.mobile || '—',
        ],
      })),
    ],
    [salesmenQuery.data],
  )

  function startNew() {
    cart.clear()
    cart.setPriceType('default')
    setQuotationDate(new Date().toISOString().slice(0, 10))
    setSalesmanUlid(null)
    setProductQuery('')
    setProductPickerOpen(false)
    setProductLookupMode('browse')
    setActiveLineKey(null)
    setActiveProductQuery('')
    setQuotationNumberValue(null)
    setPendingQuotationLookupNumber(null)
    setSavedQuotation(null)
    setOpenedQuotation(null)
    idempotencyKeyRef.current = newQuotationKey()
    void nextQuotationNumberQuery.refetch()
    setTab('entry')
  }

  function focusLineQuantity(lineKey: string) {
    window.setTimeout(() => {
      const input = globalThis.document.querySelector<HTMLInputElement>(
        `[data-quotation-line="${lineKey}"] [data-quotation-field="quantity"]`,
      )
      input?.focus()
      input?.select()
    }, 0)
  }

  function finishActiveLine(lineKey: string, focusNext = true) {
    if (activeLineKey !== lineKey) return

    setActiveLineKey(null)
    setActiveProductQuery('')
    setProductPickerOpen(false)

    if (focusNext) {
      window.setTimeout(() => {
        productSearchRef.current?.focus()
        productSearchRef.current?.select()
      }, 0)
    }
  }

  function handleLineEnter(
    event: ReactKeyboardEvent<HTMLInputElement | HTMLButtonElement>,
    lineKey: string,
    active: boolean,
  ) {
    if (event.key !== 'Enter') return

    event.preventDefault()
    const row = event.currentTarget.closest('tr')
    if (!row) return

    const fields = Array.from(
      row.querySelectorAll<HTMLInputElement | HTMLButtonElement>(
        '[data-quotation-editable="true"]:not(:disabled)',
      ),
    )
    const currentIndex = fields.indexOf(event.currentTarget)
    const next = fields[currentIndex + 1]

    if (next) {
      next.focus()
      if (next instanceof HTMLInputElement) next.select()
      return
    }

    const line = cart.paidLines.find((item) => item.line_key === lineKey)
    const quantity = Number.parseFloat(line?.quantity ?? '')
    if (!line || !Number.isFinite(quantity) || quantity <= 0) {
      feedback.error('Quotation quantity must be greater than zero.', 'Quotation / Estimate')
      focusLineQuantity(lineKey)
      return
    }

    if (active) {
      finishActiveLine(lineKey)
      return
    }

    window.setTimeout(() => {
      productSearchRef.current?.focus()
      productSearchRef.current?.select()
    }, 0)
  }

  function editCompletedProduct(line: typeof cart.paidLines[number]) {
    setActiveLineKey(line.line_key)
    setActiveProductQuery(`${line.product_number} — ${line.product_name}`)
    setProductQuery('')
    setProductLookupMode('browse')
    setProductPickerOpen(true)

    window.setTimeout(() => {
      activeProductSearchRef.current?.focus()
      activeProductSearchRef.current?.select()
    }, 0)
  }

  function addSelectedProduct(
    product: Product,
    replaceLineKey: string | null = null,
  ) {
    if (!product.ulid || readOnly) return

    const expectedPriceType = cart.priceType === 'wholesale' ? 'wholesale' : 'retail'
    const activePrice = product.prices?.find(
      (price) => price.is_active && price.price_type === expectedPriceType,
    )
    const availableBaseStock = product.sales_lookup?.in_stock ?? null

    let lineKey = replaceLineKey

    const existingLine = !replaceLineKey
      ? cart.lines.find(
          (line) =>
            line.line_kind === 'sale' &&
            line.product_ulid === product.ulid &&
            (line.unit_ulid ?? null) === (product.base_unit?.ulid ?? null) &&
            (line.barcode ?? null) === null,
        )
      : null

    if (existingLine) {
      const nextQuantity = (Number.parseFloat(existingLine.quantity) || 0) + 1
      cart.setQuantity(existingLine.line_key, nextQuantity.toFixed(6))

      setProductQuery('')
      setActiveProductQuery(`${existingLine.product_number} — ${existingLine.product_name}`)
      setProductPickerOpen(false)
      setProductLookupMode('browse')
      setActiveLineKey(existingLine.line_key)
      focusLineQuantity(existingLine.line_key)
      return
    }

    if (replaceLineKey) {
      cart.replaceProduct(
        replaceLineKey,
        product,
        null,
        availableBaseStock,
      )
    } else {
      lineKey = cart.addProduct(
        product,
        '1.000000',
        null,
        availableBaseStock,
      )
    }

    if (!lineKey) return

    if (!activePrice) {
      feedback.info(
        `No active ${expectedPriceType} price is configured for ${product.product_number} — ${product.name}. The preview rate will remain 0 until the product price is configured; the server remains final authority on save.`,
        'Quotation / Estimate',
      )
    }

    setProductQuery('')
    setActiveProductQuery(`${product.product_number} — ${product.name}`)
    setProductPickerOpen(false)
    setProductLookupMode('browse')
    setActiveLineKey(lineKey)
    focusLineQuantity(lineKey)
  }

  useEffect(() => {
    if (!activeLineKey || readOnly || tab !== 'entry') return
    const lineKey = activeLineKey

    function handleOutsideActiveRow(event: MouseEvent) {
      const target = event.target
      if (!(target instanceof Node)) return

      const row = globalThis.document.querySelector<HTMLElement>(
        `[data-quotation-line="${lineKey}"]`,
      )
      if (row?.contains(target)) return

      if (
        target instanceof Element &&
        target.closest('.sales-pos-product-results-grid')
      ) {
        return
      }

      const line = cart.paidLines.find((item) => item.line_key === lineKey)
      const quantity = Number.parseFloat(line?.quantity ?? '')
      if (!line || !Number.isFinite(quantity) || quantity <= 0) return

      finishActiveLine(lineKey, false)
    }

    window.addEventListener('mousedown', handleOutsideActiveRow)
    return () => window.removeEventListener('mousedown', handleOutsideActiveRow)
  }, [activeLineKey, cart.paidLines, readOnly, tab])

  useEffect(() => {
    if (active) return
    setProductPickerOpen(false)
  }, [active])

  return (
    <DesktopPanel
      title="Quotation / Estimate"
      className="quotation-estimate-page"
      toolbar={
        <>
          <DesktopButton icon={<Plus size={15} />} label="New" onClick={startNew} />
          <DesktopButton
            icon={<Save size={15} />}
            label={activeQuotation ? 'Update Estimate' : 'Save Estimate'}
            variant="success"
            disabled={readOnly || saveMutation.isPending || !cart.hasPaidLines}
            onClick={() => saveMutation.mutate()}
          />
          <DesktopButton
            icon={<FileSearch size={15} />}
            label="Search"
            variant="info"
            onClick={() => setTab('search')}
          />
          <DesktopButton
            icon={<Printer size={15} />}
            label="Print"
            variant="info"
            disabled={!activeQuotation || !session}
            onClick={() => {
              if (!activeQuotation || !session) return

              const opened = printSaleQuotation(activeQuotation, session)
              if (!opened) {
                feedback.error(
                  'The print window was blocked by the browser. Allow pop-ups for BluePOS and try again.',
                  'Quotation Print',
                )
              }
            }}
          />
          <DesktopButton
            icon={<ReceiptText size={15} />}
            label="Convert to Sale"
            variant="primary"
            disabled={!activeQuotation}
            onClick={() => {
              if (!activeQuotation) return
              openModule(
                `/daily/sales?quotation=${encodeURIComponent(activeQuotation.document_number)}`,
              )
            }}
          />
          <DesktopButton
            icon={<RefreshCw size={15} />}
            label="Refresh"
            variant="info"
            onClick={() => {
              if (tab === 'search') void quotationsQuery.refetch()
              else startNew()
            }}
          />
          <DesktopButton icon={<X size={15} />} label="Close" variant="info" onClick={closeActiveTab} />
        </>
      }
    >
      <div className="quotation-tabs" role="tablist" aria-label="Quotation workspace">
        <button
          type="button"
          className={tab === 'entry' ? 'is-active' : ''}
          onClick={() => setTab('entry')}
        >
          Estimate
        </button>
        <button
          type="button"
          className={tab === 'search' ? 'is-active' : ''}
          onClick={() => setTab('search')}
        >
          Search
        </button>
      </div>

      {pendingQuotationLookupNumber ? (
        <UiModal
          open
          title="Load another quotation?"
          ariaLabel="Confirm quotation change"
          size="sm"
          onClose={() => {
            if (!quotationLookupMutation.isPending) {
              cancelPendingQuotationLookup()
            }
          }}
          footer={
            <>
              <UiButton
                variant="info"
                disabled={quotationLookupMutation.isPending}
                onClick={cancelPendingQuotationLookup}
              >
                Cancel
              </UiButton>
              <UiButton
                variant="warning"
                disabled={quotationLookupMutation.isPending}
                onClick={() => {
                  const requestedNumber = pendingQuotationLookupNumber
                  if (requestedNumber) {
                    quotationLookupMutation.mutate(requestedNumber)
                  }
                }}
              >
                {quotationLookupMutation.isPending ? 'Loading...' : 'Fetch Quotation'}
              </UiButton>
            </>
          }
        >
          The current quotation contains data. If you fetch {pendingQuotationLookupNumber}, the current data will be lost.
        </UiModal>
      ) : null}

      {tab === 'entry' ? (
        <div className="quotation-entry">
          <div className="quotation-entry-head">
            <div className="quotation-header-grid">
            <label className="is-number">
              <span>Quotation #</span>
              <input
                className="desktop-input"
                value={displayedQuotationNumber}
                readOnly={!canViewQuotation}
                disabled={quotationLookupMutation.isPending || saveMutation.isPending}
                title="Type an exact quotation number and press Enter to load it"
                onChange={(event) => setQuotationNumberValue(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key !== 'Enter') return
                  event.preventDefault()
                  requestQuotationLookup()
                }}
              />
            </label>
            <label className="is-date">
              <span>Date</span>
              <input
                className="desktop-input"
                type="date"
                value={quotationDate}
                disabled={readOnly}
                onChange={(event) => setQuotationDate(event.target.value)}
              />
            </label>
            <label className="is-customer">
              <span>Customer</span>
              <UiTableDropdown
                className="quotation-canonical-select"
                value={cart.customerUlid ?? ''}
                disabled={readOnly}
                options={customerOptions}
                searchPlaceholder="Search customer"
                menuMinWidth={520}
                menuColumns={[
                  { header: 'Code', width: '110px' },
                  { header: 'Name', width: 'minmax(220px, 1fr)' },
                  { header: 'Mobile', width: '130px' },
                ]}
                onChange={(value) => cart.setCustomerUlid(value || null)}
              />
            </label>
            <label className="is-salesman">
              <span>Salesman</span>
              <UiTableDropdown
                className="quotation-canonical-select"
                value={salesmanUlid ?? ''}
                disabled={readOnly}
                options={salesmanOptions}
                searchPlaceholder="Search salesman"
                menuMinWidth={620}
                menuColumns={[
                  { header: 'Code', width: '100px' },
                  { header: 'Name', width: 'minmax(180px, 1fr)' },
                  { header: 'Address', width: 'minmax(180px, 1fr)' },
                  { header: 'Mobile', width: '130px' },
                ]}
                onChange={(value) => setSalesmanUlid(value || null)}
              />
            </label>
            <label className="is-price-type">
              <span>Price Type</span>
              <UiSimpleDropdown
                className="quotation-price-type-select"
                value={cart.priceType}
                disabled={readOnly}
                menuMinWidth={150}
                options={[
                  { value: 'default', label: 'Default / Retail' },
                  { value: 'retail', label: 'Retail' },
                  { value: 'wholesale', label: 'Wholesale' },
                ]}
                onChange={(value) => cart.setPriceType(value as 'default' | 'retail' | 'wholesale')}
              />
            </label>
            </div>
          </div>

          <PosEntryGrid
            className="quotation-lines-wrap"
            tableClassName="quotation-lines"
            ariaLabel="Quotation line items"
            minWidth={820}
            columns={[
              { key: 'product', header: 'Product', width: '30%' },
              { key: 'unit', header: 'Unit', width: quotationOtherColumnWidth },
              { key: 'stock', header: 'In Stock', width: quotationOtherColumnWidth, align: 'right' },
              { key: 'qty', header: 'Qty', width: quotationOtherColumnWidth, align: 'right' },
              { key: 'rate', header: 'Rate', width: quotationOtherColumnWidth, align: 'right' },
              { key: 'disc-pct', header: 'Disc %', width: quotationOtherColumnWidth, align: 'right' },
              { key: 'disc-rs', header: 'Disc Rs', width: quotationOtherColumnWidth, align: 'right' },
              { key: 'tax-pct', header: 'Tax %', width: quotationOtherColumnWidth, align: 'right' },
              { key: 'amount', header: 'Amount', width: quotationOtherColumnWidth, align: 'right' },
              ...(!readOnly
                ? [{ key: 'remove', header: '', width: quotationOtherColumnWidth, align: 'center' as const }]
                : []),
            ]}
          >
                {readOnly
                  ? (activeQuotation?.items ?? []).map((item) => (
                      <tr key={item.ulid}>
                        <td>{item.product ? `${item.product.product_number} — ${item.product.name}` : '—'}</td>
                        <td>{item.unit?.symbol ?? item.unit?.code ?? '—'}</td>
                        <td className="is-num">—</td>
                        <td className="is-num">{item.quantity}</td>
                        <td className="is-num">{item.unit_price}</td>
                        <td className="is-num">{item.discount_percent}</td>
                        <td className="is-num">{item.discount_amount}</td>
                        <td className="is-num">{item.tax_percent}</td>
                        <td className="is-num">{item.line_total}</td>
                      </tr>
                    ))
                  : cart.paidLines
                      .filter((line) => line.line_key !== activeLineKey)
                      .map((line) => {
                        const preview = cart.linePreview(line)
                        return (
                          <tr key={line.line_key} data-quotation-line={line.line_key}>
                            <td className="quotation-product-search-cell">
                              <button
                                type="button"
                                className="quotation-completed-product-trigger"
                                onClick={() => editCompletedProduct(line)}
                              >
                                <span>{line.product_number} — {line.product_name}</span>
                                <span className="quotation-product-combo-toggle" aria-hidden="true">
                                  <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                                    <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                                  </svg>
                                </span>
                              </button>
                            </td>
                            <td>
                              {(line.available_units?.length ?? 0) > 0 ? (
                                <UiSimpleDropdown
                                  value={line.unit_ulid ?? ''}
                                  className="quotation-line-unit-select"
                                  searchable={false}
                                  aria-label={`Unit for ${line.product_name}`}
                                  triggerProps={{ 'data-quotation-editable': 'true' }}
                                  options={(line.available_units ?? []).map((unit) => ({
                                    value: unit.unit_ulid,
                                    label: unit.code,
                                  }))}
                                  onKeyDown={(event) => handleLineEnter(event, line.line_key, false)}
                                  onChange={(value) => cart.setLineUnit(line.line_key, value)}
                                />
                              ) : (
                                line.unit_symbol ?? line.unit_code ?? '—'
                              )}
                            </td>
                            <td className="is-num">
                              {(() => {
                                const available = cart.availableStock(line)
                                return available === null ? '—' : formatDecimal(available)
                              })()}
                            </td>
                            <td className="is-num">
                              <input
                                data-quotation-editable="true"
                                data-quotation-field="quantity"
                                aria-label={`Quantity for ${line.product_name}`}
                                value={formatDecimal(line.quantity)}
                                onChange={(event) => cart.setQuantity(line.line_key, event.target.value)}
                                onKeyDown={(event) => handleLineEnter(event, line.line_key, false)}
                              />
                            </td>
                            <td className="is-num">{formatDecimal(line.unit_price)}</td>
                            <td className="is-num">
                              <input
                                data-quotation-editable="true"
                                aria-label={`Discount percent for ${line.product_name}`}
                                value={
                                  activeNumericEditor === `disc-pct:${line.line_key}`
                                    ? (line.discount_percent ?? '0')
                                    : formatDecimal(line.discount_percent ?? '0')
                                }
                                onFocus={() => setActiveNumericEditor(`disc-pct:${line.line_key}`)}
                                onBlur={() => setActiveNumericEditor(null)}
                                onChange={(event) => cart.setDiscountPercent(line.line_key, event.target.value)}
                                onKeyDown={(event) => handleLineEnter(event, line.line_key, false)}
                              />
                            </td>
                            <td className="is-num">
                              <input
                                data-quotation-editable="true"
                                aria-label={`Discount amount for ${line.product_name}`}
                                value={
                                  activeNumericEditor === `disc-rs:${line.line_key}`
                                    ? (line.discount_amount ?? '0')
                                    : formatDecimal(line.discount_amount ?? '0')
                                }
                                onFocus={() => setActiveNumericEditor(`disc-rs:${line.line_key}`)}
                                onBlur={() => setActiveNumericEditor(null)}
                                onChange={(event) => cart.setDiscountAmount(line.line_key, event.target.value)}
                                onKeyDown={(event) => handleLineEnter(event, line.line_key, false)}
                              />
                            </td>
                            <td className="is-num">{formatDecimal(line.tax_percent)}</td>
                            <td className="is-num">{formatDecimal(preview.netAmount)}</td>
                            <td>
                              <UiButton
                                variant="danger"
                                aria-label={`Remove ${line.product_name}`}
                                onClick={() => cart.removeLine(line.line_key)}
                              >
                                <XCircle size={14} />
                              </UiButton>
                            </td>
                          </tr>
                        )
                      })}

                {!readOnly && activeLineKey
                  ? cart.paidLines
                      .filter((line) => line.line_key === activeLineKey)
                      .map((line) => {
                        const preview = cart.linePreview(line)
                        return (
                          <tr
                            key={line.line_key}
                            className="quotation-active-row"
                            data-quotation-line={line.line_key}
                          >
                            <td className="quotation-product-search-cell">
                              <div ref={activeProductPickerAnchorRef} className="quotation-product-combo">
                                <input
                                  ref={activeProductSearchRef}
                                  className="quotation-product-search-input"
                                  value={activeProductQuery}
                                  aria-label="Change product"
                                  autoComplete="off"
                                  onFocus={() => {
                                    setProductLookupMode('browse')
                                    setProductPickerOpen(true)
                                    if (!activeProductQuery) {
                                      setActiveProductQuery(`${line.product_number} — ${line.product_name}`)
                                    }
                                  }}
                                  onChange={(event) => {
                                    setActiveProductQuery(event.target.value)
                                    setProductQuery(event.target.value)
                                    setProductLookupMode('search')
                                    setProductPickerOpen(true)
                                  }}
                                  onKeyDown={(event) => {
                                    if (event.key === 'Escape') {
                                      setActiveProductQuery(`${line.product_number} — ${line.product_name}`)
                                      setProductQuery('')
                                      setProductPickerOpen(false)
                                      return
                                    }
                                    if (event.key !== 'Enter') return
                                    const first = productLookupRows[0]
                                    if (!first) return
                                    event.preventDefault()
                                    addSelectedProduct(first, line.line_key)
                                  }}
                                />
                                <button
                                  type="button"
                                  className="quotation-product-combo-toggle"
                                  aria-label="Open product dropdown"
                                  aria-expanded={productPickerOpen}
                                  onMouseDown={(event) => event.preventDefault()}
                                  onClick={() => {
                                    setProductLookupMode('browse')
                                    setProductPickerOpen((open) => !open)
                                  }}
                                >
                                  <span aria-hidden="true">
                                  <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                                    <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                                  </svg>
                                </span>
                                </button>
                                {productPickerOpen ? (
                                  <ProductLookupGrid
                                    rows={productLookupRows}
                                    total={productLookupTotal}
                                    priceType={cart.priceType}
                                    selectedUlid={line.product_ulid}
                                    portalAnchorRef={activeProductPickerAnchorRef}
                                    themed
                                    onRequestClose={() => setProductPickerOpen(false)}
                                    onSelect={(product) => addSelectedProduct(product, line.line_key)}
                                  />
                                ) : null}
                              </div>
                            </td>
                            <td>
                              {(line.available_units?.length ?? 0) > 0 ? (
                                <UiSimpleDropdown
                                  value={line.unit_ulid ?? ''}
                                  className="quotation-line-unit-select"
                                  searchable={false}
                                  aria-label={`Unit for ${line.product_name}`}
                                  triggerProps={{ 'data-quotation-editable': 'true' }}
                                  options={(line.available_units ?? []).map((unit) => ({
                                    value: unit.unit_ulid,
                                    label: unit.code,
                                  }))}
                                  onKeyDown={(event) => handleLineEnter(event, line.line_key, true)}
                                  onChange={(value) => cart.setLineUnit(line.line_key, value)}
                                />
                              ) : (
                                line.unit_symbol ?? line.unit_code ?? '—'
                              )}
                            </td>
                            <td className="is-num">
                              {(() => {
                                const available = cart.availableStock(line)
                                return available === null ? '—' : formatDecimal(available)
                              })()}
                            </td>
                            <td className="is-num">
                              <input
                                data-quotation-editable="true"
                                data-quotation-field="quantity"
                                aria-label={`Quantity for ${line.product_name}`}
                                value={formatDecimal(line.quantity)}
                                onChange={(event) => cart.setQuantity(line.line_key, event.target.value)}
                                onKeyDown={(event) => handleLineEnter(event, line.line_key, true)}
                              />
                            </td>
                            <td className="is-num">{formatDecimal(line.unit_price)}</td>
                            <td className="is-num">
                              <input
                                data-quotation-editable="true"
                                aria-label={`Discount percent for ${line.product_name}`}
                                value={
                                  activeNumericEditor === `disc-pct:${line.line_key}`
                                    ? (line.discount_percent ?? '0')
                                    : formatDecimal(line.discount_percent ?? '0')
                                }
                                onFocus={() => setActiveNumericEditor(`disc-pct:${line.line_key}`)}
                                onBlur={() => setActiveNumericEditor(null)}
                                onChange={(event) => cart.setDiscountPercent(line.line_key, event.target.value)}
                                onKeyDown={(event) => handleLineEnter(event, line.line_key, true)}
                              />
                            </td>
                            <td className="is-num">
                              <input
                                data-quotation-editable="true"
                                aria-label={`Discount amount for ${line.product_name}`}
                                value={
                                  activeNumericEditor === `disc-rs:${line.line_key}`
                                    ? (line.discount_amount ?? '0')
                                    : formatDecimal(line.discount_amount ?? '0')
                                }
                                onFocus={() => setActiveNumericEditor(`disc-rs:${line.line_key}`)}
                                onBlur={() => setActiveNumericEditor(null)}
                                onChange={(event) => cart.setDiscountAmount(line.line_key, event.target.value)}
                                onKeyDown={(event) => handleLineEnter(event, line.line_key, true)}
                              />
                            </td>
                            <td className="is-num">{formatDecimal(line.tax_percent)}</td>
                            <td className="is-num">{formatDecimal(preview.netAmount)}</td>
                            <td>
                              <UiButton
                                variant="danger"
                                aria-label={`Remove ${line.product_name}`}
                                onClick={() => {
                                  cart.removeLine(line.line_key)
                                  setActiveLineKey(null)
                                  setActiveProductQuery('')
                                  setProductPickerOpen(false)
                                  window.setTimeout(() => productSearchRef.current?.focus(), 0)
                                }}
                              >
                                <XCircle size={14} />
                              </UiButton>
                            </td>
                          </tr>
                        )
                      })
                  : null}

                {!readOnly && !activeLineKey ? (
                  <tr className="quotation-entry-row is-entry-row">
                    <td className="quotation-product-search-cell">
                      <div ref={productPickerAnchorRef} className="quotation-product-combo">
                        <input
                          ref={productSearchRef}
                          className="quotation-product-search-input"
                          value={productQuery}
                          placeholder="Search product name or number"
                          aria-label="Search product"
                          autoComplete="off"
                          onFocus={() => {
                            setProductLookupMode('browse')
                            setProductPickerOpen(true)
                          }}
                          onChange={(event) => {
                            setProductQuery(event.target.value)
                            setProductLookupMode('search')
                            setProductPickerOpen(true)
                          }}
                          onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                              setProductPickerOpen(false)
                              return
                            }
                            if (event.key !== 'Enter') return
                            const first = productLookupRows[0]
                            if (!first) return
                            event.preventDefault()
                            addSelectedProduct(first)
                          }}
                        />
                        <button
                          type="button"
                          className="quotation-product-combo-toggle"
                          aria-label="Open product dropdown"
                          aria-expanded={productPickerOpen}
                          onMouseDown={(event) => event.preventDefault()}
                          onClick={() => {
                            setProductLookupMode('browse')
                            setProductPickerOpen((open) => !open)
                            window.setTimeout(() => productSearchRef.current?.focus(), 0)
                          }}
                        >
                          <span aria-hidden="true">
                                  <svg viewBox="0 0 12 8" width="10" height="7" focusable="false">
                                    <path d="M1.1 1.2 6 6l4.9-4.8 1 1.1L6 8.2.1 2.3z" fill="currentColor" />
                                  </svg>
                                </span>
                        </button>

                        {productPickerOpen ? (
                          <ProductLookupGrid
                            rows={productLookupRows}
                            total={productLookupTotal}
                            priceType={cart.priceType}
                            portalAnchorRef={productPickerAnchorRef}
                            themed
                            onRequestClose={() => setProductPickerOpen(false)}
                            onSelect={(product) => addSelectedProduct(product)}
                          />
                        ) : null}
                      </div>
                    </td>
                    <td />
                    <td className="is-num" />
                    <td className="is-num" />
                    <td className="is-num" />
                    <td className="is-num" />
                    <td className="is-num" />
                    <td className="is-num" />
                    <td className="is-num" />
                    <td />
                  </tr>
                ) : null}
                {readOnly && (activeQuotation?.items?.length ?? 0) === 0 ? (
                  <tr>
                    <td colSpan={9} className="quotation-empty">
                      No quotation lines found.
                    </td>
                  </tr>
                ) : null}
          </PosEntryGrid>

          <div className="quotation-footer-grid">
            <label>
              <span>Notes / Remarks</span>
              <textarea
                className="desktop-input"
                rows={2}
                value={cart.notes}
                disabled={readOnly}
                onChange={(event) => cart.setNotes(event.target.value)}
              />
            </label>

            <aside className="quotation-amount-options quotation-amount-options-footer" aria-label="Quotation amount options">
              <strong className="quotation-amount-options-title">Amount Options</strong>
              <div className="quotation-net-box">
                <span>Net:</span>
                <strong>{formatDecimal(cart.preview.grandTotal)}</strong>
              </div>
            </aside>

            <div className="quotation-totals">
              <div><span>Subtotal</span><strong>{formatDecimal(cart.preview.subtotal)}</strong></div>
              <div><span>Discount</span><strong>{formatDecimal(cart.preview.discount)}</strong></div>
              <div><span>Tax</span><strong>{formatDecimal(cart.preview.tax)}</strong></div>
              <div className="is-grand"><span>Estimate Total</span><strong>{formatDecimal(cart.preview.grandTotal)}</strong></div>
            </div>
          </div>

          <div className="quotation-note">
            Quotation / Estimate is non-posting: it does not create stock movement, payment, receivable or journal entries.
          </div>
        </div>
      ) : (
        <div className="quotation-search">
          <div className="quotation-search-bar">
            <input
              className="desktop-input"
              placeholder="Quotation #, customer or salesman"
              value={searchText}
              onChange={(event) => setSearchText(event.target.value)}
            />
            <UiButton variant="info" onClick={() => void quotationsQuery.refetch()}>
              Search
            </UiButton>
          </div>

          <PosDataGrid
            columns={[
              { key: 'number', header: 'Quotation #', width: 150, render: (row) => row.document_number },
              { key: 'date', header: 'Date', width: 110, render: (row) => row.quotation_date },
              { key: 'customer', header: 'Customer', render: (row) => row.customer?.name ?? 'Walk-in' },
              { key: 'salesman', header: 'Salesman', render: (row) => row.salesman?.name ?? '—' },
              { key: 'total', header: 'Total', width: 140, align: 'right', render: (row) => formatDecimal(row.grand_total) },
            ]}
            rows={quotationsQuery.data?.data ?? []}
            rowKey={(row) => row.ulid}
            onActivate={(row) => requestQuotationOpen(row)}
            emptyMessage="No quotations found."
          />
        </div>
      )}
    </DesktopPanel>
  )
}
