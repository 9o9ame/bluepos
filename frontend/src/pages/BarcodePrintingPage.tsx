import { useEffect, useMemo, useRef, useState } from 'react'
import {
  Barcode,
  CheckCircle2,
  Eraser,
  Filter,
  PackagePlus,
  Plus,
  Printer,
  RefreshCw,
  Search,
  Settings2,
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
  saveBarcodePrintSettings,
  type BarcodeLabelSize,
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

function selectedBarcode(
  line: PrintQueueLine | null,
): ProductBarcode | null {
  if (!line) return null

  return (
    activeBarcodes(line.product).find(
      (barcode) => barcode.ulid === line.barcodeUlid,
    ) ?? null
  )
}

function priceFor(
  product: Product,
  priceField: PriceField,
): string {
  if (priceField === 'none') return ''

  const price = (product.prices ?? []).find(
    (row: ProductPrice) => row.price_type === priceField,
  )

  return price?.amount ?? ''
}

function displayText(
  product: Product,
  field: DisplayField,
): string {
  if (field === 'alternate_name') {
    return product.alternate_name?.trim() || product.name
  }

  if (field === 'product_number') {
    return product.product_number
  }

  if (field === 'sku') {
    return product.sku?.trim() || product.name
  }

  return product.name
}

function queueId(productUlid: string, barcodeUlid: string) {
  return `${productUlid}:${barcodeUlid}`
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
  const [addWithExisting, setAddWithExisting] = useState(true)

  const [barcodeType, setBarcodeType] = useState<BarcodeType>(
    remembered.barcodeType ?? 'CODE128',
  )
  const [labelSize, setLabelSize] = useState<BarcodeLabelSize>(
    remembered.labelSize,
  )
  const [showPrice, setShowPrice] = useState(remembered.showPrice)
  const [displayField, setDisplayField] = useState<DisplayField>('name')
  const [priceField, setPriceField] = useState<PriceField>('retail')
  const [marginLeftMm, setMarginLeftMm] = useState(
    remembered.marginLeftMm ?? 0,
  )
  const [marginTopMm, setMarginTopMm] = useState(
    remembered.marginTopMm ?? 0,
  )
  const [scaleFactor, setScaleFactor] = useState(
    remembered.scaleFactor ?? 1,
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

  function addProduct(product: Product) {
    const barcodes = activeBarcodes(product)

    if (barcodes.length === 0) {
      setError(`${product.name} has no active barcode.`)
      return
    }

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
              quantity: Math.min(999, next[existingIndex].quantity + 1),
            }
          }
          continue
        }

        next.push({
          id,
          product,
          barcodeUlid: barcode.ulid,
          quantity: 1,
        })
      }

      return next
    })

    setSelectedQueueId(
      queueId(product.ulid, chosen[0].ulid),
    )
    setError(null)
  }

  useEffect(() => {
    const product = initialProductQuery.data
    if (!product || initializedProduct.current === product.ulid) return

    initializedProduct.current = product.ulid
    addProduct(product)
    // Intentionally initialize only once per routed product.
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

  function addVisibleProducts() {
    if (products.length === 0) {
      setError('No products match the current filters.')
      return
    }

    products.forEach(addProduct)
  }

  function saveSettings() {
    saveBarcodePrintSettings({
      labelSize,
      showPrice,
      barcodeType,
      marginLeftMm,
      marginTopMm,
      scaleFactor,
    })
  }

  function printQueue() {
    if (queue.length === 0) {
      setError('Add at least one product to the print queue.')
      return
    }

    const payloads = queue.flatMap((line) => {
      const barcode = selectedBarcode(line)
      if (!barcode || line.quantity < 1) return []

      return [
        {
          businessName: session?.tenant.name ?? 'BluePOS',
          productName: displayText(line.product, displayField),
          productNumber: line.product.product_number,
          barcode: barcode.barcode,
          unitCode: barcode.unit?.code ?? '',
          price: priceFor(line.product, priceField),
          currencyCode: session?.tenant.currency_code ?? '',
          copies: line.quantity,
          labelSize,
          showPrice: showPrice && priceField !== 'none',
          barcodeType,
          marginLeftMm,
          marginTopMm,
          scaleFactor,
        },
      ]
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

  useWorkspaceHandlers({
    refresh: () => void productsQuery.refetch(),
  })

  return (
    <div className="barcode-page">
      <header className="barcode-page-head">
        <div>
          <span className="barcode-page-kicker">Printing / Lists</span>
          <h1>
            <Barcode size={22} />
            Barcode Printing
          </h1>
          <p>
            Build a multi-product label queue, preview the selected label,
            calibrate it, and print in one batch.
          </p>
        </div>

        <div className="barcode-page-head-actions">
          <div className="barcode-page-total">
            <span>Labels</span>
            <strong>{totalLabels}</strong>
          </div>

          <button
            type="button"
            className="barcode-primary-button"
            disabled={queue.length === 0}
            onClick={printQueue}
          >
            <Printer size={17} />
            Print Queue
          </button>

          <button
            type="button"
            className="barcode-secondary-button"
            onClick={closeActiveTab}
          >
            <X size={16} />
            Close
          </button>
        </div>
      </header>

      {error ? (
        <div className="barcode-page-alert">{error}</div>
      ) : null}

      <div className="barcode-page-layout">
        <aside className="barcode-settings-card">
          <div className="barcode-card-title">
            <Settings2 size={16} />
            <span>Barcode Settings</span>
          </div>

          <div className="barcode-setting-group">
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

            <label className="barcode-check-row">
              <input
                type="checkbox"
                checked={showPrice && priceField !== 'none'}
                disabled={priceField === 'none'}
                onChange={(event) => setShowPrice(event.target.checked)}
              />
              <span>Show price on label</span>
            </label>
          </div>

          <div className="barcode-card-title is-small">
            <span>Printer Calibration</span>
          </div>

          <div className="barcode-calibration-grid">
            <label>
              <span>Left (mm)</span>
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
            </label>

            <label>
              <span>Top (mm)</span>
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
            </label>

            <label className="is-wide">
              <span>Scale Factor</span>
              <div className="barcode-scale-row">
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
          </div>

          <div className="barcode-card-title is-small">
            <span>Live Label Preview</span>
          </div>

          <div className="barcode-label-stage">
            <div
              className={`barcode-label-preview size-${labelSize}`}
              style={{
                transform: `translate(${marginLeftMm * 0.7}px, ${marginTopMm * 0.7}px) scale(${scaleFactor})`,
              }}
            >
              <strong>{session?.tenant.name ?? 'BluePOS'}</strong>
              <b>
                {previewProduct
                  ? displayText(previewProduct, displayField)
                  : 'Select a queued product'}
              </b>

              <div className="barcode-preview-meta">
                <span>{previewProduct?.product_number ?? '000000'}</span>
                <span>{previewBarcode?.unit?.code ?? ''}</span>
              </div>

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

              <code>{previewBarcode?.barcode ?? '000000000000'}</code>

              {showPrice && priceField !== 'none' ? (
                <em>
                  {session?.tenant.currency_code ?? ''}{' '}
                  {Number(previewPrice || 0).toFixed(2)}
                </em>
              ) : null}
            </div>
          </div>

          <p className="barcode-printer-note">
            Printing uses the browser/system print dialog. Select your
            thermal label printer and keep scale at 100%.
          </p>
        </aside>

        <main className="barcode-work-area">
          <section className="barcode-filter-card">
            <div className="barcode-card-title">
              <Filter size={16} />
              <span>Find Products</span>
            </div>

            <div className="barcode-filter-row">
              <label className="barcode-search-box">
                <Search size={15} />
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

              <button
                type="button"
                className="barcode-secondary-button"
                onClick={() => void productsQuery.refetch()}
              >
                <RefreshCw size={15} />
                Refresh
              </button>
            </div>

            <div className="barcode-filter-options">
              <label>
                <input
                  type="checkbox"
                  checked={includeSubBarcodes}
                  onChange={(event) =>
                    setIncludeSubBarcodes(event.target.checked)
                  }
                />
                <span>Include sub-barcodes / multi-barcode units</span>
              </label>

              <label>
                <input
                  type="checkbox"
                  checked={addWithExisting}
                  onChange={(event) =>
                    setAddWithExisting(event.target.checked)
                  }
                />
                <span>Add to quantity when already in queue</span>
              </label>

              <button
                type="button"
                className="barcode-add-visible"
                disabled={products.length === 0}
                onClick={addVisibleProducts}
              >
                <PackagePlus size={15} />
                Add Visible Products
              </button>
            </div>

            <div className="barcode-product-results">
              <div className="barcode-result-head">
                <span>Product #</span>
                <span>Product / Description</span>
                <span>Category</span>
                <span>Primary Barcode</span>
                <span />
              </div>

              <div className="barcode-result-body">
                {productsQuery.isLoading ? (
                  <div className="barcode-result-empty">Loading products…</div>
                ) : products.length === 0 ? (
                  <div className="barcode-result-empty">No products found.</div>
                ) : (
                  products.map((product) => {
                    const barcodes = activeBarcodes(product)
                    const primary =
                      barcodes.find((barcode) => barcode.is_primary) ??
                      barcodes[0] ??
                      null

                    return (
                      <div
                        className="barcode-result-row"
                        key={product.ulid}
                      >
                        <span>{product.product_number}</span>
                        <strong>{product.name}</strong>
                        <span>{product.category?.name ?? '—'}</span>
                        <code>{primary?.barcode ?? 'No barcode'}</code>
                        <button
                          type="button"
                          disabled={barcodes.length === 0}
                          title={
                            barcodes.length
                              ? 'Add to print queue'
                              : 'Product has no active barcode'
                          }
                          onClick={() => addProduct(product)}
                        >
                          <Plus size={14} />
                          Add
                        </button>
                      </div>
                    )
                  })
                )}
              </div>
            </div>
          </section>

          <section className="barcode-queue-card">
            <div className="barcode-queue-top">
              <div>
                <div className="barcode-card-title">
                  <CheckCircle2 size={16} />
                  <span>Print Queue</span>
                </div>
                <p>
                  {queue.length} queue row{queue.length === 1 ? '' : 's'} ·{' '}
                  {totalLabels} total label{totalLabels === 1 ? '' : 's'}
                </p>
              </div>

              <div className="barcode-queue-actions">
                <button
                  type="button"
                  className="barcode-secondary-button"
                  disabled={queue.length === 0}
                  onClick={() => {
                    setQueue([])
                    setSelectedQueueId(null)
                  }}
                >
                  <Eraser size={15} />
                  Clear Queue
                </button>

                <button
                  type="button"
                  className="barcode-primary-button"
                  disabled={queue.length === 0}
                  onClick={printQueue}
                >
                  <Printer size={16} />
                  Print Queue
                </button>
              </div>
            </div>

            <div className="barcode-queue-table-wrap">
              <table className="barcode-queue-table">
                <thead>
                  <tr>
                    <th className="is-num">#</th>
                    <th>Barcode</th>
                    <th>Item / Product Description</th>
                    <th>Unit</th>
                    <th>Price</th>
                    <th className="is-qty">Quantity</th>
                    <th className="is-action" />
                  </tr>
                </thead>
                <tbody>
                  {queue.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="barcode-queue-empty">
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
                          <td className="is-num">{index + 1}</td>
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
                          <td>{barcode?.unit?.code ?? '—'}</td>
                          <td>
                            {priceField === 'none'
                              ? '—'
                              : Number(
                                  priceFor(line.product, priceField) || 0,
                                ).toFixed(2)}
                          </td>
                          <td className="is-qty">
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
                              title="Remove from queue"
                              onClick={(event) => {
                                event.stopPropagation()
                                removeLine(line.id)
                              }}
                            >
                              <Trash2 size={14} />
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
        </main>
      </div>
    </div>
  )
}
