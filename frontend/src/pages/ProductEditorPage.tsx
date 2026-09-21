import { FormEvent, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
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

  const field = 'h-8 rounded border px-2 text-[12px]'

  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between">
        <h2 className="text-base font-semibold">{isNew ? 'New product' : `Product ${productQuery.data?.product_number ?? ''}`}</h2>
        <Link className="text-[12px] text-[#1f4e79]" to="/definition/products">Back to products</Link>
      </div>
      {error ? <p className="text-[12px] text-red-700">{error}</p> : null}
      <div className="flex flex-wrap gap-1">
        {TABS.map((item) => (
          <button key={item} type="button" className={`rounded border px-2 py-1 text-[11px] ${tab === item ? 'bg-[#1f4e79] text-white' : 'bg-white'}`} onClick={() => setTab(item)}>{item}</button>
        ))}
      </div>
      <form className="rounded border border-slate-300 bg-white p-3" onSubmit={onSubmit}>
        {tab === 'GENERAL' ? (
          <div className="grid gap-2 md:grid-cols-2">
            <input className={field} placeholder="Name" value={name} onChange={(e) => setName(e.target.value)} required />
            <input className={field} placeholder="SKU" value={sku} onChange={(e) => setSku(e.target.value)} />
            <select className={field} value={categoryUlid} onChange={(e) => {
              setCategoryUlid(e.target.value)
              setSubcategoryUlid('')
            }}>
              <option value="">Category</option>
              {(categories.data ?? []).map((row) => <option key={row.ulid} value={row.ulid}>{row.name}</option>)}
            </select>
            <select className={field} value={subcategoryUlid} onChange={(e) => setSubcategoryUlid(e.target.value)}>
              <option value="">Subcategory</option>
              {(subcategories.data ?? []).map((row) => <option key={row.ulid} value={row.ulid}>{row.name}</option>)}
            </select>
            <select className={field} value={brandUlid} onChange={(e) => setBrandUlid(e.target.value)}>
              <option value="">Brand</option>
              {(brands.data ?? []).map((row) => <option key={row.ulid} value={row.ulid}>{row.name}</option>)}
            </select>
          </div>
        ) : null}
        {tab === 'PRICING' ? (
          <div className="grid gap-2 md:grid-cols-3">
            <label className="text-[12px]">Retail<input className={`${field} w-full`} value={retail} onChange={(e) => setRetail(e.target.value)} /></label>
            <label className="text-[12px]">Wholesale<input className={`${field} w-full`} value={wholesale} onChange={(e) => setWholesale(e.target.value)} /></label>
            <label className="text-[12px]">Minimum sale<input className={`${field} w-full`} value={minimumSale} onChange={(e) => setMinimumSale(e.target.value)} /></label>
          </div>
        ) : null}
        {tab === 'BARCODES' ? (
          <div className="grid gap-2 md:grid-cols-3">
            <label className="text-[12px]">Piece / primary<input className={`${field} w-full`} value={pieceBarcode} onChange={(e) => setPieceBarcode(e.target.value)} /></label>
            <label className="text-[12px]">Pack x6<input className={`${field} w-full`} value={packBarcode} onChange={(e) => setPackBarcode(e.target.value)} /></label>
            <label className="text-[12px]">Carton x24<input className={`${field} w-full`} value={cartonBarcode} onChange={(e) => setCartonBarcode(e.target.value)} /></label>
          </div>
        ) : null}
        {tab === 'UNITS' ? (
          <div className="grid gap-2 md:grid-cols-3">
            <label className="text-[12px]">Base unit
              <select className={`${field} w-full`} value={baseUnitUlid || units.data?.[0]?.ulid || ''} onChange={(e) => setBaseUnitUlid(e.target.value)}>
                {(units.data ?? []).map((unit) => <option key={unit.ulid} value={unit.ulid}>{unit.code} — {unit.name}</option>)}
              </select>
            </label>
            <label className="text-[12px]">Secondary unit
              <select className={`${field} w-full`} value={secondaryUnitUlid} onChange={(e) => setSecondaryUnitUlid(e.target.value)}>
                <option value="">None</option>
                {(units.data ?? []).map((unit) => <option key={unit.ulid} value={unit.ulid}>{unit.code} — {unit.name}</option>)}
              </select>
            </label>
            <label className="text-[12px]">Conversion factor
              <input className={`${field} w-full`} placeholder="e.g. 24.00000000" value={secondaryConversion} onChange={(e) => setSecondaryConversion(e.target.value)} />
            </label>
          </div>
        ) : null}
        {tab === 'TAX' ? (
          <label className="text-[12px]">Tax percent<input className={`${field} ml-2`} value={taxPercent} onChange={(e) => setTaxPercent(e.target.value)} /></label>
        ) : null}
        {tab === 'INVENTORY SETTINGS' ? (
          <div className="grid gap-2 md:grid-cols-2">
            <label className="text-[12px]"><input type="checkbox" checked={trackBatch} onChange={(e) => setTrackBatch(e.target.checked)} /> Batch tracking</label>
            <label className="text-[12px]"><input type="checkbox" checked={trackExpiry} onChange={(e) => setTrackExpiry(e.target.checked)} /> Expiry tracking</label>
            <input className={field} placeholder="Reorder level" value={reorderLevel} onChange={(e) => setReorderLevel(e.target.value)} />
            <input className={field} placeholder="Min stock" value={minimumStock} onChange={(e) => setMinimumStock(e.target.value)} />
            <input className={field} placeholder="Max stock" value={maximumStock} onChange={(e) => setMaximumStock(e.target.value)} />
            <input className={field} placeholder="Rack" value={rackLocation} onChange={(e) => setRackLocation(e.target.value)} />
            <p className="md:col-span-2 text-[11px] text-slate-500">These fields are configuration only. Stock movements are Phase 4.</p>
          </div>
        ) : null}
        <button type="submit" className="mt-3 h-8 rounded bg-[#1f4e79] px-3 text-[12px] font-semibold text-white disabled:opacity-50" disabled={!canSave || saveMutation.isPending}>
          Save
        </button>
      </form>
    </section>
  )
}
