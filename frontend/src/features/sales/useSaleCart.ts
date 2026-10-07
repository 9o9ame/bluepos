import { useMemo, useState } from 'react'
import type { Product, ProductBarcode } from '../../types/catalog'
import type {
  Sale,
  SaleQuotation,
  SaleDraftLine,
  SaleDraftUnitOption,
  SalePriceType,
} from '../../types/sales'
import type { SaleOfferEvaluation } from '../../types/saleSchemes'

type Scheme = SaleOfferEvaluation['schemes'][number]
type Packaging = SaleOfferEvaluation['packaging'][number]

type CartPreview = {
  subtotal: string
  discount: string
  tax: string
  grandTotal: string
  qualifyingSubtotal: string
  quantity: string
}

type LinePreview = {
  grossAmount: string
  discountAmount: string
  discountPercent: string
  taxAmount: string
  taxPercent: string
  netAmount: string
}

function toNumber(value: string | number | null | undefined): number {
  const number = Number(value ?? 0)
  return Number.isFinite(number) ? number : 0
}

function money(value: number): string {
  return value.toFixed(2)
}

function money4(value: number): string {
  return value.toFixed(4)
}

function factor8(value: string | null | undefined): string {
  const number = toNumber(value)
  return (number > 0 ? number : 1).toFixed(8)
}

function isValidQuantity(value: string): boolean {
  return /^(?:0|[1-9]\d*)(?:\.\d{0,6})?$/.test(value)
}

function isPositiveQuantity(value: string): boolean {
  return isValidQuantity(value) && toNumber(value) > 0
}

function isValidPercentInput(value: string): boolean {
  if (value === '') return true
  if (!/^(?:0|[1-9]\d*)(?:\.\d{0,8})?$/.test(value)) return false
  return toNumber(value) <= 100
}

function isValidMoneyInput(value: string): boolean {
  return value === '' || /^(?:0|[1-9]\d*)(?:\.\d{0,4})?$/.test(value)
}

function resolvedPriceType(priceType: SalePriceType): 'retail' | 'wholesale' {
  return priceType === 'wholesale' ? 'wholesale' : 'retail'
}

function unitOptions(product: Product): SaleDraftUnitOption[] {
  const options: SaleDraftUnitOption[] = []
  const usedUnits = new Set<string>()

  if (product.base_unit?.ulid) {
    options.push({
      unit_ulid: product.base_unit.ulid,
      code: product.base_unit.code,
      name: product.base_unit.name,
      symbol: product.base_unit.symbol,
      allows_decimal: product.base_unit.allows_decimal,
      conversion_factor: '1.00000000',
      barcode: null,
    })
    usedUnits.add(product.base_unit.ulid)
  }

  if (product.secondary_unit?.ulid) {
    options.push({
      unit_ulid: product.secondary_unit.ulid,
      code: product.secondary_unit.code,
      name: product.secondary_unit.name,
      symbol: product.secondary_unit.symbol,
      allows_decimal: product.secondary_unit.allows_decimal,
      conversion_factor: factor8(product.secondary_conversion_factor),
      barcode: null,
    })
    usedUnits.add(product.secondary_unit.ulid)
  }

  for (const barcode of product.barcodes ?? []) {
    if (!barcode.is_active || !barcode.unit?.ulid) continue
    if (usedUnits.has(barcode.unit.ulid)) continue

    options.push({
      unit_ulid: barcode.unit.ulid,
      code: barcode.unit.code,
      name: barcode.unit.name,
      symbol: barcode.unit.symbol,
      allows_decimal: barcode.unit.allows_decimal,
      conversion_factor: factor8(barcode.conversion_factor),
      barcode: barcode.barcode,
    })
    usedUnits.add(barcode.unit.ulid)
  }

  return options
}

function priceForLine(
  line: SaleDraftLine,
  priceType: SalePriceType,
): string {
  const base =
    resolvedPriceType(priceType) === 'wholesale'
      ? line.wholesale_price
      : line.retail_price

  if (base === null || base === undefined || base === '') {
    return '0.0000'
  }

  return money4(
    toNumber(base) * toNumber(line.conversion_factor ?? '1'),
  )
}

function newLineKey(prefix: string): string {
  return `${prefix}:${Date.now()}:${Math.random().toString(36).slice(2, 9)}`
}

function calculateLinePreview(line: SaleDraftLine): LinePreview {
  if (line.line_kind !== 'sale') {
    return {
      grossAmount: '0.00',
      discountAmount: '0.00',
      discountPercent: '0.00',
      taxAmount: '0.00',
      taxPercent: '0.00',
      netAmount: '0.00',
    }
  }

  const qty = toNumber(line.quantity)
  const unitPrice = toNumber(line.unit_price)
  const gross = qty * unitPrice

  const enteredPercent = toNumber(line.discount_percent)
  const enteredAmount = toNumber(line.discount_amount)

  let discount = 0
  let discountPercent = 0

  if (enteredPercent > 0) {
    discountPercent = Math.min(enteredPercent, 100)
    discount = gross * (discountPercent / 100)
  } else if (enteredAmount > 0) {
    discount = Math.min(enteredAmount, Math.max(gross, 0))
    discountPercent = gross > 0 ? (discount / gross) * 100 : 0
  }

  const taxable = Math.max(gross - discount, 0)
  const taxPercent = Math.max(toNumber(line.tax_percent), 0)
  const tax = taxable * (taxPercent / 100)
  const net = taxable + tax

  return {
    grossAmount: money(gross),
    discountAmount: money(discount),
    discountPercent: discountPercent.toFixed(2),
    taxAmount: money(tax),
    taxPercent: taxPercent.toFixed(2),
    netAmount: money(net),
  }
}

export function useSaleCart() {
  const [lines, setLines] = useState<SaleDraftLine[]>([])
  const [priceType, setPriceTypeState] = useState<SalePriceType>('default')
  const [appliedSchemes, setAppliedSchemes] = useState<
    Array<{
      scheme_ulid: string
      qty: string
    }>
  >([])
  const [skippedSchemes, setSkippedSchemes] = useState<string[]>([])
  const [notes, setNotes] = useState('')
  const [customerUlid, setCustomerUlid] = useState<string | null>(null)

  function setPriceType(next: SalePriceType) {
    setPriceTypeState(next)
    setLines((current) =>
      current.map((line) =>
        line.line_kind === 'sale'
          ? { ...line, unit_price: priceForLine(line, next) }
          : line,
      ),
    )
  }

  function productLine(
    lineKey: string,
    product: Product,
    quantity = '1.000000',
    scannedBarcode: ProductBarcode | null = null,
    availableBaseStock: string | null = null,
  ): SaleDraftLine {
    const availableUnits = unitOptions(product)
    const barcodeUnit = scannedBarcode?.unit ?? null
    const selectedUnit = barcodeUnit ?? product.base_unit ?? null
    const conversionFactor = scannedBarcode
      ? factor8(scannedBarcode.conversion_factor)
      : '1.00000000'
    const barcode = scannedBarcode?.barcode ?? null
    const retailPrice =
      product.prices?.find(
        (row) => row.is_active && row.price_type === 'retail',
      )?.amount ?? null
    const wholesalePrice =
      product.prices?.find(
        (row) => row.is_active && row.price_type === 'wholesale',
      )?.amount ?? null

    const line: SaleDraftLine = {
      line_key: lineKey,
      product_ulid: product.ulid,
      product_name: product.name,
      product_number: product.product_number,
      quantity,
      line_kind: 'sale',
      unit_ulid: selectedUnit?.ulid,
      unit_code: selectedUnit?.code,
      unit_name: selectedUnit?.name,
      unit_symbol: selectedUnit?.symbol,
      unit_allows_decimal: selectedUnit?.allows_decimal,
      barcode,
      conversion_factor: conversionFactor,
      available_units: availableUnits,
      available_base_stock: availableBaseStock,
      retail_price: retailPrice,
      wholesale_price: wholesalePrice,
      unit_price: '0.0000',
      discount_percent: '0',
      discount_amount: '0',
      tax_percent: product.is_taxable ? product.tax_percent : '0',
      notes: null,
    }

    line.unit_price = priceForLine(line, priceType)
    return line
  }

  function addProduct(
    product: Product,
    quantity = '1.000000',
    scannedBarcode: ProductBarcode | null = null,
    availableBaseStock: string | null = null,
  ) {
    if (!isPositiveQuantity(quantity)) return null

    const barcodeUnit = scannedBarcode?.unit ?? null
    const selectedUnit = barcodeUnit ?? product.base_unit ?? null
    const barcode = scannedBarcode?.barcode ?? null
    const existing = lines.find(
      (line) =>
        line.line_kind === 'sale' &&
        line.product_ulid === product.ulid &&
        (line.unit_ulid ?? null) === (selectedUnit?.ulid ?? null) &&
        (line.barcode ?? null) === barcode,
    )

    if (existing) {
      const newQuantity = toNumber(existing.quantity) + toNumber(quantity)
      setLines((current) =>
        current.map((line) =>
          line.line_key === existing.line_key
            ? {
                ...line,
                quantity: newQuantity.toFixed(6),
                available_base_stock:
                  availableBaseStock ?? line.available_base_stock ?? null,
              }
            : line,
        ),
      )
      return existing.line_key
    }

    const line = productLine(
      newLineKey(`sale:${product.ulid}`),
      product,
      quantity,
      scannedBarcode,
      availableBaseStock,
    )

    setLines((current) => [...current, line])
    return line.line_key
  }

  function replaceProduct(
    lineKey: string,
    product: Product,
    scannedBarcode: ProductBarcode | null = null,
    availableBaseStock: string | null = null,
  ) {
    const next = productLine(
      lineKey,
      product,
      '1.000000',
      scannedBarcode,
      availableBaseStock,
    )

    setLines((current) =>
      current.map((line) =>
        line.line_key === lineKey && line.line_kind === 'sale'
          ? next
          : line,
      ),
    )
  }

  function setQuantity(lineKey: string, quantity: string) {
    if (quantity !== '' && !isValidQuantity(quantity)) return

    setLines((current) =>
      current.map((line) => {
        if (line.line_key !== lineKey || line.line_kind !== 'sale') return line

        if (
          quantity !== '' &&
          line.unit_allows_decimal === false &&
          !Number.isInteger(toNumber(quantity))
        ) {
          return line
        }

        return { ...line, quantity }
      }),
    )
  }

  function setLineUnit(lineKey: string, unitUlid: string) {
    setLines((current) =>
      current.map((line) => {
        if (line.line_key !== lineKey || line.line_kind !== 'sale') return line

        const option = line.available_units?.find(
          (row) => row.unit_ulid === unitUlid,
        )
        if (!option) return line

        const barcode = option.barcode ?? null
        const next: SaleDraftLine = {
          ...line,
          unit_ulid: option.unit_ulid,
          unit_code: option.code,
          unit_name: option.name,
          unit_symbol: option.symbol,
          unit_allows_decimal: option.allows_decimal,
          barcode,
          conversion_factor: option.conversion_factor,
        }
        next.unit_price = priceForLine(next, priceType)
        return next
      }),
    )
  }

  function setDiscountPercent(lineKey: string, value: string) {
    if (!isValidPercentInput(value)) return

    setLines((current) =>
      current.map((line) =>
        line.line_key === lineKey && line.line_kind === 'sale'
          ? {
              ...line,
              discount_percent: value,
              discount_amount: toNumber(value) > 0 ? '0' : line.discount_amount,
            }
          : line,
      ),
    )
  }

  function setDiscountAmount(lineKey: string, value: string) {
    if (!isValidMoneyInput(value)) return

    setLines((current) =>
      current.map((line) =>
        line.line_key === lineKey && line.line_kind === 'sale'
          ? {
              ...line,
              discount_amount: value,
              discount_percent: toNumber(value) > 0 ? '0' : line.discount_percent,
            }
          : line,
      ),
    )
  }

  function availableStock(line: SaleDraftLine): string | null {
    if (line.line_kind !== 'sale') return null

    if (
      line.available_base_stock === null ||
      line.available_base_stock === undefined
    ) {
      return null
    }

    const factor = toNumber(line.conversion_factor ?? '1')
    if (factor <= 0) return null

    return (toNumber(line.available_base_stock) / factor).toFixed(6)
  }

  function removeLine(lineKey: string) {
    setLines((current) => current.filter((line) => line.line_key !== lineKey))
  }

  function addSchemeReward(
    scheme: Scheme,
    quantity = scheme.max_reward_qty,
  ) {
    if (!isPositiveQuantity(quantity)) return
    if (toNumber(quantity) > toNumber(scheme.max_reward_qty)) return

    const lineKey = `scheme:${scheme.ulid}`
    const existing = lines.find((line) => line.line_key === lineKey)

    if (existing) {
      setLines((current) =>
        current.map((line) =>
          line.line_key === lineKey ? { ...line, quantity } : line,
        ),
      )
    } else {
      setLines((current) => [
        ...current,
        {
          line_key: lineKey,
          product_ulid: scheme.reward_product.ulid,
          product_name: scheme.reward_product.name,
          product_number: scheme.reward_product.product_number,
          quantity,
          line_kind: 'free_scheme',
          scheme_ulid: scheme.ulid,
          unit_price: '0',
          discount_percent: '0',
          discount_amount: '0',
          tax_percent: '0',
        },
      ])
    }

    setAppliedSchemes((current) => {
      const existingScheme = current.find(
        (item) => item.scheme_ulid === scheme.ulid,
      )

      if (existingScheme) {
        return current.map((item) =>
          item.scheme_ulid === scheme.ulid ? { ...item, qty: quantity } : item,
        )
      }

      return [...current, { scheme_ulid: scheme.ulid, qty: quantity }]
    })

    setSkippedSchemes((current) =>
      current.filter((ulid) => ulid !== scheme.ulid),
    )
  }

  function setSchemeQuantity(schemeUlid: string, quantity: string) {
    if (quantity !== '' && !isValidQuantity(quantity)) return

    setAppliedSchemes((current) =>
      current.map((item) =>
        item.scheme_ulid === schemeUlid ? { ...item, qty: quantity } : item,
      ),
    )

    setLines((current) =>
      current.map((line) =>
        line.line_kind === 'free_scheme' && line.scheme_ulid === schemeUlid
          ? { ...line, quantity }
          : line,
      ),
    )
  }

  function skipScheme(schemeUlid: string) {
    setAppliedSchemes((current) =>
      current.filter((item) => item.scheme_ulid !== schemeUlid),
    )

    setLines((current) =>
      current.filter(
        (line) =>
          !(line.line_kind === 'free_scheme' && line.scheme_ulid === schemeUlid),
      ),
    )

    setSkippedSchemes((current) =>
      current.includes(schemeUlid) ? current : [...current, schemeUlid],
    )
  }

  function addPackaging(
    product: Packaging,
    quantity = '1.000000',
  ) {
    if (!isPositiveQuantity(quantity)) return

    const maxQty = product.max_free_qty_per_sale
      ? toNumber(product.max_free_qty_per_sale)
      : null
    const lineKey = `packaging:${product.ulid}`
    const existing = lines.find((line) => line.line_key === lineKey)
    const existingQty = existing ? toNumber(existing.quantity) : 0
    const requestedQty = toNumber(quantity)
    const totalQty = existingQty + requestedQty

    if (maxQty !== null && totalQty > maxQty) return

    if (existing) {
      setLines((current) =>
        current.map((line) =>
          line.line_key === lineKey
            ? { ...line, quantity: totalQty.toFixed(6) }
            : line,
        ),
      )
      return
    }

    setLines((current) => [
      ...current,
      {
        line_key: lineKey,
        product_ulid: product.ulid,
        product_name: product.name,
        product_number: product.product_number,
        quantity,
        line_kind: 'free_packaging',
        unit_price: '0',
        discount_percent: '0',
        discount_amount: '0',
        tax_percent: '0',
      },
    ])
  }

  function clear() {
    setLines([])
    setAppliedSchemes([])
    setSkippedSchemes([])
    setNotes('')
    setCustomerUlid(null)
  }

  function restore(
    restoredLines: SaleDraftLine[],
    restoredNotes = '',
    restoredCustomerUlid: string | null = null,
    restoredPriceType?: SalePriceType,
  ) {
    const nextPriceType = restoredPriceType ?? priceType
    setPriceTypeState(nextPriceType)
    setLines(
      restoredLines.map((line) =>
        line.line_kind === 'sale'
          ? { ...line, unit_price: priceForLine(line, nextPriceType) }
          : line,
      ),
    )
    setNotes(restoredNotes)
    setCustomerUlid(restoredCustomerUlid)

    const schemes = restoredLines
      .filter(
        (line) =>
          line.line_kind === 'free_scheme' &&
          Boolean(line.scheme_ulid) &&
          isPositiveQuantity(line.quantity),
      )
      .map((line) => ({
        scheme_ulid: line.scheme_ulid as string,
        qty: line.quantity,
      }))

    setAppliedSchemes(schemes)
    setSkippedSchemes([])
  }

  function restoreSaleDocument(
    source: Sale | SaleQuotation,
    productsByUlid: Record<string, Product>,
    stockByProduct: Record<string, string>,
  ) {
    const restoredLines: SaleDraftLine[] = source.items.flatMap((item) => {
      if (!item.product) return []

      const product = productsByUlid[item.product.ulid]
      if (!product) return []

      const availableUnits = unitOptions(product)
      const sourceUnit = item.unit
      const hasSourceUnit =
        sourceUnit?.ulid &&
        availableUnits.some((option) => option.unit_ulid === sourceUnit.ulid)

      if (sourceUnit?.ulid && !hasSourceUnit) {
        availableUnits.push({
          unit_ulid: sourceUnit.ulid,
          code: sourceUnit.code,
          name: sourceUnit.name,
          symbol: sourceUnit.symbol,
          allows_decimal: sourceUnit.allows_decimal,
          conversion_factor: factor8(item.conversion_factor),
          barcode: item.barcode ?? null,
        })
      }

      const retailPrice =
        product.prices?.find(
          (row) => row.is_active && row.price_type === 'retail',
        )?.amount ?? null
      const wholesalePrice =
        product.prices?.find(
          (row) => row.is_active && row.price_type === 'wholesale',
        )?.amount ?? null

      return [{
        line_key: `source:${source.ulid}:${item.ulid}`,
        product_ulid: product.ulid,
        product_name: product.name,
        product_number: product.product_number,
        quantity: item.quantity,
        line_kind: item.line_kind,
        scheme_ulid: item.sale_scheme?.ulid,
        unit_ulid: sourceUnit?.ulid,
        unit_code: sourceUnit?.code,
        unit_name: sourceUnit?.name,
        unit_symbol: sourceUnit?.symbol,
        unit_allows_decimal: sourceUnit?.allows_decimal,
        barcode: item.barcode,
        conversion_factor: item.conversion_factor,
        available_units: availableUnits,
        available_base_stock: stockByProduct[product.ulid] ?? null,
        retail_price: retailPrice,
        wholesale_price: wholesalePrice,
        unit_price: item.line_kind === 'sale' ? item.unit_price : '0.0000',
        discount_percent: item.discount_percent,
        discount_amount: item.discount_amount,
        tax_percent: product.is_taxable ? product.tax_percent : '0',
        notes: item.notes,
      }]
    })

    restore(
      restoredLines,
      source.notes ?? '',
      source.customer?.ulid ?? null,
      source.price_type ?? 'default',
    )
  }

  const paidLines = useMemo(
    () => lines.filter((line) => line.line_kind === 'sale'),
    [lines],
  )

  const freeLines = useMemo(
    () =>
      lines.filter(
        (line) =>
          line.line_kind === 'free_packaging' || line.line_kind === 'free_scheme',
      ),
    [lines],
  )

  const packagingLines = useMemo(
    () => lines.filter((line) => line.line_kind === 'free_packaging'),
    [lines],
  )

  const appliedSchemeUlids = useMemo(
    () => appliedSchemes.map((scheme) => scheme.scheme_ulid),
    [appliedSchemes],
  )

  const preview = useMemo<CartPreview>(() => {
    let subtotal = 0
    let discount = 0
    let tax = 0
    let quantity = 0

    for (const line of paidLines) {
      const lineQuantity = toNumber(line.quantity)
      const linePreview = calculateLinePreview(line)
      quantity += lineQuantity
      subtotal += toNumber(linePreview.grossAmount)
      discount += toNumber(linePreview.discountAmount)
      tax += toNumber(linePreview.taxAmount)
    }

    const qualifyingSubtotal = Math.max(subtotal - discount, 0)
    const grandTotal = qualifyingSubtotal + tax

    return {
      subtotal: money(subtotal),
      discount: money(discount),
      tax: money(tax),
      grandTotal: money(grandTotal),
      qualifyingSubtotal: money(qualifyingSubtotal),
      quantity: quantity.toFixed(3),
    }
  }, [paidLines])

  const hasPaidLines = paidLines.length > 0

  function buildPayload() {
    const items = paidLines
      .filter((line) => isPositiveQuantity(line.quantity))
      .map((line) => ({
        product_ulid: line.product_ulid,
        barcode: line.barcode || undefined,
        unit_ulid: line.unit_ulid || undefined,
        quantity: line.quantity,
        discount_percent:
          toNumber(line.discount_percent) > 0 ? line.discount_percent : undefined,
        discount_amount:
          toNumber(line.discount_amount) > 0 ? line.discount_amount : undefined,
        notes: line.notes || undefined,
      }))

    const freePackagingLines = freeLines
      .filter(
        (line) =>
          line.line_kind === 'free_packaging' && isPositiveQuantity(line.quantity),
      )
      .map((line) => ({
        product_ulid: line.product_ulid,
        qty: line.quantity,
        line_kind: 'free_packaging' as const,
      }))

    const payload = {
      price_type: priceType,
      items,
      customer_ulid: customerUlid,
      notes: notes || null,
    } as const

    const validSchemes = appliedSchemes.filter((scheme) =>
      isPositiveQuantity(scheme.qty),
    )

    return {
      ...payload,
      ...(freePackagingLines.length > 0
        ? { free_lines: freePackagingLines }
        : {}),
      ...(validSchemes.length > 0
        ? { applied_schemes: validSchemes }
        : {}),
    }
  }

  return {
    lines,
    paidLines,
    freeLines,
    packagingLines,

    priceType,
    setPriceType,

    appliedSchemes,
    appliedSchemeUlids,
    skippedSchemes,

    preview,
    linePreview: calculateLinePreview,
    availableStock,
    hasPaidLines,

    notes,
    setNotes,

    customerUlid,
    setCustomerUlid,

    addProduct,
    replaceProduct,
    setQuantity,
    setLineUnit,
    setDiscountPercent,
    setDiscountAmount,
    removeLine,

    addSchemeReward,
    setSchemeQuantity,
    skipScheme,

    addPackaging,

    clear,
    restore,
    restoreSaleDocument,
    buildPayload,
  }
}
