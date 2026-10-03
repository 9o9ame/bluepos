import { useState } from 'react'
import { Package, Plus } from 'lucide-react'
import type { SaleOfferEvaluation } from '../../types/saleSchemes'

type Packaging = SaleOfferEvaluation['packaging'][number]

type Props = {
  packaging: Packaging[]
  /** qty already in the cart for each packaging product. */
  currentQty: Record<string, string>
  onAdd: (product: Packaging, qty: string) => void
}

/**
 * "Add Free Item" — the salesman picks a bag/box and a quantity.
 * The system never adds packaging by itself; the server enforces the cap.
 */
export function PackagingPicker({ packaging, currentQty, onAdd }: Props) {
  const [open, setOpen] = useState(false)
  const [selected, setSelected] = useState('')
  const [qty, setQty] = useState('1')

  const product = packaging.find((row) => row.ulid === selected)

  function submit() {
    if (!product) return
    onAdd(product, qty)
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
        <Package size={13} /> Add Free Item
      </button>
    )
  }

  return (
    <div className="sales-packaging-picker">
      <select
        className="desktop-input"
        value={selected}
        onChange={(e) => setSelected(e.target.value)}
      >
        <option value="">— Select bag / box —</option>
        {packaging.map((row) => (
          <option key={row.ulid} value={row.ulid}>
            {row.product_number} — {row.name}
            {currentQty[row.ulid] ? ` (in cart: ${currentQty[row.ulid]})` : ''}
          </option>
        ))}
      </select>

      <input
        className="desktop-input sales-packaging-qty"
        value={qty}
        onChange={(e) => setQty(e.target.value)}
        inputMode="decimal"
        aria-label="Quantity"
      />

      <button
        type="button"
        className="sales-packaging-add"
        disabled={!product || !qty}
        onClick={submit}
      >
        <Plus size={12} /> Add
      </button>

      <button type="button" className="sales-packaging-cancel" onClick={() => setOpen(false)}>
        Cancel
      </button>

      {product?.max_free_qty_per_sale ? (
        <span className="sales-packaging-hint">
          Max {product.max_free_qty_per_sale} per sale
        </span>
      ) : null}
    </div>
  )
}
