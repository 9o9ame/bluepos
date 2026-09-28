import { useEffect, useMemo, useRef, useState } from 'react'
import {
  Barcode,
  Check,
  ChevronDown,
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

const CUSTOMIZATION_FIELDS = [
  'Barcode',
  'BNS',
  'C',
  'DESC.',
  'Dis 3',
  'Dis A',
  'Disc-Rs',
  'Dis-Rs Amt',
  'Expiry Date',
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
  const [labelSize, setLabelSize] = useState<BarcodeLabelSize>(
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
    <div className="pos-barcode-reference">
      {error ? <div className="pos-barcode-error">{error}</div> : null}

      <aside className="pos-barcode-left">
        <section className="pos-barcode-box pos-barcode-settings">
          <div className="pos-barcode-box-title">Barcode Settings</div>

          <label className="pos-barcode-field pos-barcode-string">
            <span>Barcode Print String: (Use | ColName | For Parse)</span>
            <input
              value={printString}
              onChange={(event) => setPrintString(event.target.value)}
            />
          </label>

          <label className="pos-barcode-field">
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

          <label className="pos-barcode-field">
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

          <div className="pos-barcode-price-row">
            <label className="pos-barcode-field">
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

            <label className="pos-barcode-check pos-barcode-old-price">
              <input type="checkbox" disabled />
              <span>Show Old Price</span>
            </label>
          </div>

          <fieldset className="pos-barcode-margin-box">
            <legend>Margin Settings</legend>

            <label>
              <span>Left:</span>
              <input
                type="number"
                step={0.5}
                value={marginLeftMm}
                onChange={(event) =>
                  setMarginLeftMm(Number(event.target.value))
                }
              />
            </label>

            <label>
              <span>Scale Factor:</span>
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

            <label className="pos-barcode-sample-row">
              <span>Sample:</span>
              <input
                value={previewBarcode?.barcode ?? '1105000'}
                readOnly
              />
            </label>
          </fieldset>

          <div className="pos-barcode-printer-title">
            <span>On Following Printer</span>
            <button type="button" onClick={calibrate}>
              Calibrate
            </button>
            <label className="pos-barcode-check">
              <input type="checkbox" disabled />
              <span>Computer Based</span>
            </label>
          </div>

          <select className="pos-barcode-printer-select" value="system" disabled>
            <option value="system">System Print Dialog</option>
          </select>

          <div className="pos-barcode-extra-options">
            <label className="pos-barcode-check">
              <input type="checkbox" disabled />
              <span>Use Invoice Batch / Serial for Barcode Printing</span>
            </label>

            <label className="pos-barcode-check">
              <input type="checkbox" checked readOnly />
              <span>Auto Module</span>
            </label>

            <label className="pos-barcode-check">
              <input type="checkbox" disabled />
              <span>Direct Print to Printer</span>
            </label>

            <div className="pos-barcode-purchase-row">
              <span>From Purchase ID:</span>
              <input disabled />
              <button type="button" disabled>
                <RefreshCw size={12} />
                Get
              </button>
            </div>
          </div>
        </section>

        <section className="pos-barcode-preview-box">
          <div className="pos-barcode-preview-paper">
            {previewBarcode ? (
              <div
                className="pos-barcode-preview-svg"
                dangerouslySetInnerHTML={{
                  __html: barcodeSvg(
                    previewBarcode.barcode,
                    barcodeType,
                  ),
                }}
              />
            ) : (
              <div className="pos-barcode-preview-empty">
                Barcode Preview
              </div>
            )}
            <code>{previewBarcode?.barcode ?? '1105000'}</code>
            {showPrice && priceField !== 'none' ? (
              <strong>
                {session?.tenant.currency_code ?? ''}{' '}
                {Number(previewPrice || 0).toFixed(2)}
              </strong>
            ) : null}
          </div>
        </section>

        <section className="pos-barcode-style-row">
          <span>Barcode Printing Style</span>
          <div>
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
            <button type="button" title="Future custom style editor" disabled>
              <Plus size={13} />
            </button>
          </div>
        </section>

        <div className="pos-barcode-left-actions">
          <button type="button" onClick={removeSelected} disabled={!selectedLine}>
            <span>Delete</span>
            <Trash2 size={20} />
          </button>

          <button type="button" onClick={saveAndPreview} disabled={!selectedLine}>
            <span>Save &amp; Preview</span>
            <Save size={18} />
          </button>

          <button type="button" onClick={closeActiveTab}>
            <span>Close</span>
            <X size={20} />
          </button>
        </div>
      </aside>

      <main className="pos-barcode-right">
        <section className="pos-barcode-autofill">
          <div className="pos-barcode-autofill-title">Auto Fill Options</div>

          <div className="pos-barcode-autofill-row1">
            <label className="pos-barcode-check">
              <input type="checkbox" disabled />
              <span>Ignore Stock Qty</span>
            </label>

            <label className="pos-barcode-check">
              <input
                type="checkbox"
                checked={addWithExisting}
                onChange={(event) =>
                  setAddWithExisting(event.target.checked)
                }
              />
              <span>Add With Existing</span>
            </label>

            <label className="pos-barcode-check">
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

          <div className="pos-barcode-autofill-row2">
            <select
              value={rangeMode}
              onChange={() => setRangeMode('code')}
            >
              <option value="code">Code</option>
            </select>

            <span>From</span>
            <input
              value={rangeFrom}
              placeholder="000001"
              onChange={(event) => setRangeFrom(event.target.value)}
            />

            <span>To:</span>
            <input
              value={rangeTo}
              placeholder="999999"
              onChange={(event) => setRangeTo(event.target.value)}
            />

            <span>MF</span>
            <input
              className="pos-barcode-mf"
              type="number"
              min={1}
              max={999}
              value={multiplier}
              onChange={(event) => setMultiplier(event.target.value)}
            />

            <button type="button" onClick={autoFill}>
              <RefreshCw size={12} />
              Auto
            </button>

            <button type="button" className="pos-barcode-card-print" disabled>
              Card Print
              <CreditCard size={16} />
            </button>
          </div>

          <div className="pos-barcode-autofill-row3">
            <button
              type="button"
              disabled={!categoryUlid}
              onClick={addFilteredProducts}
            >
              <span className="pos-barcode-color-grid" />
              Selected Category
            </button>

            <button
              type="button"
              disabled={!brandUlid}
              onClick={addFilteredProducts}
            >
              <span className="pos-barcode-color-grid is-green" />
              Selected Company
            </button>

            <button
              type="button"
              disabled={products.length === 0}
              onClick={addFilteredProducts}
            >
              <span className="pos-barcode-color-grid is-orange" />
              Selected Products
            </button>

            <span className="pos-barcode-search-label">Search:</span>
            <input
              className="pos-barcode-search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />

            <select
              className="pos-barcode-hidden-filter"
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
              className="pos-barcode-hidden-filter"
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
        </section>

        <section className="pos-barcode-grid-shell">
          <table className="pos-barcode-grid">
            <thead>
              <tr>
                <th className="is-marker" />
                <th className="is-row-no" />
                <th className="is-barcode">Barcode</th>
                <th>ITEM / PRODUCT DESCRIPTION</th>
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
                      className="pos-barcode-empty-add"
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
                  const barcode = selectedBarcode(line)
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

              <tr className="pos-barcode-new-row">
                <td className="is-marker">*</td>
                <td className="is-row-no" />
                <td>----</td>
                <td>----</td>
                <td />
                <td />
              </tr>
            </tbody>
          </table>

          <div className="pos-barcode-grid-space" />

          <div className="pos-barcode-customization">
            <div className="pos-barcode-customization-title">
              Customization
              <ChevronDown size={12} />
            </div>
            <button type="button" className="is-minus">-</button>
            {CUSTOMIZATION_FIELDS.map((field) => (
              <button
                type="button"
                key={field}
                disabled={!['Barcode', 'DESC.'].includes(field)}
                title={
                  ['Barcode', 'DESC.'].includes(field)
                    ? 'Available'
                    : 'Requires source data not yet implemented'
                }
              >
                {field}
              </button>
            ))}
          </div>

          <div className="pos-barcode-grid-footer">
            <span>Record {queue.length ? 1 : 0} of {queue.length}</span>
            <div>
              <button type="button" onClick={printAll} disabled={!queue.length}>
                <Printer size={13} />
                Print All
              </button>
            </div>
          </div>
        </section>
      </main>
    </div>
  )
}
