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
import {
  barcodeSvg,
  loadBarcodePrintSettings,
  printBarcodeBatch,
  printBarcodeLabels,
  saveBarcodePrintSettings,
  type BarcodeLabelSize,
  type BarcodePrintStyle,
  type BarcodeType,
} from '../components/products/barcodePrint'
import { useAuth } from '../features/auth/AuthProvider'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
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
  return (
    (product.prices ?? []).find(
      (row: ProductPrice) => row.price_type === priceField,
    )?.amount ?? ''
  )
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
  const { closeActiveTab } = useWorkspace()
  const initializedProduct = useRef<string | null>(null)
  const remembered = useMemo(() => loadBarcodePrintSettings(), [])

  const [search, setSearch] = useState('')
  const [categoryUlid, setCategoryUlid] = useState('')
  const [brandUlid, setBrandUlid] = useState('')
  const [includeSubBarcodes, setIncludeSubBarcodes] = useState(false)
  const [addWithExisting, setAddWithExisting] = useState(false)

  const [rangeMode, setRangeMode] = useState<'code'>('code')
  const [rangeFrom, setRangeFrom] = useState('')
  const [rangeTo, setRangeTo] = useState('')
  const [multiplier, setMultiplier] = useState('1')

  const [barcodeType, setBarcodeType] = useState<BarcodeType>(
    remembered.barcodeType,
  )
  const [displayField, setDisplayField] = useState<DisplayField>('name')
  const [priceField, setPriceField] = useState<PriceField>('retail')
  const [printString, setPrintString] = useState('[PRODUCT_NAME]')
  const [labelSize] = useState<BarcodeLabelSize>(
    remembered.labelSize,
  )
  const [printStyle, setPrintStyle] = useState<BarcodePrintStyle>(
    remembered.printStyle,
  )
  const [showPrice, setShowPrice] = useState(remembered.showPrice)
  const [marginLeftMm, setMarginLeftMm] = useState(0)
  const [marginTopMm, setMarginTopMm] = useState(0)
  const [scaleFactor, setScaleFactor] = useState(1)

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
    const from = rangeFrom.trim() ? Number(rangeFrom) : null
    const to = rangeTo.trim() ? Number(rangeTo) : null
    const qty = Math.max(1, Math.min(999, Number(multiplier) || 1))

    const matched = products.filter((product) => {
      if (from === null && to === null) return true
      const value = productNumberValue(product.product_number)
      if (value === null) return false
      if (from !== null && value < from) return false
      if (to !== null && value > to) return false
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

    return {
      businessName: session?.tenant.name ?? 'BluePOS',
      productName: displayText(line.product, displayField),
      productNumber: line.product.product_number,
      barcode: barcode.barcode,
      unitCode: barcode.unit?.code ?? '',
      price: priceFor(line.product, priceField),
      currencyCode: session?.tenant.currency_code ?? '',
      copies,
      labelSize,
      showPrice: showPrice && priceField !== 'none',
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

    const payload = buildPayload(selectedLine, selectedLine.quantity)
    if (!payload) {
      setError('The selected row has no printable barcode.')
      return
    }

    try {
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

    const payloads = queue.flatMap((line) => {
      const payload = buildPayload(line, line.quantity)
      return payload ? [payload] : []
    })

    try {
      saveSettings()
      printBarcodeBatch(payloads, {
        labelSize,
        showPrice,
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
      const payload = selectedLine
        ? buildPayload(selectedLine, 1)
        : {
            businessName: session?.tenant.name ?? 'BluePOS',
            productName: 'Calibration Test',
            productNumber: '000001',
            barcode: '1105000',
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
            showBusinessName: true,
            showProductNumber: true,
            showUnit: true,
            showBarcodeText: true,
          }

      if (payload) printBarcodeLabels(payload)
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
          <div className="bp-left-scroll">
            <section className="bp-panel">
              <header className="bp-panel-title">Barcode Settings</header>
              <div className="bp-panel-body">
                <label className="bp-field">
                  <span>Barcode Print String: (Use | ColName | For Parse)</span>
                  <input
                    value={printString}
                    onChange={(event) => setPrintString(event.target.value)}
                  />
                </label>

                <label className="bp-field">
                  <span>Select Barcode Type:</span>
                  <select
                    value={barcodeType}
                    onChange={(event) =>
                      setBarcodeType(event.target.value as BarcodeType)
                    }
                  >
                    <option value="CODE128">Code128</option>
                    <option value="CODE39">Code39</option>
                  </select>
                </label>

                <label className="bp-field">
                  <span>Field to Display:</span>
                  <select
                    value={displayField}
                    onChange={(event) =>
                      setDisplayField(event.target.value as DisplayField)
                    }
                  >
                    <option value="name">PRODUCT NAME</option>
                    <option value="alternate_name">ALTERNATE DESC</option>
                    <option value="product_number">PRODUCT #</option>
                    <option value="sku">SKU / CODE</option>
                  </select>
                </label>

                <label className="bp-field">
                  <span>Price Field:</span>
                  <select
                    value={priceField}
                    onChange={(event) =>
                      setPriceField(event.target.value as PriceField)
                    }
                  >
                    <option value="retail">SUB SELLING PRICE</option>
                    <option value="wholesale">WHOLESALE PRICE</option>
                    <option value="minimum_sale">MIN SALE PRICE</option>
                    <option value="none">NO PRICE</option>
                  </select>
                </label>

                <div className="bp-check-row">
                  <label className="bp-check">
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
            </section>

            <section className="bp-panel">
              <header className="bp-panel-title">Margin Settings</header>
              <div className="bp-panel-body">
                <div className="bp-margin-grid">
                  <label className="bp-field">
                    <span>Left</span>
                    <input
                      type="number"
                      step={0.5}
                      value={marginLeftMm}
                      onChange={(event) =>
                        setMarginLeftMm(Number(event.target.value))
                      }
                    />
                  </label>

                  <label className="bp-field">
                    <span>Top</span>
                    <input
                      type="number"
                      step={0.5}
                      value={marginTopMm}
                      onChange={(event) =>
                        setMarginTopMm(Number(event.target.value))
                      }
                    />
                  </label>

                  <label className="bp-field">
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
                  </label>
                </div>

                <label className="bp-field">
                  <span>Sample</span>
                  <input
                    value={previewBarcode?.barcode ?? '1105000'}
                    readOnly
                  />
                </label>
              </div>
            </section>

            <section className="bp-panel">
              <header className="bp-panel-title">Printer / Calibration</header>
              <div className="bp-panel-body">
                <label className="bp-field">
                  <span>On Following Printer</span>
                  <select className="bp-control-full" value="system" disabled>
                    <option value="system">System Print Dialog</option>
                  </select>
                </label>

                <div className="bp-printer-actions">
                  <button type="button" className="bp-btn bp-btn-calibrate" onClick={calibrate}>
                    Calibrate
                  </button>
                  <label className="bp-check">
                    <input type="checkbox" disabled />
                    <span>Computer Based</span>
                  </label>
                </div>

                <div className="bp-extra-options">
                  <label className="bp-check">
                    <input type="checkbox" disabled />
                    <span>Use Invoice Batch / Serial for Barcode Printing</span>
                  </label>

                  <label className="bp-check">
                    <input type="checkbox" checked readOnly />
                    <span>Auto Module</span>
                  </label>

                  <label className="bp-check">
                    <input type="checkbox" disabled />
                    <span>Direct Print to Printer</span>
                  </label>

                  <div className="bp-purchase-row">
                    <span>From Purchase ID:</span>
                    <input disabled />
                    <button type="button" className="bp-btn" disabled>
                      <RefreshCw size={12} />
                      Get
                    </button>
                  </div>
                </div>
              </div>
            </section>

            <section className="bp-panel bp-preview-panel">
              <header className="bp-panel-title">Live Barcode Preview</header>
              <div className="bp-preview-body">
                <div className="bp-preview-paper">
                  {previewBarcode ? (
                    <div
                      className="bp-preview-svg"
                      dangerouslySetInnerHTML={{
                        __html: barcodeSvg(
                          previewBarcode.barcode,
                          barcodeType,
                        ),
                      }}
                    />
                  ) : (
                    <div className="bp-preview-empty">Barcode Preview</div>
                  )}
                  <code>{previewBarcode?.barcode ?? '1105000'}</code>
                  {showPrice && priceField !== 'none' ? (
                    <strong>
                      {session?.tenant.currency_code ?? ''}{' '}
                      {Number(previewPrice || 0).toFixed(2)}
                    </strong>
                  ) : null}
                </div>
              </div>
            </section>

            <section className="bp-panel">
              <header className="bp-panel-title">Barcode Printing Style</header>
              <div className="bp-panel-body bp-style-row">
                <select
                  value={printStyle}
                  onChange={(event) =>
                    setPrintStyle(event.target.value as BarcodePrintStyle)
                  }
                >
                  <option value="standard">1 Barcode (Style 2)</option>
                  <option value="compact">Compact Barcode</option>
                  <option value="price_emphasis">Price Emphasis</option>
                </select>
                <button type="button" className="bp-btn bp-btn-icon" title="Future custom style editor" disabled>
                  <Plus size={14} />
                </button>
              </div>
            </section>
          </div>

          <div className="bp-left-actions">
            <button
              type="button"
              className="bp-action-btn"
              onClick={removeSelected}
              disabled={!selectedLine}
            >
              <span>Delete</span>
              <Trash2 size={18} />
            </button>

            <button
              type="button"
              className="bp-action-btn bp-action-primary"
              onClick={saveAndPreview}
              disabled={!selectedLine}
            >
              <span>Save &amp; Preview</span>
              <Save size={18} />
            </button>

            <button
              type="button"
              className="bp-action-btn"
              onClick={closeActiveTab}
            >
              <span>Close</span>
              <X size={18} />
            </button>
          </div>
        </aside>

        <main className="bp-right">
          <section className="bp-panel bp-autofill">
            <header className="bp-panel-title">Auto Fill Options</header>
            <div className="bp-panel-body">
              <div className="bp-row bp-row-checks">
                <label className="bp-check">
                  <input type="checkbox" disabled />
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
                <select
                  className="bp-w-code"
                  value={rangeMode}
                  onChange={() => setRangeMode('code')}
                >
                  <option value="code">Code</option>
                </select>

                <span className="bp-label">From</span>
                <input
                  className="bp-w-range"
                  value={rangeFrom}
                  placeholder="000001"
                  onChange={(event) => setRangeFrom(event.target.value)}
                />

                <span className="bp-label">To</span>
                <input
                  className="bp-w-range"
                  value={rangeTo}
                  placeholder="999999"
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

                <select
                  className="bp-w-filter"
                  value={categoryUlid}
                  onChange={(event) => setCategoryUlid(event.target.value)}
                  title="Category filter"
                >
                  <option value="">All Categories</option>
                  {(categories.data ?? []).map((category) => (
                    <option key={category.ulid} value={category.ulid}>
                      {category.name}
                    </option>
                  ))}
                </select>

                <select
                  className="bp-w-filter"
                  value={brandUlid}
                  onChange={(event) => setBrandUlid(event.target.value)}
                  title="Company / Brand filter"
                >
                  <option value="">All Companies</option>
                  {(brands.data ?? []).map((brand) => (
                    <option key={brand.ulid} value={brand.ulid}>
                      {brand.name}
                    </option>
                  ))}
                </select>
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
                                </option>
                              ))}
                            </select>
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
                <Printer size={14} />
                Print All
              </button>
            </footer>
          </section>
        </main>
      </div>
    </div>
  )
}
