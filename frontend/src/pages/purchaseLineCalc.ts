/**
 * Live purchase-line money helpers.
 * GST base follows "GST Apply On"; discount base follows "Discount Apply On".
 * Dual %/Rs modes: pct keeps rate and refreshes Rs; rs keeps amount and refreshes %.
 */

export type DualMode = 'pct' | 'rs'

export type PurchaseCalcLine = {
  quantity: string
  unit_cost: string
  mrp: string
  regular_disc_pct: string
  regular_disc_rs?: string
  special_disc_pct: string
  special_disc_rs?: string
  trade_disc_pct?: string
  tax_pct: string
  tax_amount: string
  further_tax_pct: string
  further_tax_amount?: string
  disc_after_gst_pct?: string
  disc_after_gst_rs?: string
  advance_tax_pct?: string
  advance_tax_amount?: string
  discount_amount?: string
  line_total?: string
}

export type PurchaseCalcSettings = {
  calcMethod: string
  discountApplyOn: string
  dualModes: Record<string, DualMode>
}

export type LineCalcResult = {
  qty: number
  unitCost: number
  mrpEx: number
  mrpIn: number
  tradeGross: number
  discountBase: number
  regularDiscRs: number
  specialDiscRs: number
  discountAmount: number
  afterDisc: number
  gstBase: number
  taxAmount: number
  afterGst: number
  discAfterGstRs: number
  furtherBase: number
  furtherTaxAmount: number
  advanceTaxAmount: number
  lineTotal: number
  costPerUnit: number
  regular_disc_pct: string
  regular_disc_rs: string
  special_disc_pct: string
  special_disc_rs: string
  tax_pct: string
  tax_amount: string
  further_tax_pct: string
  further_tax_amount: string
  disc_after_gst_pct: string
  disc_after_gst_rs: string
  advance_tax_pct: string
  advance_tax_amount: string
  discount_amount: string
  line_total: string
}

function n(value: string | number | null | undefined): number {
  const v = Number(value ?? 0)
  return Number.isFinite(v) ? v : 0
}

function money(value: number): string {
  return (Number.isFinite(value) ? value : 0).toFixed(4)
}

function pctStr(value: number): string {
  if (!Number.isFinite(value) || Math.abs(value) < 1e-12) return '0'
  const fixed = value.toFixed(8)
  return fixed.replace(/\.?0+$/, '') || '0'
}

function dualAmount(
  mode: DualMode,
  base: number,
  pctRaw: string | undefined,
  rsRaw: string | undefined,
): { pctOut: string; rsOut: string; rs: number } {
  const pctIn = n(pctRaw)
  const rsIn = n(rsRaw)
  if (mode === 'pct') {
    const rs = (base * pctIn) / 100
    return { pctOut: pctRaw && pctRaw !== '' ? pctRaw : '0', rsOut: money(rs), rs }
  }
  const pct = base > 0 ? (rsIn / base) * 100 : 0
  return { pctOut: pctStr(pct), rsOut: money(rsIn), rs: rsIn }
}

/** Stored MRP meaning depends on GST Apply On (excl vs incl). */
export function mrpPair(mrpStored: number, taxPct: number, calcMethod: string): { ex: number; incl: number } {
  if (calcMethod === 'mrp_incl_gst') {
    const incl = mrpStored
    const ex = taxPct > 0 ? incl / (1 + taxPct / 100) : incl
    return { ex, incl }
  }
  const ex = mrpStored
  const incl = taxPct > 0 ? ex * (1 + taxPct / 100) : ex
  return { ex, incl }
}

function resolveGst(
  calcMethod: string,
  mode: DualMode,
  gstBase: number,
  taxPctRaw: string,
  taxAmountRaw: string,
): { taxAmount: number; tax_pct: string; tax_amount: string } {
  const taxPct = n(taxPctRaw)

  if (calcMethod === 'manual') {
    const dual = dualAmount(mode, gstBase, taxPctRaw, taxAmountRaw)
    return { taxAmount: dual.rs, tax_pct: dual.pctOut, tax_amount: dual.rsOut }
  }

  // Non-manual: tax rate drives the amount so qty / MRP / apply-on changes stay in sync.
  // Editing the Rs field updates the rate via the dual input handler first.
  if (mode === 'rs' && taxPct === 0) {
    const dual = dualAmount('rs', gstBase, taxPctRaw, taxAmountRaw)
    return { taxAmount: dual.rs, tax_pct: dual.pctOut, tax_amount: dual.rsOut }
  }

  if (calcMethod === 'mrp_incl_gst') {
    const taxAmount = taxPct > 0 ? (gstBase * taxPct) / (100 + taxPct) : 0
    return { taxAmount, tax_pct: taxPctRaw || '0', tax_amount: money(taxAmount) }
  }

  const taxAmount = (gstBase * taxPct) / 100
  return { taxAmount, tax_pct: taxPctRaw || '0', tax_amount: money(taxAmount) }
}

export function computeLine(line: PurchaseCalcLine, settings: PurchaseCalcSettings): LineCalcResult {
  const qty = n(line.quantity)
  const unitCost = n(line.unit_cost)
  const taxPct = n(line.tax_pct)
  const tradeGross = qty * unitCost
  const { ex: mrpEx, incl: mrpIn } = mrpPair(n(line.mrp), taxPct, settings.calcMethod)

  let discountBase = tradeGross
  if (settings.discountApplyOn === 'mrp_ex_gst') discountBase = qty * mrpEx
  else if (settings.discountApplyOn === 'mrp_incl_gst') discountBase = qty * mrpIn

  const modes = settings.dualModes
  const regular = dualAmount(modes.regular_disc ?? 'rs', discountBase, line.regular_disc_pct, line.regular_disc_rs)
  const special = dualAmount(modes.special_disc ?? 'rs', discountBase, line.special_disc_pct, line.special_disc_rs)
  const tradeDiscRs = (tradeGross * n(line.trade_disc_pct)) / 100
  const discountAmount = regular.rs + special.rs + tradeDiscRs
  const afterDisc = Math.max(0, tradeGross - discountAmount)

  let gstBase = afterDisc
  switch (settings.calcMethod) {
    case 'mrp_ex_gst':
    case 'no_gst':
      gstBase = qty * mrpEx
      break
    case 'mrp_incl_gst':
      gstBase = qty * mrpIn
      break
    case 'trade_before_disc':
    case 'gst_on_trade':
      gstBase = tradeGross
      break
    case 'manual':
      gstBase = afterDisc
      break
    case 'trade_after_disc':
    case 'disc_then_gst':
    default:
      gstBase = afterDisc
      break
  }

  const gst = resolveGst(
    settings.calcMethod,
    modes.gst ?? 'rs',
    gstBase,
    line.tax_pct,
    line.tax_amount,
  )

  const afterGst = afterDisc + gst.taxAmount
  const discAfter = dualAmount(
    modes.disc_after_gst ?? 'rs',
    afterGst,
    line.disc_after_gst_pct,
    line.disc_after_gst_rs,
  )
  const furtherBase = Math.max(0, afterGst - discAfter.rs)
  const further = dualAmount(
    modes.further_tax ?? 'rs',
    furtherBase,
    line.further_tax_pct,
    line.further_tax_amount,
  )
  const advance = dualAmount(
    modes.advance_tax ?? 'rs',
    furtherBase,
    line.advance_tax_pct,
    line.advance_tax_amount,
  )

  const lineTotal = Math.max(0, afterDisc + gst.taxAmount + further.rs - discAfter.rs)
  const costPerUnit = qty > 0 ? lineTotal / qty : unitCost

  return {
    qty,
    unitCost,
    mrpEx,
    mrpIn,
    tradeGross,
    discountBase,
    regularDiscRs: regular.rs,
    specialDiscRs: special.rs,
    discountAmount,
    afterDisc,
    gstBase,
    taxAmount: gst.taxAmount,
    afterGst,
    discAfterGstRs: discAfter.rs,
    furtherBase,
    furtherTaxAmount: further.rs,
    advanceTaxAmount: advance.rs,
    lineTotal,
    costPerUnit,
    regular_disc_pct: regular.pctOut,
    regular_disc_rs: regular.rsOut,
    special_disc_pct: special.pctOut,
    special_disc_rs: special.rsOut,
    tax_pct: gst.tax_pct,
    tax_amount: gst.tax_amount,
    further_tax_pct: further.pctOut,
    further_tax_amount: further.rsOut,
    disc_after_gst_pct: discAfter.pctOut,
    disc_after_gst_rs: discAfter.rsOut,
    advance_tax_pct: advance.pctOut,
    advance_tax_amount: advance.rsOut,
    discount_amount: money(discountAmount),
    line_total: money(lineTotal),
  }
}

export function applyCalcToLine<T extends PurchaseCalcLine>(line: T, settings: PurchaseCalcSettings): T {
  const calc = computeLine(line, settings)
  return {
    ...line,
    regular_disc_pct: calc.regular_disc_pct,
    regular_disc_rs: calc.regular_disc_rs,
    special_disc_pct: calc.special_disc_pct,
    special_disc_rs: calc.special_disc_rs,
    tax_pct: calc.tax_pct,
    tax_amount: calc.tax_amount,
    further_tax_pct: calc.further_tax_pct,
    further_tax_amount: calc.further_tax_amount,
    disc_after_gst_pct: calc.disc_after_gst_pct,
    disc_after_gst_rs: calc.disc_after_gst_rs,
    advance_tax_pct: calc.advance_tax_pct,
    advance_tax_amount: calc.advance_tax_amount,
    discount_amount: calc.discount_amount,
    line_total: calc.line_total,
  }
}

export function sumHeaderCharges(input: {
  lines: PurchaseCalcLine[]
  settings: PurchaseCalcSettings
  freight: string
  loading: string
  otherCharges: string
  otherDiscount: string
  tradeOffer: string
  advanceTax: string
  withholdingIsPct: boolean
  roundOff: string
}) {
  const calcs = input.lines.map((line) => computeLine(line, input.settings))
  const subtotal = calcs.reduce((s, c) => s + c.tradeGross, 0)
  const discount = calcs.reduce((s, c) => s + c.discountAmount, 0)
  const tax = calcs.reduce((s, c) => s + c.taxAmount, 0)
  const further = calcs.reduce((s, c) => s + c.furtherTaxAmount, 0)
  const netLines = subtotal - discount + tax + further
  const charges = n(input.freight) + n(input.loading) + n(input.otherCharges)
  const reductions = n(input.otherDiscount) + n(input.tradeOffer)
  const advance = input.withholdingIsPct
    ? (Math.max(0, netLines + charges - reductions) * n(input.advanceTax)) / 100
    : n(input.advanceTax)
  const grand = netLines + charges - reductions + advance + n(input.roundOff)
  return {
    subtotal: money(subtotal),
    discount_amount: money(discount),
    tax_amount: money(tax),
    further_tax_amount: money(further),
    advance_tax_amount: money(advance),
    grand_total: money(Math.max(0, grand)),
  }
}
