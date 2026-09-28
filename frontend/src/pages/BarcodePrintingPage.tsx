import { useEffect, useMemo, useRef, useState } from 'react'
import {
  Barcode,
  CheckCircle2,
  Eraser,
  Filter,
  Plus,
  Printer,
  RefreshCw,
  Search,
  Settings2,
  SlidersHorizontal,
  Trash2,
} from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import {
  fetchBrands,
  fetchCategories,
  fetchProduct,
  fetchProducts,
} from '../api/catalog'
import {
  barcodeSvg,
  getBarcodeLabelSizeOptions,
  loadBarcodePrintSettings,
  printBarcodeBatch,
  printBarcodeLabels,
  saveBarcodePrintSettings,
  type BarcodeLabelSize,
  type BarcodePrintStyle,
  type BarcodeType,
} from '../components/products/barcodePrint'
import { useAuth } from '../features/auth/AuthProvider'
import { useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Product, ProductBarcode, ProductPrice } from '../types/catalog'
import './BarcodePrintingPage.css'

type DisplayField = 'name' | 'alternate_name' | 'product_number' | 'sku'
type PriceField = 'retail' | 'wholesale' | 'minimum_sale' | 'none'

type PrintQueueLine = {
  id: string
  product: Product
  barcodeUlid: string
  quantity: number
}

const PRINT_STRING_TOKENS = [
  '[PRODUCT_NAME]',
  '[ALT_DESC]',
  '[PRODUCT_NO]',
  '[SKU]',
  '[BARCODE]',
  '[UNIT]',
  '[PRICE]',
  '[BRAND]',
  '[CATEGORY]',
] as const

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

function priceFor(product: Product, priceField: PriceField): string {
  if (priceField === 'none') return ''

  const price = (product.prices ?? []).find(
    (row: ProductPrice) => row.price_type === priceField,
  )

  return price?.amount ?? ''
}

function displayText(product: Product, field: DisplayField): string {
  if (field === 'alternate_name') {
    return product.alternate_name?.trim() || product.name
  }

  if (field === 'product_number') return product.product_number
  if (field === 'sku') return product.sku?.trim() || product.name
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

export function BarcodePrintingPage() {
  const { productUlid } = useParams()
  const { session } = useAuth()
  const initializedProduct = useRef<string | null>(null)
  const remembered = useMemo(() => loadBarcodePrintSettings(), [])

  const [search, setSearch] = useState('')
  const [categoryUlid, setCategoryUlid] = useState('')
  const [brandUlid, setBrandUlid] = useState('')
  const [includeSubBarcodes, setIncludeSubBarcodes] = useState(false)
  const [addWithExisting, setAddWithExisting] = useState(true)

  const [rangeFrom, setRangeFrom] = useState('')
  const [rangeTo, setRangeTo] = useState('')
  const [multiplier, setMultiplier] = useState('1')

  const [barcodeType, setBarcodeType] = useState<BarcodeType>(
    remembered.barcodeType,
  )
  const [labelSize, setLabelSize] = useState<BarcodeLabelSize>(
    remembered.labelSize,
  )
  const [printStyle, setPrintStyle] = useState<BarcodePrintStyle>(
    remembered.printStyle,
  )
  const [showPrice, setShowPrice] = useState(remembered.showPrice)
  const [displayField, setDisplayField] = useState<DisplayField>('name')
  const [priceField, setPriceField] = useState<PriceField>('retail')
  const [printString, setPrintString] = useState('[PRODUCT_NAME]')
  const [marginLeftMm, setMarginLeftMm] = useState(
    remembered.marginLeftMm,
  )
  const [marginTopMm, setMarginTopMm] = useState(
    remembered.marginTopMm,
  )
  const [scaleFactor, setScaleFactor] = useState(
    remembered.scaleFactor,
  )

  const [showBusinessName, setShowBusinessName] = useState(
    remembered.showBusinessName,
  )
  const [showProductNumber, setShowProductNumber] = useState(
    remembered.showProductNumber,
  )
  const [showUnit, setShowUnit] = useState(remembered.showUnit)
  const [showBarcodeText, setShowBarcodeText] = useState(
    remembered.showBarcodeText,
  )

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
    queryKey: [
      'barcode-print-products',
      search,
      categoryUlid,
      brandUlid,
    ],
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

  function addProduct(product: Product, quantityOverride?: number) {
    const barcodes = activeBarcodes(product)

    if (barcodes.length === 0) {
      setError(`${product.name} has no active barcode.`)
      return
    }

    const quantity = Math.max(
      1,
      Math.min(
        999,
        Math.floor(quantityOverride ?? (Number(multiplier) || 1)),
      ),
    )

    const chosen = includeSubBarcodes
      ? barcodes
      : [
          barcodes.find((barcode) => barcode.is_primary) ??
            barcodes[0],
        ]

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
                next[existingIndex].quantity + quantity,
              ),
            }
          }
          continue
        }

        next.push({
          id,
          product,
          barcodeUlid: barcode.ulid,
          quantity,
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
    // Initialize the routed product once only.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [initialProductQuery.data])

  const selectedLine =
    queue.find((line) => line.id === selectedQueueId) ??
    queue[0] ??
    null

  const previewBarcode = selectedBarcode(selectedLine)
  const previewProduct = selectedLine?.product ?? null
  const previewPrice = previewProduct
    ? priceFor(previewProduct, priceField)
    : ''

  const totalLabels = queue.reduce(
    (sum, row) => sum + Math.max(0, row.quantity || 0),
    0,
  )

  function resolvePrintString(
    product: Product,
    barcode: ProductBarcode | null,
  ): string {
    const replacements: Record<string, string> = {
      '[PRODUCT_NAME]': product.name,
      '[ALT_DESC]': product.alternate_name ?? '',
      '[PRODUCT_NO]': product.product_number,
      '[SKU]': product.sku ?? '',
      '[BARCODE]': barcode?.barcode ?? '',
      '[UNIT]': barcode?.unit?.code ?? '',
      '[PRICE]': priceFor(product, priceField),
      '[BRAND]': product.brand?.name ?? '',
      '[CATEGORY]': product.category?.name ?? '',
    }

    let resolved = printString.trim() || '[PRODUCT_NAME]'

    for (const token of PRINT_STRING_TOKENS) {
      resolved = resolved.replaceAll(token, replacements[token])
    }

    return resolved.replace(/\s+/g, ' ').trim() || product.name
  }

  function updateQuantity(id: string, quantity: number) {
    const safe = Math.max(1, Math.min(999, Math.floor(quantity || 1)))
    setQueue((current) =>
      current.map((line) =>
        line.id === id ? { ...line, quantity: safe } : line,
      ),
    )
  }

  function changeBarcode(line: PrintQueueLine, barcodeUlid: string) {
    const id = queueId(line.product.ulid, barcodeUlid)

    setQueue((current) => {
      const duplicate = current.find(
        (candidate) =>
          candidate.id === id && candidate.id !== line.id,
      )

      if (duplicate) {
        return current
          .filter((candidate) => candidate.id !== line.id)
          .map((candidate) =>
            candidate.id === duplicate.id
              ? {
                  ...candidate,
                  quantity: Math.min(
                    999,
                    candidate.quantity + line.quantity,
                  ),
                }
              : candidate,
          )
      }

      return current.map((candidate) =>
        candidate.id === line.id
          ? { ...candidate, id, barcodeUlid }
          : candidate,
      )
    })

    setSelectedQueueId(id)
  }

  function removeLine(id: string) {
    setQueue((current) => current.filter((line) => line.id !== id))
    setSelectedQueueId((current) => (current === id ? null : current))
  }

  function addProducts(items: Product[]) {
    if (items.length === 0) {
      setError('No products match the selected criteria.')
      return
    }

    items.forEach((product) => addProduct(product))
  }

  function addByRange() {
    const from = rangeFrom.trim() ? Number(rangeFrom) : null
    const to = rangeTo.trim() ? Number(rangeTo) : null

    if (
      (from !== null && !Number.isFinite(from)) ||
      (to !== null && !Number.isFinite(to))
    ) {
      setError('From and To must be numeric product numbers.')
      return
    }

    const matched = products.filter((product) => {
      const value = productNumberValue(product.product_number)
      if (value === null) return false
      if (from !== null && value < from) return false
      if (to !== null && value > to) return false
      return true
    })

    addProducts(matched)
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
      showBusinessName,
      showProductNumber,
      showUnit,
      showBarcodeText,
    })
  }

  function basePayload(
    product: Product,
    barcode: ProductBarcode,
    copies: number,
  ) {
    return {
      businessName: session?.tenant.name ?? 'BluePOS',
      productName:
        printString.trim() && printString !== '[PRODUCT_NAME]'
          ? resolvePrintString(product, barcode)
          : displayText(product, displayField),
      productNumber: product.product_number,
      barcode: barcode.barcode,
      unitCode: barcode.unit?.code ?? '',
      price: priceFor(product, priceField),
      currencyCode: session?.tenant.currency_code ?? '',
      copies,
      labelSize,
      showPrice: showPrice && priceField !== 'none',
      barcodeType,
      printStyle,
      marginLeftMm,
      marginTopMm,
      scaleFactor,
      showBusinessName,
      showProductNumber,
      showUnit,
      showBarcodeText,
    }
  }

  function printQueue() {
    if (queue.length === 0) {
      setError('Add at least one product to the print queue.')
      return
    }

    const payloads = queue.flatMap((line) => {
      const barcode = selectedBarcode(line)
      if (!barcode || line.quantity < 1) return []
      return [basePayload(line.product, barcode, line.quantity)]
    })

    if (payloads.length === 0) {
      setError('The print queue contains no printable barcode.')
      return
    }

    try {
      saveSettings()
      printBarcodeBatch(payloads, {
        labelSize,
        showPrice: showPrice && priceField !== 'none',
        barcodeType,
        printStyle,
        marginLeftMm,
        marginTopMm,
        scaleFactor,
        showBusinessName,
        showProductNumber,
        showUnit,
        showBarcodeText,
      })
      setError(null)
    } catch (err) {
      setError(
        err instanceof Error ? err.message : 'Unable to print barcodes.',
      )
    }
  }

  function printCalibrationLabel() {
    try {
      const product = previewProduct
      const barcode = previewBarcode

      if (product && barcode) {
        printBarcodeLabels(basePayload(product, barcode, 1))
      } else {
        printBarcodeLabels({
          businessName: session?.tenant.name ?? 'BluePOS',
          productName: 'Calibration Test',
          productNumber: '000001',
          barcode: '1234567890',
          unitCode: 'PCS',
          price: '100.00',
          currencyCode: session?.tenant.currency_code ?? '',
          copies: 1,
          labelSize,
          showPrice,
          barcodeType,
          printStyle,
          marginLeftMm,
          marginTopMm,
          scaleFactor,
          showBusinessName,
          showProductNumber,
          showUnit,
          showBarcodeText,
        })
      }

      saveSettings()
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
    <div className="barcode-page barcode-page-v2">
      {error ? (
        <div className="barcode-page-alert">{error}</div>
      ) : null}

      <div className="barcode-page-v2-layout">
        <aside className="barcode-settings-panel">
          <section className="barcode-compact-section">
            <div className="barcode-section-title">
              <Settings2 size={14} />
              <span>Barcode Settings</span>
            </div>

            <div className="barcode-compact-fields">
              <label className="is-wide">
                <span>Barcode Print String</span>
                <input
                  value={printString}
                  placeholder="[PRODUCT_NAME]"
                  onChange={(event) => setPrintString(event.target.value)}
                />
              </label>

              <div className="barcode-token-hint">
                {PRINT_STRING_TOKENS.map((token) => (
                  <button
                    type="button"
                    key={token}
                    title={`Insert ${token}`}
                    onClick={() =>
                      setPrintString((current) =>
                        `${current}${current ? ' ' : ''}${token}`,
                      )
                    }
                  >
                    {token}
                  </button>
                ))}
              </div>

              <label>
                <span>Barcode Type</span>
                <select
                  value={barcodeType}
                  onChange={(event) =>
                    setBarcodeType(event.target.value as BarcodeType)
                  }
                >
                  <option value="CODE128">Code 128</option>
                  <option value="CODE39">Code 39</option>
                </select>
              </label>

              <label>
                <span>Field to Display</span>
                <select
                  value={displayField}
                  onChange={(event) =>
                    setDisplayField(event.target.value as DisplayField)
                  }
                >
                  <option value="name">Product Name</option>
                  <option value="alternate_name">Alternate Description</option>
                  <option value="product_number">Product Number</option>
                  <option value="sku">SKU / Code</option>
                </select>
              </label>

              <label>
                <span>Price Field</span>
                <select
                  value={priceField}
                  onChange={(event) =>
                    setPriceField(event.target.value as PriceField)
                  }
                >
                  <option value="retail">Retail / Sale Price</option>
                  <option value="wholesale">Wholesale Price</option>
                  <option value="minimum_sale">Minimum Sale Price</option>
                  <option value="none">Do Not Print Price</option>
                </select>
              </label>

              <label>
                <span>Label Size</span>
                <select
                  value={labelSize}
                  onChange={(event) =>
                    setLabelSize(event.target.value as BarcodeLabelSize)
                  }
                >
                  {getBarcodeLabelSizeOptions().map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </label>

              <label>
                <span>Printing Style</span>
                <select
                  value={printStyle}
                  onChange={(event) =>
                    setPrintStyle(event.target.value as BarcodePrintStyle)
                  }
                >
                  <option value="standard">Standard</option>
                  <option value="compact">Compact</option>
                  <option value="price_emphasis">Price Emphasis</option>
                </select>
              </label>

              <label className="barcode-inline-check">
                <input
                  type="checkbox"
                  checked={showPrice && priceField !== 'none'}
                  disabled={priceField === 'none'}
                  onChange={(event) => setShowPrice(event.target.checked)}
                />
                <span>Show Price</span>
              </label>
            </div>
          </section>

          <section className="barcode-compact-section">
            <div className="barcode-section-title">
              <Printer size={14} />
              <span>Printer / Calibration</span>
            </div>

            <div className="barcode-printer-grid">
              <label className="is-wide">
                <span>Printer</span>
                <select value="system" disabled>
                  <option value="system">System Print Dialog</option>
                </select>
              </label>

              <label>
                <span>Left</span>
                <div className="barcode-number-with-unit">
                  <input
                    type="number"
                    min={-5}
                    max={10}
                    step={0.5}
                    value={marginLeftMm}
                    onChange={(event) =>
                      setMarginLeftMm(Number(event.target.value))
                    }
                  />
                  <small>mm</small>
                </div>
              </label>

              <label>
                <span>Top</span>
                <div className="barcode-number-with-unit">
                  <input
                    type="number"
                    min={-5}
                    max={10}
                    step={0.5}
                    value={marginTopMm}
                    onChange={(event) =>
                      setMarginTopMm(Number(event.target.value))
                    }
                  />
                  <small>mm</small>
                </div>
              </label>

              <label className="is-wide">
                <span>Scale Factor</span>
                <div className="barcode-scale-inline">
                  <input
                    type="range"
                    min={0.7}
                    max={1.3}
                    step={0.05}
                    value={scaleFactor}
                    onChange={(event) =>
                      setScaleFactor(Number(event.target.value))
                    }
                  />
                  <strong>{scaleFactor.toFixed(2)}×</strong>
                </div>
              </label>

              <button
                type="button"
                className="barcode-calibrate-button"
                onClick={printCalibrationLabel}
              >
                <SlidersHorizontal size={14} />
                Calibrate / Test Label
              </button>
            </div>
          </section>

          <section className="barcode-compact-section barcode-preview-section">
            <div className="barcode-section-title">
              <Barcode size={14} />
              <span>Live Preview</span>
            </div>

            <div className="barcode-label-stage barcode-label-stage-v2">
              <div
                className={`barcode-label-preview size-${labelSize} style-${printStyle}`}
                style={{
                  transform: `translate(${marginLeftMm * 0.55}px, ${marginTopMm * 0.55}px) scale(${scaleFactor})`,
                }}
              >
                {showBusinessName ? (
                  <strong>{session?.tenant.name ?? 'BluePOS'}</strong>
                ) : null}

                <b>
                  {previewProduct
                    ? printString.trim() &&
                      printString !== '[PRODUCT_NAME]'
                      ? resolvePrintString(
                          previewProduct,
                          previewBarcode,
                        )
                      : displayText(previewProduct, displayField)
                    : 'Select a queued product'}
                </b>

                {showProductNumber || showUnit ? (
                  <div className="barcode-preview-meta">
                    <span>
                      {showProductNumber
                        ? previewProduct?.product_number ?? '000000'
                        : ''}
                    </span>
                    <span>
                      {showUnit ? previewBarcode?.unit?.code ?? '' : ''}
                    </span>
                  </div>
                ) : null}

                {previewBarcode ? (
                  <div
                    className="barcode-preview-svg"
                    dangerouslySetInnerHTML={{
                      __html: barcodeSvg(
                        previewBarcode.barcode,
                        barcodeType,
                      ),
                    }}
                  />
                ) : (
                  <div className="barcode-preview-empty">
                    Barcode preview
                  </div>
                )}

                {showBarcodeText ? (
                  <code>{previewBarcode?.barcode ?? '000000000000'}</code>
                ) : null}

                {showPrice && priceField !== 'none' ? (
                  <em>
                    {session?.tenant.currency_code ?? ''}{' '}
                    {Number(previewPrice || 0).toFixed(2)}
                  </em>
                ) : null}
              </div>
            </div>
          </section>

          <section className="barcode-compact-section">
            <div className="barcode-section-title">
              <SlidersHorizontal size={14} />
              <span>Label Fields</span>
            </div>

            <div className="barcode-customize-grid">
              <label>
                <input
                  type="checkbox"
                  checked={showBusinessName}
                  onChange={(event) =>
                    setShowBusinessName(event.target.checked)
                  }
                />
                Business Name
              </label>
              <label>
                <input
                  type="checkbox"
                  checked={showProductNumber}
                  onChange={(event) =>
                    setShowProductNumber(event.target.checked)
                  }
                />
                Product #
              </label>
              <label>
                <input
                  type="checkbox"
                  checked={showUnit}
                  onChange={(event) => setShowUnit(event.target.checked)}
                />
                Unit
              </label>
              <label>
                <input
                  type="checkbox"
                  checked={showBarcodeText}
                  onChange={(event) =>
                    setShowBarcodeText(event.target.checked)
                  }
                />
                Barcode Text
              </label>
            </div>
          </section>
        </aside>

        <main className="barcode-main-panel">
          <section className="barcode-autofill-panel">
            <div className="barcode-section-title">
              <Filter size={14} />
              <span>Auto Fill Options</span>
            </div>

            <div className="barcode-autofill-top">
              <label className="barcode-inline-check">
                <input
                  type="checkbox"
                  checked={addWithExisting}
                  onChange={(event) =>
                    setAddWithExisting(event.target.checked)
                  }
                />
                <span>Add With Existing</span>
              </label>

              <label className="barcode-inline-check">
                <input
                  type="checkbox"
                  checked={includeSubBarcodes}
                  onChange={(event) =>
                    setIncludeSubBarcodes(event.target.checked)
                  }
                />
                <span>Print Sub Barcode (Multi Barcode)</span>
              </label>

              <label className="barcode-range-field">
                <span>From</span>
                <input
                  value={rangeFrom}
                  inputMode="numeric"
                  placeholder="000001"
                  onChange={(event) => setRangeFrom(event.target.value)}
                />
              </label>

              <label className="barcode-range-field">
                <span>To</span>
                <input
                  value={rangeTo}
                  inputMode="numeric"
                  placeholder="999999"
                  onChange={(event) => setRangeTo(event.target.value)}
                />
              </label>

              <label className="barcode-range-field is-mf">
                <span>MF</span>
                <input
                  type="number"
                  min={1}
                  max={999}
                  value={multiplier}
                  onChange={(event) => setMultiplier(event.target.value)}
                />
              </label>

              <button
                type="button"
                className="barcode-auto-button"
                onClick={addByRange}
              >
                <RefreshCw size={14} />
                Auto
              </button>
            </div>

            <div className="barcode-autofill-bottom">
              <button
                type="button"
                disabled={!categoryUlid}
                onClick={() => addProducts(products)}
              >
                Selected Category
              </button>

              <button
                type="button"
                disabled={!brandUlid}
                onClick={() => addProducts(products)}
              >
                Selected Brand
              </button>

              <button
                type="button"
                disabled={products.length === 0}
                onClick={() => addProducts(products)}
              >
                Selected Products
              </button>

              <label className="barcode-search-box">
                <Search size={14} />
                <input
                  value={search}
                  placeholder="Search product, product #, SKU or barcode..."
                  onChange={(event) => setSearch(event.target.value)}
                />
              </label>

              <select
                value={categoryUlid}
                onChange={(event) => setCategoryUlid(event.target.value)}
              >
                <option value="">All Categories</option>
                {(categories.data ?? []).map((category) => (
                  <option key={category.ulid} value={category.ulid}>
                    {category.name}
                  </option>
                ))}
              </select>

              <select
                value={brandUlid}
                onChange={(event) => setBrandUlid(event.target.value)}
              >
                <option value="">All Brands</option>
                {(brands.data ?? []).map((brand) => (
                  <option key={brand.ulid} value={brand.ulid}>
                    {brand.name}
                  </option>
                ))}
              </select>
            </div>
          </section>

          <section className="barcode-product-picker">
            <div className="barcode-picker-head">
              <span>Product #</span>
              <span>Product / Description</span>
              <span>Category</span>
              <span>Primary Barcode</span>
              <span />
            </div>

            <div className="barcode-picker-body">
              {productsQuery.isLoading ? (
                <div className="barcode-picker-empty">Loading products…</div>
              ) : products.length === 0 ? (
                <div className="barcode-picker-empty">No products found.</div>
              ) : (
                products.map((product) => {
                  const barcodes = activeBarcodes(product)
                  const primary =
                    barcodes.find((barcode) => barcode.is_primary) ??
                    barcodes[0] ??
                    null

                  return (
                    <div className="barcode-picker-row" key={product.ulid}>
                      <span>{product.product_number}</span>
                      <strong>{product.name}</strong>
                      <span>{product.category?.name ?? '—'}</span>
                      <code>{primary?.barcode ?? 'No barcode'}</code>
                      <button
                        type="button"
                        disabled={barcodes.length === 0}
                        onClick={() => addProduct(product)}
                      >
                        <Plus size={13} />
                        Add
                      </button>
                    </div>
                  )
                })
              )}
            </div>
          </section>

          <section className="barcode-queue-panel">
            <div className="barcode-queue-toolbar">
              <div>
                <CheckCircle2 size={14} />
                <strong>Print Queue</strong>
                <span>
                  {queue.length} row{queue.length === 1 ? '' : 's'} ·{' '}
                  {totalLabels} label{totalLabels === 1 ? '' : 's'}
                </span>
              </div>

              <div>
                <button
                  type="button"
                  disabled={queue.length === 0}
                  onClick={() => {
                    setQueue([])
                    setSelectedQueueId(null)
                  }}
                >
                  <Eraser size={14} />
                  Clear
                </button>

                <button
                  type="button"
                  className="is-primary"
                  disabled={queue.length === 0}
                  onClick={printQueue}
                >
                  <Printer size={15} />
                  Print Queue
                </button>
              </div>
            </div>

            <div className="barcode-queue-table-wrap">
              <table className="barcode-queue-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Barcode</th>
                    <th>Item / Product Description</th>
                    <th>Other Description</th>
                    <th>Unit</th>
                    <th>Price</th>
                    <th>Quantity</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {queue.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="barcode-queue-empty">
                        Add products above to build a barcode print batch.
                      </td>
                    </tr>
                  ) : (
                    queue.map((line, index) => {
                      const barcodes = activeBarcodes(line.product)
                      const barcode = selectedBarcode(line)
                      const isSelected = line.id === selectedQueueId

                      return (
                        <tr
                          key={line.id}
                          className={isSelected ? 'is-selected' : undefined}
                          onClick={() => setSelectedQueueId(line.id)}
                        >
                          <td className="is-row-number">{index + 1}</td>

                          <td>
                            <select
                              value={line.barcodeUlid}
                              onClick={(event) => event.stopPropagation()}
                              onChange={(event) =>
                                changeBarcode(line, event.target.value)
                              }
                            >
                              {barcodes.map((option) => (
                                <option key={option.ulid} value={option.ulid}>
                                  {option.barcode}
                                  {option.is_primary ? ' · Primary' : ''}
                                </option>
                              ))}
                            </select>
                          </td>

                          <td>
                            <strong>{line.product.name}</strong>
                            <small>{line.product.product_number}</small>
                          </td>

                          <td>
                            {line.product.alternate_name?.trim() || '—'}
                          </td>

                          <td>{barcode?.unit?.code ?? '—'}</td>

                          <td>
                            {priceField === 'none'
                              ? '—'
                              : Number(
                                  priceFor(line.product, priceField) || 0,
                                ).toFixed(2)}
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

                          <td className="is-action">
                            <button
                              type="button"
                              title="Remove row"
                              onClick={(event) => {
                                event.stopPropagation()
                                removeLine(line.id)
                              }}
                            >
                              <Trash2 size={13} />
                            </button>
                          </td>
                        </tr>
                      )
                    })
                  )}
                </tbody>
              </table>
            </div>
          </section>

          <footer className="barcode-page-footer-note">
            <span>
              Printer mode: <strong>System Print Dialog</strong>
            </span>
            <span>
              Direct silent printing requires a future local BluePOS Print
              Bridge.
            </span>
          </footer>
        </main>
      </div>
    </div>
  )
}
