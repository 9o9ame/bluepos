import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useLocation } from 'react-router-dom'
import { apiFetch } from '../api/client'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import './TopSpecialReportPage.css'

type Option = { ulid: string; name: string }
type Options = {
  categories: Option[]; brands: Option[]; customers: Option[]; suppliers: Option[]
  salespeople: Option[]; areas: string[]
}
type Kind = 'customers' | 'vendors' | 'products' | 'salespeople'
type Ranked = { rank: number; description: string; qty: string; amount: string }
type Report = { from: string; to: string; kind: Kind; format: string; ranking: Ranked[] }
function localDate() {
  const d = new Date()
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}
function number(value: string) {
  const negative = value.startsWith('-')
  const digits = negative ? value.slice(1) : value
  const [a, b = ''] = digits.split('.')
  const decimals = b.slice(0, 6).replace(/0+$/, '')
  return (negative ? '(' : '') + (a || '0').replace(/\B(?=(\d{3})+(?!\d))/g, ',') +
    (decimals ? '.' + decimals : '') + (negative ? ')' : '')
}
function CheckboxList({ items, selected, onChange }: {
  items: Option[]; selected: string[]; onChange: (items: string[]) => void
}) {
  return <div className="top-special-checklist">
    {items.map((item) => <label key={item.ulid}><input type="checkbox"
      checked={selected.includes(item.ulid)}
      onChange={(e) => onChange(e.target.checked
        ? [...selected, item.ulid] : selected.filter((v) => v !== item.ulid))}/>{item.name}</label>)}
    {!items.length && <span>No available values</span>}
  </div>
}
export function TopSpecialReportPage() {
  const { openModule, closeActiveTab } = useWorkspace()
  const params = new URLSearchParams(useLocation().search)
  const preview = params.get('view') === 'preview'
  const [kind, setKind] = useState<Kind>((params.get('kind') as Kind) || 'customers')
  const [from, setFrom] = useState(params.get('from') || localDate().slice(0, 8) + '01')
  const [to, setTo] = useState(params.get('to') || localDate())
  const [top, setTop] = useState(params.get('top') || '10')
  const [quantity, setQuantity] = useState(params.get('quantity') === '1')
  const [purchasing, setPurchasing] = useState(params.get('purchasing') === '1')
  const [format, setFormat] = useState(params.get('format') || 'standard')
  const [categoryIds, setCategoryIds] = useState(params.get('categories')?.split(',').filter(Boolean) || [])
  const [brandIds, setBrandIds] = useState(params.get('brands')?.split(',').filter(Boolean) || [])
  const [partyIds, setPartyIds] = useState(params.get('parties')?.split(',').filter(Boolean) || [])
  const [area, setArea] = useState(params.get('area') || '')
  const [search, setSearch] = useState('')
  const [zoom, setZoom] = useState(100)
  const paperRef = useRef<HTMLElement>(null)
  const optionsQuery = useQuery({
    queryKey: ['top-special-options'],
    queryFn: () => apiFetch<Options>('/api/reports/top-special/options'),
    enabled: !preview,
  })
  const options = optionsQuery.data
  const resultQuery = useQuery({
    queryKey: ['top-special', params.toString()],
    queryFn: () => {
      const searchParams = new URLSearchParams({
        from, to, top, kind, purchasing: purchasing ? '1' : '0',
        by_quantity: quantity ? '1' : '0', format,
      })
      categoryIds.forEach((id, i) => searchParams.set(`category_ulids[${i}]`, id))
      brandIds.forEach((id, i) => searchParams.set(`brand_ulids[${i}]`, id))
      partyIds.forEach((id, i) => searchParams.set(`party_ulids[${i}]`, id))
      if (area) searchParams.set('area', area)
      return apiFetch<Report>(`/api/reports/top-special?${searchParams}`)
    },
    enabled: preview,
  })
  const data = resultQuery.data
  const goPreview = () => {
    const query = new URLSearchParams({
      view: 'preview', kind, from, to, top, purchasing: purchasing ? '1' : '0',
      quantity: quantity ? '1' : '0', format,
      categories: categoryIds.join(','), brands: brandIds.join(','), parties: partyIds.join(','), area,
    })
    openModule(`/reports/accounts/top-special?${query}`)
  }
  const exportReport = (type: 'txt' | 'csv' | 'html') => {
    if (!data || !paperRef.current) return
    const csv = (value: string) => '"' + (/^\s*[=+@-]/.test(value) ? "'" : '') + value.replace(/"/g, '""') + '"'
    const content = type === 'csv' ? '\uFEFF' + [['#', 'Description', 'QTY', 'Amount'], ...data.ranking.map((item) =>
      [String(item.rank), item.description, item.qty, item.amount])].map((row) => row.map(csv).join(',')).join('\r\n')
      : type === 'html' ? '<!doctype html><meta charset="utf-8">' + paperRef.current.innerHTML
      : paperRef.current.innerText
    const url = URL.createObjectURL(new Blob([content], { type: type === 'csv' ? 'text/csv' : 'text/plain' }))
    const link = document.createElement('a')
    link.href = url; link.download = 'top-special.' + type; link.click(); URL.revokeObjectURL(url)
  }
  if (preview) return <section className="top-special-page top-special-view">
    <ReportViewerToolbar ready={Boolean(data)} busy={resultQuery.isFetching}
      search={search} onSearchChange={setSearch}
      onFind={() => { const target = Array.from(paperRef.current?.querySelectorAll('td') || [])
        .find((el) => el.textContent?.toLowerCase().includes(search.toLowerCase()))
        target?.scrollIntoView({ block: 'center' }) }}
      zoom={zoom} onZoomChange={setZoom}
      onPrint={() => window.print()}
      onCopy={() => { if (paperRef.current) void navigator.clipboard.writeText(paperRef.current.innerText) }}
      onExport={exportReport} onRefresh={() => void resultQuery.refetch()}
      onEditOptions={() => openModule('/reports/accounts/top-special')} onClose={closeActiveTab}/>
    {resultQuery.isLoading && <p>Loading Top Special…</p>}
    {resultQuery.isError && <p role="alert">Unable to load Top Special report.</p>}
    {data && <article className="top-special-paper" ref={paperRef} style={{ zoom: `${zoom}%` }}>
      <header><h1>TOP {top} {data.kind.toUpperCase()}</h1><p>From: {data.from} to: {data.to}</p></header>
      <table><thead><tr><th>Sr #</th><th>DESCRIPTION</th>
        {format === 'format1' && data.kind === 'customers' && <>
          <th>Contact Person</th><th>CNIC</th><th>Contact</th></>}
        <th>QTY</th><th>AMOUNT</th></tr></thead><tbody>
        {data.ranking.map((row) => <tr key={row.rank}><td>{row.rank}</td><td>{row.description}</td>
          {format === 'format1' && data.kind === 'customers' && <><td>—</td><td>—</td><td>—</td></>}
          <td className="top-special-num">{number(row.qty)}</td>
          <td className="top-special-num">{number(row.amount)}</td></tr>)}
      </tbody></table>
      {!data.ranking.length && <p>No posted transactions match the selected period and filters.</p>}
    </article>}
  </section>
  const parties = kind === 'vendors' || purchasing ? options?.suppliers : kind === 'salespeople' ? options?.salespeople : options?.customers
  const selectableKind = purchasing ? kind !== 'customers' && kind !== 'salespeople' : kind !== 'vendors'
  const invalid = !from || !to || from > to || !Number.isInteger(Number(top))
    || Number(top) < 1 || Number(top) > 100 || !selectableKind
  return <section className="top-special-page">
    <h1 className="top-special-title">Top Special</h1>
    <div className="top-special-selection">
      <h2>Selection Options</h2>
      <div className="top-special-grid">
        <div><strong>Main Area</strong><div className="top-special-checklist"><label>
          <input type="checkbox" disabled/> (NONE) — no verified main-area mapping
        </label></div></div>
        <div><strong>Area</strong><div className="top-special-checklist">
          <label><input type="radio" name="area" checked={!area} onChange={() => setArea('')}/>All Areas</label>
          {(options?.areas || []).map((value) => <label key={value}>
            <input type="radio" name="area" checked={area === value} onChange={() => setArea(value)}/>{value}
          </label>)}
        </div></div>
        <div><strong>Category / Company</strong><CheckboxList
          items={options?.categories || []} selected={categoryIds} onChange={setCategoryIds}/></div>
        <div className="top-special-controls">
          <strong>Top List Selection</strong>
          <label><input type="checkbox" checked={purchasing} onChange={(e) => {
            setPurchasing(e.target.checked);setKind(e.target.checked ? 'vendors' : 'customers');setPartyIds([])
          }}/> For Purchasing</label>
          <label><input type="checkbox" checked={quantity} onChange={(e) => setQuantity(e.target.checked)}/> For Quantity</label>
          <label>Show Top <input type="number" min="1" max="100" value={top} onChange={(e) => setTop(e.target.value)}/></label>
          <UiSelect searchable={false} value={kind} onChange={(v) => {setKind(v as Kind);setPartyIds([])}}
            options={[{value:'customers',label:'Customers',disabled:purchasing},
              {value:'vendors',label:'Vendors',disabled:!purchasing},
              {value:'products',label:'Products'},
              {value:'salespeople',label:'Sales Man',disabled:purchasing}]}/>
          <label>From <input type="date" value={from} onChange={(e) => setFrom(e.target.value)}/></label>
          <label>To <input type="date" value={to} onChange={(e) => setTo(e.target.value)}/></label>
          <UiSelect searchable={false} value={format} onChange={setFormat} options={[
            {value:'standard',label:'Standard Format'}, {value:'format1',label:'Format1'}]}/>
        </div>
        <div><strong>{kind === 'vendors' ? 'Vendors' : kind === 'salespeople' ? 'Sales Man' : 'Customers'}</strong>
          <CheckboxList items={parties || []} selected={partyIds} onChange={setPartyIds}/></div>
        <div><strong>Brands / Company</strong><CheckboxList items={options?.brands || []}
          selected={brandIds} onChange={setBrandIds}/></div>
      </div>
      {optionsQuery.isError && <p role="alert">Unable to load filter options.</p>}
      <div className="top-special-footer">Purchase ranking is available for Vendors and Products.
        Area and Main Area Wise variations without verified mappings remain restricted.</div>
    </div>
    <div className="top-special-actions">
      <UiButton variant="info" label="Preview" disabled={invalid || optionsQuery.isLoading || optionsQuery.isError} onClick={goPreview}/>
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
    </div>
  </section>
}
