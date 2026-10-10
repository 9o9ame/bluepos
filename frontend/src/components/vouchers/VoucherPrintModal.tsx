import { useMemo, useState } from 'react'
import { Printer } from 'lucide-react'
import { UiButton } from '../ui/UiButton'
import { UiModal } from '../ui/UiModal'
import { UiSelect } from '../ui/UiSelect'
import { UI_LAYER } from '../ui/uiLayers'
import './VoucherPrintModal.css'

export type VoucherPrintRow = {
  account: string
  narration: string
  balance: string
  debit?: string
  credit?: string
  amount?: string
  closing: string
}

export type VoucherPrintData = {
  title: string
  voucherNumber: string
  bookNumber: string
  date: string
  time: string
  account: string
  preBalance: string
  thisVoucher: string
  totalBalance: string
  type: 'payment' | 'receiving' | 'journal'
  rows: VoucherPrintRow[]
}

const FORMAT_OPTIONS = [
  { value: 'a5-1', label: 'A5 Print Format 1 (5"x7")' },
  { value: 'a5-2', label: 'A5 Print Format 2 (5"x7")' },
  { value: 'pos', label: 'POS Printer' },
  { value: 'a4', label: 'A4 Print (8.27"x11.69")' },
  { value: 'a5-simple', label: 'A5 Print Simple' },
  { value: 'a6-simple', label: 'A6 Print Simple' },
  { value: 'a6-urdu', label: 'A6 Urdu Print' },
]

const HEADING_OPTIONS = [
  { value: 'default', label: 'Default Report Heading' },
  { value: 'grid', label: 'GRID HEADING' },
  { value: 'headland', label: 'HeadLand' },
  { value: 'pos', label: 'POS' },
  { value: 'prod-template', label: 'ProdTemplate' },
  { value: 'zap-sale-footer', label: 'ZapSaleFooter' },
]

const PRINTER_OPTIONS = [
  { value: 'system', label: 'System Printer Dialog' },
  { value: 'pdf', label: 'Save / Print to PDF (via system dialog)' },
]

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

function pageSize(format: string, rotated: boolean): string {
  if (format === 'pos') return '80mm auto'

  const size = format === 'a4' ? 'A4' : format.startsWith('a6') ? 'A6' : 'A5'
  return `${size} ${rotated ? 'landscape' : 'portrait'}`
}

function renderVoucherHtml(
  data: VoucherPrintData,
  format: string,
  rotated: boolean,
  heading: string,
  hidePreviousBalance: boolean,
  copies: number,
): string {
  const formatLabel = FORMAT_OPTIONS.find((option) => option.value === format)?.label ?? ''
  const headingLabel = HEADING_OPTIONS.find((option) => option.value === heading)?.label ?? ''
  const moneyColumns =
    data.type === 'journal'
      ? '<th>Balance</th><th>Debit</th><th>Credit</th><th>Closing</th>'
      : '<th>Balance</th><th>Amount</th><th>Closing</th>'

  const rows = data.rows
    .map((row) => {
      const moneyCells =
        data.type === 'journal'
          ? `<td class="num">${escapeHtml(row.balance)}</td><td class="num">${escapeHtml(row.debit ?? '0.0000')}</td><td class="num">${escapeHtml(row.credit ?? '0.0000')}</td><td class="num">${escapeHtml(row.closing)}</td>`
          : `<td class="num">${escapeHtml(row.balance)}</td><td class="num">${escapeHtml(row.amount ?? '0.0000')}</td><td class="num">${escapeHtml(row.closing)}</td>`

      return `<tr><td>${escapeHtml(row.account)}</td><td>${escapeHtml(row.narration)}</td>${moneyCells}</tr>`
    })
    .join('')

  const summary = hidePreviousBalance
    ? `<div class="summary"><span>This Voucher <strong>${escapeHtml(data.thisVoucher)}</strong></span><span>Total Bal <strong>${escapeHtml(data.totalBalance)}</strong></span></div>`
    : `<div class="summary"><span>Pre balance <strong>${escapeHtml(data.preBalance)}</strong></span><span>This Voucher <strong>${escapeHtml(data.thisVoucher)}</strong></span><span>Total Bal <strong>${escapeHtml(data.totalBalance)}</strong></span></div>`

  const page = `
    <section class="voucher-page">
      <header>
        <div class="heading-kicker">${escapeHtml(headingLabel)}</div>
        <h1>${escapeHtml(data.title)}</h1>
        <div class="meta">
          <span><b>Voucher #</b> ${escapeHtml(data.voucherNumber)}</span>
          <span><b>Book#</b> ${escapeHtml(data.bookNumber || '—')}</span>
          <span><b>Date</b> ${escapeHtml(data.date)}</span>
          <span><b>Time</b> ${escapeHtml(data.time)}</span>
        </div>
        <div class="account"><b>Account</b> ${escapeHtml(data.account || 'JV')}</div>
        ${summary}
      </header>
      <table>
        <thead><tr><th>Vendor / Customer / Account</th><th>Narration</th>${moneyColumns}</tr></thead>
        <tbody>${rows || '<tr><td colspan="6">No voucher lines</td></tr>'}</tbody>
      </table>
      <footer>${escapeHtml(formatLabel)}</footer>
    </section>
  `

  return `<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<title>${escapeHtml(data.voucherNumber)} - ${escapeHtml(data.title)}</title>
<style>
  @page { size: ${pageSize(format, rotated)}; margin: 10mm; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Arial, sans-serif; color: #111; background: #fff; }
  .voucher-page { page-break-after: always; padding: 4mm; }
  .voucher-page:last-child { page-break-after: auto; }
  h1 { margin: 0 0 8px; font-size: 18px; text-align: center; }
  .heading-kicker { font-size: 10px; text-align: center; margin-bottom: 2px; }
  .meta, .summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; margin-bottom: 7px; font-size: 10px; }
  .summary { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .account { margin-bottom: 7px; font-size: 10px; }
  table { width: 100%; border-collapse: collapse; font-size: 9px; }
  th, td { border: 1px solid #333; padding: 4px 5px; vertical-align: top; }
  th { background: #eee; text-align: left; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  footer { margin-top: 6px; text-align: right; font-size: 8px; color: #555; }
  @media print { body { print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
</style>
</head>
<body>${Array.from({ length: Math.max(1, copies) }, () => page).join('')}</body>
</html>`
}

export function VoucherPrintModal({
  open,
  onClose,
  data,
}: {
  open: boolean
  onClose: () => void
  data: VoucherPrintData | null
}) {
  const [format, setFormat] = useState('a5-1')
  const [rotated, setRotated] = useState(false)
  const [heading, setHeading] = useState('default')
  const [printer, setPrinter] = useState('system')
  const [directPrint, setDirectPrint] = useState(false)
  const [hidePreviousBalance, setHidePreviousBalance] = useState(false)
  const [copies, setCopies] = useState('1')

  const copyCount = useMemo(() => {
    const parsed = Number.parseInt(copies, 10)
    if (!Number.isFinite(parsed)) return 1
    return Math.min(Math.max(parsed, 1), 20)
  }, [copies])

  function openPrintWindow(printImmediately: boolean) {
    if (!data) return

    const popup = window.open('', '_blank')
    if (!popup) return

    popup.opener = null
    popup.document.open()
    popup.document.write(
      renderVoucherHtml(data, format, rotated, heading, hidePreviousBalance, copyCount),
    )
    popup.document.close()

    if (printImmediately) {
      window.setTimeout(() => {
        popup.focus()
        popup.print()
      }, 150)
    }
  }

  return (
    <UiModal
      open={open}
      title="Print Voucher Options"
      size="md"
      className="voucher-print-modal"
      onClose={onClose}
      footer={
        <>
          <UiButton variant="info" icon={<Printer size={14} />} onClick={() => openPrintWindow(directPrint)}>
            {directPrint ? 'Print' : 'Preview'}
          </UiButton>
          <UiButton variant="default" onClick={onClose}>Close</UiButton>
        </>
      }
    >
      <div className="voucher-print-options">
        <label>
          <span>Print this Voucher On</span>
          <UiSelect
            value={format}
            options={FORMAT_OPTIONS}
            menuZIndex={UI_LAYER.modalDropdown}
            onChange={setFormat}
          />
        </label>

        <label className="voucher-print-check">
          <input type="checkbox" checked={rotated} onChange={(event) => setRotated(event.target.checked)} />
          <span>Rotated To Long Side</span>
        </label>

        <label>
          <span>Select Heading Option</span>
          <UiSelect
            value={heading}
            options={HEADING_OPTIONS}
            menuZIndex={UI_LAYER.modalDropdown}
            onChange={setHeading}
          />
        </label>

        <label>
          <span>On Printer</span>
          <UiSelect
            value={printer}
            options={PRINTER_OPTIONS}
            searchable={false}
            menuPlacement="down"
            maxMenuHeight={120}
            menuZIndex={UI_LAYER.modalDropdown}
            onChange={setPrinter}
          />
        </label>

        <div className="voucher-print-direct-row">
          <label className="voucher-print-check">
            <input type="checkbox" checked={directPrint} onChange={(event) => setDirectPrint(event.target.checked)} />
            <span>Direct Print to Printer</span>
          </label>
          <label className="voucher-print-copies">
            <span>No of Copies</span>
            <input
              className="desktop-input"
              inputMode="numeric"
              value={copies}
              onChange={(event) => setCopies(event.target.value.replace(/\D/g, '').slice(0, 2))}
            />
          </label>
        </div>

        <label className="voucher-print-check">
          <input
            type="checkbox"
            checked={hidePreviousBalance}
            onChange={(event) => setHidePreviousBalance(event.target.checked)}
          />
          <span>Don't Print Previous Balance</span>
        </label>

        <p className="voucher-print-note">
          Installed Windows printers are selected in the browser/system print dialog after Preview or Print.
        </p>
      </div>
    </UiModal>
  )
}
