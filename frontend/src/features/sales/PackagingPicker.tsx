import { useState } from 'react'
import { Package, Plus } from 'lucide-react'
import type { SaleOfferEvaluation } from '../../types/saleSchemes'
import { UiSelect } from '../../components/ui/UiSelect'

type Packaging = SaleOfferEvaluation['packaging'][number]

type Props = {
  packaging: Packaging[]
  currentQty: Record<string, string>
  onAdd: (product: Packaging, qty: string) => void
}

function isPositiveQuantity(value: string): boolean {
  if (!/^(?:0|[1-9]\d*)(?:\.\d{1,6})?$/.test(value)) {
    return false
  }

  return Number(value) > 0
}

export function PackagingPicker({
  packaging,
  currentQty,
  onAdd,
}: Props) {
  const [open, setOpen] = useState(false)
  const [selected, setSelected] = useState('')
  const [qty, setQty] = useState('1')

  const product = packaging.find(
    (row) => row.ulid === selected,
  )

  const existingQty = product
    ? Number(currentQty[product.ulid] ?? 0)
    : 0

  const requestedQty = Number(qty || 0)

  const maxQty = product?.max_free_qty_per_sale
    ? Number(product.max_free_qty_per_sale)
    : null

  const exceedsMaximum =
    maxQty !== null &&
    existingQty + requestedQty > maxQty

  const invalidQuantity =
    !isPositiveQuantity(qty) ||
    exceedsMaximum

  function submit() {
    if (!product || invalidQuantity) {
      return
    }

    onAdd(product, Number(qty).toFixed(6))

    setOpen(false)
    setSelected('')
    setQty('1')
  }

  function closePicker() {
    setOpen(false)
    setSelected('')
    setQty('1')
  }

  if (packaging.length === 0) {
    return null
  }

  if (!open) {
    return (
      <button
        type="button"
        className="sales-packaging-trigger"
        onClick={() => setOpen(true)}
      >
        <Package size={13} />
        Add Free Item
      </button>
    )
  }

  return (
    <div className="sales-packaging-picker">
      <UiSelect
        className="desktop-input"
        aria-label="Packaging product"
        value={selected}
        options={[
          { value: '', label: '— Select bag / box —' },
          ...packaging.map((row) => ({
            value: row.ulid,
            label: `${row.product_number} — ${row.name}`,
          })),
        ]}
        onChange={(value) => {
          setSelected(value)
          setQty('1')
        }}
      />

      <input
        type="number"
        className="desktop-input sales-packaging-qty"
        value={qty}
        onChange={(event) =>
          setQty(event.target.value)
        }
        min="0.000001"
        step="0.000001"
        max={product?.max_free_qty_per_sale ?? undefined}
        inputMode="decimal"
        aria-label="Free item quantity"
      />

      <button
        type="button"
        className="sales-packaging-add"
        disabled={!product || invalidQuantity}
        onClick={submit}
      >
        <Plus size={12} />
        Add
      </button>

      <button
        type="button"
        className="sales-packaging-cancel"
        onClick={closePicker}
      >
        Cancel
      </button>

      {product?.max_free_qty_per_sale ? (
        <span className="sales-packaging-hint">
          Max {product.max_free_qty_per_sale} per sale
          {existingQty > 0
            ? ` · Already added: ${existingQty}`
            : ''}
        </span>
      ) : null}

      {exceedsMaximum ? (
        <span className="sales-packaging-error">
          Maximum free quantity exceeded.
        </span>
      ) : null}
    </div>
  )
}