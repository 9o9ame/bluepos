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

  const [name, setName] = useState('')
  const [barcode, setBarcode] = useState('')
  const [categoryUlid, setCategoryUlid] = useState('')
  const [brandUlid, setBrandUlid] = useState('')
  const [baseUnitUlid, setBaseUnitUlid] = useState('')
  const [purchaseRate, setPurchaseRate] = useState('')
  const [saleRate, setSaleRate] = useState('')
  const [taxPercent, setTaxPercent] = useState('0')
  const [rackLocation, setRackLocation] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!open) return
    setName('')
    setBarcode('')
    setCategoryUlid('')
    setBrandUlid('')
    setPurchaseRate('')
    setSaleRate('')
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
        name: trimmedName,
        category_ulid: categoryUlid || null,
        brand_ulid: brandUlid || null,
        base_unit_ulid: baseUnitUlid,
        purchase_rate: purchaseRate.trim() || null,
        tax_percent: taxPercent.trim() || '0',
        rack_location: rackLocation.trim() || null,
      })

      if (canPrices && saleRate.trim()) {
        saved = await saveProductPrices(saved.ulid, [
          { price_type: 'retail', amount: saleRate.trim() },
        ])
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

  return (
    <UiModal
      open={open}
      title="Add Product"
      ariaLabel="Add Product"
      onClose={onClose}
      size="lg"
      className="product-modal"
      bodyClassName="product-modal-body"
      footer={
        <>
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
      <form id="global-product-modal-form" className="product-modal-form" onSubmit={submit}>
        {error ? <div className="product-modal-error">{error}</div> : null}

        <label className="product-modal-field is-wide">
          <span>Product Name</span>
          <input
            autoFocus
            value={name}
            maxLength={180}
            disabled={saving}
            onChange={(event) => setName(event.target.value)}
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

        <label className="product-modal-field">
          <span>Measure Unit</span>
          <UiSelect
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
          <span>Category</span>
          <UiSelect
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
          <span>Company / Brand</span>
          <UiSelect
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
          <span>Tax %</span>
          <input
            inputMode="decimal"
            value={taxPercent}
            disabled={saving}
            onChange={(event) => setTaxPercent(event.target.value)}
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
      </form>
    </UiModal>
  )
}
