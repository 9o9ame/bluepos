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
  XCircle,
} from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { ApiClientError } from '../api/client'
import { fetchBusinessSettings, fetchProduct, fetchProductStock, fetchProducts } from '../api/catalog'
import { fetchParties, type Party } from '../api/parties'
import { evaluateSaleOffers } from '../api/saleSchemes'
import { createSale, createSalePayment, fetchSale } from '../api/sales'
import { previewSaleReceipt, printSaleReceipt } from '../features/sales/saleReceipt'
import { PackagingPicker } from '../features/sales/PackagingPicker'
import { SalePaymentPanel } from '../features/sales/SalePaymentPanel'
import { SalesPartyModal } from '../features/sales/SalesPartyModal'
import { SchemeOfferPrompt } from '../features/sales/SchemeOfferPrompt'
import { SalesInvoiceHistory } from '../features/sales/SalesInvoiceHistory'
import { useSaleCart } from '../features/sales/useSaleCart'
import { useCan } from '../features/auth/useCan'
import { useAuth } from '../features/auth/AuthProvider'
import type { SaleOfferEvaluation } from '../types/saleSchemes'
import type { Sale, SalePaymentMethod, SalePriceType } from '../types/sales'
import { ColumnCustomizationPanel } from '../features/gridLayout/ColumnCustomizationPanel'
import {
  SALES_INVOICE_COLUMNS,
  SALES_INVOICE_SCREEN,
  type ResolvedGridColumn,
} from '../features/gridLayout/columnCatalog'
import { useColumnLayout } from '../features/gridLayout/useColumnLayout'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import type { SaleDraftLine } from '../types/sales'
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

function outstandingAfterPayments(sale: Sale): string {
  const total = Number.parseFloat(sale.grand_total) || 0
  const paid = (sale.payments ?? []).reduce(
    (sum, payment) => sum + (Number.parseFloat(payment.amount) || 0),
    0,
  )

  return Math.max(total - paid, 0).toFixed(4)
}

function newSaleKey(): string {
  return `pos-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

function newPaymentKey(): string {
  return `pos-payment-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

export function SalesInvoicePage() {
  const { closeActiveTab } = useWorkspace()

  const [view, setView] = useState<'pos' | 'history' | 'pending'>('pos')
  const [customizationOpen, setCustomizationOpen] = useState(false)

  const [heldCarts, setHeldCarts] = useState<
    Array<{
      id: string
      lines: SaleDraftLine[]
      heldAt: string
      customerUlid: string | null
      customer: Party | null
      notes: string
      priceType: SalePriceType
    }>
  >([])

  const canSaveRoleDefault = useCan('roles.edit')
  const canCreateSale = useCan('sales.create')
  const canCollectPayment = useCan('payments.create')

  const columnLayout = useColumnLayout({
    screenKey: SALES_INVOICE_SCREEN,
    catalog: SALES_INVOICE_COLUMNS,
  })

  const cart = useSaleCart()

  const [productQuery, setProductQuery] = useState('')
  const [barcodeQuery, setBarcodeQuery] = useState('')
  const [activeLineKey, setActiveLineKey] = useState<string | null>(null)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [savedSale, setSavedSale] = useState<Sale | null>(null)

  const [received, setReceived] = useState('')
  const [paymentMethod, setPaymentMethod] =
    useState<SalePaymentMethod>('cash')
  const [paymentReference, setPaymentReference] = useState('')

  const [saleDate, setSaleDate] = useState(
    () => new Date().toISOString().slice(0, 10),
  )

  const [selectedCustomer, setSelectedCustomer] = useState<Party | null>(null)
  const [customerPickerOpen, setCustomerPickerOpen] = useState(false)
  const [partyModalOpen, setPartyModalOpen] = useState(false)

  const { session } = useAuth()

  const balanceDue = useMemo(() => {
    const total = Number.parseFloat(cart.preview.grandTotal) || 0
    const paid = Number.parseFloat(received) || 0

    return Math.max(total - paid, 0).toFixed(2)
  }, [cart.preview.grandTotal, received])

  const productsQuery = useQuery({
    queryKey: ['products', 'pos-entry', productQuery],
    queryFn: () =>
      fetchProducts(
        {
          q: productQuery || undefined,
          per_page: 20,
          page: 1,
        },
        { busy: 'none' },
      ),
    enabled: productQuery.trim().length > 0,
  })

  const businessSettingsQuery = useQuery({
    queryKey: ['settings', 'business', 'sales-stock'],
    queryFn: fetchBusinessSettings,
    retry: false,
  })

  const customersQuery = useQuery({
    queryKey: ['parties', 'customers', 'pos'],
    queryFn: () => fetchParties('customer'),
    retry: false,
  })

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
  const receivedRef = useRef<HTMLInputElement | null>(null)
  const productSearchRef = useRef<HTMLInputElement | null>(null)

  const saveMutation = useMutation({
    mutationFn: async () =>
      createSale(
        {
          ...cart.buildPayload(),
          sale_date: saleDate,
          notes: cart.notes || null,
          customer_ulid: cart.customerUlid,
        },
        idempotencyKeyRef.current,
      ),

    onSuccess: (sale) => {
      const requestedPayment = Number.parseFloat(received) || 0
      const paymentReferenceValue = paymentReference.trim() || null

      setSavedSale(sale)
      setSaveError(null)

      cart.clear()
      setSelectedCustomer(null)
      setProductQuery('')

      idempotencyKeyRef.current = newSaleKey()

      /*
       * If salesman entered Received before pressing Sale,
       * actually collect that payment after the sale is created.
       */
      if (
        canCollectPayment &&
        requestedPayment > 0 &&
        requestedPayment <= (Number.parseFloat(sale.grand_total) || 0)
      ) {
        collectPaymentMutation.mutate({
          saleUlid: sale.ulid,
          amount: requestedPayment.toFixed(4),
          method: paymentMethod,
          reference: paymentReferenceValue,
        })
      } else {
        setReceived('')
        setPaymentReference('')
      }
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

  const collectPaymentMutation = useMutation({
    mutationFn: async (payload: {
      saleUlid: string
      amount: string
      method: SalePaymentMethod
      reference: string | null
    }) =>
      createSalePayment(
        payload.saleUlid,
        {
          amount: payload.amount,
          method: payload.method,
          reference: payload.reference,
        },
        newPaymentKey(),
      ),

    onSuccess: async () => {
      setReceived('')
      setPaymentReference('')

      if (savedSale) {
        await refreshSavedSale(savedSale.ulid)
      }
    },

    onError: (err) => {
      setSaveError(
        err instanceof ApiClientError
          ? err.message
          : 'Sale was saved, but payment could not be collected.',
      )
    },
  })

  async function refreshSavedSale(saleUlid: string) {
    const fresh = await fetchSale(saleUlid)
    setSavedSale(fresh)
  }

  async function addProductFromEntry(
    productUlid: string,
    scannedValue = '',
  ) {
    try {
      const [product, stock] = await Promise.all([
        fetchProduct(productUlid),
        fetchProductStock(productUlid),
      ])
      const scannedBarcode =
        scannedValue === ''
          ? null
          : product.barcodes?.find(
              (row) => row.is_active && row.barcode === scannedValue,
            ) ?? null

      const lineKey = cart.addProduct(
        product,
        '1.000000',
        scannedBarcode,
        stock.active_warehouse.quantity,
      )

      if (!lineKey) return

      setActiveLineKey(lineKey)
      setProductQuery('')
      setBarcodeQuery('')
      setSaveError(null)
    } catch (err) {
      setSaveError(
        err instanceof ApiClientError
          ? err.message
          : 'Unable to load the selected product.',
      )
    }
  }

  async function addProductFromBarcode() {
    const barcode = barcodeQuery.trim()
    if (!barcode) return

    try {
      const page = await fetchProducts(
        { q: barcode, per_page: 20, page: 1 },
        { busy: 'none' },
      )
      const match = page.data.find((product) =>
        product.barcodes?.some(
          (row) => row.is_active && row.barcode === barcode,
        ),
      )

      if (!match) {
        setSaveError(`No product found for barcode ${barcode}.`)
        return
      }

      await addProductFromEntry(match.ulid, barcode)
    } catch (err) {
      setSaveError(
        err instanceof ApiClientError
          ? err.message
          : 'Unable to resolve the scanned barcode.',
      )
    }
  }

  function finishActiveLine(lineKey: string) {
    if (activeLineKey !== lineKey) return

    setActiveLineKey(null)
    window.setTimeout(() => {
      productSearchRef.current?.focus()
      productSearchRef.current?.select()
    }, 0)
  }

  function handleActiveFieldEnter(
    event: ReactKeyboardEvent<HTMLInputElement | HTMLSelectElement>,
    lineKey: string,
  ) {
    if (event.key !== 'Enter') return

    event.preventDefault()
    const row = event.currentTarget.closest('tr')
    if (!row) return

    const fields = Array.from(
      row.querySelectorAll<HTMLInputElement | HTMLSelectElement>(
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

    finishActiveLine(lineKey)
  }

  useEffect(() => {
    if (!activeLineKey) return

    window.setTimeout(() => {
      const quantity = document.querySelector<HTMLInputElement>(
        `[data-sale-line="${activeLineKey}"] [data-sale-field="sales_qty"]`,
      )
      quantity?.focus()
      quantity?.select()
    }, 0)
  }, [activeLineKey])


  function parkCurrentCart() {
    if (!cart.hasPaidLines) return

    setHeldCarts((held) => [
      ...held,
      {
        id: `hold-${Date.now()}`,
        lines: cart.lines,
        heldAt: new Date().toLocaleTimeString(),
        customerUlid: cart.customerUlid,
        customer: selectedCustomer,
        notes: cart.notes,
        priceType: cart.priceType,
      },
    ])

    cart.clear()
    setSelectedCustomer(null)
    setReceived('')
    setPaymentReference('')
  }

  function recallHeldCart(id: string) {
    const held = heldCarts.find((entry) => entry.id === id)

    if (!held) return

    cart.restore(
      held.lines,
      held.notes,
      held.customerUlid,
      held.priceType,
    )

    setSelectedCustomer(held.customer)
    setHeldCarts((current) =>
      current.filter((entry) => entry.id !== id),
    )
    setView('pos')
  }

  function dropHeldCart(id: string) {
    setHeldCarts((current) =>
      current.filter((entry) => entry.id !== id),
    )
  }

  function refreshScreen() {
    setSaveError(null)

    if (savedSale) {
      void refreshSavedSale(savedSale.ulid)
      return
    }

    cart.clear()
    setSelectedCustomer(null)
    setReceived('')
    setPaymentReference('')
    setProductQuery('')
    setBarcodeQuery('')
    setActiveLineKey(null)
  }

  function setReceivedAmount(value: string) {
    if (!/^(?:\d*)?(?:\.\d{0,4})?$/.test(value)) return

    setReceived(value)
  }

  async function handlePartySaved(party: Party) {
    if (party.party_type !== 'customer') return

    setSelectedCustomer(party)
    cart.setCustomerUlid(party.ulid)
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
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'F9') {
        event.preventDefault()

        if (
          canCreateSale &&
          cart.hasPaidLines &&
          !stockIssue &&
          !saveMutation.isPending &&
          !collectPaymentMutation.isPending
        ) {
          saveMutation.mutate()
        }
      } else if (event.key === 'F8') {
        event.preventDefault()
        refreshScreen()
      } else if (event.key === 'F3') {
        event.preventDefault()

        if (savedSale) {
          previewSaleReceipt(savedSale)
        }
      } else if (event.key === 'F11') {
        event.preventDefault()

        if (savedSale) {
          printSaleReceipt(savedSale)
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
    collectPaymentMutation.isPending,
    savedSale,
  ])

  const isBusy =
    saveMutation.isPending || collectPaymentMutation.isPending

  return (
    <div className="sales-reference-screen">
      <main className="sales-reference-main">
        <nav
          className="sales-reference-subtabs"
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
              ({heldCarts.length},Due:0) Pending Invoices
            </span>
          </button>
        </nav>

        {view === 'history' ? (
          <SalesInvoiceHistory />
        ) : view === 'pending' ? (
          <div className="sales-history">
            <h3>Pending (on hold) invoices</h3>

            {heldCarts.length === 0 ? (
              <p>
                Nothing on hold. Tick "On Hold" with lines in the
                cart to park one.
              </p>
            ) : (
              <table className="sales-history-grid">
                <thead>
                  <tr>
                    <th>Held at</th>
                    <th className="num">Lines</th>
                    <th>Customer</th>
                    <th></th>
                  </tr>
                </thead>

                <tbody>
                  {heldCarts.map((entry) => (
                    <tr key={entry.id}>
                      <td>{entry.heldAt}</td>

                      <td className="num">
                        {
                          entry.lines.filter(
                            (line) => line.line_kind === 'sale',
                          ).length
                        }
                      </td>

                      <td>
                        {entry.customer?.name ?? 'CASH IN HAND'}
                      </td>

                      <td>
                        <button
                          type="button"
                          onClick={() => recallHeldCart(entry.id)}
                        >
                          Recall
                        </button>{' '}

                        <button
                          type="button"
                          onClick={() => dropHeldCart(entry.id)}
                        >
                          Discard
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
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
                      defaultValue="0"
                      aria-label="Copy invoice number"
                      onChange={() => undefined}
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
                    onChange={(e) => setSaleDate(e.target.value)}
                  />

                  <label>Qu #:</label>

                  <div className="sales-reference-input-button sales-reference-inline-caret">
                    <input
                      aria-label="Quotation number"
                      placeholder="Quotation #"
                    />

                    <button
                      type="button"
                      className="sales-reference-field-caret"
                      onClick={() => {
                        setSaveError(
                          'Quotation lookup is not connected to the current sales API yet.',
                        )
                      }}
                      title="Quotation lookup"
                      aria-label="Quotation lookup"
                    >
                      <ChevronDown size={12} strokeWidth={2.75} />
                    </button>
                  </div>

                  <label>S.Man:</label>

                  <div className="sales-reference-input-button">
                    <input
                      value={session?.user?.name ?? '—'}
                      readOnly
                    />
                  </div>

                  <label>To:</label>

                  <div className={`sales-reference-input-button sales-reference-to sales-reference-inline-caret${customerPickerOpen ? ' is-open' : ''}`}>
                    <input
                      value={
                        selectedCustomer?.name ?? 'CASH IN HAND'
                      }
                      readOnly
                    />

                    <button
                      type="button"
                      className="sales-reference-field-caret"
                      onClick={() =>
                        setCustomerPickerOpen((open) => !open)
                      }
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
                        <div className="sales-pos-customer-grid-head" aria-hidden="true">
                          <span>Code</span>
                          <span>Party Name</span>
                          <span>Address</span>
                          <span>Mobile</span>
                        </div>

                        <div className="sales-pos-customer-grid-body">
                          <button
                            type="button"
                            className="sales-pos-customer-grid-row"
                            onClick={() => {
                              setSelectedCustomer(null)
                              cart.setCustomerUlid(null)
                              setCustomerPickerOpen(false)
                            }}
                          >
                            <span>—</span>
                            <strong>CASH IN HAND</strong>
                            <span>Walk-in customer</span>
                            <span>—</span>
                          </button>

                          {(customersQuery.data ?? []).map((party) => (
                            <button
                              type="button"
                              className="sales-pos-customer-grid-row"
                              key={party.ulid}
                              onClick={() => {
                                setSelectedCustomer(party)
                                cart.setCustomerUlid(party.ulid)
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
                          {(customersQuery.data?.length ?? 0) + 1} Parties
                        </div>
                      </div>
                    </div>                  </div>

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
              {stockIssue ? (
                <span className="sales-pos-status-error">
                  {stockIssue}
                </span>
              ) : saveError ? (
                <span className="sales-pos-status-error">
                  {saveError}
                </span>
              ) : savedSale ? (
                <>
                  <span className="sales-pos-status-ok">
                    Saved {savedSale.document_number} — total{' '}
                    {savedSale.grand_total}
                  </span>

                  {collectPaymentMutation.isPending ? (
                    <span>
                      {' '}Collecting {received} via{' '}
                      {paymentMethod.toUpperCase()}...
                    </span>
                  ) : null}

                  {canCollectPayment ? (
                    <SalePaymentPanel
                      sale={savedSale}
                      outstanding={outstandingAfterPayments(
                        savedSale,
                      )}
                      onCollected={() =>
                        refreshSavedSale(savedSale.ulid)
                      }
                    />
                  ) : null}
                </>
              ) : cart.hasPaidLines ? (
                <span>
                  {cart.paidLines.length} line(s) ready to save
                </span>
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
                onClick={() => saveMutation.mutate()}
              >
                {isBusy ? 'Saving...' : 'Sale'}
              </button>

              <div className="sales-reference-entry-spacer" />
            </div>

            <div className="sales-reference-grid-wrap">
              <table className="sales-reference-grid">
                <colgroup>
                  {columnLayout.visibleColumns.map((col) => (
                    <col
                      key={col.key}
                      className={salesColClass(col)}
                    />
                  ))}
                </colgroup>

                <thead>
                  <tr>
                    {columnLayout.visibleColumns.map((col) => (
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
                            {columnLayout.visibleColumns.map((col) => {
                              if (col.key === 'selector') {
                                return <td key={col.key} className="sales-reference-row-arrow">›</td>
                              }

                              if (col.key === 'product') {
                                return (
                                  <td key={col.key} className="sales-reference-yellow">
                                    <strong>{line.product_number} — {line.product_name}</strong>
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
                                      value={line.quantity}
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
                                    <input className="sales-pos-line-qty" data-sale-editable="true" value={line.discount_percent ?? '0'} inputMode="decimal"
                                      onChange={(e) => cart.setDiscountPercent(line.line_key, e.target.value)}
                                      onKeyDown={(e) => handleActiveFieldEnter(e, line.line_key)} />
                                  </td>
                                )
                              }

                              if (col.key === 'disc_rs') {
                                return (
                                  <td key={col.key}>
                                    <input className="sales-pos-line-qty" data-sale-editable="true" value={line.discount_amount ?? '0'} inputMode="decimal"
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
                                      <select
                                        data-sale-editable="true"
                                        value={line.unit_ulid ?? ''}
                                        onChange={(e) => cart.setLineUnit(line.line_key, e.target.value)}
                                        onKeyDown={(e) => handleActiveFieldEnter(e, line.line_key)}
                                        aria-label={`Unit for ${line.product_name}`}
                                      >
                                        {(line.available_units ?? []).map((unit) => (
                                          <option key={unit.unit_ulid} value={unit.unit_ulid}>{unit.code}</option>
                                        ))}
                                      </select>
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
                        {columnLayout.visibleColumns.map((col) => {
                          if (col.key === 'selector') {
                            return <td key={col.key} className="sales-reference-row-arrow">›</td>
                          }

                          if (col.key === 'product') {
                            return (
                              <td key={col.key} className="sales-reference-yellow sales-product-search-cell">
                                <input
                                  ref={productSearchRef}
                                  className="sales-pos-product-entry"
                                  value={productQuery}
                                  placeholder="Search product name or number"
                                  autoComplete="off"
                                  onChange={(e) => setProductQuery(e.target.value)}
                                  onKeyDown={(e) => {
                                    if (e.key !== 'Enter') return
                                    const first = productsQuery.data?.data?.[0]
                                    if (first) {
                                      e.preventDefault()
                                      void addProductFromEntry(first.ulid)
                                    }
                                  }}
                                />
                                {productQuery.trim().length > 0 && productsQuery.data?.data?.length ? (
                                  <ul className="sales-pos-product-results sales-pos-product-results-grid">
                                    {productsQuery.data.data.slice(0, 8).map((row) => (
                                      <li key={row.ulid}>
                                        <button type="button" onClick={() => void addProductFromEntry(row.ulid)}>
                                          <span>{row.product_number}</span> {row.name}
                                        </button>
                                      </li>
                                    ))}
                                  </ul>
                                ) : null}
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
                      className={line.line_kind === 'sale' ? 'sales-pos-line' : 'sales-pos-line is-free'}
                    >
                      {columnLayout.visibleColumns.map((col) => {
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
                          return (
                            <td key={col.key}>
                              <input className="sales-pos-line-qty" value={line.quantity} disabled={line.line_kind !== 'sale'}
                                onChange={(e) => { if (line.line_kind === 'sale') cart.setQuantity(line.line_key, e.target.value) }} />
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
                          return <td key={col.key}><input className="sales-pos-line-qty" value={line.discount_percent ?? '0'} disabled={line.line_kind !== 'sale'} inputMode="decimal" onChange={(e) => cart.setDiscountPercent(line.line_key, e.target.value)} /></td>
                        }

                        if (col.key === 'disc_rs') {
                          return <td key={col.key}><input className="sales-pos-line-qty" value={line.discount_amount ?? '0'} disabled={line.line_kind !== 'sale'} inputMode="decimal" onChange={(e) => cart.setDiscountAmount(line.line_key, e.target.value)} /></td>
                        }

                        if (col.key === 'net_amt') return <td key={col.key} className="sales-pos-line-pending">{linePreview.netAmount}</td>
                        if (col.key === 'barcode') return <td key={col.key}>{line.barcode ?? ''}</td>

                        if (col.key === 'uom') {
                          return (
                            <td key={col.key}>
                              {line.line_kind === 'sale' && (line.available_units?.length ?? 0) > 0 ? (
                                <select value={line.unit_ulid ?? ''} onChange={(e) => cart.setLineUnit(line.line_key, e.target.value)} aria-label={`Unit for ${line.product_name}`}>
                                  {(line.available_units ?? []).map((unit) => <option key={unit.unit_ulid} value={unit.unit_ulid}>{unit.code}</option>)}
                                </select>
                              ) : line.unit_code ?? ''}
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

            <footer className="sales-reference-actions">
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
                  onClick={() => saveMutation.mutate()}
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
                    previewSaleReceipt(savedSale)
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
                    printSaleReceipt(savedSale)
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
            </footer>
          </>
        )}
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
                <input type="checkbox" />
                {' '}Payment Due
              </label>

              <label>
                <input
                  type="checkbox"
                  disabled={!cart.hasPaidLines}
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
                  Number.parseFloat(cart.preview.subtotal) > 0
                    ? (
                        (Number.parseFloat(cart.preview.discount) /
                          Number.parseFloat(cart.preview.subtotal)) *
                        100
                      ).toFixed(2)
                    : '0.00'
                }
                readOnly
                title="Effective discount from sale lines"
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
                      'Sale-level discount is not currently supported by the sales API.',
                    )
                  }}
                />{' '}
                Cost
              </label>
            </div>

            <div className="sales-reference-disc-values">
              <strong>{cart.preview.discount}</strong>
              <strong>
                {Number.parseFloat(cart.preview.subtotal) > 0
                  ? (
                      (Number.parseFloat(cart.preview.discount) /
                        Number.parseFloat(cart.preview.subtotal)) *
                      100
                    ).toFixed(2)
                  : '0.00'}{' '}
                %
              </strong>
            </div>

            <label className="sales-reference-payment-label">
              Payment Method:
            </label>

            <div className="sales-reference-payment-method">
              <select
                value={paymentMethod}
                onChange={(e) =>
                  setPaymentMethod(
                    e.target.value as SalePaymentMethod,
                  )
                }
                aria-label="Payment method"
              >
                <option value="cash">CASH IN HAND</option>
                <option value="card">CARD</option>
                <option value="bank">BANK</option>
                <option value="credit">CREDIT</option>
              </select>
            </div>

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

      {partyModalOpen ? (
        <SalesPartyModal
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