import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { apiFetch } from '../api/client'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiDateRangePicker } from '../components/ui/UiDateRangePicker'
import { UiSelect } from '../components/ui/UiSelect'
import { UiButton } from '../components/ui/UiButton'
import { UiSkeleton } from '../components/ui/UiSkeleton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './DailyBusinessActivityPage.css'
import './DailyReportSheets.css'

type Kind = 'stock' | 'groups' | 'profit'
type Choice = { ulid: string; name: string }
type Item = { ulid: string; product_number: string; name: string; category: Choice | null; brand: Choice | null }
type StockRow = {
  product: Item; opening_quantity: string; period_change: string; closing_quantity: string
  movements: Record<string, string>; cost_of_sales: string | null; missing_cost: boolean
  sale_quantity: string | null; sale_amount: string | null; gross_profit: string | null
}
type GroupRow = { ulid: string | null; name: string; products: number; sale_quantity: string; sale_amount: string; purchase_quantity: string; purchase_amount: string; stock_change: string }
type ProfitRow = { product: Item; sale_quantity: string; sale_amount: string; cost_amount: string | null; gross_profit: string | null; margin_percent: string | null; sale_rate: string | null; cost_rate: string | null; cost_verified: boolean }
type Report = {
  from: string; to: string; basis: string; limitations: string[]
  rows: StockRow[] | GroupRow[] | ProfitRow[]
  totals?: { sale_amount: string; sale_quantity: string; purchase_amount?: string; purchase_quantity?: string; stock_change?: string; cost_amount?: string | null; gross_profit?: string | null }
  group_by?: string
}
const pathFor: Record<Kind, string> = {
  stock: '/reports/daily/detail-sale-stock-profit',
  groups: '/reports/daily/business-by-groups',
  profit: '/reports/daily/profit-loss',
}
const endpointFor: Record<Kind, string> = {
  stock: '/api/reports/daily/detail-sale-stock-profit',
  groups: '/api/reports/daily/business-by-groups',
  profit: '/api/reports/daily/profit-loss',
}
const titleFor: Record<Kind, string> = {
  stock: 'DETAIL SALE + STOCK + PROFIT LOSS',
  groups: 'BUSINESS BY GROUPS',
  profit: 'DAILY PROFIT LOSS SUMMARY BY PRODUCT',
}
const localDate = () => {
  const d = new Date()
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}
const display = (s: string | null | undefined) => {
  if (s == null) return '— Unavailable —'
  if (!/^-?\d+(?:\.\d+)?$/.test(s)) return s
  const [whole, fraction] = s.split('.')
  const formatted = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return fraction === undefined ? formatted : formatted + '.' + fraction
}

export function ProductDailyReportPage({ kind }: { kind: Kind }) {
  const { openModule, closeActiveTab } = useWorkspace()
  const params = new URLSearchParams(useLocation().search)
  const preview = params.get('view') === 'preview'
  const [from, setFrom] = useState(params.get('from') || localDate())
  const [to, setTo] = useState(params.get('to') || localDate())
  const [period, setPeriod] = useState<'day' | 'month' | 'custom'>(params.get('period') === 'month' ? 'month' : 'custom')
  const [category, setCategory] = useState(params.get('category_ulid') || '')
  const [brand, setBrand] = useState(params.get('brand_ulid') || '')
  const [groupBy, setGroupBy] = useState(params.get('group_by') || 'category')
  const [search, setSearch] = useState('')
  const [zoom, setZoom] = useState(100)
  const paperRef = useRef<HTMLElement>(null)
  const optionsQuery = useQuery({
    queryKey: ['daily-product-report-options'],
    queryFn: () => apiFetch<{ categories: Choice[]; brands: Choice[]; groupings: string[] }>('/api/reports/daily/product-options'),
    enabled: !preview,
  })
  const reportParams = new URLSearchParams()
  for (const key of ['from', 'to', 'category_ulid', 'brand_ulid', 'group_by']) {
    if (params.get(key) && (kind === 'groups' || key !== 'group_by')) reportParams.set(key, params.get(key)!)
  }
  const query = useQuery({
    queryKey: ['daily-product-report', kind, reportParams.toString()],
    queryFn: () => apiFetch<Report>(endpointFor[kind] + '?' + reportParams.toString()),
    enabled: preview && Boolean(params.get('from')) && Boolean(params.get('to')),
  })
  const report = query.data
  const csvSafe = (value: string) => '"' + (/^\s*[=+@-]/.test(value) ? "'" : '') + value.replace(/"/g, '""') + '"'
  const columns: string[] = kind === 'stock'
    ? ['Product', 'Opening Qty', 'Movement', 'Net Qty Change', 'Closing Qty', 'Sold Qty', 'Net Sale', 'Sales Cost', 'Gross Profit']
    : kind === 'groups'
      ? ['Group', 'Products', 'Purchases Qty', 'Purchase Amount ex Tax', 'Sales Qty', 'Sale Amount ex Tax', 'Net Stock Change']
      : ['Product', 'Sale Qty', 'Sale Amount ex Tax', 'Cost Amount', 'Sale Rate', 'Cost Rate', 'Gross Profit', 'Margin %']
  const tableRows = (): string[][] => {
    if (!report) return []
    if (kind === 'stock') return (report.rows as StockRow[]).map(row => [
      row.product.name, row.opening_quantity,
      Object.entries(row.movements).map(([k, v]) => k + ': ' + v).join(' / '),
      row.period_change, row.closing_quantity, row.sale_quantity ?? 'Unavailable',
      row.sale_amount ?? 'Unavailable', row.cost_of_sales ?? 'Unavailable',
      row.gross_profit ?? 'Unavailable',
    ])
    if (kind === 'groups') return (report.rows as GroupRow[]).map(row => [
      row.name, String(row.products), row.purchase_quantity, row.purchase_amount, row.sale_quantity, row.sale_amount, row.stock_change,
    ])
    return (report.rows as ProfitRow[]).map(row => [
      row.product.name, row.sale_quantity, row.sale_amount, row.cost_amount ?? 'Unavailable',
      row.sale_rate ?? 'Unavailable', row.cost_rate ?? 'Unavailable',
      row.gross_profit ?? 'Unavailable', row.margin_percent ?? 'Unavailable',
    ])
  }
  const exportReport = (kindOf: 'txt' | 'html' | 'csv') => {
    if (!paperRef.current || !report) return
    const content = kindOf === 'csv'
      ? '\uFEFF' + [columns, ...tableRows()].map(row => row.map(csvSafe).join(',')).join('\r\n')
      : kindOf === 'html'
        ? '<!doctype html><html><head><meta charset="utf-8"></head><body>' +
          paperRef.current.innerHTML + '</body></html>'
        : paperRef.current.innerText
    const url = URL.createObjectURL(new Blob([content], {
      type: kindOf === 'csv' ? 'text/csv;charset=utf-8' : kindOf === 'html' ? 'text/html;charset=utf-8' : 'text/plain;charset=utf-8',
    }))
    const link = document.createElement('a')
    link.href = url
    link.download = kind + '-daily-report.' + kindOf
    link.click()
    URL.revokeObjectURL(url)
  }
  const find = () => {
    const term = search.trim().toLowerCase()
    if (!term) return
    Array.from(paperRef.current?.querySelectorAll('td,th,h1,h2') ?? [])
      .find(node => node.textContent?.toLowerCase().includes(term))
      ?.scrollIntoView({ block: 'center' })
  }
  if (!preview) return <section className="dba-page">
    <div className="dba-select-card">
      <h2>{titleFor[kind]} — Selection Options</h2>
      <div className="dba-fields">
        <div>
          <label className="desktop-field"><span>Category</span>
            <UiSelect value={category} onChange={setCategory} aria-label="Category"
              triggerClassName="desktop-select" options={[
                { value: '', label: 'All Categories' },
                ...(optionsQuery.data?.categories ?? []).map(option => ({ value: option.ulid, label: option.name })),
              ]} />
          </label>
          <label className="desktop-field"><span>Company / Brand</span>
            <UiSelect value={brand} onChange={setBrand} aria-label="Brand"
              triggerClassName="desktop-select" options={[
                { value: '', label: 'All Brands' },
                ...(optionsQuery.data?.brands ?? []).map(option => ({ value: option.ulid, label: option.name })),
              ]} />
          </label>
          {kind === 'groups' && <label className="desktop-field"><span>Group by</span>
            <UiSelect value={groupBy} onChange={setGroupBy} aria-label="Group by"
              triggerClassName="desktop-select"
              options={[{ value: 'category', label: 'Category' }, { value: 'brand', label: 'Company / Brand' }]} />
          </label>}
        </div>
        {kind === 'profit' && <label className="desktop-field"><span>Period</span>
          <UiSelect searchable={false} value={period}
            options={[{value:'day',label:'Current Day'},{value:'month',label:'Current Month'},{value:'custom',label:'Custom (up to 31 days)'}]}
            onChange={(value) => {
              const selected = value as 'day' | 'month' | 'custom'
              setPeriod(selected)
              const date = localDate()
              if (selected === 'day') { setFrom(date); setTo(date) }
              if (selected === 'month') {
                const [year, month] = date.split('-').map(Number)
                setFrom(date.slice(0, 7) + '-01')
                setTo(`${year}-${String(month).padStart(2, '0')}-${String(new Date(year, month, 0).getDate()).padStart(2, '0')}`)
              }
            }}/>
        </label>}
        <div className="dba-dates">
          <label><span>Date range (maximum 31 days)</span>
            <UiDateRangePicker
              fromInputProps={{ type: 'date', value: from, onChange: event => setFrom(event.target.value) }}
              toInputProps={{ type: 'date', value: to, onChange: event => setTo(event.target.value) }}
            />
          </label>
        </div>
      </div>
      {optionsQuery.isLoading && <UiSkeleton label="Loading report options…" />}
      {optionsQuery.isError && <p role="alert">Unable to load report filters.</p>}
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab} />
      <UiButton variant="info" label="View Report"
        disabled={optionsQuery.isLoading || optionsQuery.isError || !from || !to || from > to}
        onClick={() => {
          const values = new URLSearchParams({ from, to })
          if (category) values.set('category_ulid', category)
          if (brand) values.set('brand_ulid', brand)
          if (kind === 'groups') values.set('group_by', groupBy)
          if (kind === 'profit') values.set('period', period)
          openModule(pathFor[kind] + '?view=preview&' + values.toString())
        }}
      />
    </div>
  </section>
  return <section className="dba-page dba-preview">
    <ReportViewerToolbar
      ready={Boolean(report)} busy={query.isFetching}
      search={search} onSearchChange={setSearch} onFind={find}
      zoom={zoom} onZoomChange={setZoom}
      onPrint={() => window.print()}
      onCopy={async () => { if (paperRef.current) await navigator.clipboard.writeText(paperRef.current.innerText) }}
      onExport={exportReport}
      onRefresh={() => void query.refetch()}
      onEditOptions={() => openModule(pathFor[kind])}
      onClose={closeActiveTab}
    />
    {query.isLoading && <UiSkeleton label="Loading product daily report…" />}
    {query.isError && <p role="alert">Unable to load product daily report.</p>}
    {report && <article className="dba-paper daily-report-sheet" ref={paperRef} style={{ zoom: zoom + '%' }}>
      <header><h1>{kind === 'profit' && params.get('period') === 'month'
        ? 'MONTHLY PROFIT LOSS SUMMARY BY PRODUCT' : titleFor[kind]}</h1><p>From {report.from} to {report.to}</p></header>
      <table>
        <thead><tr>{columns.map(column => <th key={column}>{column}</th>)}</tr></thead>
        <tbody>
          {tableRows().map((row, i) => <tr key={i}>{row.map((value, j) =>
            <td key={j} className={j > 0 ? 'dba-amount' : undefined}>{display(value)}</td>)}</tr>)}
          {report.rows.length === 0 && <tr><td colSpan={columns.length}>No matching posted activity for this period.</td></tr>}
        </tbody>
        {report.totals && <tfoot><tr>
          <th>Totals</th>
          {kind === 'groups'
            ? <><td>—</td>
                <td className="dba-amount">{display(report.totals.purchase_quantity)}</td>
                <td className="dba-amount">{display(report.totals.purchase_amount)}</td>
                <td className="dba-amount">{display(report.totals.sale_quantity)}</td>
                <td className="dba-amount">{display(report.totals.sale_amount)}</td>
                <td className="dba-amount">{display(report.totals.stock_change)}</td></>
            : <><td className="dba-amount">{display(report.totals.sale_quantity)}</td>
                <td className="dba-amount">{display(report.totals.sale_amount)}</td>
                <td className="dba-amount">{display(report.totals.cost_amount)}</td>
                <td className="dba-amount">{display(report.totals.gross_profit)}</td>
                <td>—</td></>}
        </tr></tfoot>}
      </table>
      {report.limitations.map(note => <p key={note}>{note}</p>)}
    </article>}
  </section>
}
