export type BarcodeLabelSize = '40x25' | '50x30' | '60x40'
export type BarcodeType = 'CODE128' | 'CODE39'
export type BarcodePrintStyle = 'standard' | 'compact' | 'price_emphasis'

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

const CODE39: Record<string, string> = {
  '0': '000110100',
  '1': '100100001',
  '2': '001100001',
  '3': '101100000',
  '4': '000110001',
  '5': '100110000',
  '6': '001110000',
  '7': '000100101',
  '8': '100100100',
  '9': '001100100',
  A: '100001001',
  B: '001001001',
  C: '101001000',
  D: '000011001',
  E: '100011000',
  F: '001011000',
  G: '000001101',
  H: '100001100',
  I: '001001100',
  J: '000011100',
  K: '100000011',
  L: '001000011',
  M: '101000010',
  N: '000010011',
  O: '100010010',
  P: '001010010',
  Q: '000000111',
  R: '100000110',
  S: '001000110',
  T: '000010110',
  U: '110000001',
  V: '011000001',
  W: '111000000',
  X: '010010001',
  Y: '110010000',
  Z: '011010000',
  '-': '010000101',
  '*': '010010100',
  '+': '010001010',
  '$': '010101000',
  '%': '000101010',
  '/': '010100010',
  '.': '110000100',
  ' ': '011000100',
}

const CODE39_DIRECT = new Set(
  '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%'.split(''),
)

const CODE128_PATTERNS = [
  '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
  '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
  '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
  '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
  '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
  '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
  '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
  '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
  '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
  '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
  '114131','311141','411131','211412','211214','211232','2331112',
] as const

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;')
}

function extendedCode39(value: string): string {
  let result = ''

  for (const char of value) {
    if (CODE39_DIRECT.has(char)) {
      result += char
      continue
    }

    const code = char.charCodeAt(0)
    if (code >= 97 && code <= 122) {
      result += `+${String.fromCharCode(code - 32)}`
      continue
    }

    if (code >= 1 && code <= 26) {
      result += `$${String.fromCharCode(64 + code)}`
      continue
    }

    result += '-'
  }

  return result.toUpperCase()
}

export function code39Svg(value: string): string {
  const encoded = `*${extendedCode39(value)}*`
  const quiet = 10
  const barHeight = 58
  let x = quiet
  const bars: string[] = []

  for (const char of encoded) {
    const pattern = CODE39[char] ?? CODE39['-']
    let bar = true

    for (const token of pattern) {
      const width = token === '1' ? 2 : 1
      if (bar) {
        bars.push(
          `<rect x="${x}" y="0" width="${width}" height="${barHeight}" fill="#000"/>`,
        )
      }
      x += width
      bar = !bar
    }

    x += 1
  }

  const totalWidth = x + quiet

  return [
    `<svg xmlns="http://www.w3.org/2000/svg"`,
    ` viewBox="0 0 ${totalWidth} ${barHeight}"`,
    ` preserveAspectRatio="none"`,
    ` role="img" aria-label="Barcode ${escapeHtml(value)}">`,
    bars.join(''),
    `</svg>`,
  ].join('')
}

export function code128Svg(value: string): string {
  const normalized = Array.from(value)
    .map((char) => {
      const code = char.charCodeAt(0)
      return code >= 32 && code <= 126 ? char : '?'
    })
    .join('')

  const startCode = 104
  const dataCodes = Array.from(normalized).map(
    (char) => char.charCodeAt(0) - 32,
  )

  let checksum = startCode
  dataCodes.forEach((code, index) => {
    checksum += code * (index + 1)
  })
  checksum %= 103

  const symbols = [startCode, ...dataCodes, checksum, 106]
  const quiet = 10
  const barHeight = 58
  let x = quiet
  const bars: string[] = []

  for (const symbol of symbols) {
    const pattern = CODE128_PATTERNS[symbol]
    if (!pattern) continue

    let bar = true
    for (const token of pattern) {
      const width = Number(token)
      if (bar) {
        bars.push(
          `<rect x="${x}" y="0" width="${width}" height="${barHeight}" fill="#000"/>`,
        )
      }
      x += width
      bar = !bar
    }
  }

  const totalWidth = x + quiet

  return [
    `<svg xmlns="http://www.w3.org/2000/svg"`,
    ` viewBox="0 0 ${totalWidth} ${barHeight}"`,
    ` preserveAspectRatio="none"`,
    ` role="img" aria-label="Barcode ${escapeHtml(value)}">`,
    bars.join(''),
    `</svg>`,
  ].join('')
}

export function barcodeSvg(
  value: string,
  type: BarcodeType = 'CODE128',
): string {
  return type === 'CODE39' ? code39Svg(value) : code128Svg(value)
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
    barcodeType: 'CODE128',
    printStyle: 'standard',
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
      barcodeType:
        parsed.barcodeType === 'CODE39' || parsed.barcodeType === 'CODE128'
          ? parsed.barcodeType
          : fallback.barcodeType,
      printStyle:
        parsed.printStyle === 'compact' ||
        parsed.printStyle === 'price_emphasis' ||
        parsed.printStyle === 'standard'
          ? parsed.printStyle
          : fallback.printStyle,
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

function printDocument(
  payloads: BarcodeLabelPayload[],
  settings: BarcodePrintSettings,
): void {
  const normalized = {
    ...loadBarcodePrintSettings(),
    ...settings,
  }

  const size = LABEL_SIZES[normalized.labelSize]
  const left = Math.max(-5, Math.min(10, normalized.marginLeftMm))
  const top = Math.max(-5, Math.min(10, normalized.marginTopMm))
  const scale = Math.max(0.7, Math.min(1.3, normalized.scaleFactor))

  const labels = payloads
    .flatMap((payload) => {
      const copies = Math.max(1, Math.min(100, Math.floor(payload.copies || 1)))
      const merged = { ...normalized, ...payload }

      return Array.from({ length: copies }, () => {
        const safeBusiness = escapeHtml(payload.businessName)
        const safeProduct = escapeHtml(payload.productName)
        const safeNumber = escapeHtml(payload.productNumber)
        const safeBarcode = escapeHtml(payload.barcode)
        const safeUnit = escapeHtml(payload.unitCode ?? '')
        const currency = escapeHtml(payload.currencyCode ?? '')
        const svg = barcodeSvg(payload.barcode, merged.barcodeType)

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
        const barcodeText = merged.showBarcodeText
          ? `<div class="barcode-text">${safeBarcode}</div>`
          : ''
        const priceLine =
          merged.showPrice && payload.price
            ? `<div class="price">${currency ? `${currency} ` : ''}${money(payload.price)}</div>`
            : ''

        return `
          <section class="label style-${merged.printStyle}">
            <div class="label-inner">
              ${businessLine}
              <div class="product">${safeProduct}</div>
              ${metaLine}
              <div class="barcode">${svg}</div>
              ${barcodeText}
              ${priceLine}
            </div>
          </section>
        `
      })
    })
    .join('')

  if (!labels) {
    throw new Error('There are no barcode labels to print.')
  }

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
      size: ${size.widthMm}mm ${size.heightMm}mm;
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

    .label {
      width: ${size.widthMm}mm;
      height: ${size.heightMm}mm;
      overflow: hidden;
      page-break-after: always;
      break-after: page;
      background: #fff;
    }

    .label:last-child {
      page-break-after: auto;
      break-after: auto;
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
      height: ${size.heightMm <= 25 ? 9.1 : size.heightMm <= 30 ? 11.5 : 17}mm;
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
      font-size: ${size.heightMm <= 25 ? 2.55 : 3.1}mm;
      line-height: 1;
      font-weight: 800;
    }

    .style-compact .business {
      font-size: 2.1mm;
    }

    .style-compact .product {
      font-size: 2.35mm;
      margin-top: .25mm;
    }

    .style-compact .barcode {
      margin-top: .3mm;
      height: ${size.heightMm <= 25 ? 10.2 : size.heightMm <= 30 ? 13 : 18.5}mm;
    }

    .style-compact .price {
      margin-top: .25mm;
      font-size: 2.6mm;
    }

    .style-price_emphasis .barcode {
      height: ${size.heightMm <= 25 ? 7.9 : size.heightMm <= 30 ? 9.8 : 15}mm;
    }

    .style-price_emphasis .price {
      margin-top: .7mm;
      font-size: ${size.heightMm <= 25 ? 3.25 : 4.1}mm;
      font-weight: 900;
    }

    @media screen {
      body {
        padding: 12px;
        background: #ddd;
      }

      .label {
        margin: 0 auto 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,.18);
      }
    }

    @media print {
      body { background: #fff; }
    }
  </style>
</head>
<body>
  ${labels}
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
