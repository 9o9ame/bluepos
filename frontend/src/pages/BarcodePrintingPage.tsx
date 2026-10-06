import { useEffect, useMemo, useRef, useState } from 'react'
import {
  CreditCard,
  Plus,
  Printer,
  RefreshCw,
  Save,
  Trash2,
  X,
} from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import {
  fetchBrands,
  fetchCategories,
  fetchProduct,
  fetchProducts,
} from '../api/catalog'
import { BarcodeStyleOptionsModal } from '../components/products/BarcodeStyleOptionsModal'
import { BpFancySelect } from '../components/products/BpFancySelect'
import { UiSelect } from '../components/ui/UiSelect'
import {
  BARCODE_TYPE_OPTIONS,
  DISPLAY_FIELD_OPTIONS,
  PRICE_FIELD_OPTIONS,
  browserPrintDialogPrinter,
  getActiveBarcodeStyles,
  isBarcodeTypeSupported,
  loadBarcodePrintSettings,
  migrateLegacyBarcodeType,
  migrateLegacyPrintStyle,
  printBarcodeBatch,
  printBarcodeLabels,
  renderBarcodeSvg,
  saveBarcodePrintSettings,
  validateBarcodeValue,
  type BarcodeLabelSize,
  type BarcodeStyleId,
  type BarcodeType,
  type DisplayFieldOption,
  type PriceFieldOption,
} from '../components/products/barcodePrint'
import { useAuth } from '../features/auth/AuthProvider'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Product, ProductBarcode, ProductPrice } from '../types/catalog'
import './BarcodePrintingPage.css'

type RangeMode = 'product_number' | 'sku'

type PrintQueueLine = {
  id: string
  product: Product
  barcodeUlid: string
  quantity: number
}

const PRICE_TYPE_MAP: Record<
  Exclude<PriceFieldOption, 'NONE' | 'SELLING_WITH_UNIT' | 'SUB_SELLING_WITH_UNIT'>,
  ProductPrice['price_type']
> = {
  WHOLESALE: 'wholesale',
  SELLING: 'retail',
  SUB_SELLING: 'minimum_sale',
}

function activeBarcodes(product: Product): ProductBarcode[] {
  return (product.barcodes ?? []).filter(
    (barcode) => barcode.is_active && barcode.barcode.trim() !== '',
  )
}

function selectedBarcode(line: PrintQueueLine | null): ProductBarcode | null {
  if (!line) return null
  return (
    activeBarcodes(line.product).find(
      (barcode) => barcode.ulid === line.barcodeUlid,
    ) ?? null
  )
}

function priceFor(product: Product, priceField: PriceFieldOption): string {
  if (
    priceField === 'NONE' ||
    priceField === 'SELLING_WITH_UNIT' ||
    priceField === 'SUB_SELLING_WITH_UNIT'
  ) {
    return ''
  }
  const priceType = PRICE_TYPE_MAP[priceField]
  return (
    (product.prices ?? []).find(
      (row: ProductPrice) => row.price_type === priceType && row.is_active,
    )?.amount ?? ''
  )
}

function packingLabel(barcode: ProductBarcode | null): string {
  if (!barcode) return ''
  const unit = barcode.unit?.code?.trim()
  const factor = barcode.conversion_factor?.trim()
  if (unit && factor && factor !== '1') return `${unit} ×${factor}`
  if (unit) return unit
  if (factor && factor !== '1') return `×${factor}`
  return ''
}

function displayText(
  product: Product,
  field: DisplayFieldOption,
  barcode: ProductBarcode | null,
): string {
  if (field === 'CODE_PRODUCT_NAME') {
    const code = product.sku?.trim() || product.product_number
    return `${code} ${product.name}`.trim()
  }
  if (field === 'PRODUCT_NAME_PACKING') {
    const packing = packingLabel(barcode)
    return packing ? `${product.name} (${packing})` : product.name
  }
  if (field === 'PRODUCT_NAME_CATEGORY') {
    const category = product.category?.name?.trim()
    return category ? `${product.name} / ${category}` : product.name
  }
  if (field === 'PRODUCT_NAME_EXPIRY') {
    return product.name
  }
  return product.name
}

function queueId(productUlid: string, barcodeUlid: string) {
  return `${productUlid}:${barcodeUlid}`
}

function productNumberValue(productNumber: string): number | null {
  const normalized = productNumber.replace(/\D+/g, '')
  if (!normalized) return null
  const value = Number(normalized)
  return Number.isFinite(value) ? value : null
}

function skuInRange(sku: string | null, from: string, to: string): boolean {
  const value = (sku ?? '').trim().toUpperCase()
  if (!value) return false
  const fromKey = from.trim().toUpperCase()
  const toKey = to.trim().toUpperCase()
  if (fromKey && value < fromKey) return false
  if (toKey && value > toKey) return false
  return true
}

export function BarcodePrintingPage() {
  const { productUlid } = useParams()
  const { session } = useAuth()
  const { closeActiveTab } = useWorkspace()
  const initializedProduct = useRef<string | null>(null)
  const remembered = useMemo(() => loadBarcodePrintSettings(), [])

  const [search, setSearch] = useState('')
  const [categoryUlid, setCategoryUlid] = useState('')
  const [brandUlid, setBrandUlid] = useState('')
  const [includeSubBarcodes, setIncludeSubBarcodes] = useState(false)
  const [addWithExisting, setAddWithExisting] = useState(false)
  const [ignoreStockQty, setIgnoreStockQty] = useState(true)

  const [rangeMode, setRangeMode] = useState<RangeMode>('product_number')
  const [rangeFrom, setRangeFrom] = useState('')
  const [rangeTo, setRangeTo] = useState('')
  const [multiplier, setMultiplier] = useState('1')

  const [barcodeType, setBarcodeType] = useState<BarcodeType>(
    migrateLegacyBarcodeType(remembered.barcodeType),
  )
  const [displayField, setDisplayField] =
    useState<DisplayFieldOption>('PRODUCT_NAME')
  const [priceField, setPriceField] = useState<PriceFieldOption>('SELLING')
  const [printString, setPrintString] = useState('[PRODUCT_NAME]')
  const [labelSize] = useState<BarcodeLabelSize>(remembered.labelSize)
  const [printStyle, setPrintStyle] = useState<BarcodeStyleId>(
    migrateLegacyPrintStyle(
      typeof remembered.printStyle === 'string'
        ? remembered.printStyle
        : undefined,
    ),
  )
  const [activeStyles, setActiveStyles] = useState(() =>
    getActiveBarcodeStyles(),
  )
  const [styleModalOpen, setStyleModalOpen] = useState(false)
  const [showPrice, setShowPrice] = useState(remembered.showPrice)
  const [marginLeftMm, setMarginLeftMm] = useState(remembered.marginLeftMm)
  const [marginTopMm, setMarginTopMm] = useState(remembered.marginTopMm)
  const [scaleFactor, setScaleFactor] = useState(remembered.scaleFactor)

  const [queue, setQueue] = useState<PrintQueueLine[]>([])
  const [selectedQueueId, setSelectedQueueId] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const categories = useQuery({
    queryKey: ['categories'],
    queryFn: fetchCategories,
  })

  const brands = useQuery({
    queryKey: ['brands'],
    queryFn: fetchBrands,
  })

  const productsQuery = useQuery({
    queryKey: ['barcode-print-products', search, categoryUlid, brandUlid],
    queryFn: () =>
      fetchProducts({
        q: search || undefined,
        page: 1,
        per_page: 100,
        category_ulid: categoryUlid || undefined,
        brand_ulid: brandUlid || undefined,
        status: 'active',
      }),
  })

  const initialProductQuery = useQuery({
    queryKey: ['barcode-print-initial-product', productUlid],
    queryFn: () => fetchProduct(productUlid ?? ''),
    enabled: Boolean(productUlid),
  })

  const products = productsQuery.data?.data ?? []

  useEffect(() => {
    if (!activeStyles.some((style) => style.id === printStyle)) {
      setPrintStyle(activeStyles[0]?.id ?? 'one_2')
    }
  }, [activeStyles, printStyle])

  function refreshActiveStyles() {
    setActiveStyles(getActiveBarcodeStyles())
  }

  function addProduct(product: Product, qty = 1) {
    const barcodes = activeBarcodes(product)
    if (barcodes.length === 0) {
      setError(`${product.name} has no active barcode.`)
      return
    }

    const chosen = includeSubBarcodes
      ? barcodes
      : [barcodes.find((row) => row.is_primary) ?? barcodes[0]]

    setQueue((current) => {
      const next = [...current]

      for (const barcode of chosen) {
        const id = queueId(product.ulid, barcode.ulid)
        const existingIndex = next.findIndex((row) => row.id === id)

        if (existingIndex >= 0) {
          if (addWithExisting) {
            next[existingIndex] = {
              ...next[existingIndex],
              quantity: Math.min(
                999,
                next[existingIndex].quantity + qty,
              ),
            }
          }
          continue
        }

        next.push({
          id,
          product,
          barcodeUlid: barcode.ulid,
          quantity: qty,
        })
      }

      return next
    })

    setSelectedQueueId(queueId(product.ulid, chosen[0].ulid))
    setError(null)
  }

  useEffect(() => {
    const product = initialProductQuery.data
    if (!product || initializedProduct.current === product.ulid) return

    initializedProduct.current = product.ulid
    addProduct(product, 1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialProductQuery.data])

  const selectedLine =
    queue.find((row) => row.id === selectedQueueId) ?? queue[0] ?? null
  const previewBarcode = selectedBarcode(selectedLine)
  const previewProduct = selectedLine?.product ?? null
  const previewPrice = previewProduct
    ? priceFor(previewProduct, priceField)
    : ''
  const previewDisplay = previewProduct
    ? displayText(previewProduct, displayField, previewBarcode)
    : ''

  const previewValidation = useMemo(() => {
    if (!previewBarcode) return null
    if (!isBarcodeTypeSupported(barcodeType)) {
      return `${barcodeType} is not supported by the current barcode library.`
    }
    return validateBarcodeValue(previewBarcode.barcode, barcodeType)
  }, [previewBarcode, barcodeType])

  const previewSvg = useMemo(() => {
    if (!previewBarcode || previewValidation) return null
    try {
      return renderBarcodeSvg(previewBarcode.barcode, barcodeType)
    } catch {
      return null
    }
  }, [previewBarcode, barcodeType, previewValidation])

  function updateQuantity(id: string, quantity: number) {
    const safe = Math.max(1, Math.min(999, Math.floor(quantity || 1)))
    setQueue((current) =>
      current.map((row) =>
        row.id === id ? { ...row, quantity: safe } : row,
      ),
    )
  }

  function changeBarcode(line: PrintQueueLine, barcodeUlid: string) {
    const nextId = queueId(line.product.ulid, barcodeUlid)

    setQueue((current) =>
      current.map((row) =>
        row.id === line.id
          ? { ...row, id: nextId, barcodeUlid }
          : row,
      ),
    )

    setSelectedQueueId(nextId)
  }

  function removeSelected() {
    if (!selectedLine) return
    setQueue((current) =>
      current.filter((row) => row.id !== selectedLine.id),
    )
    setSelectedQueueId(null)
  }

  function autoFill() {
    if (!ignoreStockQty) {
      setError(
        'Ignore Stock Qty is off, but the product list does not include trustworthy available-stock quantities. Enable Ignore Stock Qty, or wait until stock is included in this list payload.',
      )
      return
    }

    const fromRaw = rangeFrom.trim()
    const toRaw = rangeTo.trim()
    const qty = Math.max(1, Math.min(999, Number(multiplier) || 1))

    const matched = products.filter((product) => {
      if (!fromRaw && !toRaw) return true

      if (rangeMode === 'sku') {
        return skuInRange(product.sku, fromRaw, toRaw)
      }

      const from = fromRaw ? Number(fromRaw) : null
      const to = toRaw ? Number(toRaw) : null
      const value = productNumberValue(product.product_number)
      if (value === null) return false
      if (from !== null && Number.isFinite(from) && value < from) return false
      if (to !== null && Number.isFinite(to) && value > to) return false
      return true
    })

    if (matched.length === 0) {
      setError('No products match the Auto Fill criteria.')
      return
    }

    matched.forEach((product) => addProduct(product, qty))
  }

  function addFilteredProducts() {
    if (products.length === 0) {
      setError('No products match the selected filter.')
      return
    }
    products.forEach((product) => addProduct(product, 1))
  }

  function saveSettings() {
    saveBarcodePrintSettings({
      labelSize,
      showPrice,
      barcodeType,
      printStyle,
      marginLeftMm,
      marginTopMm,
      scaleFactor,
    })
  }

  function buildPayload(line: PrintQueueLine, copies: number) {
    const barcode = selectedBarcode(line)
    if (!barcode) return null

    const validationError = validateBarcodeValue(barcode.barcode, barcodeType)
    if (validationError) {
      throw new Error(`${line.product.name}: ${validationError}`)
    }

    return {
      businessName: session?.tenant.name ?? 'BluePOS',
      productName: displayText(line.product, displayField, barcode),
      productNumber: line.product.product_number,
      barcode: barcode.barcode,
      unitCode: barcode.unit?.code ?? '',
      price: priceFor(line.product, priceField),
      currencyCode: session?.tenant.currency_code ?? '',
      copies,
      labelSize,
      showPrice: showPrice && priceField !== 'NONE',
      barcodeType,
      printStyle,
      marginLeftMm,
      marginTopMm,
      scaleFactor,
      showBusinessName: true,
      showProductNumber: true,
      showUnit: true,
      showBarcodeText: true,
    }
  }

  function saveAndPreview() {
    if (!selectedLine) {
      setError('Select a row first.')
      return
    }

    try {
      const payload = buildPayload(selectedLine, selectedLine.quantity)
      if (!payload) {
        setError('The selected row has no printable barcode.')
        return
      }

      saveSettings()
      printBarcodeLabels(payload)
      setError(null)
    } catch (err) {
      setError(
        err instanceof Error ? err.message : 'Unable to preview barcode.',
      )
    }
  }

  function printAll() {
    if (queue.length === 0) {
      setError('Add at least one product before printing.')
      return
    }

    try {
      const payloads = queue.flatMap((line) => {
        const payload = buildPayload(line, line.quantity)
        return payload ? [payload] : []
      })

      saveSettings()
      printBarcodeBatch(payloads, {
        labelSize,
        showPrice: showPrice && priceField !== 'NONE',
        barcodeType,
        printStyle,
        marginLeftMm,
        marginTopMm,
        scaleFactor,
      })
      setError(null)
    } catch (err) {
      setError(
        err instanceof Error ? err.message : 'Unable to print barcodes.',
      )
    }
  }

  function calibrate() {
    try {
      if (selectedLine) {
        const payload = buildPayload(selectedLine, 1)
        if (payload) {
          printBarcodeLabels(payload)
          setError(null)
          return
        }
      }

      const sampleValue = 'CALIBRATE'
      const sampleType: BarcodeType = isBarcodeTypeSupported(barcodeType)
        ? barcodeType
        : 'Code128'
      const sampleError = validateBarcodeValue(sampleValue, sampleType)
      const barcodeValue = sampleError ? '1105000' : sampleValue
      const finalType: BarcodeType =
        validateBarcodeValue(barcodeValue, sampleType) === null
          ? sampleType
          : 'Code128'

      printBarcodeLabels({
        businessName: session?.tenant.name ?? 'BluePOS',
        productName: 'Calibration Test',
        productNumber: '000001',
        barcode: barcodeValue,
        unitCode: 'PCS',
        price: '100.00',
        currencyCode: session?.tenant.currency_code ?? '',
        copies: 1,
        labelSize,
        showPrice,
        barcodeType: finalType,
        printStyle,
        marginLeftMm,
        marginTopMm,
        scaleFactor,
        showBusinessName: true,
        showProductNumber: true,
        showUnit: true,
        showBarcodeText: true,
      })
      setError(null)
    } catch (err) {
      setError(
        err instanceof Error
          ? err.message
          : 'Unable to print calibration label.',
      )
    }
  }

  useWorkspaceHandlers({
    refresh: () => void productsQuery.refetch(),
  })

  return (
    <div className="bp-workspace-scroll">
      <div className="bp-workspace">
        {error ? <div className="bp-error">{error}</div> : null}

        <aside className="bp-left">
          <section className="bp-panel bp-settings-panel">
            <header className="bp-panel-title">Barcode Settings</header>

            <div className="bp-left-body">
              <div className="bp-settings-grid">
                <label className="bp-field bp-span-2">
                  <span>Barcode Print String: (Use | ColName | For Parse)</span>
                  <input
                    value={printString}
                    onChange={(event) => setPrintString(event.target.value)}
                  />
                </label>

                <label className="bp-field">
                  <span>Select Barcode Types</span>
                  <BpFancySelect
                    aria-label="Select Barcode Types"
                    value={barcodeType}
                    onChange={(next) => setBarcodeType(next as BarcodeType)}
                    options={BARCODE_TYPE_OPTIONS.map((type) => ({
                      value: type,
                      label: isBarcodeTypeSupported(type)
                        ? type
                        : `${type} (unsupported)`,
                      disabled: !isBarcodeTypeSupported(type),
                      title: isBarcodeTypeSupported(type)
                        ? undefined
                        : 'Not supported by the current barcode library',
                    }))}
                  />
                </label>

                <label className="bp-field">
                  <span>Field to Display</span>
                  <BpFancySelect
                    aria-label="Field to Display"
                    value={displayField}
                    onChange={(next) =>
                      setDisplayField(next as DisplayFieldOption)
                    }
                    options={DISPLAY_FIELD_OPTIONS.map((option) => ({
                      value: option.value,
                      label: option.label,
                      disabled: option.disabled,
                      title: option.title,
                    }))}
                  />
                </label>

                <label className="bp-field">
                  <span>Price Field</span>
                  <BpFancySelect
                    aria-label="Price Field"
                    value={priceField}
                    onChange={(next) =>
                      setPriceField(next as PriceFieldOption)
                    }
                    options={PRICE_FIELD_OPTIONS.map((option) => ({
                      value: option.value,
                      label: option.label,
                      disabled: option.disabled,
                      title: option.title,
                    }))}
                  />
                </label>

                <div className="bp-price-checks">
                  <label
                    className="bp-check"
                    title="Historical price data is not currently available."
                  >
                    <input type="checkbox" disabled />
                    <span>Show Old Price</span>
                  </label>
                  <label className="bp-check">
                    <input
                      type="checkbox"
                      checked={showPrice}
                      onChange={(event) => setShowPrice(event.target.checked)}
                    />
                    <span>Show Price on Label</span>
                  </label>
                </div>
              </div>

              <fieldset className="bp-group">
                <legend>Margin Settings</legend>
                <div className="bp-margin-row">
                  <span>Left</span>
                  <input
                    type="number"
                    step={0.5}
                    value={marginLeftMm}
                    onChange={(event) =>
                      setMarginLeftMm(Number(event.target.value))
                    }
                  />
                  <span>Top</span>
                  <input
                    type="number"
                    step={0.5}
                    value={marginTopMm}
                    onChange={(event) =>
                      setMarginTopMm(Number(event.target.value))
                    }
                  />
                  <span>Scale Factor</span>
                  <input
                    type="number"
                    step={0.05}
                    min={0.7}
                    max={1.3}
                    value={scaleFactor}
                    onChange={(event) =>
                      setScaleFactor(Number(event.target.value))
                    }
                  />
                </div>
                <div className="bp-sample-row">
                  <span>Sample:</span>
                  <input
                    value={previewBarcode?.barcode ?? '1105000'}
                    readOnly
                  />
                </div>
              </fieldset>

              <div className="bp-printer-block">
                <div className="bp-printer-label">On Following Printer</div>
                <div className="bp-printer-row">
                  <BpFancySelect
                    aria-label="On Following Printer"
                    value={browserPrintDialogPrinter.id}
                    title="Browsers cannot enumerate installed Windows printers. Use the system print dialog. A BluePOS Print Bridge will enable native printer lists later."
                    onChange={() => undefined}
                    options={[
                      {
                        value: browserPrintDialogPrinter.id,
                        label: browserPrintDialogPrinter.name,
                      },
                    ]}
                  />
                  <button
                    type="button"
                    className="bp-btn bp-btn-calibrate"
                    onClick={calibrate}
                  >
                    Calibrate
                  </button>
                  <label
                    className="bp-check"
                    title="Requires a BluePOS local Print Bridge / native helper. Not available in browser/PWA."
                  >
                    <input type="checkbox" disabled />
                    <span>Computer Based</span>
                  </label>
                </div>
              </div>

              <div className="bp-extra-options">
                <div className="bp-lower-options-row1">
                  <label className="bp-check">
                    <input type="checkbox" disabled />
                    <span>Use Invoice Batch / Serial for Barcode Printing</span>
                  </label>
                  <label className="bp-check">
                    <input type="checkbox" checked readOnly />
                    <span>Auto Module</span>
                  </label>
                </div>
                <div className="bp-lower-options-row2">
                  <label
                    className="bp-check"
                    title="Direct print requires native printer access via a future BluePOS Print Bridge."
                  >
                    <input type="checkbox" disabled />
                    <span>Direct Print to Printer</span>
                  </label>
                  <span className="bp-label">From Purchase ID:</span>
                  <input disabled />
                  <button type="button" className="bp-btn" disabled>
                    <RefreshCw size={12} />
                    Get
                  </button>
                </div>
              </div>

              <div className="bp-preview-paper">
                {previewProduct ? (
                  <div className="bp-preview-name">{previewDisplay}</div>
                ) : null}
                {previewValidation ? (
                  <div className="bp-preview-invalid">{previewValidation}</div>
                ) : previewSvg ? (
                  <div
                    className="bp-preview-svg"
                    dangerouslySetInnerHTML={{ __html: previewSvg }}
                  />
                ) : (
                  <div className="bp-preview-empty">Barcode Preview</div>
                )}
                <code>{previewBarcode?.barcode ?? '1105000'}</code>
                {showPrice && priceField !== 'NONE' ? (
                  <strong>
                    {session?.tenant.currency_code ?? ''}{' '}
                    {Number(previewPrice || 0).toFixed(2)}
                  </strong>
                ) : null}
              </div>

              <label className="bp-field bp-style-field">
                <span>Barcode Printing Style</span>
                <div className="bp-style-row">
                  <BpFancySelect
                    aria-label="Barcode Printing Style"
                    value={printStyle}
                    onChange={(next) => setPrintStyle(next as BarcodeStyleId)}
                    options={activeStyles.map((style) => ({
                      value: style.id,
                      label: style.name,
                    }))}
                  />
                  <button
                    type="button"
                    className="bp-btn bp-btn-icon"
                    title="Report Type On / Off Options"
                    onClick={() => setStyleModalOpen(true)}
                  >
                    <Plus size={14} />
                  </button>
                </div>
              </label>
            </div>

            <div className="bp-left-actions">
              <button
                type="button"
                className="bp-action-btn bp-action-delete"
                onClick={removeSelected}
                disabled={!selectedLine}
              >
                <span>Delete</span>
                <span className="bp-action-ico" aria-hidden>
                  <Trash2 size={14} strokeWidth={2.4} />
                </span>
              </button>

              <button
                type="button"
                className="bp-action-btn bp-action-save"
                onClick={saveAndPreview}
                disabled={!selectedLine || Boolean(previewValidation)}
              >
                <span>Save &amp; Preview</span>
                <span className="bp-action-ico" aria-hidden>
                  <Save size={14} strokeWidth={2.4} />
                </span>
              </button>

              <button
                type="button"
                className="bp-action-btn bp-action-close"
                onClick={closeActiveTab}
              >
                <span>Close</span>
                <span className="bp-action-ico" aria-hidden>
                  <X size={14} strokeWidth={2.6} />
                </span>
              </button>
            </div>
          </section>
        </aside>

        <main className="bp-right">
          <section className="bp-panel bp-autofill">
            <header className="bp-panel-title">Auto Fill Options</header>
            <div className="bp-panel-body">
              <div className="bp-row bp-row-checks">
                <label
                  className="bp-check"
                  title="Product list results do not currently include available stock quantities. Unchecking will block Auto Fill until stock is present in the list payload."
                >
                  <input
                    type="checkbox"
                    checked={ignoreStockQty}
                    onChange={(event) =>
                      setIgnoreStockQty(event.target.checked)
                    }
                  />
                  <span>Ignore Stock Qty</span>
                </label>

                <label className="bp-check">
                  <input
                    type="checkbox"
                    checked={addWithExisting}
                    onChange={(event) =>
                      setAddWithExisting(event.target.checked)
                    }
                  />
                  <span>Add With Existing</span>
                </label>

                <label className="bp-check">
                  <input
                    type="checkbox"
                    checked={includeSubBarcodes}
                    onChange={(event) =>
                      setIncludeSubBarcodes(event.target.checked)
                    }
                  />
                  <span>Print Sub Barcode (Multi Barcode)</span>
                </label>
              </div>

              <div className="bp-row bp-row-range">
                <BpFancySelect
                  className="bp-w-code"
                  aria-label="Auto Fill range field"
                  value={rangeMode}
                  onChange={(next) => setRangeMode(next as RangeMode)}
                  options={[
                    { value: 'product_number', label: 'Product #' },
                    { value: 'sku', label: 'Code / SKU' },
                  ]}
                />

                <span className="bp-label">From</span>
                <input
                  className="bp-w-range"
                  value={rangeFrom}
                  placeholder={
                    rangeMode === 'sku' ? 'A0001' : '000001'
                  }
                  onChange={(event) => setRangeFrom(event.target.value)}
                />

                <span className="bp-label">To</span>
                <input
                  className="bp-w-range"
                  value={rangeTo}
                  placeholder={
                    rangeMode === 'sku' ? 'Z9999' : '999999'
                  }
                  onChange={(event) => setRangeTo(event.target.value)}
                />

                <span className="bp-label">MF</span>
                <input
                  className="bp-w-mf"
                  type="number"
                  min={1}
                  max={999}
                  value={multiplier}
                  onChange={(event) => setMultiplier(event.target.value)}
                />

                <button type="button" className="bp-btn" onClick={autoFill}>
                  <RefreshCw size={13} />
                  Auto
                </button>
              </div>

              <div className="bp-row bp-row-select-btns">
                <button
                  type="button"
                  className="bp-select-btn"
                  disabled={!categoryUlid}
                  onClick={addFilteredProducts}
                >
                  <span className="bp-color-chip" />
                  Selected Category
                </button>

                <button
                  type="button"
                  className="bp-select-btn"
                  disabled={!brandUlid}
                  onClick={addFilteredProducts}
                >
                  <span className="bp-color-chip is-green" />
                  Selected Company
                </button>

                <button
                  type="button"
                  className="bp-select-btn"
                  disabled={products.length === 0}
                  onClick={addFilteredProducts}
                >
                  <span className="bp-color-chip is-orange" />
                  Selected Products
                </button>

                <button type="button" className="bp-btn bp-btn-card" disabled>
                  Card Print
                  <CreditCard size={15} />
                </button>
              </div>

              <div className="bp-row bp-row-search">
                <span className="bp-label">Search:</span>
                <input
                  className="bp-w-search"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder="Product # / name / SKU…"
                />

                <UiSelect
                  className="bp-w-filter"
                  value={categoryUlid}
                  title="Category filter"
                  aria-label="Category filter"
                  options={[
                    { value: '', label: 'All Categories' },
                    ...(categories.data ?? []).map((category) => ({
                      value: category.ulid,
                      label: category.name,
                    })),
                  ]}
                  onChange={setCategoryUlid}
                />

                <UiSelect
                  className="bp-w-filter"
                  value={brandUlid}
                  title="Company / Brand filter"
                  aria-label="Company or Brand filter"
                  options={[
                    { value: '', label: 'All Companies' },
                    ...(brands.data ?? []).map((brand) => ({
                      value: brand.ulid,
                      label: brand.name,
                    })),
                  ]}
                  onChange={setBrandUlid}
                />
              </div>
            </div>
          </section>

          <section className="bp-grid-shell">
            <div className="bp-grid-scroll">
              <table className="bp-grid">
                <thead>
                  <tr>
                    <th className="is-marker" />
                    <th className="is-row-no">#</th>
                    <th className="is-barcode">Barcode</th>
                    <th className="is-product">ITEM / PRODUCT DESCRIPTION</th>
                    <th className="is-other">Other Description</th>
                    <th className="is-quantity">Quantity</th>
                  </tr>
                </thead>
                <tbody>
                  {queue.length === 0 ? (
                    <tr>
                      <td className="is-marker">*</td>
                      <td className="is-row-no" />
                      <td />
                      <td>
                        <button
                          type="button"
                          className="bp-empty-add"
                          disabled={products.length === 0}
                          onClick={() => {
                            const first = products.find(
                              (product) => activeBarcodes(product).length > 0,
                            )
                            if (first) addProduct(first, 1)
                          }}
                        >
                          Add a product from current search
                        </button>
                      </td>
                      <td />
                      <td />
                    </tr>
                  ) : (
                    queue.map((line, index) => {
                      const barcodes = activeBarcodes(line.product)
                      const isSelected = line.id === selectedQueueId

                      return (
                        <tr
                          key={line.id}
                          className={isSelected ? 'is-selected' : undefined}
                          onClick={() => setSelectedQueueId(line.id)}
                        >
                          <td className="is-marker">
                            {isSelected ? '›' : ''}
                          </td>
                          <td className="is-row-no">{index + 1}</td>

                          <td className="is-barcode">
                            <div onClick={(event) => event.stopPropagation()}>
                              <UiSelect
                                className="bp-grid-barcode-select"
                                aria-label="Barcode"
                                value={line.barcodeUlid}
                                options={barcodes.map((option) => ({
                                  value: option.ulid,
                                  label: option.barcode,
                                }))}
                                onChange={(barcodeUlid) => changeBarcode(line, barcodeUlid)}
                              />
                            </div>
                          </td>

                          <td className="is-product">
                            <strong>
                              {line.product.product_number}*
                              {line.product.name.toUpperCase()}
                            </strong>
                          </td>

                          <td className="is-other">
                            {line.product.alternate_name ?? ''}
                          </td>

                          <td className="is-quantity">
                            <input
                              type="number"
                              min={1}
                              max={999}
                              value={line.quantity}
                              onClick={(event) => event.stopPropagation()}
                              onChange={(event) =>
                                updateQuantity(
                                  line.id,
                                  Number(event.target.value),
                                )
                              }
                            />
                          </td>
                        </tr>
                      )
                    })
                  )}

                  <tr className="bp-new-row">
                    <td className="is-marker">*</td>
                    <td className="is-row-no" />
                    <td>----</td>
                    <td>----</td>
                    <td />
                    <td />
                  </tr>
                </tbody>
              </table>
            </div>

            <footer className="bp-grid-footer">
              <span>
                Record {queue.length ? 1 : 0} of {queue.length}
              </span>
              <button
                type="button"
                className="bp-btn bp-btn-print-all"
                onClick={printAll}
                disabled={!queue.length}
              >
                <span className="bp-print-ico" aria-hidden>
                  <Printer size={14} strokeWidth={2.4} />
                </span>
                Print All
              </button>
            </footer>
          </section>
        </main>
      </div>

      <BarcodeStyleOptionsModal
        open={styleModalOpen}
        onClose={() => setStyleModalOpen(false)}
        onSaved={refreshActiveStyles}
      />
    </div>
  )
}
