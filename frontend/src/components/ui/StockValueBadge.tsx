import './StockValueBadge.css'

type StockValueBadgeProps = {
  value: string | number | null | undefined
  displayValue?: string
  className?: string
}

export function StockValueBadge({
  value,
  displayValue,
  className,
}: StockValueBadgeProps) {
  const numeric = Number(value ?? 0)
  const tone = Number.isFinite(numeric)
    ? numeric > 0
      ? 'is-positive'
      : numeric < 0
        ? 'is-negative'
        : 'is-zero'
    : 'is-zero'

  return (
    <span className={['ui-stock-value-badge', tone, className].filter(Boolean).join(' ')}>
      {displayValue ?? String(value ?? '0')}
    </span>
  )
}
