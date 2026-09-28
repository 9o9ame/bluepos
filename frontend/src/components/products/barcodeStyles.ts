export type BarcodeStyleId =
  | 'a4_sheet'
  | 'two_side_1'
  | 'two_side_2'
  | 'one_1'
  | 'one_2'
  | 'one_3'
  | 'two_side_no_barcode'
  | 'two_side_barcode'
  | 'three_side'
  | 'three_simple'
  | 'one_4'
  | 'one_5'
  | 'one_6_3x2'
  | 'one_7'

export type BarcodeStylePreset = {
  id: BarcodeStyleId
  name: string
  usedFor: 'Barcode'
  active: boolean
  order: number
  columns: number
  rows: number
  labelWidthMm: number
  labelHeightMm: number
  showBarcode: boolean
  priceEmphasis: boolean
  gapMm: number
  pageWidthMm?: number
  pageHeightMm?: number
}

const BUILTIN: BarcodeStylePreset[] = [
  {
    id: 'a4_sheet',
    name: 'A4 Sheet',
    usedFor: 'Barcode',
    active: true,
    order: 1,
    columns: 3,
    rows: 8,
    labelWidthMm: 63,
    labelHeightMm: 33,
    showBarcode: true,
    priceEmphasis: false,
    gapMm: 2,
    pageWidthMm: 210,
    pageHeightMm: 297,
  },
  {
    id: 'two_side_1',
    name: '2 Barcode Side by Side (Style 1)',
    usedFor: 'Barcode',
    active: true,
    order: 2,
    columns: 2,
    rows: 1,
    labelWidthMm: 50,
    labelHeightMm: 30,
    showBarcode: true,
    priceEmphasis: false,
    gapMm: 2,
  },
  {
    id: 'two_side_2',
    name: '2 Barcode Side by Side (Style 2)',
    usedFor: 'Barcode',
    active: true,
    order: 3,
    columns: 2,
    rows: 1,
    labelWidthMm: 50,
    labelHeightMm: 25,
    showBarcode: true,
    priceEmphasis: false,
    gapMm: 2,
  },
  {
    id: 'one_1',
    name: '1 Barcode (Style 1)',
    usedFor: 'Barcode',
    active: true,
    order: 4,
    columns: 1,
    rows: 1,
    labelWidthMm: 50,
    labelHeightMm: 30,
    showBarcode: true,
    priceEmphasis: false,
    gapMm: 0,
  },
  {
    id: 'one_2',
    name: '1 Barcode (Style 2)',
    usedFor: 'Barcode',
    active: true,
    order: 5,
    columns: 1,
    rows: 1,
    labelWidthMm: 50,
    labelHeightMm: 30,
    showBarcode: true,
    priceEmphasis: false,
    gapMm: 0,
  },
  {
    id: 'one_3',
    name: '1 Barcode (Style 3)',
    usedFor: 'Barcode',
    active: true,
    order: 6,
    columns: 1,
    rows: 1,
    labelWidthMm: 40,
    labelHeightMm: 25,
    showBarcode: true,
    priceEmphasis: true,
    gapMm: 0,
  },
  {
    id: 'two_side_no_barcode',
    name: '2 Barcode Side by Side (Without Barcode)',
    usedFor: 'Barcode',
    active: false,
    order: 7,
    columns: 2,
    rows: 1,
    labelWidthMm: 50,
    labelHeightMm: 30,
    showBarcode: false,
    priceEmphasis: true,
    gapMm: 2,
  },
  {
    id: 'two_side_barcode',
    name: '2 Barcode Side by Side (Barcode)',
    usedFor: 'Barcode',
    active: true,
    order: 8,
    columns: 2,
    rows: 1,
    labelWidthMm: 50,
    labelHeightMm: 30,
    showBarcode: true,
    priceEmphasis: false,
    gapMm: 2,
  },
  {
    id: 'three_side',
    name: '3 Barcode Side by Side',
    usedFor: 'Barcode',
    active: true,
    order: 9,
    columns: 3,
    rows: 1,
    labelWidthMm: 40,
    labelHeightMm: 25,
    showBarcode: true,
    priceEmphasis: false,
    gapMm: 1.5,
  },
  {
    id: 'three_simple',
    name: '3 Simple Side by Side',
    usedFor: 'Barcode',
    active: false,
    order: 10,
    columns: 3,
    rows: 1,
    labelWidthMm: 40,
    labelHeightMm: 20,
    showBarcode: false,
    priceEmphasis: true,
    gapMm: 1.5,
  },
  {
    id: 'one_4',
    name: '1 Barcode (Style 4)',
    usedFor: 'Barcode',
    active: true,
    order: 11,
    columns: 1,
    rows: 1,
    labelWidthMm: 60,
    labelHeightMm: 40,
    showBarcode: true,
    priceEmphasis: false,
    gapMm: 0,
  },
  {
    id: 'one_5',
    name: '1 Barcode (Style 5)',
    usedFor: 'Barcode',
    active: true,
    order: 12,
    columns: 1,
    rows: 1,
    labelWidthMm: 60,
    labelHeightMm: 40,
    showBarcode: true,
    priceEmphasis: true,
    gapMm: 0,
  },
  {
    id: 'one_6_3x2',
    name: '1 Barcode (Style 6) Single 3x2',
    usedFor: 'Barcode',
    active: true,
    order: 13,
    columns: 1,
    rows: 1,
    labelWidthMm: 76,
    labelHeightMm: 51,
    showBarcode: true,
    priceEmphasis: false,
    gapMm: 0,
  },
  {
    id: 'one_7',
    name: '1 Barcode (Style 7)',
    usedFor: 'Barcode',
    active: true,
    order: 14,
    columns: 1,
    rows: 1,
    labelWidthMm: 50,
    labelHeightMm: 30,
    showBarcode: true,
    priceEmphasis: false,
    gapMm: 0,
  },
]

const STYLE_PREF_KEY = 'bluepos.barcodePrintStylePrefs'

export type BarcodeStylePrefs = {
  activeIds: BarcodeStyleId[]
  order: BarcodeStyleId[]
}

function defaultPrefs(): BarcodeStylePrefs {
  return {
    activeIds: BUILTIN.filter((row) => row.active).map((row) => row.id),
    order: BUILTIN.map((row) => row.id),
  }
}

export function loadBarcodeStylePrefs(): BarcodeStylePrefs {
  const fallback = defaultPrefs()
  try {
    const raw = localStorage.getItem(STYLE_PREF_KEY)
    if (!raw) return fallback
    const parsed = JSON.parse(raw) as Partial<BarcodeStylePrefs>
    const known = new Set(BUILTIN.map((row) => row.id))
    const order = (parsed.order ?? fallback.order).filter((id) =>
      known.has(id),
    ) as BarcodeStyleId[]
    const activeIds = (parsed.activeIds ?? fallback.activeIds).filter((id) =>
      known.has(id),
    ) as BarcodeStyleId[]
    const safeActive =
      activeIds.length > 0 ? activeIds : fallback.activeIds.slice(0, 1)
    const safeOrder =
      order.length === BUILTIN.length
        ? order
        : [...order, ...fallback.order.filter((id) => !order.includes(id))]
    return { activeIds: safeActive, order: safeOrder }
  } catch {
    return fallback
  }
}

export function saveBarcodeStylePrefs(prefs: BarcodeStylePrefs) {
  if (prefs.activeIds.length < 1) {
    throw new Error('At least one barcode style must remain active.')
  }
  localStorage.setItem(STYLE_PREF_KEY, JSON.stringify(prefs))
}

export function getAllBarcodeStylePresets(): BarcodeStylePreset[] {
  const prefs = loadBarcodeStylePrefs()
  const byId = new Map(BUILTIN.map((row) => [row.id, row]))
  return prefs.order
    .map((id, index) => {
      const base = byId.get(id)
      if (!base) return null
      return {
        ...base,
        order: index + 1,
        active: prefs.activeIds.includes(id),
      }
    })
    .filter((row): row is BarcodeStylePreset => Boolean(row))
}

export function getActiveBarcodeStyles(): BarcodeStylePreset[] {
  return getAllBarcodeStylePresets().filter((row) => row.active)
}

export function getBarcodeStylePreset(
  id: string | undefined,
): BarcodeStylePreset {
  const active = getActiveBarcodeStyles()
  const found = active.find((row) => row.id === id)
  if (found) return found
  return active[0] ?? getAllBarcodeStylePresets()[0]
}

export function migrateLegacyPrintStyle(
  value: string | undefined,
): BarcodeStyleId {
  if (value === 'compact') return 'one_3'
  if (value === 'price_emphasis') return 'one_5'
  if (value === 'standard') return 'one_2'
  if (getActiveBarcodeStyles().some((row) => row.id === value)) {
    return value as BarcodeStyleId
  }
  return 'one_2'
}

export type DisplayFieldOption =
  | 'PRODUCT_NAME'
  | 'CODE_PRODUCT_NAME'
  | 'PRODUCT_NAME_PACKING'
  | 'PRODUCT_NAME_CATEGORY'
  | 'PRODUCT_NAME_EXPIRY'

export const DISPLAY_FIELD_OPTIONS: Array<{
  value: DisplayFieldOption
  label: string
  disabled?: boolean
  title?: string
}> = [
  { value: 'PRODUCT_NAME', label: 'PRODUCT NAME' },
  { value: 'CODE_PRODUCT_NAME', label: 'CODE + PRODUCT NAME' },
  { value: 'PRODUCT_NAME_PACKING', label: 'PRODUCT NAME + PACKING' },
  { value: 'PRODUCT_NAME_CATEGORY', label: 'PRODUCT NAME + CATEGORY' },
  {
    value: 'PRODUCT_NAME_EXPIRY',
    label: 'PRODUCT NAME + EXPIRY (Batch required)',
    disabled: true,
    title:
      'Expiry is not available on the product list payload. Batch/expiry data is required.',
  },
]

export type PriceFieldOption =
  | 'WHOLESALE'
  | 'SELLING'
  | 'SUB_SELLING'
  | 'SELLING_WITH_UNIT'
  | 'SUB_SELLING_WITH_UNIT'
  | 'NONE'

export const PRICE_FIELD_OPTIONS: Array<{
  value: PriceFieldOption
  label: string
  disabled?: boolean
  title?: string
}> = [
  { value: 'WHOLESALE', label: 'WHOLE SALE PRICE' },
  { value: 'SELLING', label: 'SELLING PRICE' },
  { value: 'SUB_SELLING', label: 'SUB SELLING PRICE' },
  {
    value: 'SELLING_WITH_UNIT',
    label: 'SELLING PRICE (WITH UNIT)',
    disabled: true,
    title:
      'Unit-adjusted price requires verified BluePOS pricing rules; conversion_factor alone is not safe to use.',
  },
  {
    value: 'SUB_SELLING_WITH_UNIT',
    label: 'SUB SELLING PRICE (WITH UNIT)',
    disabled: true,
    title:
      'Unit-adjusted price requires verified BluePOS pricing rules; conversion_factor alone is not safe to use.',
  },
  { value: 'NONE', label: 'NO PRICE' },
]
