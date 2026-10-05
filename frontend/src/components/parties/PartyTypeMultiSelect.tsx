import type { PartyBusinessType } from '../../api/parties'

const OPTIONS: Array<{ value: PartyBusinessType; label: string }> = [
  { value: 'vendor', label: 'Vendor' },
  { value: 'customer', label: 'Customer' },
  { value: 'account', label: 'Account' },
  { value: 'salesman', label: 'Salesman' },
]

export function PartyTypeMultiSelect({
  value,
  onChange,
  disabled = false,
}: {
  value: PartyBusinessType[]
  onChange: (next: PartyBusinessType[]) => void
  disabled?: boolean
}) {
  function toggle(type: PartyBusinessType) {
    if (disabled) return
    const exists = value.includes(type)
    const next = exists ? value.filter((item) => item !== type) : [...value, type]
    if (next.length === 0) return
    onChange(next)
  }

  return (
    <details className="party-type-multi">
      <summary className="party-type-multi-control" aria-label="Party types">
        <span className="party-type-multi-chips">
          {value.map((type) => {
            const option = OPTIONS.find((item) => item.value === type)
            return <span key={type} className="party-type-chip">{option?.label ?? type}</span>
          })}
        </span>
        <span className="party-type-multi-arrow" aria-hidden>▾</span>
      </summary>
      <div className="party-type-multi-menu">
        {OPTIONS.map((option) => (
          <label key={option.value} className="party-type-multi-option">
            <input
              type="checkbox"
              checked={value.includes(option.value)}
              disabled={disabled}
              onChange={() => toggle(option.value)}
            />
            <span>{option.label}</span>
          </label>
        ))}
      </div>
    </details>
  )
}
