import bwipjs from '@bwip-js/browser'

/**
 * User-facing BluePOS barcode symbology keys (POS PLUS–aligned names).
 * Only entries with a bwip-js BCID are renderable.
 */
export const BARCODE_TYPE_OPTIONS = [
  'Codabar',
  'Industrial2of5',
  'Interleaved2of5',
  'Code39',
  'Code39Extended',
  'Code93',
  'Code93Extended',
  'Code128',
  'Code11',
  'CodeMSI',
  'PostNet',
  'EAN13',
  'UPCA',
  'EAN8',
  'EAN128',
  'UPCSupplemental2',
  'UPCSupplemental5',
  'UPCE0',
  'UPCE1',
  'Matrix2of5',
  'PDF417',
  'DataMatrix',
  'QRCode',
  'IntelligentMail',
  'DataMatrixGS1',
  'ITF14',
  'DataBar',
  'IntelligentMailPackage',
  'Pharmacode',
  'DeutschePostIdentcode',
  'DeutschePostLeitcode',
  'SSCC',
  'QRCodeGS1',
  'QRCodeEPC',
  'MicroQRCode',
  'AztecCode',
] as const

export type BarcodeType = (typeof BARCODE_TYPE_OPTIONS)[number]

type SymbologyDef = {
  bcid: string | null
  /** Why unsupported when bcid is null */
  unsupportedReason?: string
  validate: (value: string) => string | null
}

function digitsOnly(value: string) {
  return /^\d+$/.test(value)
}

function eanCheckDigitOk(body: string, expectedLength: number): boolean {
  if (!digitsOnly(body) || body.length !== expectedLength) return false
  const digits = body.split('').map(Number)
  const check = digits[digits.length - 1]
  const data = digits.slice(0, -1)
  let sum = 0
  for (let i = 0; i < data.length; i += 1) {
    const weight = (data.length - i) % 2 === 0 ? 1 : 3
    sum += data[i] * weight
  }
  const calc = (10 - (sum % 10)) % 10
  return calc === check
}

const SYMBOLOGY: Record<BarcodeType, SymbologyDef> = {
  Codabar: {
    bcid: 'rationalizedCodabar',
    validate: (value) => {
      if (!/^[A-Da-d][0-9$/:.\-+]+[A-Da-d]$/.test(value)) {
        return 'Codabar requires start/stop A–D and digits or -$/:.+ characters.'
      }
      return null
    },
  },
  Industrial2of5: {
    bcid: 'industrial2of5',
    validate: (value) =>
      digitsOnly(value) && value.length >= 1
        ? null
        : 'Industrial 2 of 5 must contain digits only.',
  },
  Interleaved2of5: {
    bcid: 'interleaved2of5',
    validate: (value) =>
      digitsOnly(value) && value.length % 2 === 0 && value.length >= 2
        ? null
        : 'Interleaved 2 of 5 must be an even number of digits.',
  },
  Code39: {
    bcid: 'code39',
    validate: (value) =>
      /^[0-9A-Z\-.$/+% ]+$/i.test(value)
        ? null
        : 'Code39 allows A–Z, 0–9, and - . $ / + % space.',
  },
  Code39Extended: {
    bcid: 'code39ext',
    validate: (value) =>
      value.length > 0 ? null : 'Code39 Extended requires a non-empty value.',
  },
  Code93: {
    bcid: 'code93',
    validate: (value) =>
      value.length > 0 ? null : 'Code93 requires a non-empty value.',
  },
  Code93Extended: {
    bcid: 'code93ext',
    validate: (value) =>
      value.length > 0 ? null : 'Code93 Extended requires a non-empty value.',
  },
  Code128: {
    bcid: 'code128',
    validate: (value) =>
      value.length > 0 ? null : 'Code128 requires a non-empty value.',
  },
  Code11: {
    bcid: 'code11',
    validate: (value) =>
      /^[0-9\-]+$/.test(value)
        ? null
        : 'Code 11 must contain only digits and dashes.',
  },
  CodeMSI: {
    bcid: 'msi',
    validate: (value) =>
      digitsOnly(value) ? null : 'MSI must contain only digits.',
  },
  PostNet: {
    bcid: 'postnet',
    validate: (value) =>
      digitsOnly(value) && [5, 9, 11].includes(value.length)
        ? null
        : 'POSTNET must be 5, 9, or 11 digits.',
  },
  EAN13: {
    bcid: 'ean13',
    validate: (value) =>
      eanCheckDigitOk(value, 13)
        ? null
        : 'EAN13 must be 13 digits with a valid check digit.',
  },
  UPCA: {
    bcid: 'upca',
    validate: (value) =>
      digitsOnly(value) && (value.length === 11 || value.length === 12)
        ? null
        : 'UPC-A must be 11 or 12 digits.',
  },
  EAN8: {
    bcid: 'ean8',
    validate: (value) =>
      eanCheckDigitOk(value, 8)
        ? null
        : 'EAN8 must be 8 digits with a valid check digit.',
  },
  EAN128: {
    bcid: 'gs1-128',
    validate: (value) =>
      /^\(\d{2,4}\)/.test(value)
        ? null
        : 'EAN128 / GS1-128 requires Application Identifiers, e.g. (01)…',
  },
  UPCSupplemental2: {
    bcid: 'ean2',
    validate: (value) =>
      digitsOnly(value) && value.length === 2
        ? null
        : 'UPC Supplemental 2 must be exactly 2 digits.',
  },
  UPCSupplemental5: {
    bcid: 'ean5',
    validate: (value) =>
      digitsOnly(value) && value.length === 5
        ? null
        : 'UPC Supplemental 5 must be exactly 5 digits.',
  },
  UPCE0: {
    bcid: 'upce',
    validate: (value) =>
      digitsOnly(value) && (value.length === 7 || value.length === 8)
        ? null
        : 'UPC-E must be 7 or 8 digits.',
  },
  UPCE1: {
    bcid: 'upce',
    validate: (value) =>
      digitsOnly(value) && (value.length === 7 || value.length === 8)
        ? null
        : 'UPC-E must be 7 or 8 digits. (UPCE1 uses the same encoder as UPCE0.)',
  },
  Matrix2of5: {
    bcid: 'matrix2of5',
    validate: (value) =>
      digitsOnly(value) ? null : 'Matrix 2 of 5 must contain only digits.',
  },
  PDF417: {
    bcid: 'pdf417',
    validate: (value) =>
      value.length > 0 ? null : 'PDF417 requires a non-empty value.',
  },
  DataMatrix: {
    bcid: 'datamatrix',
    validate: (value) =>
      value.length > 0 ? null : 'Data Matrix requires a non-empty value.',
  },
  QRCode: {
    bcid: 'qrcode',
    validate: (value) =>
      value.length > 0 ? null : 'QR Code requires a non-empty value.',
  },
  IntelligentMail: {
    bcid: 'onecode',
    validate: (value) =>
      digitsOnly(value) && value.length >= 20
        ? null
        : 'Intelligent Mail (USPS OneCode) requires a long numeric tracking string.',
  },
  DataMatrixGS1: {
    bcid: 'gs1datamatrix',
    validate: (value) =>
      /^\(\d{2,4}\)/.test(value)
        ? null
        : 'Data Matrix GS1 requires Application Identifiers, e.g. (01)…',
  },
  ITF14: {
    bcid: 'itf14',
    validate: (value) =>
      digitsOnly(value) && (value.length === 13 || value.length === 14)
        ? null
        : 'ITF-14 must be 13 or 14 digits.',
  },
  DataBar: {
    bcid: 'databaromni',
    validate: (value) =>
      digitsOnly(value) && (value.length === 13 || value.length === 14)
        ? null
        : 'GS1 DataBar Omnidirectional must be 13 or 14 digits.',
  },
  IntelligentMailPackage: {
    bcid: null,
    unsupportedReason:
      'bwip-js has no Intelligent Mail Package encoder in this build.',
    validate: () =>
      'Intelligent Mail Package is not supported by the current barcode library.',
  },
  Pharmacode: {
    bcid: 'pharmacode',
    validate: (value) =>
      digitsOnly(value) && value.length >= 1 && value.length <= 6
        ? null
        : 'Pharmacode must be 1 to 6 digits.',
  },
  DeutschePostIdentcode: {
    bcid: 'identcode',
    validate: (value) =>
      digitsOnly(value) && (value.length === 11 || value.length === 12)
        ? null
        : 'Deutsche Post Identcode must be 11 or 12 digits.',
  },
  DeutschePostLeitcode: {
    bcid: 'leitcode',
    validate: (value) =>
      digitsOnly(value) && value.length >= 13
        ? null
        : 'Deutsche Post Leitcode must be a valid numeric Leitcode.',
  },
  SSCC: {
    bcid: 'sscc18',
    validate: (value) =>
      digitsOnly(value) && (value.length === 17 || value.length === 18)
        ? null
        : 'SSCC must be 17 or 18 digits.',
  },
  QRCodeGS1: {
    bcid: 'gs1qrcode',
    validate: (value) =>
      /^\(\d{2,4}\)/.test(value)
        ? null
        : 'QR Code GS1 requires Application Identifiers, e.g. (01)…',
  },
  QRCodeEPC: {
    bcid: null,
    unsupportedReason:
      'bwip-js has no dedicated EPC QR encoder; do not fake EPC layout.',
    validate: () =>
      'QR Code EPC is not supported by the current barcode library.',
  },
  MicroQRCode: {
    bcid: 'microqrcode',
    validate: (value) =>
      value.length > 0 ? null : 'Micro QR Code requires a non-empty value.',
  },
  AztecCode: {
    bcid: 'azteccode',
    validate: (value) =>
      value.length > 0 ? null : 'Aztec Code requires a non-empty value.',
  },
}

export function isBarcodeType(value: string): value is BarcodeType {
  return (BARCODE_TYPE_OPTIONS as readonly string[]).includes(value)
}

export function isBarcodeTypeSupported(type: BarcodeType): boolean {
  return SYMBOLOGY[type].bcid !== null
}

export function unsupportedBarcodeTypes(): Array<{
  type: BarcodeType
  reason: string
}> {
  return BARCODE_TYPE_OPTIONS.filter((type) => !SYMBOLOGY[type].bcid).map(
    (type) => ({
      type,
      reason: SYMBOLOGY[type].unsupportedReason ?? 'Unsupported',
    }),
  )
}

export function validateBarcodeValue(
  value: string,
  type: BarcodeType,
): string | null {
  const trimmed = value.trim()
  if (!trimmed) return 'Barcode value is empty.'
  return SYMBOLOGY[type].validate(trimmed)
}

/**
 * Render a barcode as SVG. Does not mutate the input value.
 * Throws when the symbology is unsupported or the value is invalid.
 */
export function renderBarcodeSvg(
  value: string,
  barcodeType: BarcodeType = 'Code128',
): string {
  const type = isBarcodeType(barcodeType) ? barcodeType : 'Code128'
  const def = SYMBOLOGY[type]
  if (!def.bcid) {
    throw new Error(
      def.unsupportedReason ?? `${type} is not supported for rendering.`,
    )
  }

  const validationError = validateBarcodeValue(value, type)
  if (validationError) {
    throw new Error(validationError)
  }

  return bwipjs.toSVG({
    bcid: def.bcid,
    text: value.trim(),
    scale: 2,
    height: 10,
    includetext: false,
    backgroundcolor: 'FFFFFF',
  })
}

/** @deprecated Prefer renderBarcodeSvg */
export function barcodeSvg(
  value: string,
  type: BarcodeType | 'CODE128' | 'CODE39' = 'Code128',
): string {
  const normalized =
    type === 'CODE128'
      ? 'Code128'
      : type === 'CODE39'
        ? 'Code39'
        : type
  return renderBarcodeSvg(value, normalized)
}

export function migrateLegacyBarcodeType(
  value: string | undefined,
): BarcodeType {
  if (value === 'CODE39' || value === 'Code39') return 'Code39'
  if (value === 'CODE128' || value === 'Code128') return 'Code128'
  if (value && isBarcodeType(value) && isBarcodeTypeSupported(value)) {
    return value
  }
  return 'Code128'
}
