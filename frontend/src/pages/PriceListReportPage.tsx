import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchProductStockStatusReport } from '../api/inventory'
import { ReportViewerToolbar } from '../components/reports/ReportViewerToolbar'
import { UiButton } from '../components/ui/UiButton'
import { UiSelect } from '../components/ui/UiSelect'
import { UiSkeleton } from '../components/ui/UiSkeleton'
import { useWorkspace } from '../features/workspace/WorkspaceProvider'
import { useAuth } from '../features/auth/AuthProvider'
import type { ProductStockStatusRow } from '../types/inventory'
import './DailyBusinessActivityPage.css'
import './DailyReportSheets.css'

type Format = 'prices' | 'reorder' | 'reorder_conversion'
const formats: Record<Format,{label:string; columns:string[]}> = {
  prices:{
    label:'Sales + Purchase Price List',
    columns:['Code','Product','Category','Brand','Retail Rate','Purchase Rate','In Stock'],
  },
  reorder:{
    label:'Stock Position — Below Reorder',
    columns:['Code','Product','Category','In Stock','Reorder','Vs Reorder','Retail Rate'],
  },
  reorder_conversion:{
    label:'Price List + Stock Position — Below Reorder',
    columns:['Code','Product','Category','In Stock (Base Units)','Reorder','Vs Reorder','Conversion Factor','Retail Rate'],
  },
}
const columnCells:Record<Format,(r:ProductStockStatusRow)=>string[]> = {
  prices:row=>[row.product.sku??row.product.product_number,row.product.name,row.product.category?.name??'',
    row.product.brand?.name??'',row.retail_rate??'—',row.purchase_rate??'Restricted / Unavailable',row.quantity],
  reorder:row=>[row.product.sku??row.product.product_number,row.product.name,row.product.category?.name??'',
    row.quantity,row.reorder_level??'—',row.reorder_variance??'—',row.retail_rate??'—'],
  reorder_conversion:row=>[row.product.sku??row.product.product_number,row.product.name,row.product.category?.name??'',
    row.quantity,row.reorder_level??'—',row.reorder_variance??'—',
    row.secondary_conversion_factor??'Not configured',row.retail_rate??'—'],
}
const csv=(s:string)=>'"'+(/^\s*[=+\-@\t\r]/.test(s)?"'":'')+s.replace(/"/g,'""')+'"'
const html=(s:string)=>s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')
function save(kind:'csv'|'txt'|'html',columns:string[],rows:string[][]){
  const table=[columns,...rows]
  const data=kind==='csv'?table.map(row=>row.map(csv).join(',')).join('\r\n')
    :kind==='txt'?table.map(row=>row.join('\t')).join('\n')
      :'<!doctype html><meta charset="utf-8"><table><tbody>'+
        table.map(row=>'<tr>'+row.map(cell=>'<td>'+html(cell)+'</td>').join('')+'</tr>').join('')+'</tbody></table>'
  const uri=URL.createObjectURL(new Blob([data],{type:'text/plain;charset=utf-8'}))
  const a=document.createElement('a');a.href=uri;a.download='bluepos-price-list-current-page.'+kind;a.click()
  URL.revokeObjectURL(uri)
}
export function PriceListReportPage(){
  const { session } = useAuth()
  const {closeActiveTab}=useWorkspace()
  const [mode,setMode]=useState<Format>('prices')
  const [search,setSearch]=useState('')
  const [selectedSearch,setSelectedSearch]=useState('')
  const [page,setPage]=useState(1)
  const [preview,setPreview]=useState(false)
  const [zoom,setZoom]=useState(100)
  const paper=useRef<HTMLElement>(null)
  const query=useQuery({
    queryKey:[session?.tenant.ulid,session?.branch.ulid,session?.warehouse.ulid,'reports','price-list',mode,selectedSearch,page],
    queryFn:()=>fetchProductStockStatusReport({
      q:selectedSearch||undefined,
      active_only:false,
      below_reorder:mode!=='prices',
      page,per_page:100,
    }),
    enabled:preview,
  })
  const report=query.data
  const columnSet=formats[mode]
  const values=columnCells[mode]
  if(!preview)return <section className="dba-page">
    <header><h1>Price List / Stock Position</h1><p>Sales and configured purchase prices; current selected-warehouse stock and reorder status.</p></header>
    <div className="dba-options">
      <label>Reference Format
        <UiSelect searchable={false} value={mode}
          onChange={value=>setMode(value as Format)}
          options={Object.entries(formats).map(([value,entry])=>({value,label:entry.label}))}/>
      </label>
      <label>Search Product / SKU
        <input value={search} maxLength={180} onChange={e=>setSearch(e.target.value)}/>
      </label>
    </div>
    <div className="dba-actions">
      <UiButton variant="default" label="Close" onClick={closeActiveTab}/>
      <UiButton variant="info" label="View Report" onClick={()=>{setPage(1);setSelectedSearch(search.trim());setPreview(true)}}/>
    </div>
  </section>
  return <section className="dba-page dba-preview">
    <ReportViewerToolbar ready={!!report} busy={query.isFetching}
      search={search} onSearchChange={setSearch}
      onFind={()=>{const needle=search.trim().toLowerCase();if(needle)Array.from(paper.current?.querySelectorAll('td')??[])
        .find(td=>td.textContent?.toLowerCase().includes(needle))?.scrollIntoView({block:'center'})}}
      zoom={zoom} onZoomChange={setZoom}
      onPrint={()=>window.print()}
      onCopy={async()=>{if(paper.current)await navigator.clipboard.writeText(paper.current.innerText)}}
      onExport={kind=>save(kind,columnSet.columns,(report?.data??[]).map(values))}
      onRefresh={()=>void query.refetch()}
      onEditOptions={()=>setPreview(false)}
      onClose={closeActiveTab}/>
    {query.isLoading&&<UiSkeleton label="Loading price list…"/>}
    {query.isError&&<p role="alert">Unable to load price list. Check inventory report permissions.</p>}
    {report&&<article className="dba-paper daily-report-sheet" ref={paper} style={{zoom:zoom+'%'}}>
      <header><h1>{columnSet.label.toUpperCase()}</h1>
        <p>Current warehouse: {report.meta.warehouse.name}</p>
        <p>{report.meta.total} products · Page {report.meta.current_page} / {report.meta.last_page}</p>
      </header>
      <table><thead><tr>{columnSet.columns.map(h=><th key={h}>{h}</th>)}</tr></thead>
        <tbody>{report.data.map(row=><tr key={row.product.ulid}>{values(row).map((value,i)=><td key={i}>{value}</td>)}</tr>)}
          {report.data.length===0&&<tr><td colSpan={columnSet.columns.length}>No products match.</td></tr>}</tbody>
      </table>
      <h2>Category Subtotals — All Matching Products</h2>
      <table><thead><tr><th>Category</th><th>Products</th><th>In Stock</th><th>Stock Value</th></tr></thead>
        <tbody>{report.meta.category_totals.map(category=><tr key={category.category_ulid??'unassigned'}>
          <td>{category.category_name}</td><td>{category.products}</td><td>{category.quantity}</td>
          <td>{category.stock_value??'Restricted'}</td>
        </tr>)}</tbody></table>
      <p>Base-unit stock totals: {report.meta.totals.quantity}. Purchase rate requires cost permission; no historical stock values are inferred.</p>
      <p>Conversion factors are displayed without assuming an unverified carton-unit mapping. Export contains the current page only.</p>
    </article>}
    {report&&<div className="dba-actions">
      <UiButton label="Previous" disabled={page<=1||query.isFetching} onClick={()=>setPage(p=>Math.max(1,p-1))}/>
      <span>Page {report.meta.current_page} / {report.meta.last_page}</span>
      <UiButton label="Next" disabled={page>=report.meta.last_page||query.isFetching} onClick={()=>setPage(p=>p+1)}/>
    </div>}
  </section>
}
