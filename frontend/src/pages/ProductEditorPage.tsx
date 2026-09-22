import { FormEvent, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Barcode, Printer, RefreshCw, Save, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import {
  createProduct,
  fetchBrands,
  fetchCategories,
  fetchProduct,
  fetchSubcategories,
  fetchUnits,
  saveProductBarcodes,
  saveProductPrices,
  updateProduct,
} from '../api/catalog'
import { ApiClientError } from '../api/client'
import { useCan } from '../features/auth/useCan'
import './ProductEditorPage.reference.css'

const TABS = ['GENERAL', 'PRICING', 'BARCODES', 'UNITS', 'TAX', 'INVENTORY SETTINGS'] as const

export function ProductEditorPage() {
  const { productUlid } = useParams()
  const isNew = !productUlid || productUlid === 'new'
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const canSave = useCan(isNew ? 'products.create' : 'products.edit')
  const canPrices = useCan('products.manage_prices')
  const canBarcodes = useCan('products.manage_barcodes')
  const [tab, setTab] = useState<(typeof TABS)[number]>('GENERAL')
  const [error, setError] = useState<string | null>(null)
  const [categoryUlid, setCategoryUlid] = useState('')

  const productQuery = useQuery({
    queryKey: ['product', productUlid],
    queryFn: () => fetchProduct(productUlid ?? ''),
    enabled: !isNew,
  })
  const categories = useQuery({ queryKey: ['categories'], queryFn: fetchCategories })
  const subcategories = useQuery({
    queryKey: ['subcategories', categoryUlid],
    queryFn: () => fetchSubcategories(categoryUlid || undefined),
  })
  const brands = useQuery({ queryKey: ['brands'], queryFn: fetchBrands })
  const units = useQuery({ queryKey: ['units'], queryFn: fetchUnits })

  const [name, setName] = useState('')
  const [sku, setSku] = useState('')
  const [subcategoryUlid, setSubcategoryUlid] = useState('')
  const [brandUlid, setBrandUlid] = useState('')
  const [baseUnitUlid, setBaseUnitUlid] = useState('')
  const [secondaryUnitUlid, setSecondaryUnitUlid] = useState('')
  const [secondaryConversion, setSecondaryConversion] = useState('')
  const [taxPercent, setTaxPercent] = useState('0')
  const [trackBatch, setTrackBatch] = useState(false)
  const [trackExpiry, setTrackExpiry] = useState(false)
  const [reorderLevel, setReorderLevel] = useState('')
  const [minimumStock, setMinimumStock] = useState('')
  const [maximumStock, setMaximumStock] = useState('')
  const [rackLocation, setRackLocation] = useState('')
  const [retail, setRetail] = useState('0.0000')
  const [wholesale, setWholesale] = useState('0.0000')
  const [minimumSale, setMinimumSale] = useState('0.0000')
  const [pieceBarcode, setPieceBarcode] = useState('')
  const [packBarcode, setPackBarcode] = useState('')
  const [cartonBarcode, setCartonBarcode] = useState('')
  const { closeActiveTab } = useWorkspace()

  useWorkspaceHandlers({
    save: () => (document.getElementById('product-form') as HTMLFormElement | null)?.requestSubmit(),
    refresh: () => {
      void productQuery.refetch()
    },
  })

  useEffect(() => {
    const product = productQuery.data
    if (!product) {
      return
    }
    setName(product.name)
    setSku(product.sku ?? '')
    setCategoryUlid(product.category?.ulid ?? '')
    setSubcategoryUlid(product.subcategory?.ulid ?? '')
    setBrandUlid(product.brand?.ulid ?? '')
    setBaseUnitUlid(product.base_unit?.ulid ?? '')
    setSecondaryUnitUlid(product.secondary_unit?.ulid ?? '')
    setSecondaryConversion(product.secondary_conversion_factor ?? '')
    setTaxPercent(product.tax_percent)
    setTrackBatch(product.track_batch)
    setTrackExpiry(product.track_expiry)
    setReorderLevel(product.reorder_level ?? '')
    setMinimumStock(product.minimum_stock ?? '')
    setMaximumStock(product.maximum_stock ?? '')
    setRackLocation(product.rack_location ?? '')
    setRetail(product.prices?.find((row) => row.price_type === 'retail')?.amount ?? '0.0000')
    setWholesale(product.prices?.find((row) => row.price_type === 'wholesale')?.amount ?? '0.0000')
    setMinimumSale(product.prices?.find((row) => row.price_type === 'minimum_sale')?.amount ?? '0.0000')
    setPieceBarcode(product.barcodes?.find((row) => row.is_primary)?.barcode ?? '')
  }, [productQuery.data])

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        name,
        sku: sku || null,
        category_ulid: categoryUlid || null,
        subcategory_ulid: subcategoryUlid || null,
        brand_ulid: brandUlid || null,
        base_unit_ulid: baseUnitUlid || units.data?.[0]?.ulid,
        secondary_unit_ulid: secondaryUnitUlid || null,
        secondary_conversion_factor: secondaryConversion || null,
        tax_percent: taxPercent,
        track_batch: trackBatch,
        track_expiry: trackExpiry,
        reorder_level: reorderLevel || null,
        minimum_stock: minimumStock || null,
        maximum_stock: maximumStock || null,
        rack_location: rackLocation || null,
      }
      const product = isNew
        ? await createProduct(payload)
        : await updateProduct(productUlid ?? '', payload)
      const pcs = units.data?.find((unit) => unit.code === 'PCS')?.ulid ?? product.base_unit?.ulid
      const pack = units.data?.find((unit) => unit.code === 'PACK')?.ulid ?? pcs
      const carton = units.data?.find((unit) => unit.code === 'CARTON')?.ulid ?? pcs
      if (pcs && (pieceBarcode || packBarcode || cartonBarcode) && canBarcodes) {
        const barcodes = [
          pieceBarcode ? { barcode: pieceBarcode, unit_ulid: pcs, conversion_factor: '1.00000000', is_primary: true } : null,
          packBarcode ? { barcode: packBarcode, unit_ulid: pack ?? pcs, conversion_factor: '6.00000000', is_primary: !pieceBarcode } : null,
          cartonBarcode ? { barcode: cartonBarcode, unit_ulid: carton ?? pcs, conversion_factor: '24.00000000', is_primary: !pieceBarcode && !packBarcode } : null,
        ].filter((row): row is { barcode: string; unit_ulid: string; conversion_factor: string; is_primary: boolean } => row !== null)
        if (barcodes.length > 0) {
          await saveProductBarcodes(product.ulid, barcodes)
        }
      }
      if (canPrices) {
        await saveProductPrices(product.ulid, [
          { price_type: 'retail', amount: retail },
          { price_type: 'wholesale', amount: wholesale },
          { price_type: 'minimum_sale', amount: minimumSale },
        ])
      }
      return product
    },
    onSuccess: async (product) => {
      await queryClient.invalidateQueries({ queryKey: ['products'] })
      if (isNew) {
        navigate(`/definition/products/${product.ulid}`)
      }
    },
  })

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await saveMutation.mutateAsync()
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to save product.')
    }
  }

  const productNumber = productQuery.data?.product_number ?? (isNew ? 'NEW' : '')
  const tabLabels: Record<(typeof TABS)[number], string> = {
    GENERAL: 'Definition of Product',
    PRICING: 'Pricing',
    BARCODES: 'Barcodes',
    UNITS: 'Units',
    TAX: 'Tax',
    'INVENTORY SETTINGS': 'Inventory Settings',
  }

  return (
    <div className="product-editor-reference">
      <aside className="per-rail" aria-label="Product editor actions">
        <div className="per-photo" title="Product image support will be added later">
          <span>Right Click to +</span>
        </div>

        <button type="button" className="per-rail-small" disabled title="Available in a later phase">
          <Barcode size={18} aria-hidden="true" />
          <span>Barcode Print</span>
        </button>

        <button type="button" className="per-rail-small" disabled title="Available in a later phase">
          <Printer size={18} aria-hidden="true" />
          <span>Current Print</span>
        </button>

        <div className="per-rail-spacer" />

        <button
          type="button"
          className="per-command"
          disabled={!canSave || saveMutation.isPending}
          onClick={() => (document.getElementById('product-form') as HTMLFormElement | null)?.requestSubmit()}
        >
          <span className="per-command-icon is-green"><Save size={20} aria-hidden="true" /></span>
          <span>Save</span>
          <small>[F9]</small>
        </button>

        <button
          type="button"
          className="per-command"
          disabled={isNew}
          onClick={() => void productQuery.refetch()}
        >
          <span className="per-command-icon is-blue"><RefreshCw size={20} aria-hidden="true" /></span>
          <span>Refresh</span>
          <small>[F8]</small>
        </button>

        <button type="button" className="per-command" onClick={closeActiveTab}>
          <span className="per-command-icon is-red"><X size={20} aria-hidden="true" /></span>
          <span>Close</span>
          <small>[Esc]</small>
        </button>
      </aside>

      <section className="per-main">
        <header className="per-heading">
          <h1>Products Definition</h1>
          <div className="per-heading-meta">
            <span>{isNew ? 'New Product' : `Product # ${productNumber}`}</span>
          </div>
        </header>

        <div className="per-tabs" role="tablist" aria-label="Product editor sections">
          {TABS.map((item) => (
            <button
              key={item}
              type="button"
              role="tab"
              aria-selected={tab === item}
              className={tab === item ? 'is-active' : undefined}
              onClick={() => setTab(item)}
            >
              {tabLabels[item]}
            </button>
          ))}
        </div>

        {error ? <div className="per-error" role="alert">{error}</div> : null}

        <form id="product-form" className="per-form" onSubmit={onSubmit}>
          {tab === 'GENERAL' ? (
            <section className="per-section">
              <div className="per-section-title">Product Information</div>

              <div className="per-row per-row-split">
                <label>Product #</label>
                <input className="per-input per-short" readOnly value={productNumber} />

                <label>CODE:</label>
                <input className="per-input" value={sku} onChange={(e) => setSku(e.target.value)} />
              </div>

              <div className="per-row">
                <label>Description</label>
                <input className="per-input" value={name} onChange={(e) => setName(e.target.value)} required />
              </div>

              <div className="per-row">
                <label>Category</label>
                <select
                  className="per-select"
                  value={categoryUlid}
                  onChange={(e) => {
                    setCategoryUlid(e.target.value)
                    setSubcategoryUlid('')
                  }}
                >
                  <option value="">Category</option>
                  {(categories.data ?? []).map((row) => (
                    <option key={row.ulid} value={row.ulid}>{row.name}</option>
                  ))}
                </select>
              </div>

              <div className="per-row">
                <label>Subcategory</label>
                <select className="per-select" value={subcategoryUlid} onChange={(e) => setSubcategoryUlid(e.target.value)}>
                  <option value="">Subcategory</option>
                  {(subcategories.data ?? []).map((row) => (
                    <option key={row.ulid} value={row.ulid}>{row.name}</option>
                  ))}
                </select>
              </div>

              <div className="per-row">
                <label>Company / Brand</label>
                <select className="per-select" value={brandUlid} onChange={(e) => setBrandUlid(e.target.value)}>
                  <option value="">Brand</option>
                  {(brands.data ?? []).map((row) => (
                    <option key={row.ulid} value={row.ulid}>{row.name}</option>
                  ))}
                </select>
              </div>

              <div className="per-row">
                <label>Rack / Location</label>
                <input className="per-input" value={rackLocation} onChange={(e) => setRackLocation(e.target.value)} />
              </div>
            </section>
          ) : null}

          {tab === 'PRICING' ? (
            <section className="per-section">
              <div className="per-section-title">Pricing</div>
              <div className="per-row">
                <label>Sale Rate</label>
                <input className="per-input per-num" value={retail} onChange={(e) => setRetail(e.target.value)} />
              </div>
              <div className="per-row">
                <label>Whole Sale</label>
                <input className="per-input per-num" value={wholesale} onChange={(e) => setWholesale(e.target.value)} />
              </div>
              <div className="per-row">
                <label>Minimum Sale</label>
                <input className="per-input per-num" value={minimumSale} onChange={(e) => setMinimumSale(e.target.value)} />
              </div>
            </section>
          ) : null}

          {tab === 'BARCODES' ? (
            <section className="per-section">
              <div className="per-section-title">Multi Barcode Entry</div>
              <div className="per-row">
                <label>Piece / Primary</label>
                <input className="per-input" value={pieceBarcode} onChange={(e) => setPieceBarcode(e.target.value)} />
              </div>
              <div className="per-row">
                <label>Pack x6</label>
                <input className="per-input" value={packBarcode} onChange={(e) => setPackBarcode(e.target.value)} />
              </div>
              <div className="per-row">
                <label>Carton x24</label>
                <input className="per-input" value={cartonBarcode} onChange={(e) => setCartonBarcode(e.target.value)} />
              </div>
            </section>
          ) : null}

          {tab === 'UNITS' ? (
            <section className="per-section">
              <div className="per-section-title">Units</div>
              <div className="per-row">
                <label>Measure Unit</label>
                <select
                  className="per-select"
                  value={baseUnitUlid || units.data?.[0]?.ulid || ''}
                  onChange={(e) => setBaseUnitUlid(e.target.value)}
                >
                  {(units.data ?? []).map((unit) => (
                    <option key={unit.ulid} value={unit.ulid}>{unit.code} — {unit.name}</option>
                  ))}
                </select>
              </div>

              <div className="per-row">
                <label>Secondary Unit</label>
                <select className="per-select" value={secondaryUnitUlid} onChange={(e) => setSecondaryUnitUlid(e.target.value)}>
                  <option value="">None</option>
                  {(units.data ?? []).map((unit) => (
                    <option key={unit.ulid} value={unit.ulid}>{unit.code} — {unit.name}</option>
                  ))}
                </select>
              </div>

              <div className="per-row">
                <label>Conversion Factor</label>
                <input
                  className="per-input per-num"
                  placeholder="e.g. 24.00000000"
                  value={secondaryConversion}
                  onChange={(e) => setSecondaryConversion(e.target.value)}
                />
              </div>
            </section>
          ) : null}

          {tab === 'TAX' ? (
            <section className="per-section">
              <div className="per-section-title">Tax</div>
              <div className="per-row">
                <label>Tax %</label>
                <input className="per-input per-num" value={taxPercent} onChange={(e) => setTaxPercent(e.target.value)} />
              </div>
            </section>
          ) : null}

          {tab === 'INVENTORY SETTINGS' ? (
            <section className="per-section">
              <div className="per-section-title">Inventory Configuration</div>

              <div className="per-check-row">
                <label>
                  <input type="checkbox" checked={trackBatch} onChange={(e) => setTrackBatch(e.target.checked)} />
                  Batch Tracking
                </label>
                <label>
                  <input type="checkbox" checked={trackExpiry} onChange={(e) => setTrackExpiry(e.target.checked)} />
                  Expiry Tracking
                </label>
              </div>

              <div className="per-row">
                <label>Reorder Level</label>
                <input className="per-input per-num" value={reorderLevel} onChange={(e) => setReorderLevel(e.target.value)} />
              </div>

              <div className="per-row">
                <label>Min Stock</label>
                <input className="per-input per-num" value={minimumStock} onChange={(e) => setMinimumStock(e.target.value)} />
              </div>

              <div className="per-row">
                <label>Max Stock</label>
                <input className="per-input per-num" value={maximumStock} onChange={(e) => setMaximumStock(e.target.value)} />
              </div>

              <div className="per-row">
                <label>Rack / Location</label>
                <input className="per-input" value={rackLocation} onChange={(e) => setRackLocation(e.target.value)} />
              </div>
            </section>
          ) : null}
        </form>
      </section>

      <aside className="per-side-info">
        <div className="per-side-panel">
          <div className="per-side-title">Product Status</div>
          <dl>
            <dt>Mode</dt>
            <dd>{isNew ? 'New Product' : 'Edit Product'}</dd>

            <dt>Product #</dt>
            <dd>{productNumber}</dd>

            <dt>Category</dt>
            <dd>{categories.data?.find((row) => row.ulid === categoryUlid)?.name ?? '—'}</dd>

            <dt>Brand</dt>
            <dd>{brands.data?.find((row) => row.ulid === brandUlid)?.name ?? '—'}</dd>
          </dl>
        </div>

        <div className="per-side-panel">
          <div className="per-side-title">Save Information</div>
          <p>
            Use <strong>F9</strong> or the Save button to store the current product.
          </p>
          <p>
            Price and barcode changes are saved through the existing secured product APIs.
          </p>
        </div>
      </aside>
    </div>
  )
}
