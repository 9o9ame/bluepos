import { useDeferredValue, useMemo, useState } from 'react'
import { Download, RefreshCw, X } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { fetchBrands, fetchCategories, fetchProducts } from '../api/catalog'
import { PosDataGrid, type PosGridColumn } from '../components/desktop/PosDataGrid'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useFeedback } from '../feedback/FeedbackProvider'
import type { Product, ProductPrice } from '../types/catalog'
import './PriceListsPage.css'

type PriceType = 'retail' | 'wholesale' | 'minimum_sale'

function activePrice(product: Product, type: PriceType): ProductPrice | null {
  return product.prices?.find((price) => price.price_type === type && price.is_active) ?? null
}

function priceAmount(product: Product, type: PriceType): string {
  return activePrice(product, type)?.amount ?? '—'
}

function productCode(product: Product): string {
  return product.primary_barcode ?? product.sku ?? '—'
}

function unitLabel(product: Product): string {
  return product.base_unit?.symbol ?? product.base_unit?.code ?? '—'
}

function csvSafe(value: string | null | undefined): string {
  const text = String(value ?? '')
  return /^[=+\-@\t\r]/u.test(text) ? `'${text}` : text
}

function csvCell(value: string | null | undefined): string {
  const escaped = csvSafe(value).replace(/"/g, '""')
  return `"${escaped}"`
}

function downloadCsv(filename: string, rows: string[][]) {
  const csv = rows.map((row) => row.map(csvCell).join(',')).join('\r\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}

export function PriceListsPage() {
  const feedback = useFeedback()
  const { closeActiveTab } = useWorkspace()

  const [search, setSearch] = useState('')
  const deferredSearch = useDeferredValue(search)
  const [categoryUlid, setCategoryUlid] = useState('')
  const [brandUlid, setBrandUlid] = useState('')
  const [activeOnly, setActiveOnly] = useState(true)
  const [page, setPage] = useState(1)
  const [exporting, setExporting] = useState(false)

  const categoriesQuery = useQuery({
    queryKey: ['categories'],
    queryFn: fetchCategories,
  })

  const brandsQuery = useQuery({
    queryKey: ['brands'],
    queryFn: fetchBrands,
  })

  const productsQuery = useQuery({
    queryKey: [
      'products',
      'price-lists',
      deferredSearch,
      categoryUlid,
      brandUlid,
      activeOnly,
      page,
    ],
    queryFn: () => fetchProducts(
      {
        q: deferredSearch.trim() || undefined,
        category_ulid: categoryUlid || undefined,
        brand_ulid: brandUlid || undefined,
        active_only: activeOnly,
        page,
        per_page: 50,
      },
      { busy: 'none' },
    ),
  })

  const rows = productsQuery.data?.data ?? []
  const meta = productsQuery.data?.meta

  const columns = useMemo<PosGridColumn<Product>[]>(() => [
    {
      key: 'product_number',
      header: 'Product #',
      width: 92,
      render: (product) => product.product_number,
    },
    {
      key: 'code',
      header: 'Code / Barcode',
      width: 135,
      render: productCode,
    },
    {
      key: 'product',
      header: 'Description',
      width: '26%',
      render: (product) => product.name,
    },
    {
      key: 'brand',
      header: 'Manufacturer',
      width: '13%',
      render: (product) => product.brand?.name ?? '—',
    },
    {
      key: 'category',
      header: 'Category',
      width: '13%',
      render: (product) => product.category?.name ?? '—',
    },
    {
      key: 'unit',
      header: 'U.O.M',
      width: 76,
      render: unitLabel,
    },
    {
      key: 'retail',
      header: 'Retail',
      width: 104,
      align: 'right',
      render: (product) => priceAmount(product, 'retail'),
    },
    {
      key: 'wholesale',
      header: 'Trade',
      width: 104,
      align: 'right',
      render: (product) => priceAmount(product, 'wholesale'),
    },
    {
      key: 'minimum_sale',
      header: 'Salesman',
      width: 104,
      align: 'right',
      render: (product) => priceAmount(product, 'minimum_sale'),
    },
    {
      key: 'status',
      header: 'Status',
      width: 86,
      render: (product) => product.is_active ? 'Active' : 'Inactive',
    },
  ], [])

  async function exportAllFiltered() {
    if (exporting) return

    setExporting(true)
    try {
      const first = await fetchProducts(
        {
          q: deferredSearch.trim() || undefined,
          category_ulid: categoryUlid || undefined,
          brand_ulid: brandUlid || undefined,
          active_only: activeOnly,
          page: 1,
          per_page: 100,
        },
        { busy: 'none' },
      )

      const allProducts = [...first.data]
      for (let nextPage = 2; nextPage <= first.meta.last_page; nextPage += 1) {
        const next = await fetchProducts(
          {
            q: deferredSearch.trim() || undefined,
            category_ulid: categoryUlid || undefined,
            brand_ulid: brandUlid || undefined,
            active_only: activeOnly,
            page: nextPage,
            per_page: 100,
          },
          { busy: 'none' },
        )
        allProducts.push(...next.data)
      }

      const exportRows: string[][] = [
        [
          'Product #',
          'Code / Barcode',
          'Description',
          'Manufacturer',
          'Category',
          'U.O.M',
          'Retail',
          'Trade',
          'Salesman',
          'Currency',
          'Status',
        ],
        ...allProducts.map((product) => {
          const retail = activePrice(product, 'retail')
          const wholesale = activePrice(product, 'wholesale')
          const minimum = activePrice(product, 'minimum_sale')
          const currency = retail?.currency_code ?? wholesale?.currency_code ?? minimum?.currency_code ?? ''

          return [
            product.product_number,
            productCode(product) === '—' ? '' : productCode(product),
            product.name,
            product.brand?.name ?? '',
            product.category?.name ?? '',
            unitLabel(product) === '—' ? '' : unitLabel(product),
            retail?.amount ?? '',
            wholesale?.amount ?? '',
            minimum?.amount ?? '',
            currency,
            product.is_active ? 'Active' : 'Inactive',
          ]
        }),
      ]

      downloadCsv('bluepos-price-lists.csv', exportRows)
      feedback.success(`Exported ${allProducts.length} product price row(s).`, 'Price Lists')
    } catch (error) {
      feedback.fromApiError(error, 'Unable to export Price Lists.')
    } finally {
      setExporting(false)
    }
  }

  function resetPage() {
    setPage(1)
  }

  return (
    <section className="price-lists-page">
      <div className="price-lists-toolbar">
        <div className="price-lists-toolbar-left">
          <label className="price-lists-search">
            <span className="sr-only">Search products</span>
            <input
              value={search}
              placeholder="Search product #, SKU, barcode or name"
              onChange={(event) => {
                setSearch(event.target.value)
                resetPage()
              }}
            />
          </label>

          <UiSelect
            className="price-lists-filter"
            value={categoryUlid}
            options={[
              { value: '', label: 'All categories' },
              ...(categoriesQuery.data ?? []).map((category) => ({
                value: category.ulid,
                label: category.name,
              })),
            ]}
            onChange={(value) => {
              setCategoryUlid(value)
              resetPage()
            }}
            placeholder="All categories"
            aria-label="Price List category filter"
          />

          <UiSelect
            className="price-lists-filter"
            value={brandUlid}
            options={[
              { value: '', label: 'All manufacturers' },
              ...(brandsQuery.data ?? []).map((brand) => ({
                value: brand.ulid,
                label: brand.name,
              })),
            ]}
            onChange={(value) => {
              setBrandUlid(value)
              resetPage()
            }}
            placeholder="All manufacturers"
            aria-label="Price List manufacturer filter"
          />

          <label className="price-lists-active-only">
            <input
              type="checkbox"
              checked={activeOnly}
              onChange={(event) => {
                setActiveOnly(event.target.checked)
                resetPage()
              }}
            />
            Active Only
          </label>
        </div>

        <div className="price-lists-toolbar-right">
          <UiButton variant="info" onClick={() => void productsQuery.refetch()}>
            <RefreshCw size={15} /> Refresh
          </UiButton>
          <UiButton variant="info" onClick={() => void exportAllFiltered()} disabled={exporting}>
            <Download size={15} /> {exporting ? 'Exporting…' : 'Export'}
          </UiButton>
          <UiButton variant="info" onClick={closeActiveTab}>
            <X size={15} /> Close
          </UiButton>
        </div>
      </div>

      <div className="price-lists-grid-card">
        <PosDataGrid
          columns={columns}
          rows={rows}
          rowKey={(product) => product.ulid}
          emptyMessage={productsQuery.isLoading ? 'Loading Price Lists…' : 'No products found.'}
        />
      </div>

      <footer className="price-lists-footer">
        <span>{meta?.total ?? 0} product(s)</span>
        <div className="price-lists-pagination">
          <UiButton
            onClick={() => setPage((current) => Math.max(1, current - 1))}
            disabled={!meta || meta.current_page <= 1}
          >
            Previous
          </UiButton>
          <span>Page {meta?.current_page ?? 1} of {meta?.last_page ?? 1}</span>
          <UiButton
            onClick={() => setPage((current) => Math.min(meta?.last_page ?? current, current + 1))}
            disabled={!meta || meta.current_page >= meta.last_page}
          >
            Next
          </UiButton>
        </div>
      </footer>
    </section>
  )
}
