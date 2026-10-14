import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createProduct,
  fetchBrands,
  fetchCategories,
  fetchProduct,
  fetchUnits,
  saveProductBarcodes,
  saveProductPrices,
} from '../../api/catalog'
import { ApiClientError } from '../../api/client'
import type { Product } from '../../types/catalog'
import { useCan } from '../../features/auth/useCan'
import { useAuth } from '../../features/auth/AuthProvider'
import { useLocalSuppliers } from '../../features/catalog/useLocalCatalog'
import { UiButton } from '../ui/UiButton'
import { UiModal } from '../ui/UiModal'
import { UiSelect } from '../ui/UiSelect'
import './ProductModal.css'

export function ProductModal({
  open = true,
  onClose,
  onSaved,
}: {
  open?: boolean
  onClose: () => void
  onSaved: (product: Product) => void
}) {
  const queryClient = useQueryClient()
  const { session } = useAuth()
  const canCreate = useCan('products.create')
  const canPrices = useCan('products.manage_prices')
  const canBarcodes = useCan('products.manage_barcodes')

  const unitsQuery = useQuery({
    queryKey: ['units', 'product-modal'],
    queryFn: fetchUnits,
    enabled: open,
  })
  const categoriesQuery = useQuery({
    queryKey: ['categories', 'product-modal'],
    queryFn: fetchCategories,
    enabled: open,
  })
  const brandsQuery = useQuery({
    queryKey: ['brands', 'product-modal'],
    queryFn: fetchBrands,
    enabled: open,
  })
  const suppliersQuery = useLocalSuppliers(session, open)

  const units = useMemo(
    () => (unitsQuery.data ?? []).filter((row) => row.is_active),
    [unitsQuery.data],
  )
  const categories = useMemo(
    () => (categoriesQuery.data ?? []).filter((row) => row.is_active),
    [categoriesQuery.data],
  )
  const brands = useMemo(
    () => (brandsQuery.data ?? []).filter((row) => row.is_active),
    [brandsQuery.data],
  )

  const [productNumber, setProductNumber] = useState('')
  const [sku, setSku] = useState('')
  const [name, setName] = useState('')
  const [alternateName, setAlternateName] = useState('')
  const [barcode, setBarcode] = useState('')
  const [categoryUlid, setCategoryUlid] = useState('')
  const [brandUlid, setBrandUlid] = useState('')
  const [primarySupplierUlid, setPrimarySupplierUlid] = useState('')
  const [supplierProductCode, setSupplierProductCode] = useState('')
  const [baseUnitUlid, setBaseUnitUlid] = useState('')
  const [reorderLevel, setReorderLevel] = useState('')
  const [purchaseRate, setPurchaseRate] = useState('')
  const [saleRate, setSaleRate] = useState('')
  const [wholesaleRate, setWholesaleRate] = useState('')
  const [taxPercent, setTaxPercent] = useState('0')
  const [rackLocation, setRackLocation] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!open) return
    setProductNumber('')
    setSku('')
    setName('')
    setAlternateName('')
    setBarcode('')
    setCategoryUlid('')
    setBrandUlid('')
    setPrimarySupplierUlid('')
    setSupplierProductCode('')
    setReorderLevel('')
    setPurchaseRate('')
    setSaleRate('')
    setWholesaleRate('')
    setTaxPercent('0')
    setRackLocation('')
    setError(null)
  }, [open])

  useEffect(() => {
    if (!open || baseUnitUlid || units.length === 0) return
    setBaseUnitUlid(units[0].ulid)
  }, [baseUnitUlid, open, units])

  async function submit(event: FormEvent) {
    event.preventDefault()
    if (!canCreate || saving) return

    const trimmedName = name.trim()
    if (!trimmedName) {
      setError('Product name is required.')
      return
    }
    if (!baseUnitUlid) {
      setError('Measure unit is required.')
      return
    }

    setSaving(true)
    setError(null)

    try {
      let saved = await createProduct({
        product_number: productNumber.trim() || null,
        sku: sku.trim() || null,
        name: trimmedName,
        alternate_name: alternateName.trim() || null,
        category_ulid: categoryUlid || null,
        brand_ulid: brandUlid || null,
        primary_supplier_ulid: primarySupplierUlid || null,
        supplier_product_code: supplierProductCode.trim() || null,
        base_unit_ulid: baseUnitUlid,
        reorder_level: reorderLevel.trim() || null,
        purchase_rate: purchaseRate.trim() || null,
        tax_percent: taxPercent.trim() || '0',
        rack_location: rackLocation.trim() || null,
      })

      const prices = [
        saleRate.trim()
          ? { price_type: 'retail' as const, amount: saleRate.trim() }
          : null,
        wholesaleRate.trim()
          ? { price_type: 'wholesale' as const, amount: wholesaleRate.trim() }
          : null,
      ].filter((row): row is { price_type: 'retail' | 'wholesale'; amount: string } => Boolean(row))

      if (canPrices && prices.length > 0) {
        saved = await saveProductPrices(saved.ulid, prices)
      }

      if (canBarcodes && barcode.trim()) {
        saved = await saveProductBarcodes(saved.ulid, [
          {
            barcode: barcode.trim(),
            unit_ulid: baseUnitUlid,
            conversion_factor: '1.00000000',
            is_primary: true,
          },
        ])
      }

      saved = await fetchProduct(saved.ulid)
      await queryClient.invalidateQueries({ queryKey: ['products'] })
      onSaved(saved)
    } catch (err) {
      setError(
        err instanceof ApiClientError
          ? err.message
          : err instanceof Error
            ? err.message
            : 'Unable to create product.',
      )
    } finally {
      setSaving(false)
    }
  }

  const suppliers = suppliersQuery.rows.filter((row) => row.is_active)

  return (
    <UiModal
      open={open}
      title="Products"
      ariaLabel="Product Definition"
      onClose={onClose}
      size="xl"
      className="product-modal product-definition-modal"
      bodyClassName="product-modal-body"
      footer={
        <>
          <UiButton variant="info" label="Refresh" disabled={saving} onClick={() => {
            setError(null)
            void unitsQuery.refetch()
            void categoriesQuery.refetch()
            void brandsQuery.refetch()
            void suppliersQuery.refetch()
          }} />
          <UiButton variant="info" label="Close" disabled={saving} onClick={onClose} />
          <UiButton
            variant="success"
            label={saving ? 'Saving…' : 'Save Product'}
            disabled={!canCreate || saving}
            onClick={() => {
              const form = document.getElementById('global-product-modal-form') as HTMLFormElement | null
              form?.requestSubmit()
            }}
          />
        </>
      }
    >
      <form id="global-product-modal-form" className="product-definition-form" onSubmit={submit}>
        {error ? <div className="product-modal-error">{error}</div> : null}

        <div className="product-definition-heading">
          <div>
            <strong>Product Definition</strong>
            <span>Create a product for the current tenant using the active catalogue setup.</span>
          </div>
        </div>

        <div className="product-definition-tabs" role="tablist" aria-label="Product definition sections">
          <button type="button" className="is-active" role="tab" aria-selected="true">
            Definition of Product
          </button>
          <button type="button" role="tab" aria-selected="false" disabled>
            Opening Balance
          </button>
          <button type="button" role="tab" aria-selected="false" disabled>
            Products To Be Used With This Product
          </button>
        </div>

        <div className="product-definition-workspace">
          <section className="product-definition-panel product-definition-main">
            <div className="product-definition-section-title">Product Information</div>

            <div className="product-definition-fields">
              <label className="product-modal-field">
                <span>Product #</span>
                <input
                  value={productNumber}
                  maxLength={64}
                  disabled={saving}
                  onChange={(event) => setProductNumber(event.target.value)}
                  placeholder="Auto if blank"
                />
              </label>

              <label className="product-modal-field">
                <span>Code / SKU</span>
                <input
                  value={sku}
                  maxLength={64}
                  disabled={saving}
                  onChange={(event) => setSku(event.target.value)}
                />
              </label>

              <label className="product-modal-field is-wide">
                <span>Description</span>
                <input
                  autoFocus
                  value={name}
                  maxLength={180}
                  disabled={saving}
                  onChange={(event) => setName(event.target.value)}
                />
              </label>

              <label className="product-modal-field is-wide">
                <span>Alternate Desc.</span>
                <input
                  value={alternateName}
                  maxLength={180}
                  disabled={saving}
                  onChange={(event) => setAlternateName(event.target.value)}
                />
              </label>

              <label className="product-modal-field">
                <span>Category</span>
                <UiSelect
                  menuMotion="reveal"
                  aria-label="Product category"
                  value={categoryUlid}
                  disabled={saving}
                  options={[
                    { value: '', label: 'No category' },
                    ...categories.map((row) => ({ value: row.ulid, label: row.name })),
                  ]}
                  onChange={setCategoryUlid}
                />
              </label>

              <label className="product-modal-field">
                <span>Supplier</span>
                <UiSelect
                  menuMotion="reveal"
                  aria-label="Primary supplier"
                  value={primarySupplierUlid}
                  disabled={saving}
                  options={[
                    { value: '', label: 'No supplier' },
                    ...suppliers.map((row) => ({ value: row.ulid, label: row.name })),
                  ]}
                  onChange={setPrimarySupplierUlid}
                />
              </label>

              <label className="product-modal-field">
                <span>Company / Brand</span>
                <UiSelect
                  menuMotion="reveal"
                  aria-label="Product brand"
                  value={brandUlid}
                  disabled={saving}
                  options={[
                    { value: '', label: 'No brand' },
                    ...brands.map((row) => ({ value: row.ulid, label: row.name })),
                  ]}
                  onChange={setBrandUlid}
                />
              </label>

              <label className="product-modal-field">
                <span>Location</span>
                <input
                  value={rackLocation}
                  maxLength={64}
                  disabled={saving}
                  onChange={(event) => setRackLocation(event.target.value)}
                />
              </label>

              <label className="product-modal-field">
                <span>Reorder Level</span>
                <input
                  inputMode="decimal"
                  value={reorderLevel}
                  disabled={saving}
                  onChange={(event) => setReorderLevel(event.target.value)}
                  placeholder="0.000000"
                />
              </label>

              <label className="product-modal-field">
                <span>Purchase Rate</span>
                <input
                  inputMode="decimal"
                  value={purchaseRate}
                  disabled={saving}
                  onChange={(event) => setPurchaseRate(event.target.value)}
                  placeholder="0.0000"
                />
              </label>

              <label className="product-modal-field">
                <span>Sale Rate</span>
                <input
                  inputMode="decimal"
                  value={saleRate}
                  disabled={saving || !canPrices}
                  onChange={(event) => setSaleRate(event.target.value)}
                  placeholder={canPrices ? '0.0000' : 'No permission'}
                />
              </label>

              <label className="product-modal-field">
                <span>Wholesale</span>
                <input
                  inputMode="decimal"
                  value={wholesaleRate}
                  disabled={saving || !canPrices}
                  onChange={(event) => setWholesaleRate(event.target.value)}
                  placeholder={canPrices ? '0.0000' : 'No permission'}
                />
              </label>

              <label className="product-modal-field">
                <span>Measure Unit</span>
                <UiSelect
                  menuMotion="reveal"
                  aria-label="Product measure unit"
                  value={baseUnitUlid}
                  disabled={saving}
                  options={units.map((row) => ({
                    value: row.ulid,
                    label: row.symbol ? `${row.code} — ${row.symbol}` : row.code,
                  }))}
                  onChange={setBaseUnitUlid}
                />
              </label>

              <label className="product-modal-field">
                <span>Supplier Code</span>
                <input
                  value={supplierProductCode}
                  maxLength={100}
                  disabled={saving}
                  onChange={(event) => setSupplierProductCode(event.target.value)}
                />
              </label>

              <label className="product-modal-field">
                <span>Tax %</span>
                <input
                  inputMode="decimal"
                  value={taxPercent}
                  disabled={saving}
                  onChange={(event) => setTaxPercent(event.target.value)}
                />
              </label>

              <label className="product-modal-field">
                <span>Barcode</span>
                <input
                  value={barcode}
                  maxLength={64}
                  disabled={saving || !canBarcodes}
                  onChange={(event) => setBarcode(event.target.value)}
                  placeholder={canBarcodes ? 'Optional' : 'No permission'}
                />
              </label>
            </div>
          </section>

          <aside className="product-definition-side">
            <section className="product-definition-panel">
              <div className="product-definition-section-title">Product Setup</div>
              <dl className="product-definition-summary">
                <div><dt>Name</dt><dd>{name.trim() || 'New product'}</dd></div>
                <div><dt>Category</dt><dd>{categories.find((row) => row.ulid === categoryUlid)?.name ?? '—'}</dd></div>
                <div><dt>Company</dt><dd>{brands.find((row) => row.ulid === brandUlid)?.name ?? '—'}</dd></div>
                <div><dt>Supplier</dt><dd>{suppliers.find((row) => row.ulid === primarySupplierUlid)?.name ?? '—'}</dd></div>
                <div><dt>Unit</dt><dd>{units.find((row) => row.ulid === baseUnitUlid)?.symbol ?? '—'}</dd></div>
              </dl>
            </section>

            <section className="product-definition-panel product-definition-related">
              <div className="product-definition-section-title">Products To Be Used With This Product</div>
              <div className="product-definition-table-head">
                <span>No</span>
                <span>Name</span>
                <span>In Stock</span>
              </div>
              <div className="product-definition-empty">
                Related-product linking is available after the product is created.
              </div>
            </section>

            <section className="product-definition-panel product-definition-barcode">
              <div className="product-definition-section-title">Multi Barcode Entry</div>
              <div className="product-definition-table-head is-barcode">
                <span>Barcode</span>
                <span>Factor</span>
              </div>
              <div className="product-definition-barcode-row">
                <span>{barcode.trim() || 'Primary barcode'}</span>
                <span>1.00000000</span>
              </div>
            </section>
          </aside>
        </div>
      </form>
    </UiModal>
  )
}
