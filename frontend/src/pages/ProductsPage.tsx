import { FormEvent, useEffect, useMemo, useState } from 'react'
import {
  Barcode,
  Check,
  ChevronFirst,
  ChevronLast,
  ChevronLeft,
  ChevronRight,
  Plus,
  Printer,
  RefreshCw,
  Trash2,
  X,
} from 'lucide-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createProduct,
  deactivateProduct,
  fetchBrands,
  fetchCategories,
  fetchProduct,
  fetchProducts,
  fetchUnits,
  saveProductBarcodes,
  saveProductPrices,
  updateProduct,
} from '../api/catalog'
import { ApiClientError } from '../api/client'
import { PosDataGrid } from '../components/desktop/PosDataGrid'
import { useCan } from '../features/auth/useCan'
import { useWorkspace, useWorkspaceHandlers } from '../features/workspace/WorkspaceProvider'
import type { Product } from '../types/catalog'
import './ProductsPage.reference.css'

export function ProductsPage() {
  const queryClient = useQueryClient()
  const { closeActiveTab } = useWorkspace()

  const canCreate = useCan('products.create')
  const canEdit = useCan('products.edit')
  const canDelete = useCan('products.delete')
  const canPrices = useCan('products.manage_prices')
  const canBarcodes = useCan('products.manage_barcodes')

  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [selectedKey, setSelectedKey] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [section, setSection] = useState<'definition' | 'opening' | 'related'>('definition')
  const [error, setError] = useState<string | null>(null)

  const productsQuery = useQuery({
    queryKey: ['products', q, page],
    queryFn: () => fetchProducts({ q, page, per_page: 50 }),
  })

  const products = productsQuery.data?.data ?? []

  const productQuery = useQuery({
    queryKey: ['product', selectedKey],
    queryFn: () => fetchProduct(selectedKey ?? ''),
    enabled: Boolean(selectedKey) && !creating,
  })

  const categories = useQuery({ queryKey: ['categories'], queryFn: fetchCategories })
  const brands = useQuery({ queryKey: ['brands'], queryFn: fetchBrands })
  const units = useQuery({ queryKey: ['units'], queryFn: fetchUnits })

  const selectedRow = products.find((product) => product.ulid === selectedKey) ?? null
  const selected = productQuery.data ?? selectedRow

  const [name, setName] = useState('')
  const [sku, setSku] = useState('')
  const [categoryUlid, setCategoryUlid] = useState('')
  const [brandUlid, setBrandUlid] = useState('')
  const [baseUnitUlid, setBaseUnitUlid] = useState('')
  const [reorderLevel, setReorderLevel] = useState('')
  const [rackLocation, setRackLocation] = useState('')
  const [taxPercent, setTaxPercent] = useState('0')
  const [retail, setRetail] = useState('0.0000')
  const [wholesale, setWholesale] = useState('0.0000')
  const [minimumSale, setMinimumSale] = useState('0.0000')
  const [pieceBarcode, setPieceBarcode] = useState('')
  const [packBarcode, setPackBarcode] = useState('')
  const [cartonBarcode, setCartonBarcode] = useState('')

  const canSave = creating ? canCreate : canEdit && Boolean(selectedKey)

  function resetForm() {
    setName('')
    setSku('')
    setCategoryUlid('')
    setBrandUlid('')
    setBaseUnitUlid(units.data?.[0]?.ulid ?? '')
    setReorderLevel('')
    setRackLocation('')
    setTaxPercent('0')
    setRetail('0.0000')
    setWholesale('0.0000')
    setMinimumSale('0.0000')
    setPieceBarcode('')
    setPackBarcode('')
    setCartonBarcode('')
    setError(null)
  }

  function startNewProduct() {
    if (!canCreate) return
    setCreating(true)
    setSelectedKey(null)
    resetForm()
    setSection('definition')
  }

  useEffect(() => {
    if (creating) return

    if (products.length === 0) {
      setSelectedKey(null)
      return
    }

    if (!selectedKey || !products.some((product) => product.ulid === selectedKey)) {
      setSelectedKey(products[0].ulid)
    }
  }, [creating, products, selectedKey])

  useEffect(() => {
    if (creating || !selected) return

    setName(selected.name ?? '')
    setSku(selected.sku ?? '')
    setCategoryUlid(selected.category?.ulid ?? '')
    setBrandUlid(selected.brand?.ulid ?? '')
    setBaseUnitUlid(selected.base_unit?.ulid ?? '')
    setReorderLevel(selected.reorder_level ?? '')
    setRackLocation(selected.rack_location ?? '')
    setTaxPercent(selected.tax_percent ?? '0')
    setRetail(selected.prices?.find((row) => row.price_type === 'retail')?.amount ?? '0.0000')
    setWholesale(selected.prices?.find((row) => row.price_type === 'wholesale')?.amount ?? '0.0000')
    setMinimumSale(selected.prices?.find((row) => row.price_type === 'minimum_sale')?.amount ?? '0.0000')

    const primary = selected.barcodes?.find((row) => row.is_primary)
    const pack = selected.barcodes?.find((row) => row.unit?.code === 'PACK')
    const carton = selected.barcodes?.find((row) => row.unit?.code === 'CARTON')

    setPieceBarcode(primary?.barcode ?? '')
    setPackBarcode(pack?.barcode ?? '')
    setCartonBarcode(carton?.barcode ?? '')
    setError(null)
  }, [creating, selected])

  const saveMutation = useMutation({
    mutationFn: async () => {
      const current = selected

      const payload = {
        name,
        sku: sku || null,
        category_ulid: categoryUlid || null,
        subcategory_ulid: current?.subcategory?.ulid ?? null,
        brand_ulid: brandUlid || null,
        base_unit_ulid: baseUnitUlid || units.data?.[0]?.ulid,
        secondary_unit_ulid: current?.secondary_unit?.ulid ?? null,
        secondary_conversion_factor: current?.secondary_conversion_factor ?? null,
        tax_percent: taxPercent,
        track_batch: current?.track_batch ?? false,
        track_expiry: current?.track_expiry ?? false,
        reorder_level: reorderLevel || null,
        minimum_stock: current?.minimum_stock ?? null,
        maximum_stock: current?.maximum_stock ?? null,
        rack_location: rackLocation || null,
      }

      const saved = creating
        ? await createProduct(payload)
        : await updateProduct(selectedKey ?? '', payload)

      if (canPrices) {
        await saveProductPrices(saved.ulid, [
          { price_type: 'retail', amount: retail || '0.0000' },
          { price_type: 'wholesale', amount: wholesale || '0.0000' },
          { price_type: 'minimum_sale', amount: minimumSale || '0.0000' },
        ])
      }

      if (canBarcodes) {
        const pcs =
          units.data?.find((unit) => unit.code === 'PCS')?.ulid ??
          saved.base_unit?.ulid ??
          baseUnitUlid

        const pack = units.data?.find((unit) => unit.code === 'PACK')?.ulid ?? pcs
        const carton = units.data?.find((unit) => unit.code === 'CARTON')?.ulid ?? pcs

        if (pcs && (pieceBarcode || packBarcode || cartonBarcode)) {
          const barcodes = [
            pieceBarcode
              ? { barcode: pieceBarcode, unit_ulid: pcs, conversion_factor: '1.00000000', is_primary: true }
              : null,
            packBarcode
              ? { barcode: packBarcode, unit_ulid: pack ?? pcs, conversion_factor: '6.00000000', is_primary: !pieceBarcode }
              : null,
            cartonBarcode
              ? { barcode: cartonBarcode, unit_ulid: carton ?? pcs, conversion_factor: '24.00000000', is_primary: !pieceBarcode && !packBarcode }
              : null,
          ].filter(
            (row): row is {
              barcode: string
              unit_ulid: string
              conversion_factor: string
              is_primary: boolean
            } => row !== null,
          )

          await saveProductBarcodes(saved.ulid, barcodes)
        }
      }

      return saved
    },
    onSuccess: async (saved) => {
      setCreating(false)
      setSelectedKey(saved.ulid)
      await queryClient.invalidateQueries({ queryKey: ['products'] })
      await queryClient.invalidateQueries({ queryKey: ['product', saved.ulid] })
    },
  })

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (!canSave) return

    setError(null)

    try {
      await saveMutation.mutateAsync()
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to save product.')
    }
  }

  useWorkspaceHandlers({
    save: () => {
      ;(document.getElementById('inline-product-form') as HTMLFormElement | null)?.requestSubmit()
    },
    refresh: () => {
      void productsQuery.refetch()
      if (selectedKey) void productQuery.refetch()
    },
  })

  const gridRows = useMemo(() => products, [products])
  const total = productsQuery.data?.meta.total ?? products.length
  const currentPage = productsQuery.data?.meta.current_page ?? 1
  const lastPage = productsQuery.data?.meta.last_page ?? 1
  const productNumber = creating ? 'NEW' : selected?.product_number ?? ''

  return (
    <form id="inline-product-form" className="product-def product-reference-screen" onSubmit={onSubmit}>
      <aside className="product-def-rail" aria-label="Product actions">
        <div className="product-def-photo" title="Product image support will be added later">
          <span>Right Click to +</span>
        </div>

        <button type="button" className="product-def-rail-btn" disabled title="Available in a later phase">
          <Barcode size={18} />
          <span>Barcode Print</span>
        </button>

        <button type="button" className="product-def-rail-btn" disabled title="Available in a later phase">
          <Printer size={18} />
          <span>Current Print</span>
        </button>

        <div className="product-def-rail-spacer" />

        <button type="submit" className="product-def-cmd is-save" disabled={!canSave || saveMutation.isPending}>
          <span className="product-def-cmd-icon is-green"><Check size={22} strokeWidth={3} /></span>
          <span>{creating ? 'Create' : 'Save'}</span>
          <span className="product-def-cmd-key">[F9]</span>
        </button>

        <button
          type="button"
          className="product-def-cmd"
          onClick={() => {
            void productsQuery.refetch()
            if (selectedKey) void productQuery.refetch()
          }}
        >
          <span className="product-def-cmd-icon is-blue"><RefreshCw size={20} /></span>
          <span>Refresh</span>
          <span className="product-def-cmd-key">[F8]</span>
        </button>

        <button
          type="button"
          className="product-def-cmd"
          disabled={creating || !canDelete || !selected?.is_active}
          onClick={() => {
            if (!selectedKey) return
            void deactivateProduct(selectedKey).then(async () => {
              setSelectedKey(null)
              await queryClient.invalidateQueries({ queryKey: ['products'] })
            })
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
          <button type="button" disabled title="Available in a later phase">Opening Balance (F1)</button>
          <button type="button" disabled title="Available in a later phase">Products To Be Used With This Product</button>
        </div>

        {error ? <div className="product-inline-error">{error}</div> : null}

        <div className="product-def-fields">
          <div className="pdf-row pdf-row-split">
            <label>Product #</label>
            <input className="pdf-input pdf-short" readOnly value={productNumber} />
            <label className="pdf-right-label">CODE:</label>
            <input className="pdf-input" value={sku} readOnly={!canSave} onChange={(e) => setSku(e.target.value)} />
          </div>

          <div className="pdf-row">
            <label>Description</label>
            <input className="pdf-input" value={name} readOnly={!canSave} required onChange={(e) => setName(e.target.value)} />
          </div>

          <div className="pdf-row">
            <label>Alternate Desc</label>
            <input className="pdf-input" readOnly value={creating ? '' : selected?.alternate_name ?? ''} />
          </div>

          <div className="pdf-row">
            <label>Category</label>
            <select className="pdf-select" value={categoryUlid} disabled={!canSave} onChange={(e) => setCategoryUlid(e.target.value)}>
              <option value="">—</option>
              {(categories.data ?? []).map((row) => <option key={row.ulid} value={row.ulid}>{row.name}</option>)}
            </select>
          </div>

          <div className="pdf-row pdf-row-split">
            <label>Supplier</label>
            <input className="pdf-input" disabled placeholder="Later phase" />
            <label className="pdf-right-label">Location</label>
            <input className="pdf-input" value={rackLocation} readOnly={!canSave} onChange={(e) => setRackLocation(e.target.value)} />
          </div>

          <div className="pdf-row">
            <label>Company</label>
            <select className="pdf-select" value={brandUlid} disabled={!canSave} onChange={(e) => setBrandUlid(e.target.value)}>
              <option value="">—</option>
              {(brands.data ?? []).map((row) => <option key={row.ulid} value={row.ulid}>{row.name}</option>)}
            </select>
          </div>

          <div className="pdf-row pdf-row-split">
            <label>Bar.Grp</label>
            <input className="pdf-input" disabled placeholder="—" />
            <label className="pdf-right-label">Measure Unit</label>
            <select
              className="pdf-select"
              value={baseUnitUlid || units.data?.[0]?.ulid || ''}
              disabled={!canSave}
              onChange={(e) => setBaseUnitUlid(e.target.value)}
            >
              {(units.data ?? []).map((unit) => <option key={unit.ulid} value={unit.ulid}>{unit.code} — {unit.name}</option>)}
            </select>
          </div>

          <div className="pdf-row pdf-row-rates">
            <label>Reorder Level</label>
            <input className="pdf-input pdf-num" value={reorderLevel} readOnly={!canSave} onChange={(e) => setReorderLevel(e.target.value)} />
            <label>Purchase Rate</label>
            <input className="pdf-input pdf-num" disabled placeholder="—" />
            <label>Margin</label>
            <input className="pdf-input pdf-num" disabled placeholder="—" />
          </div>

          <div className="pdf-row pdf-row-rates">
            <label>Sale Rate</label>
            <input className="pdf-input pdf-num" value={retail} readOnly={!canSave || !canPrices} onChange={(e) => setRetail(e.target.value)} />
            <label>Whole Sale</label>
            <input className="pdf-input pdf-num" value={wholesale} readOnly={!canSave || !canPrices} onChange={(e) => setWholesale(e.target.value)} />
            <label>Min Sale</label>
            <input className="pdf-input pdf-num" value={minimumSale} readOnly={!canSave || !canPrices} onChange={(e) => setMinimumSale(e.target.value)} />
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
            <input className="pdf-input pdf-num" value={taxPercent} readOnly={!canSave} onChange={(e) => setTaxPercent(e.target.value)} />
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
                <tr>
                  <td><input className="pdf-barcode-input" value={pieceBarcode} readOnly={!canSave || !canBarcodes} placeholder="Piece barcode" onChange={(e) => setPieceBarcode(e.target.value)} /></td>
                  <td>PCS</td><td className="is-num">1</td><td className="is-center">{pieceBarcode ? 'Yes' : ''}</td>
                </tr>
                <tr>
                  <td><input className="pdf-barcode-input" value={packBarcode} readOnly={!canSave || !canBarcodes} placeholder="Pack barcode" onChange={(e) => setPackBarcode(e.target.value)} /></td>
                  <td>PACK</td><td className="is-num">6</td><td className="is-center">{!pieceBarcode && packBarcode ? 'Yes' : ''}</td>
                </tr>
                <tr>
                  <td><input className="pdf-barcode-input" value={cartonBarcode} readOnly={!canSave || !canBarcodes} placeholder="Carton barcode" onChange={(e) => setCartonBarcode(e.target.value)} /></td>
                  <td>CARTON</td><td className="is-num">24</td><td className="is-center">{!pieceBarcode && !packBarcode && cartonBarcode ? 'Yes' : ''}</td>
                </tr>
              </tbody>
            </table>

            <div className="pdf-barcode-nav">
              <button type="button" disabled><ChevronFirst size={14} /></button>
              <button type="button" disabled><ChevronLeft size={14} /></button>
              <span>3 barcode slots</span>
              <button type="button" disabled><ChevronRight size={14} /></button>
              <button type="button" disabled><ChevronLast size={14} /></button>
              <button type="button" disabled><Plus size={14} /></button>
              <button type="button" disabled><Trash2 size={14} /></button>
            </div>
          </div>
        </div>
      </section>

      <section className="product-def-grid">
        <div className="product-def-grid-top">
          <div className="product-def-grid-search">
            <input
              className="pdf-input"
              placeholder="Search products..."
              value={q}
              onChange={(e) => {
                setQ(e.target.value)
                setPage(1)
              }}
            />
            {canCreate ? (
              <button type="button" className="desktop-btn product-def-new-btn" onClick={startNewProduct}>
                <Plus size={13} /> New
              </button>
            ) : null}
          </div>
        </div>

        <div className="product-def-groupbar">
          <span className="product-def-groupbar-label">Category / Group</span>
          <span className="product-def-groupbar-value">
            {categories.data?.find((row) => row.ulid === categoryUlid)?.name ?? 'All Products'}
          </span>
          <span className="product-def-groupbar-arrow">▲</span>
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
              render: () => <span className="stock-na" title="Stock balances are a later phase">—</span>,
            },
          ]}
          rows={gridRows}
          rowKey={(row) => row.ulid}
          selectedKey={creating ? null : selectedKey}
          onSelect={(row) => {
            setCreating(false)
            setSelectedKey(row.ulid)
          }}
          onActivate={(row) => {
            setCreating(false)
            setSelectedKey(row.ulid)
          }}
          emptyMessage="No products found."
        />

        <div className="product-def-grid-foot">
          <span>{total} Products</span>
          <span className="product-def-pager">
            <button type="button" disabled={currentPage <= 1} onClick={() => setPage((n) => n - 1)}>&lt;</button>
            <span>{currentPage}/{lastPage}</span>
            <button type="button" disabled={currentPage >= lastPage} onClick={() => setPage((n) => n + 1)}>&gt;</button>
          </span>
        </div>
      </section>
    </form>
  )
}
