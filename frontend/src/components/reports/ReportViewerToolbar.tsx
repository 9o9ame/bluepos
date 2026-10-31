import { ArrowLeft, ClipboardCopy, Download, FileCode, FileText, Minus, Plus, Printer, RefreshCw, Search, X } from 'lucide-react'
import { UiButton } from '../ui/UiButton'
import { UiSelect } from '../ui/UiSelect'
import './ReportViewerToolbar.css'

type Props = {
  ready: boolean
  busy?: boolean
  search: string
  onSearchChange: (value: string) => void
  onFind: () => void
  zoom: number
  onZoomChange: (value: number) => void
  onPrint: () => void
  onCopy: () => void
  copyLabel?: string
  onExport: (format: 'txt' | 'html' | 'csv') => void
  onRefresh: () => void
  onEditOptions: () => void
  onClose: () => void
}

/** Shared toolbar presentation; report data/actions remain owned by each report. */
export function ReportViewerToolbar({
  ready, busy = false, search, onSearchChange, onFind, zoom, onZoomChange,
  onPrint, onCopy, copyLabel = 'Copy', onExport, onRefresh, onEditOptions, onClose,
}: Props) {
  return <div className="report-viewer-toolbar">
    <UiButton variant="info" icon={<Printer size={15}/>} label="Print / PDF" disabled={!ready} onClick={onPrint}/>
    <UiButton variant="default" icon={<ClipboardCopy size={15}/>} label={copyLabel} className="bp-motion-copy" data-copied={/copied/i.test(copyLabel)} disabled={!ready} onClick={onCopy}/>
    <UiButton variant="default" icon={<FileText size={15}/>} label="Text" disabled={!ready} onClick={() => onExport('txt')}/>
    <UiButton variant="default" icon={<FileCode size={15}/>} label="HTML" disabled={!ready} onClick={() => onExport('html')}/>
    <UiButton variant="info" icon={<Download size={15}/>} label="Export CSV" className="bp-motion-download" disabled={!ready} onClick={() => onExport('csv')}/>
    <UiSelect className="report-viewer-export-choice" aria-label="Export report format"
      value="" searchable={false} options={[
        { value: '', label: 'Export as…' },
        { value: 'csv', label: 'CSV (Excel compatible)' },
        { value: 'html', label: 'HTML document' },
        { value: 'txt', label: 'Plain text' },
        { value: 'pdf', label: 'PDF (browser print)' },
      ]} onChange={(format) => {
        if (format === 'csv' || format === 'html' || format === 'txt') onExport(format)
        else if (format === 'pdf') onPrint()
      }}/>
    <div className="report-viewer-search">
      <Search size={14} aria-hidden/>
      <input aria-label="Search report text" placeholder="Search report…" value={search}
        onChange={(e) => onSearchChange(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter') onFind() }}/>
      <UiButton variant="default" label="Find" disabled={!ready || !search.trim()} onClick={onFind}/>
    </div>
    <div className="report-viewer-zoom">
      <UiButton variant="default" icon={<Minus size={14}/>} aria-label="Zoom out"
        disabled={zoom <= 50} onClick={() => onZoomChange(Math.max(50, zoom - 25))}/>
      <span>{zoom}%</span>
      <UiButton variant="default" icon={<Plus size={14}/>} aria-label="Zoom in"
        disabled={zoom >= 200} onClick={() => onZoomChange(Math.min(200, zoom + 25))}/>
      <UiButton variant="default" label="Fit" onClick={() => onZoomChange(100)}/>
    </div>
    <UiButton variant="info" icon={<RefreshCw size={15}/>} label="Refresh" disabled={busy} onClick={onRefresh}/>
    <UiButton variant="default" icon={<ArrowLeft size={15}/>} label="Edit Options" onClick={onEditOptions}/>
    <UiButton variant="info" icon={<X size={15}/>} label="Close" onClick={onClose}/>
  </div>
}
