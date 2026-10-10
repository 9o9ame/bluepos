import type { Product } from '../../types/catalog'
import type { SalePriceType } from '../../types/sales'
import './ProductLookupGrid.css'

function productLookupPrice(product: Product, priceType: SalePriceType): string {
  const type = priceType === 'wholesale' ? 'wholesale' : 'retail'
  return product.prices?.find(
    (price) => price.is_active && price.price_type === type,
  )?.amount ?? '—'
}

function productBaseRate(product: Product): string {
  return product.prices?.find(
    (price) => price.is_active && price.price_type === 'minimum_sale',
  )?.amount ?? '—'
}

function productLookupDescription(product: Product): string {
  const barcode =
    product.primary_barcode ??
    product.barcodes?.find((row) => row.is_primary && row.is_active)?.barcode ??
    product.barcodes?.find((row) => row.is_active)?.barcode ??
    ''

  return [barcode, product.name, product.category?.name]
    .filter(Boolean)
    .join(' * ')
}

export function ProductLookupGrid({
  rows,
  total,
  priceType,
  onSelect,
}: {
  rows: Product[]
  total: number
  priceType: SalePriceType
  onSelect: (product: Product) => void
}) {
  return (
    <div
      className="sales-pos-product-results-grid"
      role="listbox"
      aria-label="Choose product"
    >
      <div className="sales-pos-product-grid-head" aria-hidden="true">
        <span>ID</span>
        <span>Description</span>
        <span>In Stock</span>
        <span>Unit Price</span>
        <span>Base Rate</span>
        <span>Cost</span>
        <span>Unit</span>
        <span>Location</span>
      </div>

      <div className="sales-pos-product-grid-body">
        {rows.slice(0, 12).map((row) => (
          <button
            type="button"
            className="sales-pos-product-grid-row"
            key={row.ulid}
            onClick={() => onSelect(row)}
          >
            <span>{row.product_number}</span>
            <strong>{productLookupDescription(row)}</strong>
            <span>
              {row.sales_lookup
                ? Number.parseFloat(row.sales_lookup.in_stock).toFixed(3)
                : '—'}
            </span>
            <span>{productLookupPrice(row, priceType)}</span>
            <span>{productBaseRate(row)}</span>
            <span>{row.sales_lookup?.average_cost ?? '—'}</span>
            <span>{row.base_unit?.symbol ?? row.base_unit?.code ?? '—'}</span>
            <span>{row.rack_location || '—'}</span>
          </button>
        ))}
      </div>

      <div className="sales-pos-product-grid-foot">
        Showing {Math.min(rows.length, 12)} of {total} Products
      </div>
    </div>
  )
}
