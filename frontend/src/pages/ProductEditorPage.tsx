import { FormEvent, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { RefreshCw, Save, X } from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { DesktopButton, DesktopPanel, Field, FormGroup } from '../components/desktop/DesktopPanel'
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

  return (
    <DesktopPanel
      title={isNew ? 'New product' : `Product ${productQuery.data?.product_number ?? ''}`}
      toolbar={
        <>
          <DesktopButton icon={<Save size={13} />} label="Save" shortcut="F9" disabled={!canSave || saveMutation.isPending} onClick={() => (document.getElementById('product-form') as HTMLFormElement | null)?.requestSubmit()} />
          <DesktopButton icon={<RefreshCw size={13} />} label="Refresh" shortcut="F8" disabled={isNew} onClick={() => void productQuery.refetch()} />
          <DesktopButton icon={<X size={13} />} label="Close" shortcut="Esc" onClick={closeActiveTab} />
        </>
      }
    >
      {error ? <p className="mb-2 text-[12px] text-[var(--danger)]">{error}</p> : null}
      <div className="inner-tabs">
        {TABS.map((item) => (
          <button key={item} type="button" className={`inner-tab${tab === item ? ' is-active' : ''}`} onClick={() => setTab(item)}>{item}</button>
        ))}
      </div>
      <form id="product-form" className="mt-2" onSubmit={onSubmit}>
        {tab === 'GENERAL' ? (
          <FormGroup title="Product information">
            <Field label="Name"><input className="desktop-input" value={name} onChange={(e) => setName(e.target.value)} required /></Field>
            <Field label="SKU"><input className="desktop-input" value={sku} onChange={(e) => setSku(e.target.value)} /></Field>
            <Field label="Category">
              <select className="desktop-select" value={categoryUlid} onChange={(e) => {
                setCategoryUlid(e.target.value)
                setSubcategoryUlid('')
              }}>
                <option value="">Category</option>
                {(categories.data ?? []).map((row) => <option key={row.ulid} value={row.ulid}>{row.name}</option>)}
              </select>
            </Field>
            <Field label="Subcategory">
              <select className="desktop-select" value={subcategoryUlid} onChange={(e) => setSubcategoryUlid(e.target.value)}>
                <option value="">Subcategory</option>
                {(subcategories.data ?? []).map((row) => <option key={row.ulid} value={row.ulid}>{row.name}</option>)}
              </select>
            </Field>
            <Field label="Brand">
              <select className="desktop-select" value={brandUlid} onChange={(e) => setBrandUlid(e.target.value)}>
                <option value="">Brand</option>
                {(brands.data ?? []).map((row) => <option key={row.ulid} value={row.ulid}>{row.name}</option>)}
              </select>
            </Field>
          </FormGroup>
        ) : null}
        {tab === 'PRICING' ? (
          <FormGroup title="Pricing">
            <Field label="Retail"><input className="desktop-input" value={retail} onChange={(e) => setRetail(e.target.value)} /></Field>
            <Field label="Wholesale"><input className="desktop-input" value={wholesale} onChange={(e) => setWholesale(e.target.value)} /></Field>
            <Field label="Minimum sale"><input className="desktop-input" value={minimumSale} onChange={(e) => setMinimumSale(e.target.value)} /></Field>
          </FormGroup>
        ) : null}
        {tab === 'BARCODES' ? (
          <FormGroup title="Barcodes">
            <Field label="Piece / primary"><input className="desktop-input" value={pieceBarcode} onChange={(e) => setPieceBarcode(e.target.value)} /></Field>
            <Field label="Pack x6"><input className="desktop-input" value={packBarcode} onChange={(e) => setPackBarcode(e.target.value)} /></Field>
            <Field label="Carton x24"><input className="desktop-input" value={cartonBarcode} onChange={(e) => setCartonBarcode(e.target.value)} /></Field>
          </FormGroup>
        ) : null}
        {tab === 'UNITS' ? (
          <FormGroup title="Units">
            <Field label="Base unit">
              <select className="desktop-select" value={baseUnitUlid || units.data?.[0]?.ulid || ''} onChange={(e) => setBaseUnitUlid(e.target.value)}>
                {(units.data ?? []).map((unit) => <option key={unit.ulid} value={unit.ulid}>{unit.code} — {unit.name}</option>)}
              </select>
            </Field>
            <Field label="Secondary unit">
              <select className="desktop-select" value={secondaryUnitUlid} onChange={(e) => setSecondaryUnitUlid(e.target.value)}>
                <option value="">None</option>
                {(units.data ?? []).map((unit) => <option key={unit.ulid} value={unit.ulid}>{unit.code} — {unit.name}</option>)}
              </select>
            </Field>
            <Field label="Conversion factor">
              <input className="desktop-input" placeholder="e.g. 24.00000000" value={secondaryConversion} onChange={(e) => setSecondaryConversion(e.target.value)} />
            </Field>
          </FormGroup>
        ) : null}
        {tab === 'TAX' ? (
          <FormGroup title="Tax">
            <Field label="Tax percent"><input className="desktop-input" value={taxPercent} onChange={(e) => setTaxPercent(e.target.value)} /></Field>
          </FormGroup>
        ) : null}
        {tab === 'INVENTORY SETTINGS' ? (
          <FormGroup title="Inventory configuration">
            <label className="desktop-field"><span>Batch tracking</span><input type="checkbox" checked={trackBatch} onChange={(e) => setTrackBatch(e.target.checked)} /></label>
            <label className="desktop-field"><span>Expiry tracking</span><input type="checkbox" checked={trackExpiry} onChange={(e) => setTrackExpiry(e.target.checked)} /></label>
            <Field label="Reorder level"><input className="desktop-input" value={reorderLevel} onChange={(e) => setReorderLevel(e.target.value)} /></Field>
            <Field label="Min stock"><input className="desktop-input" value={minimumStock} onChange={(e) => setMinimumStock(e.target.value)} /></Field>
            <Field label="Max stock"><input className="desktop-input" value={maximumStock} onChange={(e) => setMaximumStock(e.target.value)} /></Field>
            <Field label="Rack"><input className="desktop-input" value={rackLocation} onChange={(e) => setRackLocation(e.target.value)} /></Field>
          </FormGroup>
        ) : null}
      </form>
    </DesktopPanel>
  )
}
