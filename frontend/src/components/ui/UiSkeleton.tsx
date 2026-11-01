import './UiSkeleton.css'

export function UiSkeleton({ label = 'Loading…', compact = false }: { label?: string; compact?: boolean }) {
  return <div className={`ui-skeleton${compact ? ' is-compact' : ''}`} role="status" aria-live="polite" aria-busy="true" aria-label={label}>
    <span className="ui-skeleton-avatar" aria-hidden="true"/>
    <span className="ui-skeleton-lines" aria-hidden="true"><span/><span/><span/></span>
    {!compact && <span className="ui-skeleton-banner" aria-hidden="true"/>}
    <span className="ui-skeleton-accessible">{label}</span>
  </div>
}
