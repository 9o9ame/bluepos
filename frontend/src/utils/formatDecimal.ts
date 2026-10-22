export function formatDecimal(value: string | number | null | undefined, digits = 1): string {
  if (value === null || value === undefined || value === '') return '0.0'

  const parsed = typeof value === 'number' ? value : Number.parseFloat(value)
  if (!Number.isFinite(parsed)) return String(value)

  return parsed.toFixed(digits)
}
