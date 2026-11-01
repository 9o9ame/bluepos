import './ListLoadingIndicator.css'
import { UiSkeleton } from './UiSkeleton'

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
    <div className="ui-list-loading">
      <UiSkeleton label={label} compact/>
    </div>
  )
}
