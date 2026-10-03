import { useCallback, useMemo, useState } from 'react'
import type { SaleDraftLine, SaleLineKind, SalePayload } from '../../types/sales'

/**
 * Cart state for the sales screen.
 *
 * Money here is a PREVIEW only — the server recalculates price, totals and stock
 * on save and its figures win. Free lines are never added automatically: a
 * scheme only lands in the cart when the salesman presses Add.
 */
export function useSaleCart() {
  const [lines, setLines] = useState<SaleDraftLine[]>([])
  const [notes, setNotes] = useState('')
  const [customerUlid, setCustomerUlid] = useState<string | null>(null)

  const addProduct = useCallback(
    (product: {
      ulid: string
      name: string
      product_number: string
    }, quantity: string) => {
      setLines((current) => {
        const existing = current.find(
          (line) => line.product_ulid === product.ulid && line.line_kind === 'sale',
        )

        if (existing) {
          return current.map((line) =>
            line === existing ? { ...line, quantity: addDecimal(line.quantity, quantity) } : line,
          )
        }

        return [
          ...current,
          {
            product_ulid: product.ulid,
            product_name: product.name,
            product_number: product.product_number,
            quantity,
            line_kind: 'sale' as SaleLineKind,
          },
        ]
      })
    },
    [],
  )

  const setQuantity = useCallback((productUlid: string, quantity: string) => {
    setLines((current) =>
      current.map((line) =>
        line.product_ulid === productUlid && line.line_kind === 'sale'
          ? { ...line, quantity }
          : line,
      ),
    )
  }, [])

  const removeLine = useCallback((productUlid: string, lineKind: SaleLineKind) => {
    setLines((current) =>
      current.filter(
        (line) => !(line.product_ulid === productUlid && line.line_kind === lineKind),
      ),
    )
  }, [])

  /** Add a scheme reward. Only ever called from an explicit Add press. */
  const addSchemeReward = useCallback(
    (scheme: {
      ulid: string
      reward_product: { ulid: string; name: string; product_number: string }
      max_reward_qty: string
    }) => {
      setLines((current) => {
        if (current.some((line) => line.scheme_ulid === scheme.ulid)) {
          return current
        }

        return [
          ...current,
          {
            product_ulid: scheme.reward_product.ulid,
            product_name: scheme.reward_product.name,
            product_number: scheme.reward_product.product_number,
            quantity: scheme.max_reward_qty,
            line_kind: 'free_scheme',
            scheme_ulid: scheme.ulid,
          },
        ]
      })
    },
    [],
  )

  const skipScheme = useCallback((schemeUlid: string) => {
    setLines((current) => current.filter((line) => line.scheme_ulid !== schemeUlid))
  }, [])

  const addPackaging = useCallback(
    (product: {
      ulid: string
      name: string
      product_number: string
      max_free_qty_per_sale?: string | null
    }, qty: string) => {
      setLines((current) => {
        const existing = current.find(
          (line) => line.product_ulid === product.ulid && line.line_kind === 'free_packaging',
        )

        if (existing) {
          return current.map((line) =>
            line === existing ? { ...line, quantity: addDecimal(line.quantity, qty) } : line,
          )
        }

        return [
          ...current,
          {
            product_ulid: product.ulid,
            product_name: product.name,
            product_number: product.product_number,
            quantity: qty,
            line_kind: 'free_packaging' as SaleLineKind,
          },
        ]
      })
    },
    [],
  )

  const clear = useCallback(() => {
    setLines([])
    setNotes('')
    setCustomerUlid(null)
  }, [])

  const paidLines = useMemo(
    () => lines.filter((line) => line.line_kind === 'sale'),
    [lines],
  )

  const freeLines = useMemo(
    () => lines.filter((line) => line.line_kind !== 'sale'),
    [lines],
  )

  const appliedSchemeUlids = useMemo(
    () =>
      lines
        .filter((line) => line.line_kind === 'free_scheme' && line.scheme_ulid)
        .map((line) => line.scheme_ulid as string),
    [lines],
  )

  const packagingLines = useMemo(
    () => lines.filter((line) => line.line_kind === 'free_packaging'),
    [lines],
  )

  const hasPaidLines = paidLines.length > 0

  /**
   * Display-only subtotal used to decide which schemes to show. Free lines are
   * excluded because they must never help qualify a cart for another reward.
   */
  const hasEnoughForSchemes = hasPaidLines

  const buildPayload = useCallback((): SalePayload => {
    const packaging: SalePayload['free_lines'] = packagingLines.map((line) => ({
      product_ulid: line.product_ulid,
      qty: line.quantity,
      line_kind: 'free_packaging' as const,
    }))

    return {
      items: paidLines.map((line) => ({
        product_ulid: line.product_ulid,
        quantity: line.quantity,
      })),
      ...(packaging.length ? { free_lines: packaging } : {}),
      ...(appliedSchemeUlids.length ? { applied_scheme_ulids: appliedSchemeUlids } : {}),
      customer_ulid: customerUlid,
      notes: notes || null,
    }
  }, [paidLines, packagingLines, appliedSchemeUlids, customerUlid, notes])

  return {
    lines,
    paidLines,
    freeLines,
    packagingLines,
    appliedSchemeUlids,
    hasPaidLines,
    hasEnoughForSchemes,
    notes,
    customerUlid,
    setNotes,
    setCustomerUlid,
    addProduct,
    setQuantity,
    removeLine,
    addSchemeReward,
    skipScheme,
    addPackaging,
    clear,
    buildPayload,
  }
}

/** Add two decimal strings without floating point drift. */
function addDecimal(a: string, b: string): string {
  const left = Number.parseFloat(a) || 0
  const right = Number.parseFloat(b) || 0
  return (left + right).toFixed(6)
}
