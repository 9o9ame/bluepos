import { useEffect, useMemo, useState } from 'react'
import {
  Check,
  ChevronFirst,
  ChevronLast,
  ChevronLeft,
  ChevronRight,
  Plus,
  RefreshCw,
  Trash2,
  X,
  Barcode,
  Printer,
} from 'lucide-react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { deactivateProduct, fetchProducts } from '../api/catalog'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Product } from '../types/catalog'

export function ProductsPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab, openModule } = useWorkspace()
  const canCreate = useCan('products.create')
  const canDelete = useCan('products.delete')
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [section, setSection] = useState<'definition' | 'opening' | 'related'>('definition')

  const query = useQuery({
    queryKey: ['products', q, page],
    queryFn: () => fetchProducts({ q, page, per_page: 50 }),
  })

  const products = query.data?.data ?? []
  const selected = products.find((product) => product.ulid === selectedKey) ?? null
  const retail = selected?.prices?.find((row) => row.price_type === 'retail')?.amount ?? ''
  const wholesale = selected?.prices?.find((row) => row.price_type === 'wholesale')?.amount ?? ''
  const minSale = selected?.prices?.find((row) => row.price_type === 'minimum_sale')?.amount ?? ''
  const barcodes = selected?.barcodes ?? []

  useEffect(() => {
    if (!selectedKey && products[0]) {
      setSelectedKey(products[0].ulid)
    }
  }, [products, selectedKey])

  useWorkspaceHandlers({
    refresh: () => {
      void query.refetch()
    },
  })

  const gridRows = useMemo(() => products, [products])
  const total = query.data?.meta.total ?? products.length
  const currentPage = query.data?.meta.current_page ?? 1
  const lastPage = query.data?.meta.last_page ?? 1

  return (
    <div className="product-def">
      <aside className="product-def-rail" aria-label="Product actions">
        <button type="button" className="product-def-rail-btn" disabled title="Available in a later phase">
          <Barcode size={18} />
          <span>Barcode Print</span>
        </button>
        <button type="button" className="product-def-rail-btn" disabled title="Available in a later phase">
          <Printer size={18} />
          <span>Current Print</span>
        </button>
        <div className="product-def-rail-spacer" />
        <button
          type="button"
          className="product-def-cmd is-save"
          disabled={!selected}
          title={selected ? 'Open editor to save changes' : 'Select a product'}
          onClick={() => {
            if (selected) openModule(`/definition/products/${selected.ulid}`)
            else if (canCreate) openModule('/definition/products/new')
          }}
        >
          <span className="product-def-cmd-icon is-green"><Check size={22} strokeWidth={3} /></span>
          <span>Save</span>
          <span className="product-def-cmd-key">[F9]</span>
        </button>
        <button type="button" className="product-def-cmd" onClick={() => void query.refetch()}>
          <span className="product-def-cmd-icon is-blue"><RefreshCw size={20} /></span>
          <span>Refresh</span>
          <span className="product-def-cmd-key">[F8]</span>
        </button>
        <button
          type="button"
          className="product-def-cmd"
          disabled={!canDelete || !selected?.is_active}
          onClick={() => {
            if (!selected) return
            void deactivateProduct(selected.ulid).then(() => queryClient.invalidateQueries({ queryKey: ['products'] }))
          }}
        >
          <span className="product-def-cmd-icon is-red"><X size={22} strokeWidth={3} /></span>
          <span>Delete</span>
          <span className="product-def-cmd-key">[F7]</span>
        </button>
        <button type="button" className="product-def-cmd" onClick={closeActiveTab}>
          <span className="product-def-cmd-icon is-red-circle"><X size={18} strokeWidth={3} /></span>
          <span>Close</span>
          <span className="product-def-cmd-key">[Esc]</span>
        </button>
      </aside>

      <section className="product-def-form">
        <h1 className="product-def-title">Products Definition</h1>

        <div className="product-def-tabs">
          <button
            type="button"
            className={section === 'definition' ? 'is-active' : undefined}
            onClick={() => setSection('definition')}
          >
            Definition of Product
          </button>
          <button type="button" disabled title="Available in a later phase">
            Opening Balance (F1)
          </button>
          <button type="button" disabled title="Available in a later phase">
            Products To Be Used With This Product
          </button>
        </div>

        {section === 'definition' ? (
          <div className="product-def-fields">
            <div className="pdf-row pdf-row-split">
              <label>Product #</label>
              <input className="pdf-input pdf-short" readOnly value={selected?.product_number ?? ''} />
              <label className="pdf-right-label">CODE:</label>
              <input className="pdf-input" readOnly value={selected?.sku ?? ''} />
            </div>

            <div className="pdf-row">
              <label>Description</label>
              <input className="pdf-input" readOnly value={selected?.name ?? ''} />
            </div>

            <div className="pdf-row">
              <label>Alternate Desc</label>
              <input className="pdf-input" readOnly value={selected?.alternate_name ?? ''} />
            </div>

            <div className="pdf-row">
              <label>Category</label>
              <select className="pdf-select" disabled value={selected?.category?.ulid ?? ''}>
                <option value="">{selected?.category?.name || '—'}</option>
              </select>
            </div>

            <div className="pdf-row pdf-row-split">
              <label>Supplier</label>
              <input className="pdf-input" disabled placeholder="Later phase" />
              <label className="pdf-right-label">Location</label>
              <input className="pdf-input" readOnly value={selected?.rack_location ?? ''} />
            </div>

            <div className="pdf-row">
              <label>Company</label>
              <select className="pdf-select" disabled value={selected?.brand?.ulid ?? ''}>
                <option value="">{selected?.brand?.name || '—'}</option>
              </select>
            </div>

            <div className="pdf-row pdf-row-split">
              <label>Bar.Grp</label>
              <input className="pdf-input" disabled placeholder="—" />
              <label className="pdf-right-label">Measure Unit</label>
              <select className="pdf-select" disabled value={selected?.base_unit?.ulid ?? ''}>
                <option value="">
                  {selected?.base_unit ? `${selected.base_unit.code} — ${selected.base_unit.name}` : '—'}
                </option>
              </select>
            </div>

            <div className="pdf-row pdf-row-rates">
              <label>Reorder Level</label>
              <input className="pdf-input pdf-num" readOnly value={selected?.reorder_level ?? ''} />
              <label>Purchase Rate</label>
              <input className="pdf-input pdf-num" disabled title="Cost price — later phase" value="" placeholder="—" />
              <label>Margin</label>
              <input className="pdf-input pdf-num" disabled title="Later phase" value="" placeholder="—" />
            </div>

            <div className="pdf-row pdf-row-rates">
              <label>Sale Rate</label>
              <input className="pdf-input pdf-num" readOnly value={retail} />
              <label>Whole Sale</label>
              <input className="pdf-input pdf-num" readOnly value={wholesale} />
              <label>Min Sale</label>
              <input className="pdf-input pdf-num" readOnly value={minSale} />
            </div>

            <div className="pdf-row pdf-row-split">
              <label>In Stock</label>
              <input className="pdf-input pdf-num pdf-readonly" readOnly value="—" title="Stock balances are a later phase" />
              <label className="pdf-right-label">Supplier Code</label>
              <input className="pdf-input" disabled placeholder="—" />
            </div>

            <div className="pdf-row pdf-row-check">
              <label />
              <label className="pdf-check">
                <input type="checkbox" disabled checked={selected ? !selected.is_active : false} readOnly />
                Discontinued
              </label>
              <label className="pdf-right-label">Tax %</label>
              <input className="pdf-input pdf-num" readOnly value={selected?.tax_percent ?? ''} />
            </div>

            <div className="pdf-barcode-box">
              <div className="pdf-barcode-title">Multi Barcode Entry</div>
              <table className="pdf-barcode-table">
                <thead>
                  <tr>
                    <th>Barcode</th>
                    <th>Unit</th>
                    <th>Factor</th>
                    <th>Primary</th>
                  </tr>
                </thead>
                <tbody>
                  {barcodes.length === 0 ? (
                    <tr>
                      <td colSpan={4} className="pdf-barcode-empty">
                        {selected ? 'No barcodes on this product' : 'Select a product'}
                      </td>
                    </tr>
                  ) : (
                    barcodes.map((row) => (
                      <tr key={row.ulid}>
                        <td>{row.barcode}</td>
                        <td>{row.unit?.code ?? ''}</td>
                        <td className="is-num">{row.conversion_factor}</td>
                        <td className="is-center">{row.is_primary ? 'Yes' : ''}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
              <div className="pdf-barcode-nav">
                <button type="button" disabled aria-label="First"><ChevronFirst size={14} /></button>
                <button type="button" disabled aria-label="Previous"><ChevronLeft size={14} /></button>
                <span>Record {barcodes.length ? 1 : 0} of {barcodes.length}</span>
                <button type="button" disabled aria-label="Next"><ChevronRight size={14} /></button>
                <button type="button" disabled aria-label="Last"><ChevronLast size={14} /></button>
                <button type="button" disabled title="Edit in product editor"><Plus size={14} /></button>
                <button type="button" disabled><Trash2 size={14} /></button>
              </div>
            </div>
          </div>
        ) : null}
      </section>

      <section className="product-def-grid">
        <div className="product-def-grid-tools">
          <input
            className="pdf-input"
            style={{ width: 200 }}
            placeholder="Search…"
            value={q}
            onChange={(event) => {
              setQ(event.target.value)
              setPage(1)
            }}
          />
          {canCreate ? (
            <button type="button" className="desktop-btn" onClick={() => openModule('/definition/products/new')}>
              <Plus size={13} /> New
            </button>
          ) : null}
        </div>
        <PosDataGrid
          columns={[
            { key: 'no', header: 'No', width: 64, render: (row: Product) => row.product_number },
            { key: 'name', header: 'Name', render: (row: Product) => row.name },
            {
              key: 'stock',
              header: 'In Stock',
              width: 88,
              align: 'right',
              render: () => (
                <span className="stock-na" title="Stock balances are a later phase">
                  —
                </span>
              ),
            },
          ]}
          rows={gridRows}
          rowKey={(row) => row.ulid}
          selectedKey={selectedKey}
          onSelect={(row) => setSelectedKey(row.ulid)}
          onActivate={(row) => openModule(`/definition/products/${row.ulid}`)}
          emptyMessage="No products found."
        />
        <div className="product-def-grid-foot">
          <span>{total} Products</span>
          <span className="product-def-pager">
            <button type="button" disabled={currentPage <= 1} onClick={() => setPage((n) => n - 1)}>
              &lt;
            </button>
            <span>
              {currentPage}/{lastPage}
            </span>
            <button type="button" disabled={currentPage >= lastPage} onClick={() => setPage((n) => n + 1)}>
              &gt;
            </button>
          </span>
        </div>
      </section>
    </div>
  )
}
