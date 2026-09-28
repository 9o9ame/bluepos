import {
  getBarcodeStylePreset,
  migrateLegacyPrintStyle,
  type BarcodeStyleId,
  type BarcodeStylePreset,
} from './barcodeStyles'
import {
  migrateLegacyBarcodeType,
  renderBarcodeSvg,
  validateBarcodeValue,
  type BarcodeType,
} from './barcodeSymbology'

export type { BarcodeType } from './barcodeSymbology'
export {
  BARCODE_TYPE_OPTIONS,
  barcodeSvg,
  isBarcodeTypeSupported,
  migrateLegacyBarcodeType,
  renderBarcodeSvg,
  unsupportedBarcodeTypes,
  validateBarcodeValue,
} from './barcodeSymbology'

export type {
  BarcodeStyleId,
  BarcodeStylePreset,
  DisplayFieldOption,
  PriceFieldOption,
} from './barcodeStyles'
export {
  DISPLAY_FIELD_OPTIONS,
  PRICE_FIELD_OPTIONS,
  getActiveBarcodeStyles,
  getAllBarcodeStylePresets,
  getBarcodeStylePreset,
  loadBarcodeStylePrefs,
  migrateLegacyPrintStyle,
  saveBarcodeStylePrefs,
} from './barcodeStyles'

export type { PrinterInfo, PrinterProvider } from './printerProvider'
export {
  browserPrintDialogPrinter,
  browserPrinterProvider,
} from './printerProvider'

/** Legacy label size key kept for ProductsPage modal compatibility. */
export type BarcodeLabelSize = '40x25' | '50x30' | '60x40'
/** @deprecated Use BarcodeStyleId */
export type BarcodePrintStyle = BarcodeStyleId | 'standard' | 'compact' | 'price_emphasis'

export type BarcodePrintSettings = {
  labelSize: BarcodeLabelSize
  showPrice: boolean
  barcodeType?: BarcodeType
  printStyle?: BarcodePrintStyle
  marginLeftMm?: number
  marginTopMm?: number
  scaleFactor?: number
  showBusinessName?: boolean
  showProductNumber?: boolean
  showUnit?: boolean
  showBarcodeText?: boolean
}

export type BarcodeLabelPayload = {
  businessName: string
  productName: string
  productNumber: string
  barcode: string
  unitCode?: string
  price?: string
  currencyCode?: string
  copies: number
  labelSize: BarcodeLabelSize
  showPrice: boolean
  barcodeType?: BarcodeType
  printStyle?: BarcodePrintStyle
  marginLeftMm?: number
  marginTopMm?: number
  scaleFactor?: number
  showBusinessName?: boolean
  showProductNumber?: boolean
  showUnit?: boolean
  showBarcodeText?: boolean
}

const STORAGE_KEY = 'bluepos.productBarcodePrintSettings'

const LABEL_SIZES: Record<
  BarcodeLabelSize,
  { widthMm: number; heightMm: number; title: string }
> = {
  '40x25': { widthMm: 40, heightMm: 25, title: '40 × 25 mm' },
  '50x30': { widthMm: 50, heightMm: 30, title: '50 × 30 mm' },
  '60x40': { widthMm: 60, heightMm: 40, title: '60 × 40 mm' },
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

export function getBarcodeLabelSizeOptions() {
  return (Object.keys(LABEL_SIZES) as BarcodeLabelSize[]).map((value) => ({
    value,
    label: LABEL_SIZES[value].title,
  }))
}

export function loadBarcodePrintSettings(): Required<BarcodePrintSettings> {
  const fallback: Required<BarcodePrintSettings> = {
    labelSize: '50x30',
    showPrice: true,
    barcodeType: 'Code128',
    printStyle: 'one_2',
    marginLeftMm: 0,
    marginTopMm: 0,
    scaleFactor: 1,
    showBusinessName: true,
    showProductNumber: true,
    showUnit: true,
    showBarcodeText: true,
  }

  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return fallback
    const parsed = JSON.parse(raw) as Partial<BarcodePrintSettings>

    return {
      labelSize:
        parsed.labelSize && parsed.labelSize in LABEL_SIZES
          ? parsed.labelSize
          : fallback.labelSize,
      showPrice:
        typeof parsed.showPrice === 'boolean'
          ? parsed.showPrice
          : fallback.showPrice,
      barcodeType: migrateLegacyBarcodeType(parsed.barcodeType),
      printStyle: migrateLegacyPrintStyle(
        typeof parsed.printStyle === 'string' ? parsed.printStyle : undefined,
      ),
      marginLeftMm:
        typeof parsed.marginLeftMm === 'number'
          ? parsed.marginLeftMm
          : fallback.marginLeftMm,
      marginTopMm:
        typeof parsed.marginTopMm === 'number'
          ? parsed.marginTopMm
          : fallback.marginTopMm,
      scaleFactor:
        typeof parsed.scaleFactor === 'number' &&
        parsed.scaleFactor >= 0.7 &&
        parsed.scaleFactor <= 1.3
          ? parsed.scaleFactor
          : fallback.scaleFactor,
      showBusinessName:
        typeof parsed.showBusinessName === 'boolean'
          ? parsed.showBusinessName
          : fallback.showBusinessName,
      showProductNumber:
        typeof parsed.showProductNumber === 'boolean'
          ? parsed.showProductNumber
          : fallback.showProductNumber,
      showUnit:
        typeof parsed.showUnit === 'boolean'
          ? parsed.showUnit
          : fallback.showUnit,
      showBarcodeText:
        typeof parsed.showBarcodeText === 'boolean'
          ? parsed.showBarcodeText
          : fallback.showBarcodeText,
    }
  } catch {
    return fallback
  }
}

export function saveBarcodePrintSettings(settings: BarcodePrintSettings) {
  try {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        ...loadBarcodePrintSettings(),
        ...settings,
      }),
    )
  } catch {
    // Printing must still work if localStorage is unavailable.
  }
}

function money(value?: string): string {
  const amount = Number(value ?? 0)
  return Number.isFinite(amount) ? amount.toFixed(2) : '0.00'
}

function resolveStyle(
  printStyle: BarcodePrintStyle | undefined,
): BarcodeStylePreset {
  return getBarcodeStylePreset(
    migrateLegacyPrintStyle(
      typeof printStyle === 'string' ? printStyle : undefined,
    ),
  )
}

function buildLabelHtml(
  payload: BarcodeLabelPayload,
  settings: Required<BarcodePrintSettings>,
  style: BarcodeStylePreset,
): string {
  const merged = { ...settings, ...payload }
  const barcodeType = migrateLegacyBarcodeType(merged.barcodeType)
  const validationError = validateBarcodeValue(payload.barcode, barcodeType)
  if (validationError) {
    throw new Error(`${payload.productName}: ${validationError}`)
  }

  const svg = style.showBarcode
    ? renderBarcodeSvg(payload.barcode, barcodeType)
    : ''

  const safeBusiness = escapeHtml(payload.businessName)
  const safeProduct = escapeHtml(payload.productName)
  const safeNumber = escapeHtml(payload.productNumber)
  const safeBarcode = escapeHtml(payload.barcode)
  const safeUnit = escapeHtml(payload.unitCode ?? '')
  const currency = escapeHtml(payload.currencyCode ?? '')

  const businessLine = merged.showBusinessName
    ? `<div class="business">${safeBusiness}</div>`
    : ''
  const metaLine =
    merged.showProductNumber || merged.showUnit
      ? `<div class="meta">
          <span>${merged.showProductNumber ? safeNumber : ''}</span>
          <span>${merged.showUnit ? safeUnit : ''}</span>
        </div>`
      : ''
  const barcodeBlock = style.showBarcode
    ? `<div class="barcode">${svg}</div>`
    : ''
  const barcodeText =
    style.showBarcode && merged.showBarcodeText
      ? `<div class="barcode-text">${safeBarcode}</div>`
      : ''
  const priceLine =
    merged.showPrice && payload.price
      ? `<div class="price${style.priceEmphasis ? ' is-emphasis' : ''}">${currency ? `${currency} ` : ''}${money(payload.price)}</div>`
      : ''

  return `
    <section class="label">
      <div class="label-inner">
        ${businessLine}
        <div class="product">${safeProduct}</div>
        ${metaLine}
        ${barcodeBlock}
        ${barcodeText}
        ${priceLine}
      </div>
    </section>
  `
}

function printDocument(
  payloads: BarcodeLabelPayload[],
  settings: BarcodePrintSettings,
): void {
  const normalized = {
    ...loadBarcodePrintSettings(),
    ...settings,
  }
  const style = resolveStyle(normalized.printStyle)
  const left = Math.max(-5, Math.min(10, normalized.marginLeftMm))
  const top = Math.max(-5, Math.min(10, normalized.marginTopMm))
  const scale = Math.max(0.7, Math.min(1.3, normalized.scaleFactor))

  const expanded = payloads.flatMap((payload) => {
    const copies = Math.max(1, Math.min(100, Math.floor(payload.copies || 1)))
    return Array.from({ length: copies }, () =>
      buildLabelHtml(payload, normalized, style),
    )
  })

  if (expanded.length === 0) {
    throw new Error('There are no barcode labels to print.')
  }

  const perPage = Math.max(1, style.columns * Math.max(1, style.rows))
  const pages: string[] = []
  for (let i = 0; i < expanded.length; i += perPage) {
    const chunk = expanded.slice(i, i + perPage)
    while (chunk.length < perPage) {
      chunk.push('<section class="label is-empty"></section>')
    }
    pages.push(`<div class="page">${chunk.join('')}</div>`)
  }

  const pageWidth = style.pageWidthMm ?? style.labelWidthMm * style.columns + style.gapMm * (style.columns - 1)
  const pageHeight =
    style.pageHeightMm ??
    style.labelHeightMm * Math.max(1, style.rows) +
      style.gapMm * Math.max(0, style.rows - 1)

  const printWindow = window.open(
    '',
    '_blank',
    'popup=yes,width=900,height=700,noopener=no,noreferrer=no',
  )

  if (!printWindow) {
    throw new Error('Print window was blocked by the browser.')
  }

  printWindow.document.open()
  printWindow.document.write(`<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>BluePOS Barcode Printing</title>
  <style>
    @page {
      size: ${pageWidth}mm ${pageHeight}mm;
      margin: 0;
    }
    * { box-sizing: border-box; }
    html, body {
      margin: 0;
      padding: 0;
      background: #fff;
      color: #000;
      font-family: Arial, Helvetica, sans-serif;
    }
    .page {
      width: ${pageWidth}mm;
      height: ${pageHeight}mm;
      display: grid;
      grid-template-columns: repeat(${style.columns}, ${style.labelWidthMm}mm);
      grid-auto-rows: ${style.labelHeightMm}mm;
      gap: ${style.gapMm}mm;
      page-break-after: always;
      break-after: page;
      padding: ${style.pageWidthMm ? 4 : 0}mm;
      overflow: hidden;
    }
    .page:last-child {
      page-break-after: auto;
      break-after: auto;
    }
    .label {
      width: ${style.labelWidthMm}mm;
      height: ${style.labelHeightMm}mm;
      overflow: hidden;
      background: #fff;
    }
    .label.is-empty {
      visibility: hidden;
    }
    .label-inner {
      width: ${100 / scale}%;
      height: ${100 / scale}%;
      padding: 1.1mm 1.4mm .9mm;
      transform-origin: top left;
      transform: translate(${left}mm, ${top}mm) scale(${scale});
      overflow: hidden;
      display: flex;
      flex-direction: column;
      align-items: stretch;
    }
    .business {
      text-align: center;
      font-size: 2.5mm;
      line-height: 1.05;
      font-weight: 700;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .product {
      margin-top: .45mm;
      text-align: center;
      font-size: 2.75mm;
      line-height: 1.08;
      font-weight: 700;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .meta {
      margin-top: .35mm;
      display: flex;
      justify-content: space-between;
      gap: 2mm;
      font-size: 1.8mm;
      line-height: 1;
    }
    .barcode {
      margin-top: .55mm;
      height: ${style.labelHeightMm <= 25 ? 9.1 : style.labelHeightMm <= 30 ? 11.5 : 17}mm;
      width: 100%;
      overflow: hidden;
    }
    .barcode svg {
      display: block;
      width: 100%;
      height: 100%;
      shape-rendering: crispEdges;
    }
    .barcode-text {
      margin-top: .3mm;
      text-align: center;
      font-size: 2.05mm;
      line-height: 1;
      font-family: "Courier New", monospace;
      letter-spacing: .1mm;
      white-space: nowrap;
      overflow: hidden;
    }
    .price {
      margin-top: .5mm;
      text-align: center;
      font-size: ${style.labelHeightMm <= 25 ? 2.55 : 3.1}mm;
      line-height: 1;
      font-weight: 800;
    }
    .price.is-emphasis {
      margin-top: .7mm;
      font-size: ${style.labelHeightMm <= 25 ? 3.25 : 4.1}mm;
      font-weight: 900;
    }
    @media screen {
      body { padding: 12px; background: #ddd; }
      .page {
        margin: 0 auto 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,.18);
        background: #fff;
      }
    }
  </style>
</head>
<body>
  ${pages.join('')}
  <script>
    window.addEventListener('load', function () {
      window.setTimeout(function () {
        window.focus();
        window.print();
      }, 150);
    });
  </script>
</body>
</html>`)
  printWindow.document.close()
}

export function printBarcodeLabels(payload: BarcodeLabelPayload): void {
  printDocument([payload], payload)
}

export function printBarcodeBatch(
  payloads: BarcodeLabelPayload[],
  settings: BarcodePrintSettings,
): void {
  printDocument(payloads, settings)
}
