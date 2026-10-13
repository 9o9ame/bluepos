import './ListLoadingIndicator.css'

type ListLoadingIndicatorProps = {
  visible?: boolean
  label?: string
}

export function ListLoadingIndicator({
  visible = true,
  label = 'Loading more records…',
}: ListLoadingIndicatorProps) {
  if (!visible) return null

  return (
    <div className="ui-list-loading" role="status" aria-live="polite">
      <span className="ui-list-loading-spinner" aria-hidden="true" />
      <span className="ui-list-loading-label">{label}</span>
    </div>
  )
}
