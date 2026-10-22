export function formatDecimal(
  value: string | number | null | undefined,
): string {
  if (value === null || value === undefined || value === '') return '0.0'

  const raw = String(value).trim()
  const parsed = Number(raw)

  if (!Number.isFinite(parsed)) return raw

  // BluePOS display rule:
  // - always show a decimal point with at least one fractional digit
  // - preserve meaningful fractional digits
  // - remove trailing zeroes only
  //
  // Examples:
  // 1        -> 1.0
  // 1.1000   -> 1.1
  // 1.1100   -> 1.11
  // 1.123000 -> 1.123
  const normalized = raw.match(/^([+-]?)(\d+)(?:\.(\d+))?$/)

  if (normalized) {
    const [, sign, integerRaw, fractionRaw = ''] = normalized
    const integer = integerRaw.replace(/^0+(?=\d)/, '') || '0'
    const fraction = fractionRaw.replace(/0+$/, '')

    return `${sign}${integer}.${fraction || '0'}`
  }

  const fallback = parsed.toString()

  return fallback.includes('.') ? fallback : `${fallback}.0`
}
