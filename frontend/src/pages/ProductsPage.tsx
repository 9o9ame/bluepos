import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { deactivateProduct, fetchProducts } from '../api/catalog'
import { useCan } from '../features/auth/useCan'
import { PRODUCT_COLUMNS, type Product } from '../types/catalog'

function cellValue(product: Product, key: string): string {
  switch (key) {
    case 'product_number':
      return product.product_number
    case 'sku':
      return product.sku ?? ''
    case 'primary_barcode':
      return product.primary_barcode ?? ''
    case 'name':
      return product.name
    case 'category':
      return product.category?.name ?? ''
    case 'brand':
      return product.brand?.name ?? ''
    case 'unit':
      return product.base_unit?.code ?? ''
    case 'retail':
      return product.prices?.find((row) => row.price_type === 'retail')?.amount ?? ''
    case 'wholesale':
      return product.prices?.find((row) => row.price_type === 'wholesale')?.amount ?? ''
    case 'tax':
      return product.tax_percent
    case 'status':
      return product.status
    default:
      return ''
  }
}

export function ProductsPage() {
  const queryClient = useQueryClient()
  const canCreate = useCan('products.create')
  const canDelete = useCan('products.delete')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const query = useQuery({
    queryKey: ['products', q, page],
    queryFn: () => fetchProducts({ q, page, per_page: 25 }),
  })
  const columns = useMemo(() => PRODUCT_COLUMNS.filter((column) => column.visible), [])

  return (
    <section className="space-y-3">
      <div className="flex items-center justify-between">
        <h2 className="text-base font-semibold">Products</h2>
        {canCreate ? <Link className="rounded bg-[#1f4e79] px-3 py-1 text-[12px] font-semibold text-white" to="/definition/products/new">Add product</Link> : null}
      </div>
      <input
        className="h-8 w-72 rounded border px-2 text-[12px]"
        placeholder="Search number, SKU, name, barcode"
        value={q}
        onChange={(event) => {
          setQ(event.target.value)
          setPage(1)
        }}
      />
      <div className="overflow-auto rounded border border-slate-300 bg-white">
        <table className="min-w-full text-[12px]">
          <thead className="bg-slate-100">
            <tr>
              {columns.map((column) => (
                <th key={column.key} className="p-2 text-left" style={{ width: column.width }}>{column.label}</th>
              ))}
              <th className="p-2"></th>
            </tr>
          </thead>
          <tbody>
            {(query.data?.data ?? []).map((product) => (
              <tr key={product.ulid} className="border-t">
                {columns.map((column) => (
                  <td key={column.key} className="p-2">{cellValue(product, column.key)}</td>
                ))}
                <td className="p-2 text-right space-x-2">
                  <Link className="text-[#1f4e79]" to={`/definition/products/${product.ulid}`}>View</Link>
                  <Link className="text-[#1f4e79]" to={`/definition/products/${product.ulid}`}>Edit</Link>
                  {canDelete && product.is_active ? (
                    <button
                      type="button"
                      className="rounded border px-2 py-0.5"
                      onClick={() => {
                        void deactivateProduct(product.ulid).then(() => queryClient.invalidateQueries({ queryKey: ['products'] }))
                      }}
                    >
                      Deactivate
                    </button>
                  ) : null}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex gap-2 text-[12px]">
        <button type="button" className="rounded border bg-white px-2 py-1" disabled={(query.data?.meta.current_page ?? 1) <= 1} onClick={() => setPage((current) => current - 1)}>Prev</button>
        <span>Page {query.data?.meta.current_page ?? 1} / {query.data?.meta.last_page ?? 1}</span>
        <button type="button" className="rounded border bg-white px-2 py-1" disabled={(query.data?.meta.current_page ?? 1) >= (query.data?.meta.last_page ?? 1)} onClick={() => setPage((current) => current + 1)}>Next</button>
      </div>
    </section>
  )
}
