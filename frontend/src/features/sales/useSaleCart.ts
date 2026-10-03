import { useMemo, useState } from 'react'
import type { SaleDraftLine } from '../../types/sales'
import type { SaleOfferEvaluation } from '../../types/saleSchemes'

type Scheme = SaleOfferEvaluation['schemes'][number]
type Packaging = SaleOfferEvaluation['packaging'][number]

type CartPreview = {
  subtotal: string
  discount: string
  tax: string
  grandTotal: string
  quantity: string
}

function toNumber(value: string | number | null | undefined): number {
  const number = Number(value ?? 0)

  return Number.isFinite(number) ? number : 0
}

function money(value: number): string {
  return value.toFixed(2)
}

function isValidQuantity(value: string): boolean {
  return /^(?:0|[1-9]\d*)(?:\.\d{0,6})?$/.test(value)
}

function isPositiveQuantity(value: string): boolean {
  return isValidQuantity(value) && toNumber(value) > 0
}

export function useSaleCart() {
  const [lines, setLines] = useState<SaleDraftLine[]>([])
  const [appliedSchemes, setAppliedSchemes] = useState<
    Array<{
      scheme_ulid: string
      qty: string
    }>
  >([])

  const [skippedSchemes, setSkippedSchemes] = useState<string[]>([])
  const [notes, setNotes] = useState('')
  const [customerUlid, setCustomerUlid] = useState<string | null>(null)

  function addProduct(
    product: {
      ulid: string
      name: string
      product_number: string
      price?: string
      unit_price?: string
      tax_percent?: string
    },
    quantity = '1.000000',
  ) {
    if (!isPositiveQuantity(quantity)) {
      return
    }

    const existing = lines.find(
      (line) =>
        line.product_ulid === product.ulid &&
        line.line_kind === 'sale',
    )

    if (existing) {
      const newQuantity =
        toNumber(existing.quantity) + toNumber(quantity)

      setLines((current) =>
        current.map((line) =>
          line === existing
            ? {
                ...line,
                quantity: newQuantity.toFixed(6),
              }
            : line,
        ),
      )

      return
    }

    setLines((current) => [
      ...current,
      {
        product_ulid: product.ulid,
        product_name: product.name,
        product_number: product.product_number,
        quantity,
        line_kind: 'sale',
        unit_price: product.unit_price ?? product.price ?? '0',
        tax_percent: product.tax_percent ?? '0',
      },
    ])
  }

  function setQuantity(productUlid: string, quantity: string) {
    if (quantity !== '' && !isValidQuantity(quantity)) {
      return
    }

    setLines((current) =>
      current.map((line) =>
        line.product_ulid === productUlid &&
        line.line_kind === 'sale'
          ? {
              ...line,
              quantity,
            }
          : line,
      ),
    )
  }

  function removeLine(
    productUlid: string,
    lineKind?: SaleDraftLine['line_kind'],
  ) {
    setLines((current) =>
      current.filter((line) => {
        if (line.product_ulid !== productUlid) {
          return true
        }

        if (lineKind && line.line_kind !== lineKind) {
          return true
        }

        return false
      }),
    )
  }

  function addSchemeReward(
    scheme: Scheme,
    quantity = scheme.max_reward_qty,
  ) {
    if (!isPositiveQuantity(quantity)) {
      return
    }

    if (toNumber(quantity) > toNumber(scheme.max_reward_qty)) {
      return
    }

    const existing = lines.find(
      (line) =>
        line.line_kind === 'free_scheme' &&
        line.scheme_ulid === scheme.ulid,
    )

    if (existing) {
      setLines((current) =>
        current.map((line) =>
          line === existing
            ? {
                ...line,
                quantity,
              }
            : line,
        ),
      )
    } else {
      setLines((current) => [
        ...current,
        {
          product_ulid: scheme.reward_product.ulid,
          product_name: scheme.reward_product.name,
          product_number: scheme.reward_product.product_number,
          quantity,
          line_kind: 'free_scheme',
          scheme_ulid: scheme.ulid,
          unit_price: '0',
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
          item.scheme_ulid === scheme.ulid
            ? {
                ...item,
                qty: quantity,
              }
            : item,
        )
      }

      return [
        ...current,
        {
          scheme_ulid: scheme.ulid,
          qty: quantity,
        },
      ]
    })

    setSkippedSchemes((current) =>
      current.filter((ulid) => ulid !== scheme.ulid),
    )
  }

  function setSchemeQuantity(
    schemeUlid: string,
    quantity: string,
  ) {
    if (quantity !== '' && !isValidQuantity(quantity)) {
      return
    }

    setAppliedSchemes((current) =>
      current.map((item) =>
        item.scheme_ulid === schemeUlid
          ? {
              ...item,
              qty: quantity,
            }
          : item,
      ),
    )

    setLines((current) =>
      current.map((line) =>
        line.line_kind === 'free_scheme' &&
        line.scheme_ulid === schemeUlid
          ? {
              ...line,
              quantity,
            }
          : line,
      ),
    )
  }

  function skipScheme(schemeUlid: string) {
    setAppliedSchemes((current) =>
      current.filter(
        (item) => item.scheme_ulid !== schemeUlid,
      ),
    )

    setLines((current) =>
      current.filter(
        (line) =>
          !(
            line.line_kind === 'free_scheme' &&
            line.scheme_ulid === schemeUlid
          ),
      ),
    )

    setSkippedSchemes((current) =>
      current.includes(schemeUlid)
        ? current
        : [...current, schemeUlid],
    )
  }

  function addPackaging(
    product: Packaging,
    quantity = '1.000000',
  ) {
    if (!isPositiveQuantity(quantity)) {
      return
    }

    const maxQty = product.max_free_qty_per_sale
      ? toNumber(product.max_free_qty_per_sale)
      : null

    const existing = lines.find(
      (line) =>
        line.line_kind === 'free_packaging' &&
        line.product_ulid === product.ulid,
    )

    const existingQty = existing
      ? toNumber(existing.quantity)
      : 0

    const requestedQty = toNumber(quantity)
    const totalQty = existingQty + requestedQty

    if (maxQty !== null && totalQty > maxQty) {
      return
    }

    if (existing) {
      setLines((current) =>
        current.map((line) =>
          line === existing
            ? {
                ...line,
                quantity: totalQty.toFixed(6),
              }
            : line,
        ),
      )

      return
    }

    setLines((current) => [
      ...current,
      {
        product_ulid: product.ulid,
        product_name: product.name,
        product_number: product.product_number,
        quantity,
        line_kind: 'free_packaging',
        unit_price: '0',
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
  ) {
    setLines(restoredLines)
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

  const paidLines = useMemo(
    () =>
      lines.filter(
        (line) => line.line_kind === 'sale',
      ),
    [lines],
  )

  const freeLines = useMemo(
    () =>
      lines.filter(
        (line) =>
          line.line_kind === 'free_packaging' ||
          line.line_kind === 'free_scheme',
      ),
    [lines],
  )

  const packagingLines = useMemo(
    () =>
      lines.filter(
        (line) =>
          line.line_kind === 'free_packaging',
      ),
    [lines],
  )

  const appliedSchemeUlids = useMemo(
    () =>
      appliedSchemes.map(
        (scheme) => scheme.scheme_ulid,
      ),
    [appliedSchemes],
  )

  const preview = useMemo<CartPreview>(() => {
    let subtotal = 0
    let quantity = 0

    for (const line of paidLines) {
      const lineQuantity = toNumber(line.quantity)
      const unitPrice = toNumber(line.unit_price)

      quantity += lineQuantity
      subtotal += lineQuantity * unitPrice
    }

    const discount = 0
    const tax = 0
    const grandTotal =
      subtotal - discount + tax

    return {
      subtotal: money(subtotal),
      discount: money(discount),
      tax: money(tax),
      grandTotal: money(grandTotal),
      quantity: quantity.toFixed(3),
    }
  }, [paidLines])

  const hasPaidLines = paidLines.length > 0

  function buildPayload() {
    const items = paidLines
      .filter((line) =>
        isPositiveQuantity(line.quantity),
      )
      .map((line) => ({
        product_ulid: line.product_ulid,
        quantity: line.quantity,
      }))

    /*
     * Only packaging free items are sent through free_lines.
     * Scheme rewards are represented by applied_schemes.
     */
    const freePackagingLines = freeLines
      .filter(
        (line) =>
          line.line_kind === 'free_packaging' &&
          isPositiveQuantity(line.quantity),
      )
      .map((line) => ({
        product_ulid: line.product_ulid,
        qty: line.quantity,
        line_kind: 'free_packaging' as const,
      }))

    const payload: {
      items: Array<{
        product_ulid: string
        quantity: string
      }>
      free_lines?: Array<{
        product_ulid: string
        qty: string
        line_kind: 'free_packaging'
      }>
      applied_schemes?: Array<{
        scheme_ulid: string
        qty: string
      }>
      customer_ulid?: string | null
      notes?: string | null
    } = {
      items,
      customer_ulid: customerUlid,
      notes: notes || null,
    }

    if (freePackagingLines.length > 0) {
      payload.free_lines = freePackagingLines
    }

    const validSchemes = appliedSchemes.filter(
      (scheme) =>
        isPositiveQuantity(scheme.qty),
    )

    if (validSchemes.length > 0) {
      payload.applied_schemes = validSchemes
    }

    return payload
  }

  return {
    lines,

    paidLines,
    freeLines,
    packagingLines,

    appliedSchemes,
    appliedSchemeUlids,
    skippedSchemes,

    preview,
    hasPaidLines,

    notes,
    setNotes,

    customerUlid,
    setCustomerUlid,

    addProduct,
    setQuantity,
    removeLine,

    addSchemeReward,
    setSchemeQuantity,
    skipScheme,

    addPackaging,

    clear,
    restore,
    buildPayload,
  }
}