import './ToggleSwitch.css'

type Props = {
  checked: boolean
  onChange: (checked: boolean) => void
  label: string
  disabled?: boolean
  title?: string
  tone?: 'default' | 'cash' | 'bank' | 'rec' | 'pay' | 'active'
}

export function ToggleSwitch({
  checked,
  onChange,
  label,
  disabled = false,
  title,
  tone = 'default',
}: Props) {
  return (
    <label
      className={`vca-toggle vca-toggle-${tone}${checked ? ' is-on' : ''}${disabled ? ' is-disabled' : ''}`}
      title={title ?? (label || undefined)}
    >
      <input
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
      />
      <span className="vca-toggle-track" aria-hidden="true">
        <span className="vca-toggle-thumb" />
      </span>
      {label ? <span className="vca-toggle-label">{label}</span> : null}
    </label>
  )
}
